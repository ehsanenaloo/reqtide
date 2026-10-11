<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\State;

use Enaxon\Reqtide\Admission\AdmissionDecision;
use Enaxon\Reqtide\Admission\RateLimitInspection;
use Enaxon\Reqtide\Exception\CorruptStateException;
use Enaxon\Reqtide\Exception\IndeterminateStateException;
use Enaxon\Reqtide\Exception\StateUnavailableException;
use Enaxon\Reqtide\Policy\RateLimitPolicy;
use Enaxon\Reqtide\Value\CommandId;
use Enaxon\Reqtide\Value\PositiveInt;

interface RateLimitBackend extends StateBackend
{
    /** @throws StateUnavailableException|CorruptStateException|IndeterminateStateException */
    public function consume(RateLimitPolicy $policy, PositiveInt $cost, CommandId $command): AdmissionDecision;

    /**
     * Never debits, resets, extends TTL/lease, allocates a partition or performs maintenance.
     *
     * @throws StateUnavailableException|CorruptStateException
     */
    public function inspect(RateLimitPolicy $policy, PositiveInt $requestedCost): RateLimitInspection;
}
