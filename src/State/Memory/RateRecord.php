<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\State\Memory;

use Enaxon\Reqtide\Policy\RateAlgorithmOptions;

/**
 * Mutable per-policy-identity rate state owned by MemoryRateLimitBackend. All tick values are monotonic
 * clock nanoseconds. Fields unused by the record's algorithm stay zero.
 *
 * @internal
 */
final class RateRecord
{
    public function __construct(
        /** Identity-bound immutable settings; a different value for the same identity is a configuration error. */
        public readonly RateAlgorithmOptions $options,
        public readonly string $fingerprint,
        /** Effective tick of the last write; it is also the origin of the next elapsed-time calculation. */
        public int $lastTick,
        /** Token bucket: scaled balance. Fixed window / sliding counter: current-window count. */
        public int $primary = 0,
        /** Token bucket: refill remainder in [0, interval). Sliding counter: previous-window count. */
        public int $secondary = 0,
        /** Fixed window / sliding counter: start tick of the stored window. */
        public int $windowStart = 0,
    ) {}
}
