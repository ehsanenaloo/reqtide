<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Contract;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Exception\StateCapacityExceededException;
use Enaxon\Reqtide\Outcome\CircuitVerdict;
use Enaxon\Reqtide\Policy\CircuitPolicy;
use Enaxon\Reqtide\State\CircuitAdmissionStatus;
use Enaxon\Reqtide\State\CircuitPermitKind;
use Enaxon\Reqtide\State\FinalizationStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Thresholds and windows, probes, stale and duplicate finalizations, and the retention and ceiling rules
 * every CircuitBackend must show, whatever its storage. Boundary expectations use the harness step.
 *
 * Intentionally backend-specific and therefore not here: the manual reset entry point, bucket-ceiling and generation
 * exhaustion tests that corrupt process memory through reflection, the exact-product comparison helper, partition
 * counters and maintenance budgets (process-local); script, registry and encoding behaviour (shared).
 */
abstract class CircuitBackendContract extends TestCase
{
    private const S = ContractCircuitRig::SECOND;

    private ?CircuitHarness $harness = null;

    abstract protected function createHarness(): CircuitHarness;

    final protected function harness(): CircuitHarness
    {
        return $this->harness ??= $this->createHarness();
    }

    final protected function rig(int $maxPartitions = 10_000, int $maxRecords = 20_000): ContractCircuitRig
    {
        // One authority per rig: a rig that needs different ceilings starts from its own harness.
        $harness = $this->harness === null ? $this->harness() : $this->createHarness();
        $this->harness = $harness;

        return new ContractCircuitRig($harness, $maxPartitions, $maxRecords);
    }

    private static function failure(): CircuitVerdict
    {
        return CircuitVerdict::Failure;
    }

    private static function success(): CircuitVerdict
    {
        return CircuitVerdict::Success;
    }

    public function testCapabilitiesMatchTheHarnessDeclaration(): void
    {
        $rig = $this->rig();
        $capabilities = $rig->backend->capabilities();
        self::assertSame($this->harness()->expectedScope(), $capabilities->scope);
        self::assertSame($this->harness()->expectsFiniteIoTimeout(), $capabilities->ioTimeout !== null);
        self::assertLessThanOrEqual($rig->step(), $capabilities->clockResolution->nanoseconds);
    }

    public function testPartitionCeilingRejectsNewPartitionsAndNeverEvictsProtectiveState(): void
    {
        $rig = $this->rig(maxPartitions: 2);
        $a = ContractCircuitRig::countPolicy(minimum: 2, identity: ContractCircuitRig::identity('a'));
        $b = ContractCircuitRig::countPolicy(minimum: 2, identity: ContractCircuitRig::identity('b'));
        $c = ContractCircuitRig::countPolicy(minimum: 2, identity: ContractCircuitRig::identity('c'));
        $this->trip($rig, $a);
        $rig->complete($b, self::failure());

        $rig->advance(7_200 * self::S);
        $rig->reclaim();
        try {
            $rig->acquire($c);
            self::fail('Expected StateCapacityExceededException.');
        } catch (StateCapacityExceededException) {
        }
        self::assertSame(CircuitAdmissionStatus::Accepted, $rig->status($a), 'Open partition survived and is HalfOpen now');
        // b held count-window history, so it was not evicted either: its failure still counts toward the next sample.
        $rig->complete($b, self::failure());
        self::assertSame(CircuitAdmissionStatus::Open, $rig->status($b), 'two failures of two samples: the history of b survived');
    }

    public function testTenOfTwentyFailuresOpensAtEquality(): void
    {
        $rig = $this->rig();
        $policy = ContractCircuitRig::countPolicy();
        for ($i = 0; $i < 10; ++$i) {
            $rig->complete($policy, self::failure());
        }
        for ($i = 0; $i < 9; ++$i) {
            $rig->complete($policy, self::success());
        }
        self::assertSame(CircuitAdmissionStatus::Accepted, $rig->status($policy), '19 samples: below the minimum');

        $rig2 = $this->rig();
        for ($i = 0; $i < 10; ++$i) {
            $rig2->complete($policy, self::failure());
        }
        for ($i = 0; $i < 10; ++$i) {
            $rig2->complete($policy, self::success());
        }
        $open = $rig2->acquire($policy);
        self::assertSame(CircuitAdmissionStatus::Open, $open->status);
        self::assertNull($open->permit);
        self::assertSame(30 * self::S, $open->retryAfter?->nanoseconds);
    }

    public function testNineOfTwentyFailuresStaysClosed(): void
    {
        $rig = $this->rig();
        $policy = ContractCircuitRig::countPolicy();
        for ($i = 0; $i < 9; ++$i) {
            $rig->complete($policy, self::failure());
        }
        for ($i = 0; $i < 11; ++$i) {
            $rig->complete($policy, self::success());
        }

        self::assertSame(CircuitAdmissionStatus::Accepted, $rig->status($policy));
    }

    public function testNeutralOutcomesAreNeverSamples(): void
    {
        $rig = $this->rig();
        $policy = ContractCircuitRig::countPolicy(minimum: 2);
        for ($i = 0; $i < 50; ++$i) {
            $rig->complete($policy, CircuitVerdict::Neutral);
            $permit = $rig->permit($policy);
            self::assertSame(FinalizationStatus::Applied, $rig->abandon($policy, $permit));
        }
        $rig->complete($policy, self::failure());

        self::assertSame(CircuitAdmissionStatus::Accepted, $rig->status($policy), 'one sample is below the minimum of two');
    }

    public function testCountWindowHoldsExactlyTheLastNOutcomes(): void
    {
        $rig = $this->rig();
        $policy = ContractCircuitRig::countPolicy(window: 4, minimum: 4, ratioNumerator: 3, ratioDenominator: 4);
        foreach ([true, true, false, false, true] as $failure) { // window F,S,S,F = 2/4
            $rig->complete($policy, $failure ? self::failure() : self::success());
        }
        self::assertSame(CircuitAdmissionStatus::Accepted, $rig->status($policy));
        $rig->complete($policy, self::failure()); // S,S,F,F
        self::assertSame(CircuitAdmissionStatus::Accepted, $rig->status($policy));
        $rig->complete($policy, self::failure()); // S,F,F,F = 3/4
        self::assertSame(CircuitAdmissionStatus::Open, $rig->status($policy));
    }

    public function testOldGenerationSamplesDoNotSurviveRecovery(): void
    {
        $rig = $this->rig();
        $policy = ContractCircuitRig::countPolicy(minimum: 2);
        $rig->complete($policy, self::failure());
        $rig->complete($policy, self::failure());
        self::assertSame(CircuitAdmissionStatus::Open, $rig->status($policy));
        $rig->advance(30 * self::S);
        $probe = $rig->permit($policy);
        self::assertSame(CircuitPermitKind::HalfOpenProbe, $probe->kind);
        self::assertSame(FinalizationStatus::Applied, $rig->record($policy, $probe, self::success()));

        // Closed again with an empty window: one failure is below the minimum of two.
        $rig->complete($policy, self::failure());
        self::assertSame(CircuitAdmissionStatus::Accepted, $rig->status($policy));
    }

    /** @return iterable<string, array{int, int, bool}> */
    public static function timeBucketVectors(): iterable
    {
        // [success completion time in ns, ..., expected open] for a failure at t=0 and a success at the given time.
        yield 'now=59.999s bucket [0,1) included' => [59_999_000_000, 0, true];
        yield 'now=60.000s bucket [0,1) intersects (0,60]' => [60_000_000_000, 0, true];
        yield 'now=60.999s cutoff 0.999s, bucket still included' => [60_999_000_000, 0, true];
        yield 'now=61.000s bucket [0,1) excluded' => [61_000_000_000, 0, false];
        yield 'failure at 0.100s, now=60.999s retained' => [60_999_000_000, 100_000_000, true];
        yield 'failure at 0.100s, now=61.000s excluded' => [61_000_000_000, 100_000_000, false];
        yield 'failure at 0.999s, now=60.999s retained' => [60_999_000_000, 999_000_000, true];
        yield 'failure at 0.999s, now=61.000s excluded' => [61_000_000_000, 999_000_000, false];
        yield 'failure at 1.500s, now=61.000s bucket [1,2) retained' => [61_000_000_000, 1_500_000_000, true];
        yield 'failure at 1.500s, now=62.000s excluded' => [62_000_000_000, 1_500_000_000, false];
    }

    #[DataProvider('timeBucketVectors')]
    public function testTimeBucketBoundariesFollowTheWholeIntersectingBucketRule(int $successAt, int $failureAt, bool $expectOpen): void
    {
        $rig = $this->rig();
        $policy = ContractCircuitRig::timePolicy(windowSeconds: 60, minimum: 2);
        $rig->at($failureAt);
        $rig->complete($policy, self::failure());
        $rig->at($successAt);
        $rig->complete($policy, self::success());

        self::assertSame($expectOpen ? CircuitAdmissionStatus::Open : CircuitAdmissionStatus::Accepted, $rig->status($policy));
    }

    public function testHugeRatioStillComparesExactly(): void
    {
        $rig = $this->rig();
        $denominator = 9_007_199_254_740_991;
        $policy = ContractCircuitRig::countPolicy(window: 3, minimum: 3, ratioNumerator: 9_007_199_254_740_990, ratioDenominator: $denominator);
        // 2 failures of 3 is far below 0.9999..., 3 of 3 is above.
        $rig->complete($policy, self::failure());
        $rig->complete($policy, self::failure());
        $rig->complete($policy, self::success());
        self::assertSame(CircuitAdmissionStatus::Accepted, $rig->status($policy));
        $rig->complete($policy, self::failure());
        $rig->complete($policy, self::failure());
        $rig->complete($policy, self::failure());
        self::assertSame(CircuitAdmissionStatus::Open, $rig->status($policy));
    }

    public function testOpenRejectsUntilRecoveryTimeThenEntersHalfOpenAtEquality(): void
    {
        $rig = $this->rig();
        $policy = ContractCircuitRig::countPolicy(minimum: 2);
        $this->trip($rig, $policy);

        $rig->advance(30 * self::S - $rig->step());
        $open = $rig->acquire($policy);
        self::assertSame(CircuitAdmissionStatus::Open, $open->status);
        self::assertSame($rig->step(), $open->retryAfter?->nanoseconds);

        $rig->advance($rig->step());
        $probe = $rig->acquire($policy);
        self::assertSame(CircuitAdmissionStatus::Accepted, $probe->status);
        self::assertSame(CircuitPermitKind::HalfOpenProbe, $probe->permit?->kind);
        self::assertSame(CircuitAdmissionStatus::ProbeCapacityExhausted, $rig->status($policy));
    }

    public function testFailedProbeReopensAndOtherProbesBecomeStale(): void
    {
        $rig = $this->rig();
        $policy = ContractCircuitRig::countPolicy(minimum: 2, probeCapacity: 2, threshold: 2);
        $this->trip($rig, $policy);
        $rig->advance(30 * self::S);
        $first = $rig->permit($policy);
        $second = $rig->permit($policy);
        self::assertSame(CircuitAdmissionStatus::ProbeCapacityExhausted, $rig->status($policy));

        self::assertSame(FinalizationStatus::Applied, $rig->record($policy, $first, self::failure()));
        $reopened = $rig->acquire($policy);
        self::assertSame(CircuitAdmissionStatus::Open, $reopened->status);
        self::assertSame(30 * self::S, $reopened->retryAfter?->nanoseconds);
        self::assertSame(FinalizationStatus::StaleOwnership, $rig->record($policy, $second, self::success()));
        self::assertSame(CircuitAdmissionStatus::Open, $rig->status($policy));
    }

    public function testSuccessThresholdTwoClosesInEitherSettlementOrder(): void
    {
        foreach ([[0, 1], [1, 0]] as $order) {
            $rig = $this->rig();
            $policy = ContractCircuitRig::countPolicy(minimum: 2, probeCapacity: 2, threshold: 2);
            $this->trip($rig, $policy);
            $rig->advance(30 * self::S);
            $probes = [$rig->permit($policy), $rig->permit($policy)];

            self::assertSame(FinalizationStatus::Applied, $rig->record($policy, $probes[$order[0]], self::success()));
            self::assertSame(FinalizationStatus::Applied, $rig->record($policy, $probes[$order[1]], self::success()));
            self::assertSame(CircuitPermitKind::ClosedSample, $rig->permit($policy)->kind);
        }
    }

    public function testNoCloseWhileAnAdmittedProbeIsUnresolvedAndNoNewProbeAfterThreshold(): void
    {
        $rig = $this->rig();
        $policy = ContractCircuitRig::countPolicy(minimum: 2, probeCapacity: 3, threshold: 2);
        $this->trip($rig, $policy);
        $rig->advance(30 * self::S);
        $a = $rig->permit($policy);
        $b = $rig->permit($policy);
        $c = $rig->permit($policy);

        $rig->record($policy, $a, self::success());
        $rig->record($policy, $b, self::success());
        // Threshold reached but c is unresolved: still HalfOpen and admission has stopped.
        self::assertSame(CircuitAdmissionStatus::ProbeCapacityExhausted, $rig->status($policy));
        self::assertSame(FinalizationStatus::Applied, $rig->abandon($policy, $c));
        // The abandoned probe gave no credit but the already-recorded successes now close.
        self::assertSame(CircuitPermitKind::ClosedSample, $rig->permit($policy)->kind);
    }

    public function testUnresolvedProbeFailureAfterThresholdStillReopens(): void
    {
        $rig = $this->rig();
        $policy = ContractCircuitRig::countPolicy(minimum: 2, probeCapacity: 2, threshold: 1);
        $this->trip($rig, $policy);
        $rig->advance(30 * self::S);
        $a = $rig->permit($policy);
        $b = $rig->permit($policy);
        $rig->record($policy, $a, self::success());
        self::assertSame(CircuitAdmissionStatus::ProbeCapacityExhausted, $rig->status($policy));
        $rig->record($policy, $b, self::failure());

        self::assertSame(CircuitAdmissionStatus::Open, $rig->status($policy));
    }

    public function testAbandonedAndNeutralProbesFreeOwnershipWithoutRecoveryCredit(): void
    {
        $rig = $this->rig();
        $policy = ContractCircuitRig::countPolicy(minimum: 2);
        $this->trip($rig, $policy);
        $rig->advance(30 * self::S);

        $abandoned = $rig->permit($policy);
        self::assertSame(CircuitAdmissionStatus::ProbeCapacityExhausted, $rig->status($policy));
        self::assertSame(FinalizationStatus::Applied, $rig->abandon($policy, $abandoned));
        $neutral = $rig->permit($policy);
        self::assertSame(CircuitPermitKind::HalfOpenProbe, $neutral->kind, 'replacement probe, not Closed');
        self::assertSame(FinalizationStatus::Applied, $rig->record($policy, $neutral, CircuitVerdict::Neutral));
        $success = $rig->permit($policy);
        self::assertSame(CircuitPermitKind::HalfOpenProbe, $success->kind);
        self::assertSame(FinalizationStatus::Applied, $rig->record($policy, $success, self::success()));
        self::assertSame(CircuitPermitKind::ClosedSample, $rig->permit($policy)->kind);
    }

    public function testExpiredProbeFreesOwnershipAtLeaseEquality(): void
    {
        $rig = $this->rig();
        $policy = ContractCircuitRig::countPolicy(minimum: 2);
        $this->trip($rig, $policy);
        $rig->advance(30 * self::S);
        $probe = $rig->permit($policy);

        $rig->advance(10 * self::S - $rig->step());
        self::assertSame(CircuitAdmissionStatus::ProbeCapacityExhausted, $rig->status($policy), 'still valid one tick before expiry');
        $rig->advance($rig->step());
        $replacement = $rig->acquire($policy);
        self::assertSame(CircuitAdmissionStatus::Accepted, $replacement->status);
        self::assertSame(CircuitPermitKind::HalfOpenProbe, $replacement->permit?->kind);
        self::assertSame(FinalizationStatus::StaleOwnership, $rig->record($policy, $probe, self::success()), 'expired at equality');
        // The expired probe gave no recovery credit: the circuit is not Closed.
        self::assertSame(CircuitAdmissionStatus::ProbeCapacityExhausted, $rig->status($policy));
    }

    public function testStaleGenerationOutcomeCannotChangeCurrentState(): void
    {
        $rig = $this->rig();
        $policy = ContractCircuitRig::countPolicy(minimum: 2);
        $straggler = $rig->permit($policy);
        $this->trip($rig, $policy);
        $rig->advance(30 * self::S);
        $rig->record($policy, $rig->permit($policy), self::success());
        self::assertSame(CircuitAdmissionStatus::Accepted, $rig->status($policy), 'closed again');

        self::assertSame(FinalizationStatus::StaleOwnership, $rig->record($policy, $straggler, self::failure()));
        $rig->complete($policy, self::failure());
        self::assertSame(CircuitAdmissionStatus::Accepted, $rig->status($policy), 'straggler failure was ignored: 1 sample < 2');
    }

    public function testStaleClosedPermitCannotAffectOpenCircuit(): void
    {
        $rig = $this->rig();
        $policy = ContractCircuitRig::countPolicy(minimum: 2);
        $straggler = $rig->permit($policy);
        $this->trip($rig, $policy);

        self::assertSame(FinalizationStatus::StaleOwnership, $rig->record($policy, $straggler, self::success()));
        self::assertSame(FinalizationStatus::StaleOwnership, $rig->abandon($policy, $straggler));
        self::assertSame(CircuitAdmissionStatus::Open, $rig->status($policy));
    }

    public function testDuplicateRecordAndAbandonDoNotDoubleCount(): void
    {
        $rig = $this->rig();
        $policy = ContractCircuitRig::countPolicy(minimum: 2);
        $permit = $rig->permit($policy);

        self::assertSame(FinalizationStatus::Applied, $rig->record($policy, $permit, self::failure()));
        self::assertSame(FinalizationStatus::AlreadyFinalized, $rig->record($policy, $permit, self::failure()));
        self::assertSame(FinalizationStatus::AlreadyFinalized, $rig->record($policy, $permit, self::failure()));
        self::assertSame(FinalizationStatus::AlreadyFinalized, $rig->abandon($policy, $permit));
        self::assertSame(CircuitAdmissionStatus::Accepted, $rig->status($policy), 'a double counted failure would have opened (2 of 2)');

        $abandoned = $rig->permit($policy);
        self::assertSame(FinalizationStatus::Applied, $rig->abandon($policy, $abandoned));
        self::assertSame(FinalizationStatus::AlreadyFinalized, $rig->record($policy, $abandoned, self::failure()));
    }

    public function testDuplicateAfterTombstoneExpiryIsStaleNotReapplied(): void
    {
        $rig = $this->rig();
        $policy = ContractCircuitRig::countPolicy(minimum: 2);
        $permit = $rig->permit($policy);
        $rig->record($policy, $permit, self::failure());

        $rig->advance(120 * self::S - $rig->step());
        self::assertSame(FinalizationStatus::AlreadyFinalized, $rig->record($policy, $permit, self::failure()));
        $rig->advance($rig->step());
        self::assertSame(FinalizationStatus::StaleOwnership, $rig->record($policy, $permit, self::failure()));
        self::assertSame(CircuitAdmissionStatus::Accepted, $rig->status($policy));
    }

    public function testClosedSamplePermitExpiryIsNeutralAtEquality(): void
    {
        $rig = $this->rig();
        $policy = ContractCircuitRig::countPolicy(minimum: 1, ratioNumerator: 1, ratioDenominator: 1);
        $valid = $rig->permit($policy);
        $expired = $rig->permit($policy);

        $rig->advance(10 * self::S - $rig->step());
        self::assertSame(FinalizationStatus::Applied, $rig->record($policy, $valid, self::success()));
        $rig->advance($rig->step());
        self::assertSame(FinalizationStatus::StaleOwnership, $rig->record($policy, $expired, self::failure()));
        self::assertSame(CircuitAdmissionStatus::Accepted, $rig->status($policy), 'expired failure was not counted');
    }

    public function testTamperedPermitFieldsAreStale(): void
    {
        $rig = $this->rig();
        $policy = ContractCircuitRig::countPolicy(minimum: 2);
        $permit = $rig->permit($policy);
        $other = $rig->permit($policy);
        $forged = new \Enaxon\Reqtide\State\CircuitPermit(
            $permit->policy,
            $permit->incarnation,
            $permit->generation,
            $other->attempt,
            $permit->owner,
            $permit->kind,
            $permit->lease,
            $permit->expiresAt,
        );

        self::assertSame(FinalizationStatus::StaleOwnership, $rig->record($policy, $forged, self::failure()));
        self::assertSame(FinalizationStatus::Applied, $rig->record($policy, $permit, self::success()));
    }

    public function testConflictingSettingsForOneIdentityAreRejectedBeforeMutation(): void
    {
        $rig = $this->rig();
        $rig->permit(ContractCircuitRig::countPolicy(minimum: 2));

        $this->expectException(InvalidConfigurationException::class);
        $rig->acquire(ContractCircuitRig::countPolicy(minimum: 3));
    }

    public function testRecordCeilingRejectsAcquireButAlwaysAllowsFinalization(): void
    {
        $rig = $this->rig(maxRecords: 2);
        $policy = ContractCircuitRig::countPolicy(minimum: 5);
        $a = $rig->permit($policy);
        $b = $rig->permit($policy);
        try {
            $rig->acquire($policy);
            self::fail('Expected StateCapacityExceededException.');
        } catch (StateCapacityExceededException) {
            // The ceiling counts owners.
        }
        self::assertSame(FinalizationStatus::Applied, $rig->record($policy, $a, self::success()), 'finalization needs no new slot');
        self::assertSame(FinalizationStatus::Applied, $rig->abandon($policy, $b));
        // Tombstones are nonprotective: pressure evicts the OLDEST one (a's) instead of rejecting the allocation.
        $c = $rig->permit($policy);
        self::assertSame(FinalizationStatus::StaleOwnership, $rig->record($policy, $a, self::success()), 'evicted tombstone => stale, never reapplied');
        self::assertSame(FinalizationStatus::AlreadyFinalized, $rig->abandon($policy, $b), 'newer tombstone survives');
        self::assertSame(FinalizationStatus::Applied, $rig->abandon($policy, $c));
        $rig->advance(120 * self::S);
        self::assertSame(CircuitAdmissionStatus::Accepted, $rig->status($policy));
    }
    public function testOnePartitionCannotTakeTheLastQuarterOfTheRecordCeiling(): void
    {
        // Ceiling 8: one partition may hold 8 - floor(8 / 4) = 6 active permits; the rest stays for the others.
        $rig = $this->rig(maxRecords: 8);
        $slow = ContractCircuitRig::countPolicy(minimum: 100, identity: ContractIdentities::identity('slow'));
        $other = ContractCircuitRig::countPolicy(minimum: 100, identity: ContractIdentities::identity('other'));
        $held = [];
        for ($i = 0; $i < 6; ++$i) {
            $held[] = $rig->permit($slow);
        }
        try {
            $rig->acquire($slow);
            self::fail('Expected StateCapacityExceededException for the partition that holds three quarters of the ceiling.');
        } catch (StateCapacityExceededException) {
            // Refused for this partition only.
        }

        $rig->permit($other);
        $rig->permit($other);

        self::assertSame(FinalizationStatus::Applied, $rig->record($slow, $held[0], self::success()));
        $rig->permit($slow);
    }

    private function trip(ContractCircuitRig $rig, CircuitPolicy $policy): void
    {
        $rig->complete($policy, self::failure());
        $rig->complete($policy, self::failure());
        self::assertSame(CircuitAdmissionStatus::Open, $rig->status($policy));
    }

    public function testRecreatedPartitionHasANewIncarnationAndRejectsOldPermits(): void
    {
        $rig = $this->rig();
        $policy = ContractCircuitRig::timePolicy(minimum: 2);
        $old = $rig->permit($policy);
        $rig->record($policy, $old, CircuitVerdict::Neutral);
        $rig->advance(121 * self::S);
        $rig->reclaim();

        $fresh = $rig->permit($policy);
        self::assertSame(1, $fresh->generation->value);
        self::assertSame(1, $old->generation->value);
        self::assertFalse($fresh->incarnation->equals($old->incarnation));
        self::assertSame(FinalizationStatus::StaleOwnership, $rig->record($policy, $old, self::failure()));
        self::assertSame(FinalizationStatus::StaleOwnership, $rig->abandon($policy, $old));
        self::assertSame(FinalizationStatus::Applied, $rig->record($policy, $fresh, self::success()));
    }

    public function testCountHistorySurvivesIdlenessAndMaintenance(): void
    {
        $rig = $this->rig();
        $policy = ContractCircuitRig::countPolicy(window: 4, minimum: 4, ratioNumerator: 1, ratioDenominator: 2);
        $rig->complete($policy, self::failure());
        $rig->complete($policy, self::failure());
        $rig->complete($policy, self::success());

        $rig->advance(3_600 * self::S);
        for ($i = 0; $i < 5; ++$i) {
            $rig->reclaim();
        }
        $rig->complete($policy, self::success()); // 2 failures in 4 samples

        self::assertSame(CircuitAdmissionStatus::Open, $rig->status($policy));
    }

    public function testOpenAndHalfOpenStateNeverExpiresFromInactivity(): void
    {
        $rig = $this->rig();
        $policy = ContractCircuitRig::countPolicy(minimum: 2);
        $this->trip($rig, $policy);
        $rig->advance(60 * self::S);
        $rig->reclaim();
        $probe = $rig->permit($policy); // half open
        $rig->record($policy, $probe, CircuitVerdict::Neutral);
        $rig->advance(86_400 * self::S);
        $rig->reclaim();

        $next = $rig->acquire($policy);
        self::assertSame(CircuitAdmissionStatus::Accepted, $next->status);
        self::assertSame(CircuitPermitKind::HalfOpenProbe, $next->permit?->kind, 'still HalfOpen, never silently closed');
    }
}
