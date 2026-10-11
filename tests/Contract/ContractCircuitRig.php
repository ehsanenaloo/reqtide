<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Contract;

use Enaxon\Reqtide\Outcome\CircuitVerdict;
use Enaxon\Reqtide\Policy\CircuitPolicy;
use Enaxon\Reqtide\Policy\CircuitWindowOptions;
use Enaxon\Reqtide\Policy\CountCircuitWindow;
use Enaxon\Reqtide\Policy\TimeCircuitWindow;
use Enaxon\Reqtide\State\CircuitAdmission;
use Enaxon\Reqtide\State\CircuitAdmissionStatus;
use Enaxon\Reqtide\State\CircuitBackend;
use Enaxon\Reqtide\State\CircuitPermit;
use Enaxon\Reqtide\State\FinalizationStatus;
use Enaxon\Reqtide\Tests\Support\Fixtures;
use Enaxon\Reqtide\Value\ApplicationId;
use Enaxon\Reqtide\Value\AttemptId;
use Enaxon\Reqtide\Value\CommandId;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\EnvironmentId;
use Enaxon\Reqtide\Value\PartitionIdentity;
use Enaxon\Reqtide\Value\PermitOwnerId;
use Enaxon\Reqtide\Value\PolicyId;
use Enaxon\Reqtide\Value\PolicyIdentity;
use Enaxon\Reqtide\Value\PositiveInt;
use Enaxon\Reqtide\Value\ProfileVersion;
use Enaxon\Reqtide\Value\Ratio;
use Enaxon\Reqtide\Value\SubjectId;
use PHPUnit\Framework\Assert;

/**
 * Test rig around one backend from a CircuitHarness, its controllable time and fresh attempt, owner and command ids.
 * The policy builders are shared with the process-local rig of the memory tests.
 */
final class ContractCircuitRig
{
    public const SECOND = 1_000_000_000;

    public readonly CircuitBackend $backend;

    public readonly ControllableTime $time;

    private int $sequence = 0x10_0000;

    public function __construct(private readonly CircuitHarness $harness, int $maxPartitions = 10_000, int $maxRecords = 20_000)
    {
        $this->time = $harness->time();
        $this->backend = $harness->circuit($maxPartitions, $maxRecords);
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
        return self::policy(new CountCircuitWindow(new PositiveInt($window)), $minimum, $ratioNumerator, $ratioDenominator, $probeCapacity, $threshold, $openSeconds, $leaseSeconds, $identity);
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
        return self::policy(
            new TimeCircuitWindow(Duration::seconds($windowSeconds), Duration::seconds(1)),
            $minimum,
            $ratioNumerator,
            $ratioDenominator,
            $probeCapacity,
            $threshold,
            $openSeconds,
            $leaseSeconds,
            $identity,
        );
    }

    public static function identity(string $subject): PolicyIdentity
    {
        return new PolicyIdentity(
            new PartitionIdentity(new ApplicationId('app'), new EnvironmentId('test'), null, new SubjectId($subject)),
            new ProfileVersion('v1'),
            new PolicyId('policy'),
        );
    }

    private static function policy(
        CircuitWindowOptions $window,
        int $minimum,
        int $ratioNumerator,
        int $ratioDenominator,
        int $probeCapacity,
        int $threshold,
        int $openSeconds,
        int $leaseSeconds,
        ?PolicyIdentity $identity,
    ): CircuitPolicy {
        return new CircuitPolicy(
            $identity ?? Fixtures::policyIdentity(),
            $window,
            new PositiveInt($minimum),
            Ratio::of($ratioNumerator, $ratioDenominator),
            Duration::seconds($openSeconds),
            new PositiveInt($probeCapacity),
            new PositiveInt($threshold),
            Duration::seconds($leaseSeconds),
        );
    }

    public function advance(int $nanoseconds): void
    {
        $this->time->advance($nanoseconds);
    }

    public function at(int $nanoseconds): void
    {
        $this->time->moveTo($nanoseconds);
    }

    /** The smallest distinguishable step of the authority clock, in nanoseconds. */
    public function step(): int
    {
        return $this->time->step();
    }

    /** Let the backend reclaim expired nonprotective state (see BackendHarness::reclaimExpired()). */
    public function reclaim(): void
    {
        $this->harness->reclaimExpired();
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

    /**
     * Acquire and immediately record, expecting both to succeed.
     *
     * @phpstan-impure
     */
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
