<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Policy;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Internal\NumericLimits;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\PositiveInt;

/**
 * Whole tokens; stored scale is fixed at exactly 1_000_000 and is not configurable.
 */
final readonly class TokenBucketOptions implements RateAlgorithmOptions
{
    public function __construct(
        public PositiveInt $capacityUnits,
        public PositiveInt $refillUnits,
        public Duration $refillInterval,
    ) {
        if ($capacityUnits->value > NumericLimits::MAX_PORTABLE_TOKENS || $refillUnits->value > NumericLimits::MAX_PORTABLE_TOKENS) {
            throw new InvalidConfigurationException('Token capacity and refill must not exceed 9007199254 tokens.');
        }
        if (!$refillInterval->isPositive()) {
            throw new InvalidConfigurationException('Refill interval must be positive.');
        }
    }
}
