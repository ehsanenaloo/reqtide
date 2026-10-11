<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Policy;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Internal\NumericLimits;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\PositiveInt;

final readonly class FixedWindowOptions implements RateAlgorithmOptions
{
    public function __construct(
        public PositiveInt $capacityUnits,
        public Duration $window,
        public FixedWindowAnchor $anchor,
    ) {
        if ($capacityUnits->value > NumericLimits::MAX_PORTABLE_TOKENS) {
            throw new InvalidConfigurationException('Window capacity must not exceed 9007199254.');
        }
        if (!$window->isPositive()) {
            throw new InvalidConfigurationException('Window must be positive.');
        }
    }
}
