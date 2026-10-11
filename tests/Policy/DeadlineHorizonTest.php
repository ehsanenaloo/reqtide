<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Policy;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Internal\NumericLimits;
use Enaxon\Reqtide\Policy\CircuitPolicy;
use Enaxon\Reqtide\Policy\ConcurrencyPolicy;
use Enaxon\Reqtide\Policy\CooldownPolicy;
use Enaxon\Reqtide\Policy\CountCircuitWindow;
use Enaxon\Reqtide\Policy\RetryBudgetPolicy;
use Enaxon\Reqtide\Tests\Support\Fixtures;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\PositiveInt;
use Enaxon\Reqtide\Value\Ratio;
use PHPUnit\Framework\TestCase;

/** Leases, open durations and retention horizons are bounded so `now + duration` cannot be made to overflow. */
final class DeadlineHorizonTest extends TestCase
{
    private const MAX = NumericLimits::MAX_DEADLINE_HORIZON_NANOSECONDS;

    /** @param callable(Duration): object $build */
    private function assertBoundary(callable $build, string $label): void
    {
        self::assertIsObject($build(new Duration(self::MAX)), $label . ' accepts exactly the horizon');
        foreach ([self::MAX + 1, PHP_INT_MAX] as $tooLong) {
            try {
                $build(new Duration($tooLong));
                self::fail($label . ' must reject ' . $tooLong);
            } catch (InvalidConfigurationException $e) {
                self::assertStringContainsString('must not exceed', $e->getMessage());
            }
        }
    }

    public function testHorizonIsTwoToTheSixtySecond(): void
    {
        self::assertSame(1 << 62, self::MAX);
    }

    public function testConcurrencyLease(): void
    {
        $this->assertBoundary(
            static fn(Duration $d) => new ConcurrencyPolicy(Fixtures::policyIdentity(), new PositiveInt(1), $d),
            'concurrency lease',
        );
    }

    public function testRetryBudgetReservationLease(): void
    {
        $this->assertBoundary(
            static fn(Duration $d) => new RetryBudgetPolicy(Fixtures::policyIdentity(), new PositiveInt(1), new PositiveInt(1), Duration::seconds(1), $d),
            'reservationLease',
        );
    }

    public function testCooldownCleanupGrace(): void
    {
        $this->assertBoundary(static fn(Duration $d) => new CooldownPolicy(Fixtures::policyIdentity(), $d), 'cleanupGrace');
    }

    public function testCircuitOpenDurationAndPermitLease(): void
    {
        $build = static fn(Duration $open, Duration $lease) => new CircuitPolicy(
            Fixtures::policyIdentity(),
            new CountCircuitWindow(new PositiveInt(10)),
            new PositiveInt(1),
            Ratio::of(1, 2),
            $open,
            new PositiveInt(1),
            new PositiveInt(1),
            $lease,
        );

        $this->assertBoundary(static fn(Duration $d) => $build($d, Duration::seconds(1)), 'openDuration');
        $this->assertBoundary(static fn(Duration $d) => $build(Duration::seconds(1), $d), 'permitLease');
    }
}
