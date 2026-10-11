<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\State;

use Enaxon\Reqtide\Exception\CorruptStateException;
use Enaxon\Reqtide\Exception\IndeterminateStateException;
use Enaxon\Reqtide\Exception\StateCapacityExceededException;
use Enaxon\Reqtide\Exception\StateUnavailableException;
use Enaxon\Reqtide\Policy\ConcurrencyPolicy;
use Enaxon\Reqtide\Value\CommandId;
use Enaxon\Reqtide\Value\PermitOwnerId;

interface ConcurrencyBackend extends StateBackend
{
    /** @throws StateUnavailableException|CorruptStateException|IndeterminateStateException|StateCapacityExceededException */
    public function acquire(ConcurrencyPolicy $policy, PermitOwnerId $owner, CommandId $command): ConcurrencyAdmission;

    /** @throws StateUnavailableException|CorruptStateException|IndeterminateStateException */
    public function release(ConcurrencyPolicy $policy, ConcurrencyPermit $permit, CommandId $command): FinalizationResult;
}
