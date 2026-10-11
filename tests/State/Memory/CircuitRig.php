<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\State\Memory;

use Enaxon\Reqtide\Outcome\CircuitVerdict;
use Enaxon\Reqtide\Policy\CircuitPolicy;
use Enaxon\Reqtide\State\CircuitAdmission;
use Enaxon\Reqtide\State\CircuitAdmissionStatus;
use Enaxon\Reqtide\State\CircuitPermit;
use Enaxon\Reqtide\State\FinalizationStatus;
use Enaxon\Reqtide\State\Memory\MemoryCircuitBackend;
use Enaxon\Reqtide\Tests\Contract\ContractCircuitRig;
use Enaxon\Reqtide\Tests\Support\FakeClock;
use Enaxon\Reqtide\Tests\Support\Fixtures;
use Enaxon\Reqtide\Value\AttemptId;
use Enaxon\Reqtide\Value\CommandId;
use Enaxon\Reqtide\Value\PermitOwnerId;
use Enaxon\Reqtide\Value\PolicyIdentity;
use PHPUnit\Framework\Assert;

/** Test harness around one backend, one fake clock and fresh attempt/owner/command ids. */
final class CircuitRig
{
    public const SECOND = 1_000_000_000;

    public readonly FakeClock $clock;
    public readonly MemoryCircuitBackend $backend;
    private int $sequence = 0x10_0000;

    public function __construct(int $maxPartitions = 10_000, int $maxRecords = 20_000)
    {
        $this->clock = new FakeClock(0);
        $this->backend = new MemoryCircuitBackend($this->clock, new SequenceIdentityGenerator(), $maxPartitions, $maxRecords);
    }

    public static function countPolicy(
        int $window = 100,
        int $minimum = 20,
        int $ratioNumerator = 1,
        int $ratioDenominator = 2,
        int $probeCapacity = 1,
        int $threshold = 1,
        int $openSeconds = 30,
        int $leaseSeconds = 10,
        ?PolicyIdentity $identity = null,
    ): CircuitPolicy {
        return ContractCircuitRig::countPolicy($window, $minimum, $ratioNumerator, $ratioDenominator, $probeCapacity, $threshold, $openSeconds, $leaseSeconds, $identity);
    }

    public static function timePolicy(
        int $windowSeconds = 60,
        int $minimum = 2,
        int $ratioNumerator = 1,
        int $ratioDenominator = 2,
        int $probeCapacity = 1,
        int $threshold = 1,
        int $openSeconds = 30,
        int $leaseSeconds = 10,
        ?PolicyIdentity $identity = null,
    ): CircuitPolicy {
        return ContractCircuitRig::timePolicy($windowSeconds, $minimum, $ratioNumerator, $ratioDenominator, $probeCapacity, $threshold, $openSeconds, $leaseSeconds, $identity);
    }

    public static function identity(string $subject): PolicyIdentity
    {
        return ContractCircuitRig::identity($subject);
    }


    public function advance(int $nanoseconds): void
    {
        $this->clock->now += $nanoseconds;
    }

    public function at(int $nanoseconds): void
    {
        $this->clock->now = $nanoseconds;
    }

    /** @phpstan-impure */
    public function acquire(CircuitPolicy $policy): CircuitAdmission
    {
        ++$this->sequence;

        return $this->backend->acquire(
            $policy,
            AttemptId::fromLowerHex(Fixtures::hex($this->sequence)),
            PermitOwnerId::fromLowerHex(Fixtures::hex($this->sequence + 0x1000_0000)),
            CommandId::fromLowerHex(Fixtures::hex($this->sequence + 0x2000_0000)),
        );
    }

    /** @phpstan-impure */
    public function permit(CircuitPolicy $policy): CircuitPermit
    {
        $admission = $this->acquire($policy);
        Assert::assertSame(CircuitAdmissionStatus::Accepted, $admission->status);
        Assert::assertNotNull($admission->permit);

        return $admission->permit;
    }

    /** @phpstan-impure */
    public function record(CircuitPolicy $policy, CircuitPermit $permit, CircuitVerdict $verdict): FinalizationStatus
    {
        ++$this->sequence;

        return $this->backend->record($policy, $permit, $verdict, CommandId::fromLowerHex(Fixtures::hex($this->sequence + 0x3000_0000)))->status;
    }

    /** @phpstan-impure */
    public function abandon(CircuitPolicy $policy, CircuitPermit $permit): FinalizationStatus
    {
        ++$this->sequence;

        return $this->backend->abandon($policy, $permit, CommandId::fromLowerHex(Fixtures::hex($this->sequence + 0x3000_0000)))->status;
    }

    /** Acquire and immediately record, expecting both to succeed. */
    /** @phpstan-impure */
    public function complete(CircuitPolicy $policy, CircuitVerdict $verdict): void
    {
        Assert::assertSame(FinalizationStatus::Applied, $this->record($policy, $this->permit($policy), $verdict));
    }

    /** @phpstan-impure */
    public function status(CircuitPolicy $policy): CircuitAdmissionStatus
    {
        return $this->acquire($policy)->status;
    }
}
