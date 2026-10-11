<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\State;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Value\AttemptId;
use Enaxon\Reqtide\Value\BackendExpiry;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\Generation;
use Enaxon\Reqtide\Value\IncarnationId;
use Enaxon\Reqtide\Value\PermitOwnerId;
use Enaxon\Reqtide\Value\PolicyIdentity;

final readonly class RetryBudgetReservation
{
    public function __construct(
        public PolicyIdentity $policy,
        public IncarnationId $incarnation,
        public Generation $generation,
        public AttemptId $attempt,
        public PermitOwnerId $owner,
        public Duration $lease,
        public BackendExpiry $expiresAt,
    ) {
        if (!$lease->isPositive()) {
            throw new InvalidConfigurationException('Reservation lease must be positive.');
        }
    }
}
