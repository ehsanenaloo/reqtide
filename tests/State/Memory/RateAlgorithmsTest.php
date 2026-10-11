<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\State\Memory;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Policy\FixedWindowAnchor;
use Enaxon\Reqtide\Policy\FixedWindowOptions;
use Enaxon\Reqtide\Policy\RateAlgorithmOptions;
use Enaxon\Reqtide\Policy\SlidingCounterOptions;
use Enaxon\Reqtide\Policy\TokenBucketOptions;
use Enaxon\Reqtide\State\Memory\RateAlgorithms;
use Enaxon\Reqtide\State\Memory\RateEvaluation;
use Enaxon\Reqtide\State\Memory\RateRecord;
use Enaxon\Reqtide\State\Memory\RateView;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\PositiveInt;
use PHPUnit\Framework\TestCase;

/**
 * The pure rate arithmetic asserted directly: exact projected views, accept/reject boundaries, retry-after values and
 * the canonical settings encoding. The sliding-counter wait is checked against a brute-force reference that walks the
 * future tick by tick.
 */
final class RateAlgorithmsTest extends TestCase
{
    private const SCALE = RateAlgorithms::TOKEN_SCALE;

    private static function bucket(int $capacity = 10, int $refill = 3, int $intervalNanoseconds = 2_000_000_000): TokenBucketOptions
    {
        return new TokenBucketOptions(new PositiveInt($capacity), new PositiveInt($refill), Duration::nanoseconds($intervalNanoseconds));
    }

    private static function fixed(int $capacity = 5, int $window = 100, FixedWindowAnchor $anchor = FixedWindowAnchor::Aligned): FixedWindowOptions
    {
        return new FixedWindowOptions(new PositiveInt($capacity), Duration::nanoseconds($window), $anchor);
    }

    private static function sliding(int $capacity = 10, int $window = 100): SlidingCounterOptions
    {
        return new SlidingCounterOptions(new PositiveInt($capacity), Duration::nanoseconds($window));
    }

    private static function record(RateAlgorithmOptions $options, int $lastTick, int $primary = 0, int $secondary = 0, int $windowStart = 0): RateRecord
    {
        return new RateRecord($options, RateAlgorithms::fingerprint($options), $lastTick, $primary, $secondary, $windowStart);
    }

    private static function unsupported(): RateAlgorithmOptions
    {
        return new class implements RateAlgorithmOptions {};
    }

    // ---- unsupported implementations are a typed configuration error everywhere -----------------------

    public function testEveryEntryPointRejectsAnUnknownAlgorithmImplementationWithAConfigurationError(): void
    {
        $unknown = self::unsupported();
        $view = new RateView(0, 0, 0);
        $record = new RateRecord(self::bucket(), 'x', 0);
        $calls = [
            'capacity' => static fn() => RateAlgorithms::capacity($unknown),
            'fingerprint' => static fn() => RateAlgorithms::fingerprint($unknown),
            'project' => static fn() => RateAlgorithms::project($unknown, null, 0),
            'available' => static fn() => RateAlgorithms::available($unknown, $view, 0),
            'evaluate' => static fn() => RateAlgorithms::evaluate($unknown, $view, 0, 1),
            'isFreshEquivalent' => static fn() => RateAlgorithms::isFreshEquivalent($unknown, $record, 0),
        ];
        foreach ($calls as $name => $call) {
            try {
                $call();
                self::fail($name . ' accepted an unknown algorithm implementation.');
            } catch (InvalidConfigurationException $e) {
                self::assertStringContainsString('Unsupported rate algorithm options', $e->getMessage(), $name);
            }
        }
    }

    public function testCapacityIsTheConfiguredWholeUnitCapacityOfEveryAlgorithm(): void
    {
        self::assertSame(10, RateAlgorithms::capacity(self::bucket(10)));
        self::assertSame(5, RateAlgorithms::capacity(self::fixed(5)));
        self::assertSame(7, RateAlgorithms::capacity(self::sliding(7)));
    }

    // ---- canonical settings encoding ------------------------------------------------------------------

    public function testTheCanonicalEncodingNamesEveryParameterInOrder(): void
    {
        self::assertSame('token_bucket:10:3:2000000000', RateAlgorithms::fingerprint(self::bucket(10, 3, 2_000_000_000)));
        self::assertSame('fixed_window:5:100:aligned', RateAlgorithms::fingerprint(self::fixed(5, 100, FixedWindowAnchor::Aligned)));
        self::assertSame('fixed_window:5:100:first_hit', RateAlgorithms::fingerprint(self::fixed(5, 100, FixedWindowAnchor::FirstHit)));
        self::assertSame('sliding_counter:7:250', RateAlgorithms::fingerprint(self::sliding(7, 250)));
    }

    public function testNoTwoDifferentSettingsShareAnEncoding(): void
    {
        $all = [
            self::bucket(1, 23, 5), self::bucket(12, 3, 5), self::bucket(1, 2, 35), self::bucket(12, 35, 1),
            self::fixed(1, 23, FixedWindowAnchor::Aligned), self::fixed(12, 3, FixedWindowAnchor::Aligned),
            self::fixed(1, 23, FixedWindowAnchor::FirstHit), self::sliding(1, 23), self::sliding(12, 3),
        ];
        $seen = [];
        foreach ($all as $options) {
            $encoding = RateAlgorithms::fingerprint($options);
            self::assertArrayNotHasKey($encoding, $seen, $encoding);
            $seen[$encoding] = true;
        }
    }

    // ---- token bucket ---------------------------------------------------------------------------------

    public function testABucketWithNoRecordStartsFullWithNoRemainder(): void
    {
        self::assertEquals(new RateView(10 * self::SCALE, 0, 0), RateAlgorithms::project(self::bucket(), null, 12_345));
    }

    public function testAFullBucketDropsAnyStoredRemainderEvenWithoutElapsedTime(): void
    {
        $bucket = self::bucket();
        $record = self::record($bucket, 100, 10 * self::SCALE, 5);

        self::assertEquals(new RateView(10 * self::SCALE, 0, 0), RateAlgorithms::project($bucket, $record, 100));
        self::assertEquals(new RateView(10 * self::SCALE, 0, 0), RateAlgorithms::project($bucket, $record, 999));
    }

    public function testNoElapsedTimeKeepsBalanceAndRemainderUntouched(): void
    {
        $bucket = self::bucket();
        $record = self::record($bucket, 100, 4 * self::SCALE, 777);

        self::assertEquals(new RateView(4 * self::SCALE, 777, 0), RateAlgorithms::project($bucket, $record, 100));
    }

    public function testRefillAddsWholeScaledUnitsAndCarriesTheRemainder(): void
    {
        $bucket = self::bucket(10, 3, 2_000_000_000);
        // 1 s elapsed at 3 tokens / 2 s: exactly 1.5 tokens, no remainder.
        self::assertEquals(
            new RateView(1_500_000, 0, 0),
            RateAlgorithms::project($bucket, self::record($bucket, 0), 1_000_000_000),
        );
        // 1 ns elapsed: 3_000_000 / 2_000_000_000 = 0 whole scaled units, remainder 3_000_000.
        self::assertEquals(new RateView(0, 3_000_000, 0), RateAlgorithms::project($bucket, self::record($bucket, 0), 1));
        // The stored remainder participates: 1_999_999_999 + 3_000_000 carries one scaled unit.
        self::assertEquals(
            new RateView(1, 2_999_999, 0),
            RateAlgorithms::project($bucket, self::record($bucket, 0, 0, 1_999_999_999), 1),
        );
    }

    public function testRefillIsClampedToCapacityOnlyWhenItWouldReachIt(): void
    {
        $bucket = self::bucket(10, 3, 2_000_000_000);
        // Deficit is exactly 3 scaled units one refill step away from full.
        $record = self::record($bucket, 0, 10 * self::SCALE - 3, 0);
        // 1_333 ns adds floor(1_333 * 3_000_000 / 2e9) = 1 with remainder 1_999_000_000.
        self::assertEquals(new RateView(10 * self::SCALE - 3 + 1, 1_999_000_000, 0), RateAlgorithms::project($bucket, $record, 1_333));
        // Enough time to refill past the deficit: exactly full and no remainder.
        self::assertEquals(new RateView(10 * self::SCALE, 0, 0), RateAlgorithms::project($bucket, $record, 10_000_000_000));
        // Reaching the capacity exactly is also full (quotient equal to the deficit counts as capped).
        self::assertEquals(new RateView(10 * self::SCALE - 1, 1_997_000_000, 0), RateAlgorithms::project($bucket, $record, 1_999));
        self::assertEquals(new RateView(10 * self::SCALE, 0, 0), RateAlgorithms::project($bucket, $record, 2_000));
    }

    public function testAvailableIsTheWholeTokenCountOfTheBalance(): void
    {
        $bucket = self::bucket();
        self::assertSame(2, RateAlgorithms::available($bucket, new RateView(2_999_999, 0, 0), 0));
        self::assertSame(3, RateAlgorithms::available($bucket, new RateView(3_000_000, 0, 0), 0));
        self::assertSame(0, RateAlgorithms::available($bucket, new RateView(999_999, 0, 0), 0));
    }

    public function testBucketAcceptsExactlyTheBalanceAndKeepsTheRemainder(): void
    {
        $bucket = self::bucket();
        $accepted = RateAlgorithms::evaluate($bucket, new RateView(3 * self::SCALE, 42, 0), 0, 3);
        self::assertEquals(new RateEvaluation(true, new RateView(0, 42, 0), 3, null), $accepted);

        $partial = RateAlgorithms::evaluate($bucket, new RateView(5 * self::SCALE + 10, 9, 0), 0, 2);
        self::assertEquals(new RateEvaluation(true, new RateView(3 * self::SCALE + 10, 9, 0), 5, null), $partial);
    }

    public function testBucketRejectionReportsTheExactCeilingWaitAndLeavesTheViewUntouched(): void
    {
        $bucket = self::bucket(10, 3, 2_000_000_000);
        $view = new RateView(2_500_000, 1_000, 0);
        // Need 500_000 more scaled units: ceil((500_000 * 2e9 - 1_000) / 3_000_000) = 333_333_334 ns.
        self::assertEquals(new RateEvaluation(false, $view, 2, 333_333_334), RateAlgorithms::evaluate($bucket, $view, 0, 3));
        // One scaled unit short of the cost: the wait is the time to earn exactly that unit.
        $nearly = new RateView(3 * self::SCALE - 1, 0, 0);
        self::assertEquals(new RateEvaluation(false, $nearly, 2, 667), RateAlgorithms::evaluate($bucket, $nearly, 0, 3));
    }

    public function testBucketWaitThatDoesNotFitAnIntegerIsNullNotRoundedDown(): void
    {
        // Refill so slow that earning one token takes more than PHP_INT_MAX nanoseconds.
        $slow = new TokenBucketOptions(new PositiveInt(9_007_199_254), new PositiveInt(1), Duration::nanoseconds(PHP_INT_MAX));
        $view = new RateView(0, 0, 0);

        self::assertEquals(new RateEvaluation(false, $view, 0, null), RateAlgorithms::evaluate($slow, $view, 0, 9_007_199_254));
    }

    // ---- fixed window ---------------------------------------------------------------------------------

    public function testAnAlignedWindowStartsOnTheEpochAxis(): void
    {
        $options = self::fixed(5, 100, FixedWindowAnchor::Aligned);

        self::assertEquals(new RateView(0, 0, 200), RateAlgorithms::project($options, null, 250), 'default anchor 0');
        self::assertEquals(new RateView(0, 0, 200), RateAlgorithms::project($options, null, 250, 0));
        self::assertEquals(new RateView(0, 0, 170), RateAlgorithms::project($options, null, 250, 30), 'epoch = tick + 30');
        self::assertEquals(new RateView(0, 0, 230), RateAlgorithms::project($options, null, 250, -30), 'epoch = tick - 30');
    }

    public function testAFirstHitWindowStartsAtTheEffectiveTick(): void
    {
        self::assertEquals(new RateView(0, 0, 123), RateAlgorithms::project(self::fixed(5, 100, FixedWindowAnchor::FirstHit), null, 123));
    }

    public function testAnAlignedRecordIsReusedOnlyInsideItsOwnWindow(): void
    {
        $options = self::fixed(5, 100, FixedWindowAnchor::Aligned);
        $record = self::record($options, 205, 3, 0, 200);

        self::assertEquals(new RateView(3, 0, 200), RateAlgorithms::project($options, $record, 299));
        self::assertEquals(new RateView(0, 0, 300), RateAlgorithms::project($options, $record, 300));
    }

    public function testAFirstHitRecordExpiresExactlyOneWindowAfterItsFirstHit(): void
    {
        $options = self::fixed(5, 100, FixedWindowAnchor::FirstHit);
        $record = self::record($options, 140, 2, 0, 140);

        self::assertEquals(new RateView(2, 0, 140), RateAlgorithms::project($options, $record, 239));
        self::assertEquals(new RateView(0, 0, 240), RateAlgorithms::project($options, $record, 240));
    }

    public function testFixedWindowAvailabilityAndAcceptanceBoundary(): void
    {
        $options = self::fixed(5, 100, FixedWindowAnchor::FirstHit);
        $view = new RateView(3, 0, 1_000);

        self::assertSame(2, RateAlgorithms::available($options, $view, 1_010));
        self::assertEquals(new RateEvaluation(true, new RateView(5, 0, 1_000), 2, null), RateAlgorithms::evaluate($options, $view, 1_010, 2));
        // One unit more than available is refused; the wait runs to the window end (start + window).
        self::assertEquals(new RateEvaluation(false, $view, 2, 100 - 30), RateAlgorithms::evaluate($options, $view, 1_030, 3));
        // At the last tick of the window the wait is one tick.
        self::assertEquals(new RateEvaluation(false, $view, 2, 1), RateAlgorithms::evaluate($options, $view, 1_099, 3));
    }

    // ---- sliding counter ------------------------------------------------------------------------------

    public function testSlidingProjectionRollsThePreviousWindow(): void
    {
        $options = self::sliding(10, 100);
        $record = self::record($options, 120, 4, 6, 100);

        self::assertEquals(new RateView(4, 6, 100), RateAlgorithms::project($options, $record, 199));
        self::assertEquals(new RateView(0, 4, 200), RateAlgorithms::project($options, $record, 200), 'one window later: current becomes previous');
        self::assertEquals(new RateView(0, 4, 299 - 99), RateAlgorithms::project($options, $record, 299));
        self::assertEquals(new RateView(0, 0, 300), RateAlgorithms::project($options, $record, 300), 'two windows later: nothing is carried');
        self::assertEquals(new RateView(0, 0, 0), RateAlgorithms::project($options, null, 40));
    }

    public function testSlidingAvailabilityWeightsThePreviousWindowByCeiling(): void
    {
        $options = self::sliding(10, 100);

        self::assertSame(5, RateAlgorithms::available($options, new RateView(2, 4, 0), 25), 'ceil(4 * 75 / 100) = 3');
        // The product is one more than a multiple of the window: the ceiling must round up, never down.
        self::assertSame(10 - 2 - 1, RateAlgorithms::available($options, new RateView(2, 1, 0), 99));
        // Exact multiple: no rounding.
        self::assertSame(10 - 2 - 2, RateAlgorithms::available($options, new RateView(2, 4, 0), 50));
        // Never negative.
        self::assertSame(0, RateAlgorithms::available($options, new RateView(9, 9, 0), 0));
        self::assertSame(0, RateAlgorithms::available($options, new RateView(10, 0, 0), 0));
    }

    public function testSlidingAcceptsExactlyAtTheCapacityAndRefusesOneUnitMore(): void
    {
        $options = self::sliding(10, 100);
        $view = new RateView(3, 4, 0);

        // (3 + 5) * 100 + 4 * 50 = 1000 = capacity * window: accepted at equality.
        self::assertEquals(new RateEvaluation(true, new RateView(8, 4, 0), 5, null), RateAlgorithms::evaluate($options, $view, 50, 5));
        $rejected = RateAlgorithms::evaluate($options, $view, 50, 6);
        self::assertFalse($rejected->accepted);
        self::assertEquals($view, $rejected->view);
        self::assertSame(5, $rejected->available);
    }

    /**
     * Brute-force reference for the sliding counter: walk future ticks until the same cost would be accepted by the
     * documented approximation, rolling the windows exactly as projectSliding() does.
     */
    private static function referenceWait(int $capacity, int $window, int $current, int $previous, int $x, int $cost): ?int
    {
        for ($d = 1; $d <= 3 * $window; ++$d) {
            $t = $x + $d;
            if ($t < $window) {
                [$c, $p, $at] = [$current, $previous, $t];
            } elseif ($t < 2 * $window) {
                [$c, $p, $at] = [0, $current, $t - $window];
            } else {
                [$c, $p, $at] = [0, 0, $t - 2 * $window];
            }
            if (($c + $cost) * $window + $p * ($window - $at) <= $capacity * $window) {
                return $d;
            }
        }

        return null;
    }

    public function testSlidingRetryAfterMatchesTheBruteForceReferenceOverAGrid(): void
    {
        $window = 20;
        $checked = 0;
        foreach ([1, 3, 10] as $capacity) {
            $options = self::sliding($capacity, $window);
            for ($current = 0; $current <= $capacity; ++$current) {
                for ($previous = 0; $previous <= $capacity; ++$previous) {
                    foreach ([0, 1, 9, 10, 18, 19] as $x) {
                        for ($cost = 1; $cost <= $capacity; ++$cost) {
                            $view = new RateView($current, $previous, 0);
                            $fits = ($current + $cost) * $window + $previous * ($window - $x) <= $capacity * $window;
                            $evaluation = RateAlgorithms::evaluate($options, $view, $x, $cost);
                            $label = "cap=$capacity cur=$current prev=$previous x=$x cost=$cost";
                            self::assertSame($fits, $evaluation->accepted, $label);
                            if (!$fits) {
                                self::assertSame(self::referenceWait($capacity, $window, $current, $previous, $x, $cost), $evaluation->retryAfterNanoseconds, $label);
                                self::assertEquals($view, $evaluation->view, $label);
                            }
                            ++$checked;
                        }
                    }
                }
            }
        }
        self::assertGreaterThan(1_000, $checked);
    }

    public function testSlidingWaitIsNullWhenItDoesNotFitAnInteger(): void
    {
        $huge = PHP_INT_MAX;
        // Neither the rest of this window nor the next admits one more unit: the answer is a whole window away.
        $options = self::sliding(1, $huge);
        self::assertNull(RateAlgorithms::evaluate($options, new RateView(1, 0, 0), 0, 1)->retryAfterNanoseconds);

        // Second search window: the earliest fitting tick of the next window is 2^62; untilNext + lo overflows at x = 0.
        $two = self::sliding(2, $huge);
        self::assertNull(RateAlgorithms::evaluate($two, new RateView(2, 0, 0), 0, 1)->retryAfterNanoseconds);
    }

    public function testSlidingWaitThatEndsExactlyAtTheLargestRepresentableValueIsKept(): void
    {
        // x = 2^62: untilNext = 2^62 - 1 and the earliest fitting tick of the next window is 2^62, so the sum is
        // exactly PHP_INT_MAX and must be reported, not nulled.
        $two = self::sliding(2, PHP_INT_MAX);
        self::assertSame(PHP_INT_MAX, RateAlgorithms::evaluate($two, new RateView(2, 0, 0), 2 ** 62, 1)->retryAfterNanoseconds);

        // Third search window: window = 2^62 and x = 1 give untilNext + window == PHP_INT_MAX exactly.
        $window = 2 ** 62;
        $one = self::sliding(1, $window);
        self::assertSame(PHP_INT_MAX, RateAlgorithms::evaluate($one, new RateView(1, 0, 0), 1, 1)->retryAfterNanoseconds);
    }

    // ---- maintenance equivalence ----------------------------------------------------------------------

    public function testABucketIsFreshEquivalentOnlyWhenFull(): void
    {
        $bucket = self::bucket(10, 3, 2_000_000_000);

        self::assertTrue(RateAlgorithms::isFreshEquivalent($bucket, self::record($bucket, 0, 10 * self::SCALE, 0), 5));
        self::assertFalse(RateAlgorithms::isFreshEquivalent($bucket, self::record($bucket, 0, 10 * self::SCALE - 1, 0), 0));
        // Time heals it.
        self::assertTrue(RateAlgorithms::isFreshEquivalent($bucket, self::record($bucket, 0, 10 * self::SCALE - 1, 0), 10_000_000_000));
    }

    public function testAFixedWindowIsFreshEquivalentWhenItsWindowHasEnded(): void
    {
        $options = self::fixed(5, 100, FixedWindowAnchor::Aligned);
        $record = self::record($options, 250, 3, 0, 200);

        self::assertFalse(RateAlgorithms::isFreshEquivalent($options, $record, 299));
        self::assertTrue(RateAlgorithms::isFreshEquivalent($options, $record, 300));
        self::assertTrue(RateAlgorithms::isFreshEquivalent($options, self::record($options, 250, 0, 0, 200), 250));
        // The default anchor is zero: ticks 200..299 form one aligned window, so 299 is still inside it.
        self::assertFalse(RateAlgorithms::isFreshEquivalent($options, $record, 299, 0));
        // With an anchor of 30 the window containing 250 starts at tick 170, so the record stored for 200 is stale.
        self::assertTrue(RateAlgorithms::isFreshEquivalent($options, $record, 250, 30));
    }

    public function testASlidingCounterIsFreshEquivalentOnlyWhenBothCountsAreZero(): void
    {
        $options = self::sliding(10, 100);

        self::assertTrue(RateAlgorithms::isFreshEquivalent($options, self::record($options, 120, 0, 0, 100), 150));
        self::assertFalse(RateAlgorithms::isFreshEquivalent($options, self::record($options, 120, 3, 0, 100), 150), 'current only');
        self::assertFalse(RateAlgorithms::isFreshEquivalent($options, self::record($options, 120, 0, 3, 100), 150), 'previous only');
        // The previous count is still alive one window later, then gone.
        self::assertFalse(RateAlgorithms::isFreshEquivalent($options, self::record($options, 120, 3, 0, 100), 250));
        self::assertTrue(RateAlgorithms::isFreshEquivalent($options, self::record($options, 120, 3, 0, 100), 300));
    }
}
