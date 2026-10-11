<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\State;

use Enaxon\Reqtide\Exception\CorruptStateException;
use Enaxon\Reqtide\Exception\IndeterminateStateException;
use Enaxon\Reqtide\Exception\StateCapacityExceededException;
use Enaxon\Reqtide\Exception\StateUnavailableException;
use Enaxon\Reqtide\Outcome\CircuitVerdict;
use Enaxon\Reqtide\Policy\CircuitPolicy;
use Enaxon\Reqtide\Value\AttemptId;
use Enaxon\Reqtide\Value\CommandId;
use Enaxon\Reqtide\Value\PermitOwnerId;

interface CircuitBackend extends StateBackend
{
    /** @throws StateUnavailableException|CorruptStateException|IndeterminateStateException|StateCapacityExceededException */
    public function acquire(CircuitPolicy $policy, AttemptId $attempt, PermitOwnerId $owner, CommandId $command): CircuitAdmission;

    /** @throws StateUnavailableException|CorruptStateException|IndeterminateStateException|StateCapacityExceededException */
    public function record(CircuitPolicy $policy, CircuitPermit $permit, CircuitVerdict $verdict, CommandId $command): FinalizationResult;

    /** @throws StateUnavailableException|CorruptStateException|IndeterminateStateException */
    public function abandon(CircuitPolicy $policy, CircuitPermit $permit, CommandId $command): FinalizationResult;
}
