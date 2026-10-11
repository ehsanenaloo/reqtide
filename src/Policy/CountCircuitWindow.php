<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Policy;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Internal\NumericLimits;
use Enaxon\Reqtide\Value\PositiveInt;

/** Exact last N completed non-neutral outcomes, N in [1, 10000]. */
final readonly class CountCircuitWindow implements CircuitWindowOptions
{
    public function __construct(public PositiveInt $completedSamples)
    {
        if ($completedSamples->value > NumericLimits::MAX_COUNT_WINDOW_SAMPLES) {
            throw new InvalidConfigurationException('Count window must hold at most 10000 samples.');
        }
    }
}
