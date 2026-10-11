<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Internal;

/**
 * Numeric limits that keep integer arithmetic exact on every backend, including backends that compute with IEEE-754 doubles.
 *
 * Internal constants, not part of the public API catalogue.
 *
 * @internal
 */
final class NumericLimits
{
    /** Largest consecutive integer exactly representable by an IEEE-754 binary64. */
    public const LUA_EXACT_MAX = 9_007_199_254_740_991;

    /** floor(LUA_EXACT_MAX / 1_000_000): portable rate/budget capacity, refill and cost ceiling in whole tokens. */
    public const MAX_PORTABLE_TOKENS = 9_007_199_254;

    public const MAX_COUNT_WINDOW_SAMPLES = 10_000;

    public const MAX_TIME_BUCKETS = 1_024;

    /** floor(LUA_EXACT_MAX / 1_024). */
    public const MAX_SAMPLES_PER_BUCKET = 8_796_093_022_207;

    /** MAX_SAMPLES_PER_BUCKET * 1_024. */
    public const MAX_AGGREGATE_SAMPLES = 9_007_199_254_739_968;

    /**
     * Longest lease, open duration or retention a policy may declare: 2^62 ns (about 146 years). A deadline
     * `now + duration` therefore cannot overflow int64 for any monotonic reading below 2^62 ns; backends still
     * check the sum and fail with a typed error rather than wrap for larger readings.
     */
    public const MAX_DEADLINE_HORIZON_NANOSECONDS = 4_611_686_018_427_387_904;

    public const MAX_ATTEMPTS = 64;

    public const MAX_DIAGNOSTICS = 32;

    private function __construct() {}
}
