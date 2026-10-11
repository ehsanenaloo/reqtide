<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Policy;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Policy\AttemptOptions;
use Enaxon\Reqtide\Policy\AttemptTimeoutRequirement;
use Enaxon\Reqtide\Policy\BackoffMode;
use Enaxon\Reqtide\Policy\CircuitPolicy;
use Enaxon\Reqtide\Policy\CircuitWindowOptions;
use Enaxon\Reqtide\Policy\ConcurrencyPolicy;
use Enaxon\Reqtide\Policy\CountCircuitWindow;
use Enaxon\Reqtide\Policy\FixedWindowAnchor;
use Enaxon\Reqtide\Policy\FixedWindowOptions;
use Enaxon\Reqtide\Policy\JitterMode;
use Enaxon\Reqtide\Policy\RetryBudgetPolicy;
use Enaxon\Reqtide\Policy\RetryOptions;
use Enaxon\Reqtide\Policy\SlidingCounterOptions;
use Enaxon\Reqtide\Policy\TimeCircuitWindow;
use Enaxon\Reqtide\Policy\TokenBucketOptions;
use Enaxon\Reqtide\Tests\Support\Fixtures;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\PositiveInt;
use Enaxon\Reqtide\Value\Ratio;
use PHPUnit\Framework\TestCase;

final class PolicyBoundsTest extends TestCase
{
    private const MAX_TOKENS = 9_007_199_254;

    /** @param callable(): object $build */
    private function assertRejected(callable $build, string $label): void
    {
        try {
            $build();
        } catch (InvalidConfigurationException $e) {
            self::assertInstanceOf(InvalidConfigurationException::class, $e);

            return;
        }
        self::fail('Expected InvalidConfigurationException for ' . $label);
    }

    private function retry(int $attempts, Duration $base = new Duration(0), Duration $max = new Duration(0)): RetryOptions
    {
        return new RetryOptions(new PositiveInt($attempts), BackoffMode::Exponential, $base, $max, JitterMode::Full, Duration::zero());
    }

    public function testMaxAttemptsBoundary(): void
    {
        self::assertSame(1, $this->retry(1)->maxAttempts->value);
        self::assertSame(64, $this->retry(64)->maxAttempts->value);
        $this->assertRejected(fn() => $this->retry(65), 'maxAttempts 65');
        $this->assertRejected(fn() => new PositiveInt(0), 'maxAttempts 0');
    }

    public function testRetryDelayOrdering(): void
    {
        self::assertSame(5, $this->retry(2, new Duration(5), new Duration(5))->maximumDelay->nanoseconds);
        $this->assertRejected(fn() => $this->retry(2, new Duration(6), new Duration(5)), 'maximumDelay < baseDelay');
    }

    public function testNoRetryIsSingleAttemptWithZeroDelays(): void
    {
        $r = RetryOptions::noRetry();
        self::assertSame(1, $r->maxAttempts->value);
        self::assertTrue($r->baseDelay->isZero());
        self::assertTrue($r->maximumDelay->isZero());
        self::assertSame(JitterMode::None, $r->jitter);
    }

    public function testAttemptDurationMustBePositive(): void
    {
        self::assertSame(1, (new AttemptOptions(new Duration(1), AttemptTimeoutRequirement::Cooperative))->maximumDuration->nanoseconds);
        $this->assertRejected(fn() => new AttemptOptions(Duration::zero(), AttemptTimeoutRequirement::Cooperative), 'zero attempt duration');
    }

    public function testTokenBucketCapacityBoundary(): void
    {
        $bucket = new TokenBucketOptions(new PositiveInt(self::MAX_TOKENS), new PositiveInt(self::MAX_TOKENS), Duration::seconds(1));
        self::assertSame(self::MAX_TOKENS, $bucket->capacityUnits->value);
        $this->assertRejected(fn() => new TokenBucketOptions(new PositiveInt(self::MAX_TOKENS + 1), new PositiveInt(1), Duration::seconds(1)), 'capacity + 1');
        $this->assertRejected(fn() => new TokenBucketOptions(new PositiveInt(1), new PositiveInt(self::MAX_TOKENS + 1), Duration::seconds(1)), 'refill + 1');
        $this->assertRejected(fn() => new TokenBucketOptions(new PositiveInt(1), new PositiveInt(1), Duration::zero()), 'zero interval');
        // The scaled maximum stays within the exact-integer ceiling.
        self::assertLessThanOrEqual(9_007_199_254_740_991, self::MAX_TOKENS * 1_000_000);
        self::assertGreaterThan(9_007_199_254_740_991, (self::MAX_TOKENS + 1) * 1_000_000);
    }

    public function testWindowAlgorithmsShareTheCapacityBoundAndRequirePositiveWindow(): void
    {
        self::assertSame(self::MAX_TOKENS, (new FixedWindowOptions(new PositiveInt(self::MAX_TOKENS), Duration::seconds(1), FixedWindowAnchor::Aligned))->capacityUnits->value);
        self::assertSame(self::MAX_TOKENS, (new SlidingCounterOptions(new PositiveInt(self::MAX_TOKENS), Duration::seconds(1)))->capacityUnits->value);
        $this->assertRejected(fn() => new FixedWindowOptions(new PositiveInt(self::MAX_TOKENS + 1), Duration::seconds(1), FixedWindowAnchor::FirstHit), 'fixed capacity + 1');
        $this->assertRejected(fn() => new SlidingCounterOptions(new PositiveInt(self::MAX_TOKENS + 1), Duration::seconds(1)), 'sliding capacity + 1');
        $this->assertRejected(fn() => new FixedWindowOptions(new PositiveInt(1), Duration::zero(), FixedWindowAnchor::Aligned), 'zero fixed window');
        $this->assertRejected(fn() => new SlidingCounterOptions(new PositiveInt(1), Duration::zero()), 'zero sliding window');
    }

    public function testCountWindowBoundary(): void
    {
        self::assertSame(10_000, (new CountCircuitWindow(new PositiveInt(10_000)))->completedSamples->value);
        $this->assertRejected(fn() => new CountCircuitWindow(new PositiveInt(10_001)), 'count window 10001');
    }

    public function testTimeWindowBucketBoundary(): void
    {
        // ceil(W/G) + 1 <= 1024  =>  1023 granularity units fit, 1024 do not.
        self::assertSame(1023, intdiv((new TimeCircuitWindow(Duration::seconds(1023), Duration::seconds(1)))->window->nanoseconds, 1_000_000_000));
        $this->assertRejected(fn() => new TimeCircuitWindow(Duration::seconds(1024), Duration::seconds(1)), '1025 intersecting buckets');
        // A non-divisible window rounds up before the +1.
        self::assertSame(1_000_000_001, (new TimeCircuitWindow(new Duration(1_023_000_000_000 - 1_000_000_000 + 1), new Duration(1_000_000_000)))->bucketGranularity->nanoseconds + 1);
        $this->assertRejected(fn() => new TimeCircuitWindow(new Duration(1_023_000_000_001), new Duration(1_000_000_000)), 'ceil rounding to 1025');
        $this->assertRejected(fn() => new TimeCircuitWindow(Duration::seconds(1), Duration::zero()), 'zero granularity');
        $this->assertRejected(fn() => new TimeCircuitWindow(Duration::zero(), Duration::seconds(1)), 'zero window');
        self::assertInstanceOf(CircuitWindowOptions::class, new TimeCircuitWindow(Duration::seconds(60), Duration::seconds(1)));
    }

    private function circuit(CircuitWindowOptions $window, int $minimum = 10, int $capacity = 4, int $threshold = 2, Duration $open = new Duration(1), Duration $lease = new Duration(1)): CircuitPolicy
    {
        return new CircuitPolicy(
            Fixtures::policyIdentity(),
            $window,
            new PositiveInt($minimum),
            Ratio::of(1, 2),
            $open,
            new PositiveInt($capacity),
            new PositiveInt($threshold),
            $lease,
        );
    }

    public function testCircuitPolicyRules(): void
    {
        $count = new CountCircuitWindow(new PositiveInt(20));
        self::assertSame(20, $this->circuit($count, 20)->minimumSamples->value);
        $this->assertRejected(fn() => $this->circuit($count, 21), 'minimum > N');
        $this->assertRejected(fn() => $this->circuit($count, 10, 2, 3), 'success threshold > probe capacity');
        $this->assertRejected(fn() => $this->circuit($count, open: Duration::zero()), 'zero open duration');
        $this->assertRejected(fn() => $this->circuit($count, lease: Duration::zero()), 'zero permit lease');
        $time = new TimeCircuitWindow(Duration::seconds(60), Duration::seconds(1));
        self::assertSame(9_007_199_254_739_968, $this->circuit($time, 9_007_199_254_739_968)->minimumSamples->value);
        $this->assertRejected(fn() => $this->circuit($time, 9_007_199_254_739_969), 'minimum > aggregate ceiling');
    }

    public function testConcurrencyAndBudgetPolicies(): void
    {
        $identity = Fixtures::policyIdentity();
        $this->assertRejected(fn() => new ConcurrencyPolicy($identity, new PositiveInt(1), Duration::zero()), 'zero concurrency lease');

        $make = static fn(int $capacity, int $refill, Duration $interval, Duration $lease): RetryBudgetPolicy => new RetryBudgetPolicy(
            $identity,
            new PositiveInt($capacity),
            new PositiveInt($refill),
            $interval,
            $lease,
        );
        self::assertSame(self::MAX_TOKENS, $make(self::MAX_TOKENS, 1, Duration::seconds(1), Duration::seconds(1))->capacityTokens->value);
        $this->assertRejected(fn() => $make(self::MAX_TOKENS + 1, 1, Duration::seconds(1), Duration::seconds(1)), 'budget capacity + 1');
        $this->assertRejected(fn() => $make(1, self::MAX_TOKENS + 1, Duration::seconds(1), Duration::seconds(1)), 'budget refill + 1');
        $this->assertRejected(fn() => $make(1, 1, Duration::zero(), Duration::seconds(1)), 'zero refill interval');
        $this->assertRejected(fn() => $make(1, 1, Duration::seconds(1), Duration::zero()), 'zero reservation lease');
    }

    public function testLargestDurationIsAcceptedWhereOnlyPositivityIsRequired(): void
    {
        $bucket = new TokenBucketOptions(new PositiveInt(1), new PositiveInt(1), new Duration(PHP_INT_MAX));
        self::assertSame(PHP_INT_MAX, $bucket->refillInterval->nanoseconds);
    }
}
