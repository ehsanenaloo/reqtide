<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Pipeline\Support;

use Enaxon\Reqtide\Outcome\CircuitVerdict;
use Enaxon\Reqtide\Policy\CircuitPolicy;
use Enaxon\Reqtide\State\CircuitAdmission;
use Enaxon\Reqtide\State\CircuitAdmissionStatus;
use Enaxon\Reqtide\State\CircuitBackend;
use Enaxon\Reqtide\State\CircuitPermit;
use Enaxon\Reqtide\State\CircuitPermitKind;
use Enaxon\Reqtide\State\CircuitTransition;
use Enaxon\Reqtide\State\FinalizationResult;
use Enaxon\Reqtide\State\FinalizationStatus;
use Enaxon\Reqtide\Value\AttemptId;
use Enaxon\Reqtide\Value\BackendExpiry;
use Enaxon\Reqtide\Value\CommandId;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\Generation;
use Enaxon\Reqtide\Value\PermitOwnerId;

final class FakeCircuitBackend extends FakeBackend implements CircuitBackend
{
    public CircuitAdmissionStatus $status = CircuitAdmissionStatus::Accepted;

    public CircuitPermitKind $permitKind = CircuitPermitKind::ClosedSample;

    public ?\Throwable $acquireFailure = null;

    public ?\Throwable $recordFailure = null;

    public ?\Throwable $abandonFailure = null;

    /** Outstanding (acquired, not yet recorded or abandoned) permits. */
    public int $outstanding = 0;

    /** @var list<CircuitVerdict> */
    public array $recorded = [];

    public int $abandoned = 0;

    public int $acquired = 0;

    public FinalizationStatus $recordStatus = FinalizationStatus::Applied;

    public FinalizationStatus $abandonStatus = FinalizationStatus::Applied;

    /** Transition reported by the next acquire, record and abandon call respectively (null: none). */
    public ?CircuitTransition $acquireTransition = null;

    public ?CircuitTransition $recordTransition = null;

    public ?CircuitTransition $abandonTransition = null;

    public function acquire(CircuitPolicy $policy, AttemptId $attempt, PermitOwnerId $owner, CommandId $command): CircuitAdmission
    {
        $this->enter('circuit.acquire');
        if ($this->acquireFailure !== null) {
            throw $this->acquireFailure;
        }
        if ($this->status !== CircuitAdmissionStatus::Accepted) {
            return new CircuitAdmission($this->status, null, $this->status === CircuitAdmissionStatus::Open ? Duration::seconds(30) : null, $this->acquireTransition);
        }
        ++$this->acquired;
        ++$this->outstanding;

        return new CircuitAdmission(
            CircuitAdmissionStatus::Accepted,
            new CircuitPermit(
                $policy->identity,
                self::incarnation(),
                new Generation(1),
                $attempt,
                $owner,
                $this->permitKind,
                $policy->permitLease,
                BackendExpiry::local(1),
            ),
            null,
            $this->acquireTransition,
        );
    }

    public function record(CircuitPolicy $policy, CircuitPermit $permit, CircuitVerdict $verdict, CommandId $command): FinalizationResult
    {
        $this->enter('circuit.record.' . $verdict->value);
        if ($this->recordFailure !== null) {
            throw $this->recordFailure;
        }
        --$this->outstanding;
        $this->recorded[] = $verdict;

        return new FinalizationResult($this->recordStatus, $this->recordTransition);
    }

    public function abandon(CircuitPolicy $policy, CircuitPermit $permit, CommandId $command): FinalizationResult
    {
        $this->enter('circuit.abandon');
        if ($this->abandonFailure !== null) {
            throw $this->abandonFailure;
        }
        --$this->outstanding;
        ++$this->abandoned;

        return new FinalizationResult($this->abandonStatus, $this->abandonTransition);
    }
}
