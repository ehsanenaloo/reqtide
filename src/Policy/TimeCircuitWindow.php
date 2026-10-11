<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Policy;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Internal\NumericLimits;
use Enaxon\Reqtide\Value\Duration;

/** Whole-intersecting-bucket window: requires ceil(window/granularity) + 1 <= 1024 buckets. */
final readonly class TimeCircuitWindow implements CircuitWindowOptions
{
    public function __construct(public Duration $window, public Duration $bucketGranularity)
    {
        if (!$window->isPositive() || !$bucketGranularity->isPositive()) {
            throw new InvalidConfigurationException('Window and bucket granularity must be positive.');
        }
        $whole = intdiv($window->nanoseconds, $bucketGranularity->nanoseconds);
        $buckets = $whole + ($window->nanoseconds % $bucketGranularity->nanoseconds === 0 ? 0 : 1) + 1;
        if ($buckets > NumericLimits::MAX_TIME_BUCKETS) {
            throw new InvalidConfigurationException('A time window may intersect at most 1024 buckets.');
        }
    }
}
