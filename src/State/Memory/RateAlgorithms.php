<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\State\Memory;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Internal\Arithmetic\BoundedArithmetic;
use Enaxon\Reqtide\Policy\FixedWindowAnchor;
use Enaxon\Reqtide\Policy\FixedWindowOptions;
use Enaxon\Reqtide\Policy\RateAlgorithmOptions;
use Enaxon\Reqtide\Policy\SlidingCounterOptions;
use Enaxon\Reqtide\Policy\TokenBucketOptions;

/**
 * Pure rate-algorithm arithmetic over integer monotonic nanosecond ticks. Nothing here reads a clock or touches storage.
 *
 * @internal
 */
final class RateAlgorithms
{
    /** Fixed-point scaled units per public token. */
    public const TOKEN_SCALE = 1_000_000;

    private function __construct() {}

    /** Whole-unit capacity of the algorithm. */
    public static function capacity(RateAlgorithmOptions $options): int
    {
        return match (true) {
            $options instanceof TokenBucketOptions, $options instanceof FixedWindowOptions,
            $options instanceof SlidingCounterOptions => $options->capacityUnits->value,
            default => throw self::unsupported(),
        };
    }

    /** Canonical settings encoding used for identity binding. */
    public static function fingerprint(RateAlgorithmOptions $options): string
    {
        return match (true) {
            $options instanceof TokenBucketOptions => 'token_bucket:' . $options->capacityUnits->value . ':'
                . $options->refillUnits->value . ':' . $options->refillInterval->nanoseconds,
            $options instanceof FixedWindowOptions => 'fixed_window:' . $options->capacityUnits->value . ':'
                . $options->window->nanoseconds . ':' . $options->anchor->value,
            $options instanceof SlidingCounterOptions => 'sliding_counter:' . $options->capacityUnits->value . ':'
                . $options->window->nanoseconds,
            default => throw self::unsupported(),
        };
    }

    /** State of the record (or fresh state when absent) at effective tick $eff >= the record's last tick. */
    public static function project(RateAlgorithmOptions $options, ?RateRecord $record, int $eff, int $epochAnchor = 0): RateView
    {
        return match (true) {
            $options instanceof TokenBucketOptions => self::projectBucket($options, $record, $eff),
            $options instanceof FixedWindowOptions => self::projectFixed($options, $record, $eff, $epochAnchor),
            $options instanceof SlidingCounterOptions => self::projectSliding($options, $record, $eff, $epochAnchor),
            default => throw self::unsupported(),
        };
    }

    /** Whole units a single consumption could take right now. */
    public static function available(RateAlgorithmOptions $options, RateView $view, int $eff): int
    {
        return match (true) {
            $options instanceof TokenBucketOptions => intdiv($view->primary, self::TOKEN_SCALE),
            $options instanceof FixedWindowOptions => $options->capacityUnits->value - $view->primary,
            $options instanceof SlidingCounterOptions => self::slidingAvailable($options, $view, $eff),
            default => throw self::unsupported(),
        };
    }

    /** Evaluates $cost (whole units, not greater than the capacity) against $view. */
    public static function evaluate(RateAlgorithmOptions $options, RateView $view, int $eff, int $cost): RateEvaluation
    {
        return match (true) {
            $options instanceof TokenBucketOptions => self::evaluateBucket($options, $view, $eff, $cost),
            $options instanceof FixedWindowOptions => self::evaluateFixed($options, $view, $eff, $cost),
            $options instanceof SlidingCounterOptions => self::evaluateSliding($options, $view, $eff, $cost),
            default => throw self::unsupported(),
        };
    }

    /**
     * Equivalence to a fresh state at $eff, used only by maintenance: true when removing the record cannot
     * change any later admission or inspection result.
     */
    public static function isFreshEquivalent(RateAlgorithmOptions $options, RateRecord $record, int $eff, int $epochAnchor = 0): bool
    {
        $view = self::project($options, $record, $eff, $epochAnchor);

        return match (true) {
            $options instanceof TokenBucketOptions => $view->primary === $options->capacityUnits->value * self::TOKEN_SCALE,
            $options instanceof FixedWindowOptions => $view->primary === 0,
            $options instanceof SlidingCounterOptions => $view->primary === 0 && $view->secondary === 0,
            default => throw self::unsupported(),
        };
    }

    /**
     * Start tick of the window containing `$eff`, with window boundaries at multiples of `$window` on the epoch
     * axis `epoch = tick + $epochAnchor`. Overflow-free: only remainders are added.
     */
    public static function alignedStart(int $eff, int $window, int $epochAnchor): int
    {
        $anchorOffset = $epochAnchor % $window;
        if ($anchorOffset < 0) {
            $anchorOffset += $window;
        }

        $position = $eff % $window;
        $offset = $position >= $window - $anchorOffset ? $position - ($window - $anchorOffset) : $position + $anchorOffset;

        return $eff - $offset;
    }

    private static function unsupported(): InvalidConfigurationException
    {
        return new InvalidConfigurationException('Unsupported rate algorithm options implementation.');
    }

    // ---- token bucket ------------------------------------------------------------------------------

    private static function projectBucket(TokenBucketOptions $options, ?RateRecord $record, int $eff): RateView
    {
        $capacity = $options->capacityUnits->value * self::TOKEN_SCALE;
        if ($record === null) {
            return new RateView($capacity, 0, 0);
        }

        $balance = $record->primary;
        $remainder = $record->secondary;
        $deficit = $capacity - $balance;
        if ($deficit <= 0) {
            return new RateView($capacity, 0, 0);
        }
        $elapsed = $eff - $record->lastTick;
        if ($elapsed === 0) {
            return new RateView($balance, $remainder, 0);
        }

        $added = BoundedArithmetic::mulAddDivFloorCapped(
            $elapsed,
            $options->refillUnits->value * self::TOKEN_SCALE,
            $remainder,
            $options->refillInterval->nanoseconds,
            $deficit,
        );
        if ($added->capped) {
            return new RateView($capacity, 0, 0);
        }

        return new RateView($balance + $added->quotient, $added->remainder, 0);
    }

    private static function evaluateBucket(TokenBucketOptions $options, RateView $view, int $eff, int $cost): RateEvaluation
    {
        $available = self::available($options, $view, $eff);
        $scaledCost = $cost * self::TOKEN_SCALE;
        if ($view->primary >= $scaledCost) {
            return new RateEvaluation(true, new RateView($view->primary - $scaledCost, $view->secondary, 0), $available, null);
        }

        // Smallest tick count d with floor((d*R + remainder) / W) >= need, i.e. d = ceil((need*W - remainder) / R).
        $wait = BoundedArithmetic::mulSubCeilDivCapped(
            $scaledCost - $view->primary,
            $options->refillInterval->nanoseconds,
            $view->secondary,
            $options->refillUnits->value * self::TOKEN_SCALE,
            PHP_INT_MAX,
        );

        return new RateEvaluation(false, $view, $available, $wait->capped ? null : $wait->quotient);
    }

    // ---- fixed window ------------------------------------------------------------------------------

    private static function projectFixed(FixedWindowOptions $options, ?RateRecord $record, int $eff, int $epochAnchor): RateView
    {
        $window = $options->window->nanoseconds;
        $start = $options->anchor === FixedWindowAnchor::Aligned ? self::alignedStart($eff, $window, $epochAnchor) : $eff;
        if ($record === null) {
            return new RateView(0, 0, $start);
        }

        $sameWindow = $options->anchor === FixedWindowAnchor::Aligned
            ? $record->windowStart === $start
            : $eff - $record->windowStart < $window;

        return $sameWindow ? new RateView($record->primary, 0, $record->windowStart) : new RateView(0, 0, $start);
    }

    private static function evaluateFixed(FixedWindowOptions $options, RateView $view, int $eff, int $cost): RateEvaluation
    {
        $capacity = $options->capacityUnits->value;
        $available = $capacity - $view->primary;
        if ($cost <= $available) {
            return new RateEvaluation(true, new RateView($view->primary + $cost, 0, $view->windowStart), $available, null);
        }

        // The window is [start, start + W); exactly at its end a new window starts, so the wait is W - elapsed.
        return new RateEvaluation(
            false,
            $view,
            $available,
            $options->window->nanoseconds - ($eff - $view->windowStart),
        );
    }

    // ---- approximate sliding counter -----------------------------------------------------------------

    private static function projectSliding(SlidingCounterOptions $options, ?RateRecord $record, int $eff, int $epochAnchor): RateView
    {
        $window = $options->window->nanoseconds;
        $start = self::alignedStart($eff, $window, $epochAnchor);
        if ($record === null) {
            return new RateView(0, 0, $start);
        }

        $elapsedWindows = intdiv($start - $record->windowStart, $window);

        return match (true) {
            $elapsedWindows <= 0 => new RateView($record->primary, $record->secondary, $start),
            $elapsedWindows === 1 => new RateView(0, $record->primary, $start),
            default => new RateView(0, 0, $start),
        };
    }

    private static function slidingAvailable(SlidingCounterOptions $options, RateView $view, int $eff): int
    {
        $window = $options->window->nanoseconds;
        $x = $eff - $view->windowStart;
        $weighted = BoundedArithmetic::mulSubCeilDivCapped($view->secondary, $window - $x, 0, $window, PHP_INT_MAX)->quotient;

        return max(0, $options->capacityUnits->value - $view->primary - $weighted);
    }

    /**
     * ((current + cost) * W) + (previous * (W - x)) <= capacity * W, compared exactly.
     */
    private static function slidingFits(int $current, int $cost, int $previous, int $window, int $x, int $capacity): bool
    {
        return BoundedArithmetic::compareWeightedSum($current + $cost, $window, $previous, $window - $x, $capacity, $window) <= 0;
    }

    private static function evaluateSliding(SlidingCounterOptions $options, RateView $view, int $eff, int $cost): RateEvaluation
    {
        $window = $options->window->nanoseconds;
        $capacity = $options->capacityUnits->value;
        $x = $eff - $view->windowStart;
        $current = $view->primary;
        $previous = $view->secondary;
        $available = self::slidingAvailable($options, $view, $eff);

        if (self::slidingFits($current, $cost, $previous, $window, $x, $capacity)) {
            return new RateEvaluation(true, new RateView($current + $cost, $previous, $view->windowStart), $available, null);
        }

        return new RateEvaluation(false, $view, $available, self::slidingWait($current, $cost, $previous, $window, $x, $capacity));
    }

    /**
     * Smallest wait after which the same cost fits the same approximation, searched over at most the
     * two-window horizon: the rest of this window, then the next window (previous = current), then the
     * window after that (both counts zero, always eligible at its first tick). Null when the wait does not
     * fit a 64-bit nanosecond duration; it is never rounded down.
     */
    private static function slidingWait(int $current, int $cost, int $previous, int $window, int $x, int $capacity): ?int
    {
        $last = $window - 1;

        // Remaining ticks of the current window; the condition is monotone in x because the previous weight shrinks.
        if ($x < $last && self::slidingFits($current, $cost, $previous, $window, $last, $capacity)) {
            $lo = $x + 1;
            $hi = $last;
            while ($lo < $hi) {
                $mid = $lo + intdiv($hi - $lo, 2);
                if (self::slidingFits($current, $cost, $previous, $window, $mid, $capacity)) {
                    $hi = $mid;
                } else {
                    $lo = $mid + 1;
                }
            }

            return $lo - $x;
        }

        $untilNext = $window - $x;
        if (self::slidingFits(0, $cost, $current, $window, $last, $capacity)) {
            $lo = 0;
            $hi = $last;
            while ($lo < $hi) {
                $mid = $lo + intdiv($hi - $lo, 2);
                if (self::slidingFits(0, $cost, $current, $window, $mid, $capacity)) {
                    $hi = $mid;
                } else {
                    $lo = $mid + 1;
                }
            }

            return $lo > PHP_INT_MAX - $untilNext ? null : $untilNext + $lo;
        }

        return $window > PHP_INT_MAX - $untilNext ? null : $untilNext + $window;
    }
}
