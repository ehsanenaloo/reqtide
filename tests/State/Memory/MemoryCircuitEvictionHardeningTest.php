<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\State\Memory;

use Enaxon\Reqtide\Exception\StateCapacityExceededException;
use Enaxon\Reqtide\Outcome\CircuitVerdict;
use Enaxon\Reqtide\Policy\CircuitPolicy;
use Enaxon\Reqtide\State\CircuitAdmissionStatus;
use Enaxon\Reqtide\State\CircuitPermit;
use Enaxon\Reqtide\State\CircuitPermitKind;
use Enaxon\Reqtide\State\FinalizationStatus;
use Enaxon\Reqtide\Tests\Support\Fixtures;
use Enaxon\Reqtide\Value\AttemptId;
use Enaxon\Reqtide\Value\BackendExpiry;
use PHPUnit\Framework\TestCase;

/** Owner-field checks, maintenance budget on tombstones, and which tombstones survive capacity pressure. */
final class MemoryCircuitEvictionHardeningTest extends TestCase
{
    private const SECOND = CircuitRig::SECOND;

    public function testAPermitThatDiffersInAnyFieldFromTheIssuedOneIsStaleAndLeavesTheOwnerUntouched(): void
    {
        $rig = new CircuitRig();
        $policy = CircuitRig::countPolicy();
        $real = $rig->permit($policy);
        $variants = [
            'attempt' => new CircuitPermit($real->policy, $real->incarnation, $real->generation, AttemptId::fromLowerHex(Fixtures::hex(0xDEAD)), $real->owner, $real->kind, $real->lease, $real->expiresAt),
            'kind' => new CircuitPermit($real->policy, $real->incarnation, $real->generation, $real->attempt, $real->owner, CircuitPermitKind::HalfOpenProbe, $real->lease, $real->expiresAt),
            'generation' => new CircuitPermit($real->policy, $real->incarnation, $real->generation->next(), $real->attempt, $real->owner, $real->kind, $real->lease, $real->expiresAt),
            'expiry' => new CircuitPermit($real->policy, $real->incarnation, $real->generation, $real->attempt, $real->owner, $real->kind, $real->lease, BackendExpiry::local($real->expiresAt->localMonotonicNanoseconds + 1)),
        ];

        foreach ($variants as $field => $forged) {
            self::assertSame(FinalizationStatus::StaleOwnership, $rig->record($policy, $forged, CircuitVerdict::Failure), $field);
            self::assertSame(FinalizationStatus::StaleOwnership, $rig->abandon($policy, $forged), $field . ' (abandon)');
        }
        self::assertSame(FinalizationStatus::Applied, $rig->record($policy, $real, CircuitVerdict::Success), 'the genuine permit still works');
    }

    public function testMaintainSpendsTheBudgetLeftAfterTheVisitOnTombstonesAndNoMore(): void
    {
        $rig = new CircuitRig();
        $policy = CircuitRig::countPolicy();
        $rig->at(100 * self::SECOND);
        for ($i = 0; $i < 3; ++$i) {
            $rig->complete($policy, CircuitVerdict::Success);
        }
        $rig->at(221 * self::SECOND);

        self::assertSame(2, $rig->backend->maintain(3), 'one unit for the visit, two for tombstones');
        self::assertSame(1, $rig->backend->maintain(128));
    }

    public function testResetGivesBackTheRecordsOfTheOwnersItInvalidates(): void
    {
        $rig = new CircuitRig(maxRecords: 3);
        $policy = CircuitRig::countPolicy();
        for ($i = 0; $i < 3; ++$i) {
            $rig->permit($policy);
        }
        self::assertNotNull($rig->backend->reset($policy->identity));

        for ($i = 0; $i < 3; ++$i) {
            $rig->permit($policy);
        }
        $this->expectException(StateCapacityExceededException::class);
        $rig->acquire($policy);
    }

    public function testRoomFreedByAnElapsedTombstoneElsewhereSparesTheOtherProtectiveTombstones(): void
    {
        $rig = new CircuitRig(maxRecords: 2);
        $a = CircuitRig::countPolicy(identity: CircuitRig::identity('a'));
        $b = CircuitRig::countPolicy(identity: CircuitRig::identity('b'));
        $rig->at(100 * self::SECOND);
        $permitA = $rig->permit($a);
        $rig->record($a, $permitA, CircuitVerdict::Success); // tombstone until 220 s
        $rig->at(150 * self::SECOND);
        $permitB = $rig->permit($b);
        $rig->record($b, $permitB, CircuitVerdict::Success); // tombstone until 270 s
        $rig->at(225 * self::SECOND);

        self::assertSame(CircuitAdmissionStatus::Accepted, $rig->acquire(CircuitRig::countPolicy(identity: CircuitRig::identity('c')))->status);

        self::assertSame(FinalizationStatus::AlreadyFinalized, $rig->record($b, $permitB, CircuitVerdict::Success), 'b was not evicted: a had already elapsed');
        self::assertSame(FinalizationStatus::StaleOwnership, $rig->record($a, $permitA, CircuitVerdict::Success), 'a elapsed and was reclaimed');
    }

    public function testEvictionPicksTheOldestTombstoneEvenWhenItsPartitionIsListedLater(): void
    {
        // maxRecords 16 evicts one tombstone per pressure event.
        $rig = new CircuitRig(maxRecords: 16);
        $newer = CircuitRig::countPolicy(leaseSeconds: 100, identity: CircuitRig::identity('newer'));
        $older = CircuitRig::countPolicy(leaseSeconds: 100, identity: CircuitRig::identity('older'));
        // Two filler partitions: one partition may hold at most three quarters of the ceiling in active permits.
        $filler = CircuitRig::countPolicy(leaseSeconds: 1_000, identity: CircuitRig::identity('filler'));
        $filler2 = CircuitRig::countPolicy(leaseSeconds: 1_000, identity: CircuitRig::identity('filler-2'));

        $rig->at(100 * self::SECOND);
        $newerPermit = $rig->permit($newer); // this partition is created (and listed) first
        $rig->at(101 * self::SECOND);
        $olderPermit = $rig->permit($older);
        $rig->at(105 * self::SECOND);
        $rig->record($older, $olderPermit, CircuitVerdict::Success); // oldest tombstone
        $rig->at(110 * self::SECOND);
        $rig->record($newer, $newerPermit, CircuitVerdict::Success);
        for ($i = 0; $i < 14; ++$i) {
            $rig->permit($i % 2 === 0 ? $filler : $filler2);
        }
        // 2 tombstones and 14 owners: 16 records are in use, so the next allocation must evict one tombstone.
        $rig->permit($filler);

        self::assertSame(FinalizationStatus::StaleOwnership, $rig->record($older, $olderPermit, CircuitVerdict::Success), 'the oldest was evicted');
        self::assertSame(FinalizationStatus::AlreadyFinalized, $rig->record($newer, $newerPermit, CircuitVerdict::Success), 'the newer one was kept');
    }

    /** Guards the helper used above: the policy type is the one the rig builds. */
    public function testTheRigBuildsCountPolicies(): void
    {
        self::assertInstanceOf(CircuitPolicy::class, CircuitRig::countPolicy());
    }
}
