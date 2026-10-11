<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\State\Memory;

use Enaxon\Reqtide\Admission\AdmissionStatus;
use Enaxon\Reqtide\Exception\ClockFailureException;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Policy\FixedWindowAnchor;
use Enaxon\Reqtide\Policy\FixedWindowOptions;
use Enaxon\Reqtide\Policy\RateLimitPolicy;
use Enaxon\Reqtide\Policy\TokenBucketOptions;
use Enaxon\Reqtide\State\Memory\MemoryRateLimitBackend;
use Enaxon\Reqtide\Tests\Support\Fixtures;
use Enaxon\Reqtide\Time\Clock;
use Enaxon\Reqtide\Value\CommandId;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\PolicyId;
use Enaxon\Reqtide\Value\PolicyIdentity;
use Enaxon\Reqtide\Value\PositiveInt;
use Enaxon\Reqtide\Value\ProfileVersion;
use PHPUnit\Framework\TestCase;

/** Wall-clock anchoring of aligned windows, the representable range of the anchor, and idle-record maintenance. */
final class MemoryRateLimitHardeningTest extends TestCase
{
    private const SECOND = 1_000_000_000;

    private int $commands = 0;

    private function policyFor(string $subject, FixedWindowOptions|TokenBucketOptions $algorithm): RateLimitPolicy
    {
        return new RateLimitPolicy(
            new PolicyIdentity(Fixtures::partition(), new ProfileVersion('v1'), new PolicyId($subject)),
            $algorithm,
        );
    }

    private function consume(MemoryRateLimitBackend $backend, RateLimitPolicy $policy, int $cost = 1): AdmissionStatus
    {
        return $backend->consume($policy, new PositiveInt($cost), CommandId::fromLowerHex(Fixtures::hex(++$this->commands)))->status;
    }

    private static function clockAt(string $wall, int $monotonic = 0): Clock
    {
        return new class ($wall, $monotonic) implements Clock {
            public function __construct(private readonly string $wall, public int $monotonic) {}

            public function monotonicNow(): int
            {
                return $this->monotonic;
            }

            public function wallNow(): \DateTimeImmutable
            {
                return new \DateTimeImmutable($this->wall);
            }
        };
    }

    // ---- construction ---------------------------------------------------------------------------------

    public function testASinglePartitionIsAValidCeilingAndZeroIsNot(): void
    {
        $backend = new MemoryRateLimitBackend(new AnchoredClock(0), 1);
        self::assertSame(0, $backend->partitionCount());

        $this->expectException(InvalidConfigurationException::class);
        new MemoryRateLimitBackend(new AnchoredClock(0), 0);
    }

    // ---- aligned windows are placed on the wall-clock epoch axis --------------------------------------

    public function testAnAlignedWindowStartsWhereTheWallClockSaysIncludingItsMicroseconds(): void
    {
        // Wall clock 1.25 s past the epoch at monotonic 0: one-second windows begin at monotonic 0.75 s.
        $clock = new AnchoredClock(0);
        $clock->wallEpochNanoseconds = 1_250_000_000;
        $backend = new MemoryRateLimitBackend($clock);
        $policy = $this->policyFor('aligned', new FixedWindowOptions(new PositiveInt(1), Duration::seconds(1), FixedWindowAnchor::Aligned));

        self::assertSame(AdmissionStatus::Accepted, $this->consume($backend, $policy));
        $clock->now = 749_999_999;
        self::assertSame(AdmissionStatus::Rejected, $this->consume($backend, $policy), 'still inside the window that began at -0.25 s');
        $clock->now = 750_000_000;
        self::assertSame(AdmissionStatus::Accepted, $this->consume($backend, $policy), 'the next epoch second begins here');
    }

    public function testTheAnchorSubtractsTheMonotonicReadingTakenAtConstruction(): void
    {
        // Wall clock exactly on an epoch second while the monotonic clock reads 0.4 s: boundaries at monotonic 0.6 s.
        $clock = new AnchoredClock(400_000_000);
        $clock->wallEpochNanoseconds = 10 * self::SECOND;
        $backend = new MemoryRateLimitBackend($clock);
        $policy = $this->policyFor('aligned', new FixedWindowOptions(new PositiveInt(1), Duration::seconds(1), FixedWindowAnchor::Aligned));

        self::assertSame(AdmissionStatus::Accepted, $this->consume($backend, $policy));
        $clock->now = 1_399_999_999;
        self::assertSame(AdmissionStatus::Rejected, $this->consume($backend, $policy));
        $clock->now = 1_400_000_000;
        self::assertSame(AdmissionStatus::Accepted, $this->consume($backend, $policy));
    }

    // ---- representable range of the anchor ------------------------------------------------------------

    public function testTheWallClockMayBeAsLateAsTheLargestRepresentableSecondAndNotOneLater(): void
    {
        $latest = intdiv(PHP_INT_MAX, 1_000_000_000) - 1;
        self::assertInstanceOf(MemoryRateLimitBackend::class, new MemoryRateLimitBackend(self::clockAt('@' . $latest)));

        $this->expectException(ClockFailureException::class);
        new MemoryRateLimitBackend(self::clockAt('@' . ($latest + 1)));
    }

    public function testTheWallClockMayBeAsEarlyAsTheSmallestRepresentableSecondAndNotOneEarlier(): void
    {
        $earliest = intdiv(PHP_INT_MIN, 1_000_000_000) + 1;
        self::assertInstanceOf(MemoryRateLimitBackend::class, new MemoryRateLimitBackend(self::clockAt('@' . $earliest)));

        $this->expectException(ClockFailureException::class);
        new MemoryRateLimitBackend(self::clockAt('@' . ($earliest - 1)));
    }

    public function testTheAnchorMustNotUnderflowOnceTheMonotonicReadingIsSubtracted(): void
    {
        $earliest = intdiv(PHP_INT_MIN, 1_000_000_000) + 1; // epoch ns = PHP_INT_MIN + 1_854_775_808
        // Exactly at the limit: epoch == PHP_INT_MIN + monotonic is representable.
        self::assertInstanceOf(MemoryRateLimitBackend::class, new MemoryRateLimitBackend(self::clockAt('@' . $earliest, 1_854_775_808)));

        $this->expectException(ClockFailureException::class);
        new MemoryRateLimitBackend(self::clockAt('@' . $earliest, 1_854_775_809));
    }

    // ---- maintenance ----------------------------------------------------------------------------------

    private function idleBucket(MemoryRateLimitBackend $backend, string $subject): RateLimitPolicy
    {
        $policy = $this->policyFor($subject, new TokenBucketOptions(new PositiveInt(10), new PositiveInt(10), Duration::seconds(1)));
        self::assertSame(AdmissionStatus::Accepted, $this->consume($backend, $policy));

        return $policy;
    }

    public function testAnIdleRecordIsRemovedExactlyWhenItHasBeenIdleForTheRetention(): void
    {
        $clock = new AnchoredClock(500 * self::SECOND);
        $backend = new MemoryRateLimitBackend($clock);
        $this->idleBucket($backend, 'idle');

        $clock->now = 500 * self::SECOND + 1 * self::SECOND;
        self::assertSame(0, $backend->maintain(), 'refilled but idle for only one second, with a clock far from zero');
        $clock->now = 500 * self::SECOND + 120 * self::SECOND - 1;
        self::assertSame(0, $backend->maintain());
        $clock->now = 500 * self::SECOND + 120 * self::SECOND;
        self::assertSame(1, $backend->maintain());
        self::assertSame(0, $backend->partitionCount());
    }

    public function testAnIdleRecordThatHasNotRefilledIsKept(): void
    {
        $clock = new AnchoredClock(500 * self::SECOND);
        $backend = new MemoryRateLimitBackend($clock);
        $slow = $this->policyFor('slow', new TokenBucketOptions(new PositiveInt(10), new PositiveInt(1), Duration::seconds(10_000)));
        $this->consume($backend, $slow);

        $clock->now += 130 * self::SECOND;

        self::assertSame(0, $backend->maintain());
        self::assertSame(1, $backend->partitionCount());
    }

    public function testMaintenanceBudgetIsOneToOneHundredTwentyEight(): void
    {
        $backend = new MemoryRateLimitBackend(new AnchoredClock(0));
        foreach ([0, 129, -1] as $bad) {
            try {
                $backend->maintain($bad);
                self::fail('A batch of ' . $bad . ' must be rejected.');
            } catch (InvalidConfigurationException $e) {
                self::assertStringContainsString('1..128', $e->getMessage());
            }
        }
        self::assertSame(0, $backend->maintain(1));
        self::assertSame(0, $backend->maintain(128));
    }

    public function testMaintenanceResumesAfterTheRecordsItHasAlreadyExamined(): void
    {
        $clock = new AnchoredClock(500 * self::SECOND);
        $backend = new MemoryRateLimitBackend($clock);
        // a and b become removable after 120 s of idleness; c and d are touched late and are not.
        $this->idleBucket($backend, 'a');
        $this->idleBucket($backend, 'b');
        $clock->now += 100 * self::SECOND;
        $c = $this->idleBucket($backend, 'c');
        $d = $this->idleBucket($backend, 'd');
        $clock->now += 21 * self::SECOND;

        // A full batch of two examines a and b and removes both; the cursor must then point at c, not past it.
        self::assertSame(2, $backend->maintain(2));
        self::assertSame(2, $backend->partitionCount());
        $clock->now += 100 * self::SECOND;
        self::assertSame(2, $backend->maintain(2), 'c and d are examined next, not skipped');
        self::assertSame(0, $backend->partitionCount());
        self::assertSame(AdmissionStatus::Accepted, $this->consume($backend, $c));
        self::assertSame(AdmissionStatus::Accepted, $this->consume($backend, $d));
    }
}
