<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\State\Memory;

use Enaxon\Reqtide\Exception\StateCapacityExceededException;
use Enaxon\Reqtide\Outcome\CircuitVerdict;
use Enaxon\Reqtide\Policy\CircuitPolicy;
use Enaxon\Reqtide\Policy\ConcurrencyPolicy;
use Enaxon\Reqtide\Policy\RetryBudgetPolicy;
use Enaxon\Reqtide\State\CircuitAdmissionStatus;
use Enaxon\Reqtide\State\CircuitPermit;
use Enaxon\Reqtide\State\ConcurrencyAdmissionStatus;
use Enaxon\Reqtide\State\ConcurrencyPermit;
use Enaxon\Reqtide\State\FinalizationStatus;
use Enaxon\Reqtide\State\Memory\MemoryConcurrencyBackend;
use Enaxon\Reqtide\State\Memory\MemoryRetryBudgetBackend;
use Enaxon\Reqtide\State\RetryBudgetFinalizationStatus;
use Enaxon\Reqtide\State\RetryBudgetReservation;
use Enaxon\Reqtide\State\RetryReservationStatus;
use Enaxon\Reqtide\Tests\Support\FakeClock;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\PositiveInt;
use PHPUnit\Framework\TestCase;

/**
 * Long-running workers must not exhaust the record ceiling with finalization tombstones: capacity pressure reclaims
 * expired records and then evicts the OLDEST nonprotective tombstones, never protective state.
 */
final class TombstoneEvictionTest extends TestCase
{
    private const SECOND = 1_000_000_000;

    /** More cycles than the default 20,000-record ceiling, finishing inside the 120 s tombstone retention. */
    private const CYCLES = 25_000;

    private const STEP = 4_000_000;

    private function concurrencyPolicy(string $subject, int $leaseSeconds = 5, int $capacity = 1): ConcurrencyPolicy
    {
        return new ConcurrencyPolicy(MemoryTestSupport::identity($subject), new PositiveInt($capacity), Duration::seconds($leaseSeconds));
    }

    private function acquire(MemoryConcurrencyBackend $backend, MemoryTestSupport $ids, ConcurrencyPolicy $policy): ConcurrencyPermit
    {
        $admission = $backend->acquire($policy, $ids->owner(), $ids->command());
        self::assertSame(ConcurrencyAdmissionStatus::Accepted, $admission->status);
        self::assertNotNull($admission->permit);

        return $admission->permit;
    }

    private function records(object $backend): int
    {
        $value = (new \ReflectionProperty($backend, 'records'))->getValue($backend);
        self::assertIsInt($value);

        return $value;
    }

    // ---- concurrency -------------------------------------------------------------------------------

    public function testConcurrencyChurnInsideRetentionNeverExhaustsRecords(): void
    {
        $clock = new FakeClock(0);
        $ids = new MemoryTestSupport();
        $backend = new MemoryConcurrencyBackend($clock, $ids);
        $policy = $this->concurrencyPolicy('churn');
        $guardPolicy = $this->concurrencyPolicy('guard', 300);
        $guard = $this->acquire($backend, $ids, $guardPolicy);

        $first = null;
        $last = null;
        for ($i = 0; $i < self::CYCLES; ++$i) {
            $permit = $this->acquire($backend, $ids, $policy);
            $first ??= $permit;
            $last = $permit;
            if ($backend->release($policy, $permit, $ids->command())->status !== FinalizationStatus::Applied) {
                self::fail('Release must always apply and never need a new slot (cycle ' . $i . ').');
            }
            $clock->now += self::STEP;
        }

        self::assertLessThan(120 * self::SECOND, $clock->now, 'the whole churn fits inside the tombstone retention');
        self::assertLessThanOrEqual(20_000, $this->records($backend));
        self::assertSame(FinalizationStatus::StaleOwnership, $backend->release($policy, $first, $ids->command())->status, 'evicted tombstone => stale');
        self::assertSame(FinalizationStatus::AlreadyFinalized, $backend->release($policy, $last, $ids->command())->status, 'newest tombstone survives');
        self::assertSame(FinalizationStatus::Applied, $backend->release($guardPolicy, $guard, $ids->command())->status, 'an active owner is never evicted');
    }

    public function testConcurrencyStalePermitCannotFreeANewerHoldersSlotAfterEviction(): void
    {
        $clock = new FakeClock(0);
        $ids = new MemoryTestSupport();
        $backend = new MemoryConcurrencyBackend($clock, $ids, 10, 3);
        $policy = $this->concurrencyPolicy('shared');

        $old = $this->acquire($backend, $ids, $policy);
        self::assertSame(FinalizationStatus::Applied, $backend->release($policy, $old, $ids->command())->status);
        for ($i = 0; $i < 2; ++$i) {
            $clock->now += self::SECOND;
            $filler = $this->acquire($backend, $ids, $policy);
            $backend->release($policy, $filler, $ids->command());
        }
        // Three tombstones fill the ceiling; the next acquire evicts the oldest (old).
        $clock->now += self::SECOND;
        $holder = $this->acquire($backend, $ids, $policy);

        self::assertSame(FinalizationStatus::StaleOwnership, $backend->release($policy, $old, $ids->command())->status);
        self::assertSame(
            ConcurrencyAdmissionStatus::CapacityExhausted,
            $backend->acquire($policy, $ids->owner(), $ids->command())->status,
            'the stale release did not free the holder slot',
        );
        self::assertSame(FinalizationStatus::Applied, $backend->release($policy, $holder, $ids->command())->status);
    }

    public function testConcurrencyEvictsGloballyOldestTombstoneAcrossPartitions(): void
    {
        $clock = new FakeClock(0);
        $ids = new MemoryTestSupport();
        $backend = new MemoryConcurrencyBackend($clock, $ids, 10, 3);
        $p1 = $this->concurrencyPolicy('one');
        $p2 = $this->concurrencyPolicy('two');

        $o1 = $this->acquire($backend, $ids, $p1);
        $backend->release($p1, $o1, $ids->command());
        $clock->now += 10 * self::SECOND;
        $o2 = $this->acquire($backend, $ids, $p2);
        $backend->release($p2, $o2, $ids->command());
        $clock->now += 10 * self::SECOND;
        $o3 = $this->acquire($backend, $ids, $p1);
        $backend->release($p1, $o3, $ids->command());
        $clock->now += self::SECOND;

        $this->acquire($backend, $ids, $p2);

        self::assertSame(FinalizationStatus::StaleOwnership, $backend->release($p1, $o1, $ids->command())->status, 'oldest overall is evicted');
        self::assertSame(FinalizationStatus::AlreadyFinalized, $backend->release($p2, $o2, $ids->command())->status);
        self::assertSame(FinalizationStatus::AlreadyFinalized, $backend->release($p1, $o3, $ids->command())->status);
    }

    public function testConcurrencyActiveOwnersAreNeverEvictedForCapacity(): void
    {
        $clock = new FakeClock(0);
        $ids = new MemoryTestSupport();
        $backend = new MemoryConcurrencyBackend($clock, $ids, 10, 2);
        $policy = $this->concurrencyPolicy('busy', 100, 5);
        $this->acquire($backend, $ids, $policy);
        $this->acquire($backend, $ids, $policy);

        $this->expectException(StateCapacityExceededException::class);
        $backend->acquire($policy, $ids->owner(), $ids->command());
    }

    public function testConcurrencyEvictionFreesPartitionCeilingByDroppingEmptiedPartitions(): void
    {
        $clock = new FakeClock(0);
        $ids = new MemoryTestSupport();
        $backend = new MemoryConcurrencyBackend($clock, $ids, 2, 100);
        foreach (['a', 'b'] as $subject) {
            $policy = $this->concurrencyPolicy($subject);
            $backend->release($policy, $this->acquire($backend, $ids, $policy), $ids->command());
            $clock->now += self::SECOND;
        }

        // Both partitions hold only tombstones; a third subject must still be admitted.
        $third = $this->concurrencyPolicy('c');
        self::assertTrue($this->acquire($backend, $ids, $third)->policy->equals($third->identity));
    }

    // ---- retry budget ------------------------------------------------------------------------------

    private function budgetPolicy(string $subject = 'budget', int $capacity = 100, int $leaseSeconds = 5): RetryBudgetPolicy
    {
        return new RetryBudgetPolicy(
            MemoryTestSupport::identity($subject),
            new PositiveInt($capacity),
            new PositiveInt(10),
            Duration::seconds(60),
            Duration::seconds($leaseSeconds),
        );
    }

    private function reserve(MemoryRetryBudgetBackend $backend, MemoryTestSupport $ids, RetryBudgetPolicy $policy): RetryBudgetReservation
    {
        $decision = $backend->reserve($policy, $ids->attempt(), $ids->owner(), $ids->command());
        self::assertSame(RetryReservationStatus::Reserved, $decision->status);
        self::assertNotNull($decision->reservation);

        return $decision->reservation;
    }

    public function testRetryBudgetChurnInsideRetentionNeverExhaustsRecords(): void
    {
        $clock = new FakeClock(0);
        $ids = new MemoryTestSupport();
        $backend = new MemoryRetryBudgetBackend($clock, $ids);
        $policy = $this->budgetPolicy();
        $guardPolicy = $this->budgetPolicy('guard', 5, 300);
        $guard = $this->reserve($backend, $ids, $guardPolicy);

        $first = null;
        $last = null;
        for ($i = 0; $i < self::CYCLES; ++$i) {
            $reservation = $this->reserve($backend, $ids, $policy);
            $first ??= $reservation;
            $last = $reservation;
            if ($backend->release($policy, $reservation, $ids->command())->status !== RetryBudgetFinalizationStatus::Released) {
                self::fail('Release must always apply and never need a new slot (cycle ' . $i . ').');
            }
            $clock->now += self::STEP;
        }

        self::assertLessThan(120 * self::SECOND, $clock->now);
        self::assertLessThanOrEqual(20_000, $this->records($backend));
        self::assertSame(RetryBudgetFinalizationStatus::StaleOwnership, $backend->commit($policy, $first, $ids->command())->status, 'evicted tombstone must never allow a late commit');
        self::assertSame(RetryBudgetFinalizationStatus::AlreadyReleased, $backend->release($policy, $last, $ids->command())->status);
        self::assertSame(RetryBudgetFinalizationStatus::Committed, $backend->commit($guardPolicy, $guard, $ids->command())->status, 'a pending reservation is never evicted');
    }

    public function testRetryBudgetEvictedTombstoneNeverRefundsOrCommitsTwice(): void
    {
        $clock = new FakeClock(0);
        $ids = new MemoryTestSupport();
        $backend = new MemoryRetryBudgetBackend($clock, $ids, 10, 2);
        $policy = $this->budgetPolicy('tight', 10);

        $a = $this->reserve($backend, $ids, $policy);
        self::assertSame(RetryBudgetFinalizationStatus::Committed, $backend->commit($policy, $a, $ids->command())->status);
        $clock->now += self::SECOND;
        $b = $this->reserve($backend, $ids, $policy);
        $backend->release($policy, $b, $ids->command());
        $before = $backend->snapshot($policy);

        $clock->now += self::SECOND;
        $this->reserve($backend, $ids, $policy); // evicts a's tombstone (the oldest)
        self::assertSame(RetryBudgetFinalizationStatus::StaleOwnership, $backend->commit($policy, $a, $ids->command())->status);
        self::assertSame(RetryBudgetFinalizationStatus::StaleOwnership, $backend->release($policy, $a, $ids->command())->status);
        $after = $backend->snapshot($policy);

        self::assertNotNull($before);
        self::assertNotNull($after);
        self::assertSame($before['pending'] + 1_000_000, $after['pending'], 'only the new reservation is pending; the stale calls changed nothing');
    }

    // ---- circuit -----------------------------------------------------------------------------------

    /** @return array{0: CircuitPolicy, 1: CircuitPermit} */
    private function guardedClosedPartition(CircuitRig $rig): array
    {
        $policy = CircuitRig::countPolicy(window: 10, minimum: 10, leaseSeconds: 300, identity: CircuitRig::identity('guard'));

        return [$policy, $rig->permit($policy)];
    }

    public function testCircuitChurnInsideRetentionNeverExhaustsRecords(): void
    {
        $rig = new CircuitRig();
        $policy = CircuitRig::countPolicy(window: 100, minimum: 100);
        $other = CircuitRig::countPolicy(window: 2, minimum: 2, openSeconds: 300, identity: CircuitRig::identity('other'));
        $rig->complete($other, CircuitVerdict::Failure);
        $rig->complete($other, CircuitVerdict::Failure);
        self::assertSame(CircuitAdmissionStatus::Open, $rig->status($other));
        [$guardPolicy, $guard] = $this->guardedClosedPartition($rig);

        $first = null;
        $last = null;
        for ($i = 0; $i < self::CYCLES; ++$i) {
            $permit = $rig->permit($policy);
            $first ??= $permit;
            $last = $permit;
            if ($rig->record($policy, $permit, CircuitVerdict::Success) !== FinalizationStatus::Applied) {
                self::fail('Finalization must always apply and never need a new slot (cycle ' . $i . ').');
            }
            $rig->advance(self::STEP);
        }

        self::assertLessThan(120 * self::SECOND, $rig->clock->now);
        self::assertLessThanOrEqual(20_000, $this->records($rig->backend));
        self::assertSame(FinalizationStatus::StaleOwnership, $rig->record($policy, $first, CircuitVerdict::Failure), 'evicted tombstone must never apply a late failure');
        self::assertSame(FinalizationStatus::AlreadyFinalized, $rig->record($policy, $last, CircuitVerdict::Success));
        self::assertSame(CircuitAdmissionStatus::Open, $rig->status($other), 'Open state survives record pressure');
        self::assertSame(FinalizationStatus::Applied, $rig->abandon($guardPolicy, $guard), 'an active owner survives record pressure');
    }

    public function testCircuitActiveOwnersAreNeverEvictedForCapacity(): void
    {
        $rig = new CircuitRig(maxRecords: 2);
        $policy = CircuitRig::countPolicy(minimum: 5);
        $rig->permit($policy);
        $rig->permit($policy);

        $this->expectException(StateCapacityExceededException::class);
        $rig->acquire($policy);
    }
}
