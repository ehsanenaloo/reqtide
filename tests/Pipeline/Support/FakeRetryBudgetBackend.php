<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Pipeline\Support;

use Enaxon\Reqtide\Policy\RetryBudgetPolicy;
use Enaxon\Reqtide\State\RetryBudgetBackend;
use Enaxon\Reqtide\State\RetryBudgetFinalizationResult;
use Enaxon\Reqtide\State\RetryBudgetFinalizationStatus;
use Enaxon\Reqtide\State\RetryBudgetInspection;
use Enaxon\Reqtide\State\RetryBudgetReservation;
use Enaxon\Reqtide\State\RetryReservationDecision;
use Enaxon\Reqtide\State\RetryReservationStatus;
use Enaxon\Reqtide\Value\AttemptId;
use Enaxon\Reqtide\Value\BackendExpiry;
use Enaxon\Reqtide\Value\CommandId;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\Generation;
use Enaxon\Reqtide\Value\NonNegativeInt;
use Enaxon\Reqtide\Value\PermitOwnerId;

final class FakeRetryBudgetBackend extends FakeBackend implements RetryBudgetBackend
{
    public RetryReservationStatus $status = RetryReservationStatus::Reserved;

    public RetryBudgetFinalizationStatus $commitStatus = RetryBudgetFinalizationStatus::Committed;

    /**
     * What inspect() reports, independently of reserve(): the pre-delay check is advisory, so tests can make it
     * pass while the authoritative reservation refuses after the delay, or the reverse.
     */
    public bool $inspectHeadroom = true;

    public ?Duration $inspectRetryAfter = null;

    public ?\Throwable $inspectFailure = null;

    /** @var (\Closure(): void)|null runs when inspect() is called, before it answers */
    public ?\Closure $onInspect = null;

    public int $inspected = 0;

    public ?\Throwable $reserveFailure = null;

    public ?\Throwable $commitFailure = null;

    public ?\Throwable $releaseFailure = null;

    public int $reserved = 0;

    public int $committed = 0;

    public int $released = 0;

    /** @var list<AttemptId> */
    public array $reservedFor = [];

    public function inspect(RetryBudgetPolicy $policy): RetryBudgetInspection
    {
        $this->enter('budget.inspect');
        ++$this->inspected;
        if ($this->onInspect !== null) {
            ($this->onInspect)();
        }
        if ($this->inspectFailure !== null) {
            throw $this->inspectFailure;
        }

        return $this->inspectHeadroom
            ? new RetryBudgetInspection(new NonNegativeInt(3), true, null)
            : new RetryBudgetInspection(new NonNegativeInt(0), false, $this->inspectRetryAfter);
    }

    public function reserve(RetryBudgetPolicy $policy, AttemptId $attempt, PermitOwnerId $owner, CommandId $command): RetryReservationDecision
    {
        $this->enter('budget.reserve');
        if ($this->reserveFailure !== null) {
            throw $this->reserveFailure;
        }
        if ($this->status !== RetryReservationStatus::Reserved) {
            return new RetryReservationDecision($this->status, null, Duration::seconds(5));
        }
        ++$this->reserved;
        $this->reservedFor[] = $attempt;

        return new RetryReservationDecision(
            RetryReservationStatus::Reserved,
            new RetryBudgetReservation($policy->identity, self::incarnation(), new Generation(1), $attempt, $owner, $policy->reservationLease, BackendExpiry::local(1)),
            null,
        );
    }

    public function commit(RetryBudgetPolicy $policy, RetryBudgetReservation $reservation, CommandId $command): RetryBudgetFinalizationResult
    {
        $this->enter('budget.commit');
        if ($this->commitFailure !== null) {
            throw $this->commitFailure;
        }
        if ($this->commitStatus === RetryBudgetFinalizationStatus::Committed) {
            ++$this->committed;
        }

        return new RetryBudgetFinalizationResult($this->commitStatus);
    }

    public function release(RetryBudgetPolicy $policy, RetryBudgetReservation $reservation, CommandId $command): RetryBudgetFinalizationResult
    {
        $this->enter('budget.release');
        if ($this->releaseFailure !== null) {
            throw $this->releaseFailure;
        }
        ++$this->released;

        return new RetryBudgetFinalizationResult(RetryBudgetFinalizationStatus::Released);
    }
}
