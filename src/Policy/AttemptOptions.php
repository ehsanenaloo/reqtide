<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Policy;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Value\Duration;

final readonly class AttemptOptions
{
    /** Effective timeout is min(maximumDuration, remaining deadline). */
    public function __construct(public Duration $maximumDuration, public AttemptTimeoutRequirement $timeoutRequirement)
    {
        if (!$maximumDuration->isPositive()) {
            throw new InvalidConfigurationException('Attempt maximumDuration must be strictly positive.');
        }
    }
}
