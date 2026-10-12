<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\State\Memory;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Exception\StateCapacityExceededException;
use Enaxon\Reqtide\Outcome\CircuitVerdict;
use Enaxon\Reqtide\Policy\CircuitPolicy;
use Enaxon\Reqtide\Policy\TimeCircuitWindow;
use Enaxon\Reqtide\State\CircuitAdmission;
use Enaxon\Reqtide\State\CircuitAdmissionStatus;
use Enaxon\Reqtide\State\CircuitPermit;
use Enaxon\Reqtide\State\FinalizationStatus;
use Enaxon\Reqtide\Tests\Support\Fixtures;
use Enaxon\Reqtide\Value\AttemptId;
use Enaxon\Reqtide\Value\CommandId;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\PermitOwnerId;
use Enaxon\Reqtide\Value\PositiveInt;
use Enaxon\Reqtide\Value\Ratio;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural hardening of the process-local circuit backend: identity binding of every setting, exact retention and
 * deletion rules of maintenance, record accounting under the ceiling, eviction order, and clock-domain edges.
 */
final class MemoryCircuitHardeningTest extends TestCase
{
    private const SECOND = CircuitRig::SECOND;

    private int $next = 0x9000;

    /** Acquires with explicit attempt and owner tokens, so owner reuse can be exercised. */
    private function acquireAs(CircuitRig $rig, CircuitPolicy $policy, ?int $attempt = null, ?int $owner = null): CircuitAdmission
    {
        $attempt ??= ++$this->next;
        $owner ??= ++$this->next;

        return $rig->backend->acquire(
            $policy,
            AttemptId::fromLowerHex(Fixtures::hex($attempt)),
            PermitOwnerId::fromLowerHex(Fixtures::hex($owner)),
            CommandId::fromLowerHex(Fixtures::hex(++$this->next)),
        );
    }

    private function permitAs(CircuitRig $rig, CircuitPolicy $policy, ?int $attempt = null, ?int $owner = null): CircuitPermit
    {
        $admission = $this->acquireAs($rig, $policy, $attempt, $owner);
        self::assertSame(CircuitAdmissionStatus::Accepted, $admission->status);
        self::assertNotNull($admission->permit);

        return $admission->permit;
    }

    // ---- identity binding ---------------------------------------------------------------------------

    /** @return iterable<string, array{0: CircuitPolicy, 1: CircuitPolicy}> bound policy, conflicting policy */
    public static function conflictingPolicies(): iterable
    {
        $id = CircuitRig::identity('bound');
        $base = static fn(array $override = []): CircuitPolicy => CircuitRig::countPolicy(...array_merge(
            ['window' => 100, 'minimum' => 20, 'ratioNumerator' => 1, 'ratioDenominator' => 2, 'probeCapacity' => 2, 'threshold' => 1, 'openSeconds' => 30, 'leaseSeconds' => 10, 'identity' => $id],
            $override,
        ));
        yield 'count window size' => [$base(), $base(['window' => 101])];
        yield 'minimum samples' => [$base(), $base(['minimum' => 21])];
        yield 'ratio numerator' => [$base(), $base(['ratioNumerator' => 2, 'ratioDenominator' => 2])];
        yield 'ratio denominator' => [$base(), $base(['ratioDenominator' => 3])];
        yield 'probe capacity' => [$base(), $base(['probeCapacity' => 3])];
        yield 'probe success threshold' => [$base(), $base(['threshold' => 2])];
        yield 'open duration' => [$base(), $base(['openSeconds' => 31])];
        yield 'permit lease' => [$base(), $base(['leaseSeconds' => 11])];
        yield 'window kind' => [$base(), CircuitRig::timePolicy(100, 20, 1, 2, 2, 1, 30, 10, $id)];
        yield 'time window length' => [CircuitRig::timePolicy(60, 2, 1, 2, 2, 1, 30, 10, $id), CircuitRig::timePolicy(61, 2, 1, 2, 2, 1, 30, 10, $id)];
        yield 'time bucket granularity' => [
            new CircuitPolicy($id, new TimeCircuitWindow(Duration::seconds(60), Duration::seconds(1)), new PositiveInt(2), Ratio::of(1, 2), Duration::seconds(30), new PositiveInt(1), new PositiveInt(1), Duration::seconds(10)),
            new CircuitPolicy($id, new TimeCircuitWindow(Duration::seconds(60), Duration::seconds(2)), new PositiveInt(2), Ratio::of(1, 2), Duration::seconds(30), new PositiveInt(1), new PositiveInt(1), Duration::seconds(10)),
        ];
    }

    #[DataProvider('conflictingPolicies')]
    public function testChangingAnySettingOfABoundIdentityIsRejectedByEveryOperation(CircuitPolicy $bound, CircuitPolicy $conflicting): void
    {
        $rig = new CircuitRig();
        $permit = $rig->permit($bound);

        foreach ([
            'acquire' => fn() => $this->acquireAs($rig, $conflicting),
            'record' => fn() => $rig->record($conflicting, $permit, CircuitVerdict::Success),
            'abandon' => fn() => $rig->abandon($conflicting, $permit),
        ] as $name => $operation) {
            try {
                $operation();
                self::fail($name . ' accepted different settings for a bound identity.');
            } catch (InvalidConfigurationException $e) {
                self::assertStringContainsString('different circuit settings', $e->getMessage(), $name);
            }
        }
        // The failed calls changed nothing: the bound settings still finalize the original permit.
        self::assertSame(FinalizationStatus::Applied, $rig->record($bound, $permit, CircuitVerdict::Success));
    }

    // ---- maintenance: retention and deletion --------------------------------------------------------

    public function testAnIdleEmptyClosedPartitionIsDeletedExactlyAfterTheRetention(): void
    {
        $rig = new CircuitRig();
        $policy = CircuitRig::countPolicy();
        $rig->at(100 * self::SECOND);
        $incarnation = $rig->permit($policy)->incarnation;

        $rig->at(220 * self::SECOND - 1);
        self::assertSame(1, $rig->backend->maintain(), 'only the lease-expired owner goes: the partition has been idle for 120 s minus 1 ns');
        $rig->at(220 * self::SECOND);
        self::assertSame(1, $rig->backend->maintain(), 'exactly 120 s idle: the partition itself is removed');
        self::assertFalse($rig->permit($policy)->incarnation->equals($incarnation), 'a fresh partition proves deletion');
    }

    public function testRetentionIsMeasuredFromTheLastActivityNotFromTheClockOrigin(): void
    {
        $rig = new CircuitRig();
        $policy = CircuitRig::countPolicy();
        $rig->at(100 * self::SECOND);
        $incarnation = $rig->permit($policy)->incarnation;

        // 60 s idle with a clock far from zero: clock + lastActivity would look like "very idle".
        $rig->at(160 * self::SECOND);
        $rig->backend->maintain();

        self::assertTrue($rig->permit($policy)->incarnation->equals($incarnation));
    }

    public function testAClosedPartitionWithAnActiveOwnerIsNeverDeleted(): void
    {
        $rig = new CircuitRig();
        $policy = CircuitRig::countPolicy(leaseSeconds: 300);
        $rig->at(100 * self::SECOND);
        $permit = $rig->permit($policy);

        $rig->at(399 * self::SECOND);
        self::assertSame(0, $rig->backend->maintain());

        self::assertTrue($rig->permit($policy)->incarnation->equals($permit->incarnation));
        self::assertSame(FinalizationStatus::Applied, $rig->record($policy, $permit, CircuitVerdict::Success));
    }

    public function testAClosedCountWindowKeepsItsHistoryForeverSoThePartitionIsKept(): void
    {
        $rig = new CircuitRig();
        $policy = CircuitRig::countPolicy();
        $rig->at(100 * self::SECOND);
        $rig->complete($policy, CircuitVerdict::Success);
        $incarnation = $rig->permit($policy)->incarnation;

        $rig->at(5_000 * self::SECOND);
        $rig->backend->maintain();

        self::assertTrue($rig->permit($policy)->incarnation->equals($incarnation));
    }

    public function testATimeWindowPartitionIsKeptWhileASampleIsInsideTheWindowAndDeletedWhenItLeaves(): void
    {
        $rig = new CircuitRig();
        $policy = CircuitRig::timePolicy(windowSeconds: 300, minimum: 2);
        $rig->at(100 * self::SECOND);
        $rig->complete($policy, CircuitVerdict::Failure);
        $incarnation = $rig->permit($policy)->incarnation;

        // 230 s: idle for more than 120 s... but the sample (bucket 100) is still inside the 300 s window.
        $rig->at(230 * self::SECOND);
        $rig->backend->maintain();
        self::assertTrue($rig->permit($policy)->incarnation->equals($incarnation));
    }

    public function testATimeWindowSampleLeavesTheWindowAfterExactlyTheWindowLengthPlusItsBucket(): void
    {
        $rig = new CircuitRig();
        $policy = CircuitRig::timePolicy(windowSeconds: 150, minimum: 2);
        $rig->at(100 * self::SECOND);
        $rig->complete($policy, CircuitVerdict::Failure); // bucket 100
        $first = $rig->permit($policy);
        self::assertSame(FinalizationStatus::Applied, $rig->abandon($policy, $first));

        // now = 250 s: cutoff 100 s, bucket 100 is kept (b >= 100). Idle since 100 s: long enough, but the sample lives.
        $rig->at(250 * self::SECOND);
        $rig->backend->maintain();
        self::assertTrue($rig->permit($policy)->incarnation->equals($first->incarnation));

        // The permit just taken moves lastActivity; wait out retention and the bucket in one step.
        $rig->at(250 * self::SECOND + 400 * self::SECOND);
        $rig->backend->maintain();
        self::assertFalse($rig->permit($policy)->incarnation->equals($first->incarnation));
    }

    public function testMaintainSumsTheRecordsItRemovesAcrossPartitions(): void
    {
        $rig = new CircuitRig();
        $a = CircuitRig::countPolicy(identity: CircuitRig::identity('a'));
        $b = CircuitRig::countPolicy(identity: CircuitRig::identity('b'));
        $rig->at(100 * self::SECOND);
        $rig->permit($a);
        $rig->permit($b);
        $rig->at(111 * self::SECOND);

        self::assertSame(2, $rig->backend->maintain());
    }

    public function testMaintainStopsSpendingAnExhaustedBudgetAndResumesOnTheSamePartition(): void
    {
        $rig = new CircuitRig();
        $a = CircuitRig::countPolicy(identity: CircuitRig::identity('a'));
        $b = CircuitRig::countPolicy(identity: CircuitRig::identity('b'));
        $rig->at(100 * self::SECOND);
        foreach ([$a, $b] as $policy) {
            for ($i = 0; $i < 3; ++$i) {
                $rig->permit($policy);
            }
        }
        $rig->at(111 * self::SECOND);

        // Budget 3: one unit visits the first partition, two units remove two of its owners, and the pass ends there.
        self::assertSame(2, $rig->backend->maintain(3));
        // The next pass resumes at the same partition: it removes its last owner and may then only visit the next one.
        self::assertSame(1, $rig->backend->maintain(3));
        self::assertSame(3, $rig->backend->maintain(128));
    }

    public function testMaintainRejectsABudgetOutsideOneToOneHundredTwentyEight(): void
    {
        $rig = new CircuitRig();
        foreach ([0, 129, -1] as $bad) {
            try {
                $rig->backend->maintain($bad);
                self::fail('maxRecords ' . $bad . ' must be rejected.');
            } catch (InvalidConfigurationException $e) {
                self::assertStringContainsString('[1, 128]', $e->getMessage());
            }
        }
        self::assertSame(0, $rig->backend->maintain(1));
        self::assertSame(0, $rig->backend->maintain(128));
    }

    public function testDeletingPartitionsKeepsTheSurvivorsIntactAndTheIndexConsistent(): void
    {
        $rig = new CircuitRig();
        $short = static fn(string $s): CircuitPolicy => CircuitRig::countPolicy(identity: CircuitRig::identity($s));
        $long = static fn(string $s): CircuitPolicy => CircuitRig::countPolicy(leaseSeconds: 1_000, identity: CircuitRig::identity($s));
        $policies = ['a' => $short('a'), 'b' => $long('b'), 'c' => $long('c'), 'd' => $short('d')];
        $rig->at(100 * self::SECOND);
        $incarnations = [];
        foreach ($policies as $name => $policy) {
            $incarnations[$name] = $rig->permit($policy)->incarnation;
        }

        // a and d are idle long enough and empty; b and c each hold an active owner.
        $rig->at(300 * self::SECOND);
        self::assertSame(4, $rig->backend->maintain(), 'two expired owners and two deleted partitions');
        foreach (['b', 'c'] as $name) {
            self::assertTrue($rig->permit($policies[$name])->incarnation->equals($incarnations[$name]), $name . ' survived');
        }
        foreach (['a', 'd'] as $name) {
            self::assertFalse($rig->permit($policies[$name])->incarnation->equals($incarnations[$name]), $name . ' was deleted and recreated');
        }

        // Now everything expires: the swapped-in index entries must still delete the right partitions.
        $rig->at(300 * self::SECOND + 2_000 * self::SECOND);
        $rig->backend->maintain();
        foreach ($policies as $name => $policy) {
            self::assertFalse($rig->permit($policy)->incarnation->equals($incarnations[$name]), $name . ' is gone after full expiry');
        }
    }

    // ---- record accounting and ceilings -------------------------------------------------------------

    public function testATransitionGivesBackTheRecordsOfTheOwnersItInvalidates(): void
    {
        $rig = new CircuitRig(maxRecords: 3);
        $opens = CircuitRig::countPolicy(window: 4, minimum: 1, identity: CircuitRig::identity('x'));
        $other = CircuitRig::countPolicy(identity: CircuitRig::identity('y'));
        $first = $rig->permit($opens);
        $rig->permit($opens);
        $rig->permit($opens);
        self::assertSame(FinalizationStatus::Applied, $rig->record($opens, $first, CircuitVerdict::Failure));
        self::assertSame(CircuitAdmissionStatus::Open, $rig->status($opens));

        // Three records were in use; the two invalidated owners are released, leaving only the tombstone of $first.
        $rig->permit($other);
        $rig->permit($other);
        $rig->permit($other); // the third reuses the room of $first's evicted tombstone
        $this->expectException(StateCapacityExceededException::class);
        $this->acquireAs($rig, $other);
    }

    public function testReusingAFinalizedOwnerTokenDoesNotLeakARecordSlot(): void
    {
        $rig = new CircuitRig(maxRecords: 2);
        $policy = CircuitRig::countPolicy();
        $owner = 0x7001;
        $first = $this->permitAs($rig, $policy, 0x7101, $owner);
        self::assertSame(FinalizationStatus::Applied, $rig->record($policy, $first, CircuitVerdict::Success));
        $second = $this->permitAs($rig, $policy, 0x7102, $owner);
        self::assertSame(FinalizationStatus::Applied, $rig->record($policy, $second, CircuitVerdict::Success));

        // One tombstone remains. A fresh owner fits next to it without any eviction or maintenance failure.
        $fresh = $this->permitAs($rig, $policy);
        // And the tombstone is still there to recognise a duplicate (nothing was evicted for room).
        self::assertSame(FinalizationStatus::AlreadyFinalized, $rig->record($policy, $second, CircuitVerdict::Success));
        self::assertSame(FinalizationStatus::Applied, $rig->record($policy, $fresh, CircuitVerdict::Success));
    }

    public function testRoomIsReclaimedFromExpiredOwnersWithoutEvictingAProtectiveTombstone(): void
    {
        $rig = new CircuitRig(maxRecords: 3);
        $policy = CircuitRig::countPolicy();
        $rig->at(100 * self::SECOND);
        $finalized = $rig->permit($policy);
        self::assertSame(FinalizationStatus::Applied, $rig->record($policy, $finalized, CircuitVerdict::Success));
        $rig->permit($policy);
        $rig->permit($policy);
        $rig->at(111 * self::SECOND); // both owners' 10 s leases have ended

        // At the ceiling, allocating in ANOTHER partition: maintenance frees the two expired owners, so no tombstone
        // has to be sacrificed.
        $this->permitAs($rig, CircuitRig::countPolicy(identity: CircuitRig::identity('other')));

        self::assertSame(FinalizationStatus::AlreadyFinalized, $rig->record($policy, $finalized, CircuitVerdict::Success));
    }

    public function testThePartitionCeilingIsExactAndOnlyIdlePartitionsAreReclaimedForRoom(): void
    {
        $rig = new CircuitRig(maxPartitions: 2);
        $rig->at(100 * self::SECOND);
        $rig->permit(CircuitRig::countPolicy(identity: CircuitRig::identity('p1')));
        $rig->permit(CircuitRig::countPolicy(identity: CircuitRig::identity('p2')));

        try {
            $this->acquireAs($rig, CircuitRig::countPolicy(identity: CircuitRig::identity('p3')));
            self::fail('A third partition must not fit under a ceiling of two.');
        } catch (StateCapacityExceededException $e) {
            self::assertStringContainsString('partition ceiling', $e->getMessage());
        }

        // Once the first two are idle for the retention, creating the third reclaims them.
        $rig->at(100 * self::SECOND + 120 * self::SECOND);
        self::assertSame(CircuitAdmissionStatus::Accepted, $this->acquireAs($rig, CircuitRig::countPolicy(identity: CircuitRig::identity('p3')))->status);
    }

    public function testConstructionRejectsNonPositiveCeilings(): void
    {
        foreach ([[0, 1], [1, 0], [-1, 5], [5, -1]] as [$partitions, $records]) {
            try {
                new CircuitRig($partitions, $records);
                self::fail("ceilings $partitions/$records must be rejected");
            } catch (InvalidConfigurationException $e) {
                self::assertStringContainsString('must be positive', $e->getMessage());
            }
        }
        self::assertInstanceOf(CircuitRig::class, new CircuitRig(1, 1));
    }

    public function testEvictionDropsTheGloballyOldestTombstonesAndKeepsTheNewestOnes(): void
    {
        // maxRecords 32 evicts in batches of two.
        $rig = new CircuitRig(maxRecords: 32);
        $p = CircuitRig::countPolicy(identity: CircuitRig::identity('p'));
        $q = CircuitRig::countPolicy(identity: CircuitRig::identity('q'));
        // Two filler partitions: one partition may hold at most three quarters of the ceiling in active permits.
        $filler = CircuitRig::countPolicy(leaseSeconds: 1_000, identity: CircuitRig::identity('filler'));
        $filler2 = CircuitRig::countPolicy(leaseSeconds: 1_000, identity: CircuitRig::identity('filler-2'));

        $rig->at(100 * self::SECOND);
        $p1 = $rig->permit($p);
        $rig->record($p, $p1, CircuitVerdict::Success);
        $rig->at(101 * self::SECOND);
        $q1 = $rig->permit($q);
        $rig->record($q, $q1, CircuitVerdict::Success);
        $rig->at(102 * self::SECOND);
        $p2 = $rig->permit($p);
        $rig->record($p, $p2, CircuitVerdict::Success);
        for ($i = 0; $i < 29; ++$i) {
            $rig->permit($i % 2 === 0 ? $filler : $filler2);
        }
        // 32 records: three tombstones and 29 active owners. The next allocation must evict two tombstones.
        $rig->permit($filler);

        self::assertSame(FinalizationStatus::StaleOwnership, $rig->record($p, $p1, CircuitVerdict::Success), 'oldest evicted');
        self::assertSame(FinalizationStatus::StaleOwnership, $rig->record($q, $q1, CircuitVerdict::Success), 'second oldest evicted');
        self::assertSame(FinalizationStatus::AlreadyFinalized, $rig->record($p, $p2, CircuitVerdict::Success), 'newest kept');
    }

    // ---- clock-domain edges -------------------------------------------------------------------------

    public function testALeaseThatEndsExactlyAtTheLargestRepresentableTickIsGranted(): void
    {
        $rig = new CircuitRig();
        $policy = CircuitRig::countPolicy();
        $rig->at(PHP_INT_MAX - 10 * self::SECOND);

        $permit = $rig->permit($policy);

        self::assertSame(PHP_INT_MAX, $permit->expiresAt->localMonotonicNanoseconds);
    }

    public function testALeaseThatWouldOverflowTheClockDomainIsRefused(): void
    {
        $rig = new CircuitRig();
        $policy = CircuitRig::countPolicy();
        $rig->at(PHP_INT_MAX - 10 * self::SECOND + 1);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('overflows the monotonic clock domain');
        $rig->acquire($policy);
    }

    // ---- count-window ring --------------------------------------------------------------------------

    public function testTheCountWindowRingRestartsAtTheOldestSlotAfterAReset(): void
    {
        $rig = new CircuitRig();
        $policy = CircuitRig::countPolicy(window: 3, minimum: 3, ratioNumerator: 1, ratioDenominator: 2);
        $identity = $policy->identity;
        $rig->complete($policy, CircuitVerdict::Success);
        $rig->complete($policy, CircuitVerdict::Success);
        $rig->complete($policy, CircuitVerdict::Failure);
        self::assertNotNull($rig->backend->reset($identity));

        // Failure, success, success fill the ring; the next failure replaces the OLDEST outcome (the first failure).
        $rig->complete($policy, CircuitVerdict::Failure);
        $rig->complete($policy, CircuitVerdict::Success);
        $rig->complete($policy, CircuitVerdict::Success);
        $rig->complete($policy, CircuitVerdict::Failure);

        self::assertSame(CircuitAdmissionStatus::Accepted, $rig->status($policy), '1 failure in 3 stays below the 1/2 threshold');
    }

    public function testResetOfAnUnknownPartitionReportsNothing(): void
    {
        self::assertNull((new CircuitRig())->backend->reset(CircuitRig::identity('nobody')));
    }
}
