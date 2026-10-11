<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Contract;

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
use Enaxon\Reqtide\State\RateLimitBackend;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\PositiveInt;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour every RateLimitBackend must show for the three algorithms: exact boundaries, conservative waits, no hidden
 * credit, clock regression, impossible costs, mutation-free inspection, identity-bound settings and ceilings.
 * Aligned windows start on the epoch axis of the harness (zero is a day boundary); boundary expectations use the
 * harness step.
 *
 * Intentionally backend-specific and therefore not here:
 *  - the wall-clock anchor a process-local backend captures at construction (a shared backend uses the server epoch);
 *  - maintenance budgets and counts, partition counters, constructor argument checks (process-local);
 *  - extreme intervals that only the nanosecond domain can represent (the bcmath oracle test, an unrepresentable wait,
 *    overflow-free alignment arithmetic);
 *  - script, registry and encoding behaviour (shared).
 */
abstract class RateLimitBackendContract extends TestCase
{
    protected const SECOND = 1_000_000_000;

    private const START = 1_000;

    private ?RateLimitHarness $harness = null;

    private ?ContractIdentities $identities = null;

    private ?RateLimitBackend $defaultBackend = null;

    abstract protected function createHarness(): RateLimitHarness;

    final protected function harness(): RateLimitHarness
    {
        if ($this->harness === null) {
            $this->harness = $this->createHarness();
            $this->harness->time()->moveTo(self::START);
        }

        return $this->harness;
    }

    final protected function time(): ControllableTime
    {
        return $this->harness()->time();
    }

    final protected function ids(): ContractIdentities
    {
        return $this->identities ??= new ContractIdentities();
    }

    final protected function backend(): RateLimitBackend
    {
        return $this->defaultBackend ??= $this->harness()->rateLimit();
    }

    public function testCapabilitiesMatchTheHarnessDeclaration(): void
    {
        $capabilities = $this->backend()->capabilities();
        self::assertSame($this->harness()->expectedScope(), $capabilities->scope);
        self::assertSame($this->harness()->expectsFiniteIoTimeout(), $capabilities->ioTimeout !== null);
        self::assertLessThanOrEqual($this->time()->step(), $capabilities->clockResolution->nanoseconds);
    }

    // ---- variants of process-local tests that read internal counters: same scenario, public results only ------------

    public function testFixedWindowImpossibleCostAllocatesNothingObservable(): void
    {
        $policy = $this->fixed(2, 10 * self::SECOND, FixedWindowAnchor::Aligned);
        $decision = $this->consume($policy, 3);
        self::assertSame(AdmissionStatus::Impossible, $decision->status);
        self::assertSame(AdmissionStatus::Accepted, $this->consume($policy, 2)->status, 'the impossible attempt left a full window');
    }

    public function testInspectionIsMutationFree(): void
    {
        foreach ([$this->bucket(5, 5, 10 * self::SECOND), $this->fixed(5, 10 * self::SECOND, FixedWindowAnchor::Aligned), $this->sliding(5, 10 * self::SECOND)] as $policy) {
            $backend = $this->harness()->rateLimit();
            for ($i = 0; $i < 3; ++$i) {
                $inspection = $backend->inspect($policy, new PositiveInt(5));
                self::assertSame(5, $inspection->available->value);
                self::assertTrue($inspection->requestedCostWouldBeAccepted);
                self::assertNull($inspection->retryAfter);
            }
            self::assertSame(AdmissionStatus::Accepted, $backend->consume($policy, new PositiveInt(5), $this->command())->status);
            $before = $backend->inspect($policy, new PositiveInt(1));
            self::assertSame(0, $before->available->value);
            $again = $backend->inspect($policy, new PositiveInt(1));
            self::assertEquals($before, $again);
        }
    }

    public function testNewPartitionsAreRejectedAtCapacityWithoutEvictingActiveProtection(): void
    {
        $backend = $this->harness()->rateLimit(2);
        $a = $this->bucket(1, 1, 60 * self::SECOND, 'a');
        $b = $this->bucket(1, 1, 60 * self::SECOND, 'b');
        $c = $this->bucket(1, 1, 60 * self::SECOND, 'c');
        self::assertSame(AdmissionStatus::Accepted, $backend->consume($a, new PositiveInt(1), $this->command())->status);
        self::assertSame(AdmissionStatus::Accepted, $backend->consume($b, new PositiveInt(1), $this->command())->status);

        try {
            $backend->consume($c, new PositiveInt(1), $this->command());
            self::fail('Expected capacity failure.');
        } catch (StateCapacityExceededException) {
        }

        // Existing partitions keep their (exhausted) protection; they are not reset by pressure.
        self::assertSame(AdmissionStatus::Rejected, $backend->consume($a, new PositiveInt(1), $this->command())->status);
        // Inspecting an unallocated partition is allowed and allocates nothing: the ceiling still rejects it afterwards.
        self::assertTrue($backend->inspect($c, new PositiveInt(1))->requestedCostWouldBeAccepted);
        $this->expectException(StateCapacityExceededException::class);
        $backend->consume($c, new PositiveInt(1), $this->command());
    }

    public function testIdleFreshEquivalentPartitionsAreReclaimedAtTheCeilingBeforeRejecting(): void
    {
        $backend = $this->harness()->rateLimit(2);
        $a = $this->bucket(1, 1, 60 * self::SECOND, 'a');
        $b = $this->bucket(1, 1, 60 * self::SECOND, 'b');
        $c = $this->bucket(1, 1, 60 * self::SECOND, 'c');
        self::assertSame(AdmissionStatus::Accepted, $backend->consume($a, new PositiveInt(1), $this->command())->status);
        self::assertSame(AdmissionStatus::Accepted, $backend->consume($b, new PositiveInt(1), $this->command())->status);

        // After an hour both buckets are full again (fresh-equivalent) and past the 120 s retention.
        $this->time()->advance(3_600 * self::SECOND);
        self::assertSame(AdmissionStatus::Accepted, $backend->consume($c, new PositiveInt(1), $this->command())->status);
    }

    public function testActiveProtectiveStateIsNeverEvictedAtTheCeiling(): void
    {
        $backend = $this->harness()->rateLimit(2);
        $a = $this->bucket(1, 1, 100_000 * self::SECOND, 'a');
        $b = $this->bucket(1, 1, 100_000 * self::SECOND, 'b');
        $c = $this->bucket(1, 1, 100_000 * self::SECOND, 'c');
        $backend->consume($a, new PositiveInt(1), $this->command());
        $backend->consume($b, new PositiveInt(1), $this->command());

        // Idle for an hour, but still drained (not fresh-equivalent): protection must stay.
        $this->time()->advance(3_600 * self::SECOND);
        try {
            $backend->consume($c, new PositiveInt(1), $this->command());
            self::fail('Expected capacity failure.');
        } catch (StateCapacityExceededException) {
        }
        self::assertSame(AdmissionStatus::Rejected, $backend->consume($a, new PositiveInt(1), $this->command())->status);
    }

    // ---- scenarios moved from the process-local test ------------------------------------------------------------------

    public function testHundredTokensPerMinuteBoundaryAtSixHundredMillisecondsAndOneMicrosecondLess(): void
    {
        $policy = $this->bucket(100, 100, 60 * self::SECOND);
        $start = $this->time()->now();
        self::assertSame(AdmissionStatus::Accepted, $this->consume($policy, 100)->status);

        // 599_999 us: 999_998 scaled units plus a remainder; a one-token consume must be rejected.
        $this->time()->moveTo($start + 599_999_000);
        $rejected = $this->consume($policy, 1);
        self::assertSame(AdmissionStatus::Rejected, $rejected->status);
        self::assertSame(0, $rejected->remaining?->value);
        // Exactly 1 us (1_000 ns) is still missing, rounded upward, never early.
        self::assertSame(1_000, $rejected->retryAfter?->nanoseconds);

        $this->time()->moveTo($start + 600_000_000 - $this->time()->step());
        self::assertSame(AdmissionStatus::Rejected, $this->consume($policy, 1)->status);

        // 600_000 us: exactly one token.
        $this->time()->moveTo($start + 600_000_000);
        $accepted = $this->consume($policy, 1);
        self::assertSame(AdmissionStatus::Accepted, $accepted->status);
        self::assertSame(0, $accepted->remaining?->value);
        self::assertSame(AdmissionStatus::Rejected, $this->consume($policy, 1)->status);
    }

    public function testFullBucketGivesNoHiddenCredit(): void
    {
        $policy = $this->bucket(100, 100, 60 * self::SECOND);
        self::assertSame(AdmissionStatus::Accepted, $this->consume($policy, 1)->status);

        // A long idle period while full must not accumulate refill or remainder.
        $this->time()->advance(3_600 * self::SECOND + 123_456_789);
        $first = $this->consume($policy, 1);
        self::assertSame(99, $first->remaining?->value);
        $second = $this->consume($policy, 100);
        self::assertSame(AdmissionStatus::Rejected, $second->status);
        self::assertSame(99, $second->remaining?->value);
        // One whole token missing: exactly 600_000 us.
        self::assertSame(600_000_000, $second->retryAfter?->nanoseconds);
    }

    public function testRemainderIsPreservedAcrossRejectedConsumes(): void
    {
        // 1 token per 3 s: each second yields 333_333 scaled units with a nonzero remainder.
        $policy = $this->bucket(10, 1, 3 * self::SECOND);
        $start = $this->time()->now();
        self::assertSame(AdmissionStatus::Accepted, $this->consume($policy, 10)->status);

        $this->time()->moveTo($start + self::SECOND);
        $first = $this->consume($policy, 1);
        self::assertSame(AdmissionStatus::Rejected, $first->status);
        self::assertSame(2 * self::SECOND, $first->retryAfter?->nanoseconds);
        $this->time()->moveTo($start + 2 * self::SECOND);
        self::assertSame(AdmissionStatus::Rejected, $this->consume($policy, 1)->status);
        // Truncating each step would lose one scaled unit (999_999 < 1_000_000).
        $this->time()->moveTo($start + 3 * self::SECOND - $this->time()->step());
        self::assertSame(AdmissionStatus::Rejected, $this->consume($policy, 1)->status);
        $this->time()->moveTo($start + 3 * self::SECOND);
        self::assertSame(AdmissionStatus::Accepted, $this->consume($policy, 1)->status);
    }

    public function testMaximumPortableCapacityAndImpossibleCost(): void
    {
        $policy = $this->bucket(9_007_199_254, 9_007_199_254, 60 * self::SECOND);
        $decision = $this->consume($policy, 9_007_199_254);
        self::assertSame(AdmissionStatus::Accepted, $decision->status);
        self::assertSame(0, $decision->remaining?->value);

        $impossible = $this->consume($policy, 9_007_199_255);
        self::assertSame(AdmissionStatus::Impossible, $impossible->status);
        self::assertNull($impossible->remaining);
        self::assertNull($impossible->retryAfter);

        $huge = $this->consume($policy, PHP_INT_MAX);
        self::assertSame(AdmissionStatus::Impossible, $huge->status);
    }

    public function testClockRegressionNeverRefills(): void
    {
        $policy = $this->bucket(1, 1, 10 * self::SECOND);
        $this->time()->moveTo(100 * self::SECOND);
        self::assertSame(AdmissionStatus::Accepted, $this->consume($policy, 1)->status);

        $this->time()->moveTo(50 * self::SECOND);
        $rejected = $this->consume($policy, 1);
        self::assertSame(AdmissionStatus::Rejected, $rejected->status);
        self::assertSame(10 * self::SECOND, $rejected->retryAfter?->nanoseconds);

        $this->time()->moveTo(105 * self::SECOND);
        $inspection = $this->backend()->inspect($policy, new PositiveInt(1));
        self::assertFalse($inspection->requestedCostWouldBeAccepted);
        self::assertSame(5 * self::SECOND, $inspection->retryAfter?->nanoseconds);
    }

    public function testAlignedFixedWindowBoundaries(): void
    {
        $policy = $this->fixed(2, 10 * self::SECOND, FixedWindowAnchor::Aligned);
        $this->time()->moveTo(13 * self::SECOND);
        self::assertSame(1, $this->consume($policy, 1)->remaining?->value);
        self::assertSame(0, $this->consume($policy, 1)->remaining?->value);

        $this->time()->moveTo(20 * self::SECOND - $this->time()->step());
        $rejected = $this->consume($policy, 1);
        self::assertSame(AdmissionStatus::Rejected, $rejected->status);
        self::assertSame($this->time()->step(), $rejected->retryAfter?->nanoseconds);

        // Exactly at the window end a new window starts.
        $this->time()->moveTo(20 * self::SECOND);
        self::assertSame(AdmissionStatus::Accepted, $this->consume($policy, 2)->status);

        // A gap of several windows starts from a fresh window.
        $this->time()->moveTo(95 * self::SECOND);
        self::assertSame(AdmissionStatus::Accepted, $this->consume($policy, 2)->status);
    }

    public function testFirstHitFixedWindowBoundaries(): void
    {
        $policy = $this->fixed(2, 10 * self::SECOND, FixedWindowAnchor::FirstHit);
        $this->time()->moveTo(3 * self::SECOND);
        self::assertSame(AdmissionStatus::Accepted, $this->consume($policy, 2)->status);

        $this->time()->moveTo(13 * self::SECOND - $this->time()->step());
        $rejected = $this->consume($policy, 1);
        self::assertSame(AdmissionStatus::Rejected, $rejected->status);
        self::assertSame($this->time()->step(), $rejected->retryAfter?->nanoseconds);

        // The next window is anchored at the first consumption after expiry.
        $this->time()->moveTo(17 * self::SECOND);
        self::assertSame(AdmissionStatus::Accepted, $this->consume($policy, 2)->status);
        $this->time()->moveTo(27 * self::SECOND - $this->time()->step());
        $second = $this->consume($policy, 1);
        self::assertSame(AdmissionStatus::Rejected, $second->status);
        self::assertSame($this->time()->step(), $second->retryAfter?->nanoseconds);
        $this->time()->moveTo(27 * self::SECOND);
        self::assertSame(AdmissionStatus::Accepted, $this->consume($policy, 1)->status);
    }

    public function testSlidingCounterEqualityAdmitsAndWaitIsExactAndConservative(): void
    {
        $window = 10 * self::SECOND;
        $policy = $this->sliding(10, $window);
        $this->time()->moveTo(0);
        self::assertSame(AdmissionStatus::Accepted, $this->consume($policy, 10)->status);

        // x = 5 s into the next window: previous weight is exactly 5 -> five more fit with equality.
        $this->time()->moveTo(15 * self::SECOND);
        $inspection = $this->backend()->inspect($policy, new PositiveInt(5));
        self::assertSame(5, $inspection->available->value);
        self::assertSame(AdmissionStatus::Accepted, $this->consume($policy, 5)->status);

        $rejected = $this->consume($policy, 1);
        self::assertSame(AdmissionStatus::Rejected, $rejected->status);
        // Smallest eligible tick: 6 s into the window, i.e. exactly one second later.
        self::assertSame(self::SECOND, $rejected->retryAfter?->nanoseconds);

        $this->time()->moveTo(16 * self::SECOND - $this->time()->step());
        self::assertFalse($this->backend()->inspect($policy, new PositiveInt(1))->requestedCostWouldBeAccepted);
        $this->time()->moveTo(16 * self::SECOND);
        self::assertSame(AdmissionStatus::Accepted, $this->consume($policy, 1)->status);
    }

    public function testSlidingCounterNextWindowWaitAndTwoWindowGap(): void
    {
        $window = 10 * self::SECOND;
        $policy = $this->sliding(10, $window);
        $this->time()->moveTo(0);
        self::assertSame(AdmissionStatus::Accepted, $this->consume($policy, 10)->status);

        $rejected = $this->consume($policy, 1);
        self::assertSame(AdmissionStatus::Rejected, $rejected->status);
        // Next window starts at 10 s; one more token needs the previous weight to fall to 9 -> x = 1 s.
        self::assertSame(11 * self::SECOND, $rejected->retryAfter?->nanoseconds);
        $this->time()->moveTo(11 * self::SECOND - $this->time()->step());
        self::assertFalse($this->backend()->inspect($policy, new PositiveInt(1))->requestedCostWouldBeAccepted);
        $this->time()->moveTo(11 * self::SECOND);
        self::assertTrue($this->backend()->inspect($policy, new PositiveInt(1))->requestedCostWouldBeAccepted);

        // After two elapsed windows the previous count is zero.
        $this->time()->moveTo(20 * self::SECOND);
        $gap = $this->backend()->inspect($policy, new PositiveInt(10));
        self::assertSame(10, $gap->available->value);
        self::assertTrue($gap->requestedCostWouldBeAccepted);
    }

    public function testSlidingCounterFullCostWaitsAcrossBothWindows(): void
    {
        $window = 10 * self::SECOND;
        $policy = $this->sliding(10, $window);
        $this->time()->moveTo(0);
        self::assertSame(AdmissionStatus::Accepted, $this->consume($policy, 10)->status);
        // Cost equal to capacity never fits while the previous window still weighs anything.
        $rejected = $this->consume($policy, 10);
        self::assertSame(20 * self::SECOND, $rejected->retryAfter?->nanoseconds);
    }

    public function testInspectionOfImpossibleCostHasNoWait(): void
    {
        $inspection = $this->backend()->inspect($this->bucket(5, 5, 10 * self::SECOND), new PositiveInt(6));
        self::assertFalse($inspection->requestedCostWouldBeAccepted);
        self::assertNull($inspection->retryAfter);
        self::assertSame(5, $inspection->available->value);
    }

    public function testIdentityBoundSettingsRejectChangeBeforeMutation(): void
    {
        $original = $this->bucket(3, 1, 60 * self::SECOND);
        self::assertSame(2, $this->consume($original, 1)->remaining?->value);

        foreach ([
            $this->bucket(4, 1, 60 * self::SECOND),
            $this->bucket(3, 2, 60 * self::SECOND),
            $this->bucket(3, 1, 61 * self::SECOND),
            $this->fixed(3, 60 * self::SECOND, FixedWindowAnchor::Aligned),
        ] as $changed) {
            try {
                $this->consume($changed, 1);
                self::fail('Expected configuration failure.');
            } catch (InvalidConfigurationException) {
                // expected
            }
            try {
                $this->backend()->inspect($changed, new PositiveInt(1));
                self::fail('Expected configuration failure.');
            } catch (InvalidConfigurationException) {
                // expected
            }
        }
        // The original binding is intact and was not mutated by the rejected attempts.
        self::assertSame(1, $this->consume($original, 1)->remaining?->value);
    }

    public function testFixedWindowAnchorIsPartOfIdentityBinding(): void
    {
        $this->consume($this->fixed(3, 60 * self::SECOND, FixedWindowAnchor::Aligned), 1);
        $this->expectException(InvalidConfigurationException::class);
        $this->consume($this->fixed(3, 60 * self::SECOND, FixedWindowAnchor::FirstHit), 1);
    }

    public function testReturnedWaitIsNeverEarlyAndIsTight(): void
    {
        mt_srand(20_261_009);
        for ($case = 0; $case < 400; ++$case) {
            // A fresh authority per case, starting at the origin of the axis.
            $harness = $this->createHarness();
            $time = $harness->time();
            $time->moveTo(0);
            $unit = $time->step();
            $backend = $harness->rateLimit();
            $capacity = mt_rand(1, 12);
            $interval = mt_rand(1, 5_000) * $unit;
            $policy = match ($case % 4) {
                0 => $this->bucket($capacity, mt_rand(1, 12), $interval),
                1 => $this->fixed($capacity, $interval, FixedWindowAnchor::Aligned),
                2 => $this->fixed($capacity, $interval, FixedWindowAnchor::FirstHit),
                default => $this->sliding($capacity, $interval),
            };

            for ($step = 0; $step < 12; ++$step) {
                $time->advance(mt_rand(0, 2 * intdiv($interval, $unit)) * $unit);
                $cost = new PositiveInt(mt_rand(1, $capacity));
                $decision = $backend->consume($policy, $cost, $this->command());
                if ($decision->status !== AdmissionStatus::Rejected) {
                    continue;
                }
                $wait = $decision->retryAfter?->nanoseconds;
                self::assertNotNull($wait);
                self::assertGreaterThan(0, $wait);

                $at = $time->now();
                $time->moveTo($at + $wait - $unit);
                self::assertFalse($backend->inspect($policy, $cost)->requestedCostWouldBeAccepted, "early admission case $case step $step");
                $time->moveTo($at + $wait);
                self::assertTrue($backend->inspect($policy, $cost)->requestedCostWouldBeAccepted, "loose wait case $case step $step");
                $time->moveTo($at);
            }
        }
    }

    // ---- helpers -----------------------------------------------------------------------------------------

    /** @phpstan-impure */
    private function consume(RateLimitPolicy $policy, int $cost): AdmissionDecision
    {
        return $this->backend()->consume($policy, new PositiveInt($cost), $this->command());
    }

    private function command(): \Enaxon\Reqtide\Value\CommandId
    {
        return $this->ids()->command();
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
        return new RateLimitPolicy(ContractIdentities::identity($subject), $options);
    }
}
