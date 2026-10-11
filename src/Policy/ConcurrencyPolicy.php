<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Policy;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Internal\Horizon;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\PolicyIdentity;
use Enaxon\Reqtide\Value\PositiveInt;

final readonly class ConcurrencyPolicy
{
    public function __construct(
        public PolicyIdentity $identity,
        public PositiveInt $capacity,
        public Duration $lease,
    ) {
        if (!$lease->isPositive()) {
            throw new InvalidConfigurationException('Concurrency lease must be positive.');
        }
        Horizon::assert($lease, 'Concurrency lease');
    }
}
