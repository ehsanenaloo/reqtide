<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\State;

use Enaxon\Reqtide\Exception\CorruptStateException;
use Enaxon\Reqtide\Exception\IndeterminateStateException;
use Enaxon\Reqtide\Exception\StateCapacityExceededException;
use Enaxon\Reqtide\Exception\StateUnavailableException;
use Enaxon\Reqtide\Policy\RetryBudgetPolicy;
use Enaxon\Reqtide\Value\AttemptId;
use Enaxon\Reqtide\Value\CommandId;
use Enaxon\Reqtide\Value\PermitOwnerId;

interface RetryBudgetBackend extends StateBackend
{
    /**
     * Read-only: writes nothing, allocates no state, performs no cleanup and never reserves. The pipeline calls it
     * before a retry delay so an empty budget is discovered before waiting, not after. The answer is advisory; the
     * authoritative reservation still follows the delay.
     *
     * @throws StateUnavailableException|CorruptStateException
     */
    public function inspect(RetryBudgetPolicy $policy): RetryBudgetInspection;

    /** @throws StateUnavailableException|CorruptStateException|IndeterminateStateException|StateCapacityExceededException */
    public function reserve(RetryBudgetPolicy $policy, AttemptId $attempt, PermitOwnerId $owner, CommandId $command): RetryReservationDecision;

    /** @throws StateUnavailableException|CorruptStateException|IndeterminateStateException */
    public function commit(RetryBudgetPolicy $policy, RetryBudgetReservation $reservation, CommandId $command): RetryBudgetFinalizationResult;

    /** @throws StateUnavailableException|CorruptStateException|IndeterminateStateException */
    public function release(RetryBudgetPolicy $policy, RetryBudgetReservation $reservation, CommandId $command): RetryBudgetFinalizationResult;
}
