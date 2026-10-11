<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\State;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Value\Duration;

final readonly class CooldownDecision
{
    /** `remaining` is zero iff Clear and positive iff Active. */
    public function __construct(public CooldownStatus $status, public Duration $remaining)
    {
        if (($status === CooldownStatus::Active) !== $remaining->isPositive()) {
            throw new InvalidConfigurationException('Cooldown remaining must be positive if and only if Active.');
        }
    }
}
