<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Pipeline\Support;

use Enaxon\Reqtide\Policy\ConcurrencyPolicy;
use Enaxon\Reqtide\State\ConcurrencyAdmission;
use Enaxon\Reqtide\State\ConcurrencyAdmissionStatus;
use Enaxon\Reqtide\State\ConcurrencyBackend;
use Enaxon\Reqtide\State\ConcurrencyPermit;
use Enaxon\Reqtide\State\FinalizationResult;
use Enaxon\Reqtide\State\FinalizationStatus;
use Enaxon\Reqtide\Value\BackendExpiry;
use Enaxon\Reqtide\Value\CommandId;
use Enaxon\Reqtide\Value\Generation;
use Enaxon\Reqtide\Value\PermitOwnerId;

final class FakeConcurrencyBackend extends FakeBackend implements ConcurrencyBackend
{
    public bool $exhausted = false;

    public ?\Throwable $acquireFailure = null;

    public ?\Throwable $releaseFailure = null;

    public int $active = 0;

    public int $released = 0;

    public FinalizationStatus $releaseStatus = FinalizationStatus::Applied;

    public function acquire(ConcurrencyPolicy $policy, PermitOwnerId $owner, CommandId $command): ConcurrencyAdmission
    {
        $this->enter('concurrency.acquire');
        if ($this->acquireFailure !== null) {
            throw $this->acquireFailure;
        }
        if ($this->exhausted) {
            return new ConcurrencyAdmission(ConcurrencyAdmissionStatus::CapacityExhausted, null);
        }
        ++$this->active;

        return new ConcurrencyAdmission(
            ConcurrencyAdmissionStatus::Accepted,
            new ConcurrencyPermit($policy->identity, self::incarnation(), new Generation(1), $owner, $policy->lease, BackendExpiry::local(1)),
        );
    }

    public function release(ConcurrencyPolicy $policy, ConcurrencyPermit $permit, CommandId $command): FinalizationResult
    {
        $this->enter('concurrency.release');
        if ($this->releaseFailure !== null) {
            throw $this->releaseFailure;
        }
        --$this->active;
        ++$this->released;

        return new FinalizationResult($this->releaseStatus);
    }
}
