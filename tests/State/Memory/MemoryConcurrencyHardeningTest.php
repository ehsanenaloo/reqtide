<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\State\Memory;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Exception\StateCapacityExceededException;
use Enaxon\Reqtide\Policy\ConcurrencyPolicy;
use Enaxon\Reqtide\State\ConcurrencyAdmissionStatus;
use Enaxon\Reqtide\State\ConcurrencyPermit;
use Enaxon\Reqtide\State\FinalizationStatus;
use Enaxon\Reqtide\State\Memory\MemoryConcurrencyBackend;
use Enaxon\Reqtide\State\StateScope;
use Enaxon\Reqtide\Tests\Support\FakeClock;
use Enaxon\Reqtide\Tests\Support\Fixtures;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\IncarnationId;
use Enaxon\Reqtide\Value\PermitOwnerId;
use Enaxon\Reqtide\Value\PositiveInt;
use PHPUnit\Framework\TestCase;

/** Behavioural hardening of the process-local bulkhead: ownership checks, maintenance budget and capacity pressure. */
final class MemoryConcurrencyHardeningTest extends TestCase
{
    private const SECOND = 1_000_000_000;

    private ?FakeClock $fakeClock = null;

    private ?MemoryTestSupport $identities = null;

    private function clock(): FakeClock
    {
        return $this->fakeClock ??= new FakeClock(1_000 * self::SECOND);
    }

    private function ids(): MemoryTestSupport
    {
        return $this->identities ??= new MemoryTestSupport();
    }

    private function backend(int $partitions = 10_000, int $records = 20_000): MemoryConcurrencyBackend
    {
        return new MemoryConcurrencyBackend($this->clock(), $this->ids(), $partitions, $records);
    }

    private function policy(string $subject = 'subject', int $capacity = 100, int $leaseSeconds = 10): ConcurrencyPolicy
    {
        return new ConcurrencyPolicy(MemoryTestSupport::identity($subject), new PositiveInt($capacity), Duration::seconds($leaseSeconds));
    }

    private function acquire(MemoryConcurrencyBackend $backend, ConcurrencyPolicy $policy, ?PermitOwnerId $owner = null): ConcurrencyPermit
    {
        $admission = $backend->acquire($policy, $owner ?? $this->ids()->owner(), $this->ids()->command());
        self::assertSame(ConcurrencyAdmissionStatus::Accepted, $admission->status);
        self::assertNotNull($admission->permit);

        return $admission->permit;
    }

    private function release(MemoryConcurrencyBackend $backend, ConcurrencyPolicy $policy, ConcurrencyPermit $permit): FinalizationStatus
    {
        return $backend->release($policy, $permit, $this->ids()->command())->status;
    }

    private function advance(int $nanoseconds): void
    {
        $this->clock()->now += $nanoseconds;
    }

    public function testCapabilitiesDescribeAProcessLocalNanosecondClockWithNoIoTimeout(): void
    {
        $capabilities = $this->backend()->capabilities();

        self::assertSame(StateScope::ProcessLocal, $capabilities->scope);
        self::assertNull($capabilities->ioTimeout);
        self::assertSame(1, $capabilities->clockResolution->nanoseconds);
    }

    public function testTheInjectedIdentityGeneratorIssuesTheIncarnation(): void
    {
        $permit = $this->acquire($this->backend(), $this->policy());

        self::assertTrue($permit->incarnation->equals(IncarnationId::fromLowerHex(Fixtures::hex(0x1001))));
    }

    public function testASinglePartitionAndASingleRecordAreValidCeilings(): void
    {
        $backend = $this->backend(1, 1);
        $this->acquire($backend, $this->policy());
        $this->expectException(StateCapacityExceededException::class);
        $this->acquire($backend, $this->policy('another'));
    }

    // ---- ownership and settings binding --------------------------------------------------------------

    public function testAPermitOfAnotherPolicyIdentityIsStaleEvenWhenThePartitionExists(): void
    {
        $backend = $this->backend();
        $policyA = $this->policy('a');
        $policyB = $this->policy('b');
        $permitA = $this->acquire($backend, $policyA);
        $this->acquire($backend, $policyB);

        self::assertSame(FinalizationStatus::StaleOwnership, $this->release($backend, $policyB, $permitA));
        self::assertSame(FinalizationStatus::Applied, $this->release($backend, $policyA, $permitA), 'the permit was untouched');
    }

    public function testChangingTheSettingsOfABoundIdentityIsRejectedByEveryOperation(): void
    {
        $backend = $this->backend();
        $bound = $this->policy('s', 2, 10);
        $permit = $this->acquire($backend, $bound);

        foreach ([$this->policy('s', 3, 10), $this->policy('s', 2, 11)] as $index => $other) {
            foreach ([
                'acquire' => fn() => $backend->acquire($other, $this->ids()->owner(), $this->ids()->command()),
                'release' => fn() => $backend->release($other, $permit, $this->ids()->command()),
            ] as $name => $operation) {
                try {
                    $operation();
                    self::fail($name . ' accepted different settings (variant ' . $index . ').');
                } catch (InvalidConfigurationException $e) {
                    self::assertStringContainsString('different concurrency settings', $e->getMessage());
                }
            }
        }
        self::assertSame(FinalizationStatus::Applied, $this->release($backend, $bound, $permit));
    }

    public function testReusingAnOwnerTokenIsRejectedWhileItIsActiveOrRemembered(): void
    {
        $backend = $this->backend();
        $policy = $this->policy();
        $owner = $this->ids()->owner();
        $permit = $this->acquire($backend, $policy, $owner);

        try {
            $backend->acquire($policy, $owner, $this->ids()->command());
            self::fail('An active owner token must not be reused.');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString('owner identity must be unique', $e->getMessage());
        }
        self::assertSame(FinalizationStatus::Applied, $this->release($backend, $policy, $permit));
        $this->expectException(InvalidConfigurationException::class);
        $backend->acquire($policy, $owner, $this->ids()->command());
    }

    // ---- maintenance budget --------------------------------------------------------------------------

    public function testMaintainSpendsItsBudgetAcrossPartitionsAndKeepsGoingAfterADeletion(): void
    {
        $backend = $this->backend();
        $a = $this->policy('a');
        $b = $this->policy('b');
        foreach ([$a, $b] as $policy) {
            $this->acquire($backend, $policy);
            $this->acquire($backend, $policy);
        }
        $this->advance(11 * self::SECOND);

        // Budget three: partition a spends two (and is then empty and deleted), partition b may spend only one.
        self::assertSame(3, $backend->maintain(3));
        self::assertSame(1, $backend->maintain(3));
    }

    public function testMaintainDoesNotSpendBudgetOnTombstonesOnceTheOwnersUsedItUp(): void
    {
        $backend = $this->backend();
        $policy = $this->policy('p', 10, 100);
        $b = $this->acquire($backend, $policy);
        $c = $this->acquire($backend, $policy);
        $this->acquire($backend, $policy); // stays an active owner (lease 100 s)
        $backend->release($policy, $b, $this->ids()->command());
        $backend->release($policy, $c, $this->ids()->command());
        $this->advance(121 * self::SECOND); // both tombstones elapsed; the owner's 100 s lease also ended

        self::assertSame(1, $backend->maintain(1), 'one unit of budget: the owner, and no tombstone');
        self::assertSame(2, $backend->maintain(128), 'then the two tombstones');
    }

    public function testMaintainCountsOwnersAndTombstonesTogether(): void
    {
        $backend = $this->backend(10, 4);
        $policy = $this->policy('p', 10, 10);
        $released = $this->acquire($backend, $policy);
        $backend->release($policy, $released, $this->ids()->command());
        $this->acquire($backend, $policy);
        $this->advance(121 * self::SECOND);

        self::assertSame(2, $backend->maintain());
        // Both records were given back to the ceiling: four fit again.
        for ($i = 0; $i < 4; ++$i) {
            $this->acquire($backend, $this->policy('q' . $i));
        }
        $this->expectException(StateCapacityExceededException::class);
        $this->acquire($backend, $this->policy('q5'));
    }

    public function testMaintainRejectsABudgetBelowOneAndCapsOneAboveTheMaximum(): void
    {
        $backend = $this->backend();
        self::assertSame(0, $backend->maintain(100_000), 'a larger budget is capped, not rejected');
        foreach ([0, -1] as $bad) {
            try {
                $backend->maintain($bad);
                self::fail('maxRecords ' . $bad . ' must be rejected.');
            } catch (InvalidConfigurationException $e) {
                self::assertStringContainsString('must be at least one', $e->getMessage());
            }
        }
        self::assertSame(0, $backend->maintain(1));
    }

    // ---- capacity pressure ---------------------------------------------------------------------------

    public function testRoomIsReclaimedFromExpiredOwnersWithoutEvictingAProtectiveTombstone(): void
    {
        $backend = $this->backend(10, 3);
        $policy = $this->policy();
        $finalized = $this->acquire($backend, $policy);
        $backend->release($policy, $finalized, $this->ids()->command());
        $this->acquire($backend, $policy);
        $this->acquire($backend, $policy);
        $this->advance(11 * self::SECOND); // both owners expired, the tombstone lives on

        // Allocating in ANOTHER partition: maintenance frees the expired owners instead of evicting the tombstone.
        $this->acquire($backend, $this->policy('other'));

        self::assertSame(FinalizationStatus::AlreadyFinalized, $this->release($backend, $policy, $finalized));
    }

    public function testEvictionDropsTheOldestTombstonesFirstAcrossAndWithinPartitions(): void
    {
        // maxRecords 32 evicts in batches of two.
        $backend = $this->backend(10, 32);
        $p = $this->policy('p', 100, 1_000);
        $q = $this->policy('q', 100, 1_000);
        $filler = $this->policy('filler', 100, 1_000);

        $p1 = $this->acquire($backend, $p);
        $p2 = $this->acquire($backend, $p);
        $q1 = $this->acquire($backend, $q);
        $backend->release($p, $p1, $this->ids()->command());
        $this->advance(1 * self::SECOND);
        $backend->release($p, $p2, $this->ids()->command());
        $this->advance(1 * self::SECOND);
        $backend->release($q, $q1, $this->ids()->command());
        for ($i = 0; $i < 29; ++$i) {
            $this->acquire($backend, $filler);
        }
        // 32 records: three tombstones and 29 active owners. The next allocation evicts two tombstones.
        $this->acquire($backend, $filler);

        self::assertSame(FinalizationStatus::StaleOwnership, $this->release($backend, $p, $p1), 'oldest evicted');
        self::assertSame(FinalizationStatus::StaleOwnership, $this->release($backend, $p, $p2), 'second oldest evicted although it lives in the same partition');
        self::assertSame(FinalizationStatus::AlreadyFinalized, $this->release($backend, $q, $q1), 'newest kept');
    }

    public function testEvictingTheLastTombstoneOfAPartitionNeverDeletesItsActiveOwners(): void
    {
        $backend = $this->backend(10, 32);
        $p = $this->policy('p', 100, 1_000);
        $filler = $this->policy('filler', 100, 1_000);

        $done = $this->acquire($backend, $p);
        $active = $this->acquire($backend, $p);
        $backend->release($p, $done, $this->ids()->command());
        for ($i = 0; $i < 30; ++$i) {
            $this->acquire($backend, $filler);
        }
        // 32 records are in use: the next allocation must evict $done's tombstone.
        $this->acquire($backend, $filler);

        self::assertSame(FinalizationStatus::StaleOwnership, $this->release($backend, $p, $done));
        self::assertSame(FinalizationStatus::Applied, $this->release($backend, $p, $active), 'the active owner survived the eviction');
    }

    public function testAPartitionLeftEmptyByEvictionIsDeletedButTheOneBeingAllocatedInIsKept(): void
    {
        $backend = $this->backend(10, 32);
        $gone = $this->policy('gone', 100, 1_000);
        $kept = $this->policy('kept', 100, 1_000);
        $filler = $this->policy('filler', 100, 1_000);

        $oldGone = $this->acquire($backend, $gone);
        $backend->release($gone, $oldGone, $this->ids()->command());
        $oldKept = $this->acquire($backend, $kept);
        $backend->release($kept, $oldKept, $this->ids()->command());
        for ($i = 0; $i < 30; ++$i) {
            $this->acquire($backend, $filler);
        }
        self::assertCount(3, self::partitions($backend));

        // 32 records: the next allocation, into "kept", evicts the two oldest tombstones.
        $permit = $this->acquire($backend, $kept);

        self::assertTrue($permit->incarnation->equals($oldKept->incarnation), 'the partition being allocated in is not deleted');
        self::assertCount(2, self::partitions($backend), 'the emptied partition "gone" was removed');
    }

    /** @return array<array-key, mixed> */
    private static function partitions(MemoryConcurrencyBackend $backend): array
    {
        $value = (new \ReflectionProperty($backend, 'partitions'))->getValue($backend);
        self::assertIsArray($value);

        return $value;
    }

    public function testThePartitionCeilingIsExactAndReclaimsOnlyEmptyPartitions(): void
    {
        $backend = $this->backend(2, 100);
        $this->acquire($backend, $this->policy('p1'));
        $this->acquire($backend, $this->policy('p2'));

        try {
            $this->acquire($backend, $this->policy('p3'));
            self::fail('A third partition must not fit under a ceiling of two.');
        } catch (StateCapacityExceededException $e) {
            self::assertStringContainsString('registry is at capacity', $e->getMessage());
        }

        $this->advance(130 * self::SECOND); // leases ended and tombstones elapsed: both are empty and reclaimable
        $this->acquire($backend, $this->policy('p3'));
    }
}
