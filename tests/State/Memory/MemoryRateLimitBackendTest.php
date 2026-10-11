<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\State\Memory;

use Enaxon\Reqtide\Admission\AdmissionDecision;
use Enaxon\Reqtide\Admission\AdmissionStatus;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Exception\StateCapacityExceededException;
use Enaxon\Reqtide\Policy\FixedWindowAnchor;
use Enaxon\Reqtide\Policy\FixedWindowOptions;
use Enaxon\Reqtide\Policy\RateAlgorithmOptions;
use Enaxon\Reqtide\Policy\RateLimitPolicy;
use Enaxon\Reqtide\Policy\SlidingCounterOptions;
use Enaxon\Reqtide\Policy\TokenBucketOptions;
use Enaxon\Reqtide\State\Memory\MemoryRateLimitBackend;
use Enaxon\Reqtide\State\Memory\RateAlgorithms;
use Enaxon\Reqtide\State\StateScope;
use Enaxon\Reqtide\Tests\Support\Fixtures;
use Enaxon\Reqtide\Value\ApplicationId;
use Enaxon\Reqtide\Value\CommandId;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\EnvironmentId;
use Enaxon\Reqtide\Value\PartitionIdentity;
use Enaxon\Reqtide\Value\PolicyId;
use Enaxon\Reqtide\Value\PolicyIdentity;
use Enaxon\Reqtide\Value\PositiveInt;
use Enaxon\Reqtide\Value\ProfileVersion;
use Enaxon\Reqtide\Value\SubjectId;
use PHPUnit\Framework\TestCase;

final class MemoryRateLimitBackendTest extends TestCase
{
    private const SECOND = 1_000_000_000;

    private ?AnchoredClock $fakeClock = null;

    private ?MemoryRateLimitBackend $memoryBackend = null;

    private int $commands = 0;

    private function clock(): AnchoredClock
    {
        return $this->fakeClock ??= new AnchoredClock(1_000);
    }

    private function backend(): MemoryRateLimitBackend
    {
        return $this->memoryBackend ??= new MemoryRateLimitBackend($this->clock());
    }

    public function testCapabilities(): void
    {
        $capabilities = $this->backend()->capabilities();
        self::assertSame(StateScope::ProcessLocal, $capabilities->scope);
        self::assertNull($capabilities->ioTimeout);
        self::assertSame(1, $capabilities->clockResolution->nanoseconds);
    }

    public function testRejectsInvalidPartitionBound(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        new MemoryRateLimitBackend($this->clock(), 0);
    }

    // ---- token bucket ---------------------------------------------------------------------

    public function testExtremeIntervalUsesExactLimbPathAgainstOracle(): void
    {
        if (!extension_loaded('bcmath')) {
            self::markTestSkipped('bcmath oracle required.');
        }
        $capacity = 9_007_199_254;
        $interval = PHP_INT_MAX;
        $policy = $this->bucket($capacity, $capacity, $interval);
        $this->clock()->now = 0;
        self::assertSame(AdmissionStatus::Accepted, $this->consume($policy, $capacity)->status);

        foreach ([1, 12_345_678_901_234, 4_611_686_018_427_387_904, PHP_INT_MAX] as $elapsed) {
            $this->clock()->now = $elapsed;
            $scaled = bcdiv(bcmul((string) $elapsed, bcmul((string) $capacity, '1000000')), (string) $interval, 0);
            $expected = min($capacity, (int) bcdiv($scaled, '1000000', 0));
            $inspection = $this->backend()->inspect($policy, new PositiveInt(1));
            self::assertSame($expected, $inspection->available->value, "elapsed $elapsed");
            self::assertSame($expected >= 1, $inspection->requestedCostWouldBeAccepted);
        }
    }

    // ---- fixed window ---------------------------------------------------------------------

    public function testFixedWindowImpossibleCost(): void
    {
        $policy = $this->fixed(2, 10 * self::SECOND, FixedWindowAnchor::Aligned);
        $decision = $this->consume($policy, 3);
        self::assertSame(AdmissionStatus::Impossible, $decision->status);
        self::assertSame(0, $this->backend()->partitionCount());
    }

    // ---- approximate sliding counter ------------------------------------------------------

    // ---- Cross-cutting -------------------------------------------------------------------

    public function testInspectionIsMutationFreeAndAllocatesNothing(): void
    {
        foreach ([$this->bucket(5, 5, 10 * self::SECOND), $this->fixed(5, 10 * self::SECOND, FixedWindowAnchor::Aligned), $this->sliding(5, 10 * self::SECOND)] as $policy) {
            for ($i = 0; $i < 3; ++$i) {
                $inspection = $this->backend()->inspect($policy, new PositiveInt(5));
                self::assertSame(5, $inspection->available->value);
                self::assertTrue($inspection->requestedCostWouldBeAccepted);
                self::assertNull($inspection->retryAfter);
            }
            self::assertSame(0, $this->backend()->partitionCount());
            self::assertSame(AdmissionStatus::Accepted, $this->consume($policy, 5)->status);
            $before = $this->backend()->inspect($policy, new PositiveInt(1));
            self::assertSame(0, $before->available->value);
            $again = $this->backend()->inspect($policy, new PositiveInt(1));
            self::assertEquals($before, $again);
            $this->backend()->maintain();
            $this->fakeClock = null;
            $this->memoryBackend = null;
        }
    }

    public function testNewPartitionsAreRejectedAtCapacityWithoutEvictingActiveProtection(): void
    {
        $backend = new MemoryRateLimitBackend($this->clock(), 2);
        $a = $this->bucket(1, 1, 60 * self::SECOND, 'a');
        $b = $this->bucket(1, 1, 60 * self::SECOND, 'b');
        $c = $this->bucket(1, 1, 60 * self::SECOND, 'c');
        self::assertSame(AdmissionStatus::Accepted, $backend->consume($a, new PositiveInt(1), $this->command())->status);
        self::assertSame(AdmissionStatus::Accepted, $backend->consume($b, new PositiveInt(1), $this->command())->status);

        try {
            $backend->consume($c, new PositiveInt(1), $this->command());
            self::fail('Expected capacity failure.');
        } catch (StateCapacityExceededException) {
            self::assertSame(2, $backend->partitionCount());
        }

        // Existing partitions keep their (exhausted) protection; they are not reset by pressure.
        self::assertSame(AdmissionStatus::Rejected, $backend->consume($a, new PositiveInt(1), $this->command())->status);
        // Inspecting an unallocated partition is allowed and allocates nothing.
        self::assertTrue($backend->inspect($c, new PositiveInt(1))->requestedCostWouldBeAccepted);
        self::assertSame(2, $backend->partitionCount());
    }

    public function testMaintenanceRemovesOnlyFreshEquivalentRecordsAfterRetention(): void
    {
        $full = $this->bucket(1, 1, 10 * self::SECOND, 'full');
        $partial = $this->bucket(100, 1, 1_000 * self::SECOND, 'partial');
        $window = $this->fixed(1, 10 * self::SECOND, FixedWindowAnchor::Aligned, 'window');
        $sliding = $this->sliding(1, 10 * self::SECOND, 'sliding');
        $this->clock()->now = 0;
        $this->consume($full, 1);
        $this->consume($partial, 100);
        $this->consume($window, 1);
        $this->consume($sliding, 1);
        self::assertSame(4, $this->backend()->partitionCount());

        // Before the 120 s retention nothing is removed, even though the small bucket is already full.
        $this->clock()->now = 119 * self::SECOND;
        self::assertSame(0, $this->backend()->maintain());

        // After retention: full bucket, ended window and cleared sliding counter go; the partial bucket stays.
        $this->clock()->now = 121 * self::SECOND;
        self::assertSame(3, $this->backend()->maintain());
        self::assertSame(1, $this->backend()->partitionCount());
        self::assertSame(AdmissionStatus::Rejected, $this->consume($partial, 100)->status);
    }

    public function testMaintenanceIsBoundedAndMakesProgressAcrossCalls(): void
    {
        $backend = new MemoryRateLimitBackend($this->clock(), 1_000);
        $this->clock()->now = 0;
        for ($i = 0; $i < 300; ++$i) {
            $backend->consume($this->fixed(1, self::SECOND, FixedWindowAnchor::Aligned, 's' . $i), new PositiveInt(1), $this->command());
        }
        $this->clock()->now = 500 * self::SECOND;

        self::assertSame(128, $backend->maintain());
        self::assertSame(172, $backend->partitionCount());
        self::assertSame(128, $backend->maintain(128));
        self::assertSame(44, $backend->maintain());
        self::assertSame(0, $backend->partitionCount());
        self::assertSame(0, $backend->maintain());
    }

    public function testMaintenanceBatchBoundIsValidated(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->backend()->maintain(129);
    }

    public function testSkippedRecordsDoNotStarveLaterRecordsAcrossCalls(): void
    {
        $backend = new MemoryRateLimitBackend($this->clock(), 1_000);
        $this->clock()->now = 0;
        // 128 active (never fresh) buckets followed by 3 that become fresh.
        for ($i = 0; $i < 128; ++$i) {
            $backend->consume($this->bucket(100, 1, 1_000_000 * self::SECOND, 'keep' . $i), new PositiveInt(100), $this->command());
        }
        for ($i = 0; $i < 3; ++$i) {
            $backend->consume($this->fixed(1, self::SECOND, FixedWindowAnchor::Aligned, 'drop' . $i), new PositiveInt(1), $this->command());
        }
        $this->clock()->now = 500 * self::SECOND;
        self::assertSame(0, $backend->maintain());
        self::assertSame(3, $backend->maintain());
        self::assertSame(128, $backend->partitionCount());
    }

    // ---- property: a returned wait is exact and never early --------------------------------------------

    // ---- ceiling maintenance ------------------------------------------------------------------------------

    public function testIdleFreshEquivalentPartitionsAreReclaimedAtTheCeilingBeforeRejecting(): void
    {
        $backend = new MemoryRateLimitBackend($this->clock(), 2);
        $a = $this->bucket(1, 1, 60 * self::SECOND, 'a');
        $b = $this->bucket(1, 1, 60 * self::SECOND, 'b');
        $c = $this->bucket(1, 1, 60 * self::SECOND, 'c');
        self::assertSame(AdmissionStatus::Accepted, $backend->consume($a, new PositiveInt(1), $this->command())->status);
        self::assertSame(AdmissionStatus::Accepted, $backend->consume($b, new PositiveInt(1), $this->command())->status);

        // After an hour both buckets are full again (fresh-equivalent) and past the 120 s retention.
        $this->clock()->now += 3_600 * self::SECOND;
        self::assertSame(AdmissionStatus::Accepted, $backend->consume($c, new PositiveInt(1), $this->command())->status);
        self::assertSame(1, $backend->partitionCount());
    }

    public function testActiveProtectiveStateIsNeverEvictedAtTheCeiling(): void
    {
        $backend = new MemoryRateLimitBackend($this->clock(), 2);
        $a = $this->bucket(1, 1, 100_000 * self::SECOND, 'a');
        $b = $this->bucket(1, 1, 100_000 * self::SECOND, 'b');
        $c = $this->bucket(1, 1, 100_000 * self::SECOND, 'c');
        $backend->consume($a, new PositiveInt(1), $this->command());
        $backend->consume($b, new PositiveInt(1), $this->command());

        // Idle for an hour, but still drained (not fresh-equivalent): protection must stay.
        $this->clock()->now += 3_600 * self::SECOND;
        try {
            $backend->consume($c, new PositiveInt(1), $this->command());
            self::fail('Expected capacity failure.');
        } catch (StateCapacityExceededException) {
            self::assertSame(2, $backend->partitionCount());
        }
        self::assertSame(AdmissionStatus::Rejected, $backend->consume($a, new PositiveInt(1), $this->command())->status);
    }

    // ---- unrepresentable waits -----------------------------------------------------------------------------

    public function testUnrepresentableWaitIsRejectedWithoutWaitAndWithDistinctReason(): void
    {
        // One token per PHP_INT_MAX ns: two missing tokens need 2 * PHP_INT_MAX ns, which cannot be represented.
        $policy = $this->bucket(2, 1, PHP_INT_MAX);
        self::assertSame(AdmissionStatus::Accepted, $this->consume($policy, 2)->status);

        $rejected = $this->consume($policy, 2);
        self::assertSame(AdmissionStatus::Rejected, $rejected->status);
        self::assertSame('rate.wait_unrepresentable', $rejected->reasonCode);
        self::assertNull($rejected->retryAfter, 'a wait is never truncated or wrapped');
        self::assertSame(0, $rejected->remaining?->value);

        // A representable wait keeps the ordinary reason.
        $ordinary = $this->bucket(1, 1, 60 * self::SECOND, 'ordinary');
        $this->consume($ordinary, 1);
        $one = $this->consume($ordinary, 1);
        self::assertSame('rate.exhausted', $one->reasonCode);
        self::assertSame(60 * self::SECOND, $one->retryAfter?->nanoseconds);
    }

    // ---- epoch-based alignment (B-01) ------------------------------------------------------------------------

    /** A clock whose wall time sits 3 s past a multiple of 10 s while the monotonic origin is 0. */
    private function skewedClock(): AnchoredClock
    {
        $clock = new AnchoredClock(0);
        $clock->wallEpochNanoseconds = AnchoredClock::DEFAULT_EPOCH_BASE + 3 * self::SECOND;

        return $clock;
    }

    public function testAlignedFixedWindowFollowsTheEpochAnchorCapturedAtConstruction(): void
    {
        $clock = $this->skewedClock();
        $backend = new MemoryRateLimitBackend($clock);
        $policy = $this->fixed(1, 10 * self::SECOND, FixedWindowAnchor::Aligned);

        // Epoch boundaries are at monotonic -3 s, 7 s, 17 s ...
        self::assertSame(AdmissionStatus::Accepted, $backend->consume($policy, new PositiveInt(1), $this->command())->status);
        $rejected = $backend->consume($policy, new PositiveInt(1), $this->command());
        self::assertSame(AdmissionStatus::Rejected, $rejected->status);
        self::assertSame(7 * self::SECOND, $rejected->retryAfter?->nanoseconds, 'the window ends at the next epoch boundary');

        $clock->now = 7 * self::SECOND - 1;
        self::assertSame(AdmissionStatus::Rejected, $backend->consume($policy, new PositiveInt(1), $this->command())->status);
        $clock->now = 7 * self::SECOND;
        self::assertSame(AdmissionStatus::Accepted, $backend->consume($policy, new PositiveInt(1), $this->command())->status);
    }

    public function testFirstHitFixedWindowIgnoresTheEpochAnchor(): void
    {
        $clock = $this->skewedClock();
        $backend = new MemoryRateLimitBackend($clock);
        $policy = $this->fixed(1, 10 * self::SECOND, FixedWindowAnchor::FirstHit);

        $backend->consume($policy, new PositiveInt(1), $this->command());
        $rejected = $backend->consume($policy, new PositiveInt(1), $this->command());
        self::assertSame(10 * self::SECOND, $rejected->retryAfter?->nanoseconds);
    }

    public function testSlidingCounterWindowsFollowTheEpochAnchor(): void
    {
        $clock = $this->skewedClock();
        $backend = new MemoryRateLimitBackend($clock);
        $policy = $this->sliding(10, 10 * self::SECOND);

        // The current window started at monotonic -3 s; at 6 s it is 9 s old, so it ends in exactly 1 s.
        $clock->now = 6 * self::SECOND;
        self::assertSame(AdmissionStatus::Accepted, $backend->consume($policy, new PositiveInt(10), $this->command())->status);
        $rejected = $backend->consume($policy, new PositiveInt(1), $this->command());
        self::assertSame(AdmissionStatus::Rejected, $rejected->status);
        // Next window begins at 7 s; one more token needs previous weight to drop to 9, i.e. 1 s into it => 8 s.
        self::assertSame(2 * self::SECOND, $rejected->retryAfter?->nanoseconds);

        $clock->now = 8 * self::SECOND - 1;
        self::assertFalse($backend->inspect($policy, new PositiveInt(1))->requestedCostWouldBeAccepted);
        $clock->now = 8 * self::SECOND;
        self::assertTrue($backend->inspect($policy, new PositiveInt(1))->requestedCostWouldBeAccepted);
    }

    public function testAnchorIsCapturedOnceAndLaterWallClockStepsDoNotShiftWindows(): void
    {
        $clock = $this->skewedClock();
        $backend = new MemoryRateLimitBackend($clock);
        $policy = $this->fixed(1, 10 * self::SECOND, FixedWindowAnchor::Aligned);

        // A wall-clock step after construction must not move the boundary (admission safety and length are monotonic).
        $clock->wallEpochNanoseconds = AnchoredClock::DEFAULT_EPOCH_BASE + 9 * self::SECOND;
        $backend->consume($policy, new PositiveInt(1), $this->command());
        $rejected = $backend->consume($policy, new PositiveInt(1), $this->command());
        self::assertSame(7 * self::SECOND, $rejected->retryAfter?->nanoseconds);
    }

    public function testAlignmentIsOverflowFreeForExtremeWindowsAndReadings(): void
    {
        self::assertSame(0, RateAlgorithms::alignedStart(0, PHP_INT_MAX, 0));
        $start = RateAlgorithms::alignedStart(PHP_INT_MAX - 1, PHP_INT_MAX, PHP_INT_MAX - 1);
        self::assertSame(1, $start, 'offset (2^63 - 2) + (2^63 - 2) mod (2^63 - 1) = 2^63 - 2, computed without overflow');
        self::assertSame(-3, RateAlgorithms::alignedStart(0, 10, 3));
        self::assertSame(7, RateAlgorithms::alignedStart(7, 10, 3));
        self::assertSame(3, RateAlgorithms::alignedStart(7, 10, -3));
    }

    // ---- helpers -----------------------------------------------------------------------------------------

    /** @phpstan-impure */
    private function consume(RateLimitPolicy $policy, int $cost): AdmissionDecision
    {
        return $this->backend()->consume($policy, new PositiveInt($cost), $this->command());
    }

    private function command(): CommandId
    {
        return CommandId::fromLowerHex(Fixtures::hex(++$this->commands));
    }

    private function bucket(int $capacity, int $refill, int $intervalNanoseconds, string $subject = 'subject'): RateLimitPolicy
    {
        return $this->policy(
            new TokenBucketOptions(new PositiveInt($capacity), new PositiveInt($refill), new Duration($intervalNanoseconds)),
            $subject,
        );
    }

    private function fixed(int $capacity, int $windowNanoseconds, FixedWindowAnchor $anchor, string $subject = 'subject'): RateLimitPolicy
    {
        return $this->policy(
            new FixedWindowOptions(new PositiveInt($capacity), new Duration($windowNanoseconds), $anchor),
            $subject,
        );
    }

    private function sliding(int $capacity, int $windowNanoseconds, string $subject = 'subject'): RateLimitPolicy
    {
        return $this->policy(new SlidingCounterOptions(new PositiveInt($capacity), new Duration($windowNanoseconds)), $subject);
    }

    private function policy(RateAlgorithmOptions $options, string $subject): RateLimitPolicy
    {
        return new RateLimitPolicy(
            new PolicyIdentity(
                new PartitionIdentity(new ApplicationId('app'), new EnvironmentId('test'), null, new SubjectId($subject)),
                new ProfileVersion('v1'),
                new PolicyId('policy'),
            ),
            $options,
        );
    }
}
