<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\State\Memory;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Exception\StateCapacityExceededException;
use Enaxon\Reqtide\Policy\RetryBudgetPolicy;
use Enaxon\Reqtide\State\Memory\MemoryRetryBudgetBackend;
use Enaxon\Reqtide\State\RetryBudgetFinalizationStatus;
use Enaxon\Reqtide\State\RetryBudgetReservation;
use Enaxon\Reqtide\State\RetryReservationStatus;
use Enaxon\Reqtide\State\StateScope;
use Enaxon\Reqtide\Tests\Support\FakeClock;
use Enaxon\Reqtide\Tests\Support\Fixtures;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\IncarnationId;
use Enaxon\Reqtide\Value\PermitOwnerId;
use Enaxon\Reqtide\Value\PositiveInt;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural hardening of the process-local retry budget: identity binding of every setting, owner uniqueness,
 * ownership checks on finalization, and the exact accounting and retention rules of maintenance and capacity pressure.
 */
final class MemoryRetryBudgetHardeningTest extends TestCase
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

    private function backend(int $partitions = 10_000, int $records = 20_000): MemoryRetryBudgetBackend
    {
        return new MemoryRetryBudgetBackend($this->clock(), $this->ids(), $partitions, $records);
    }

    private function policy(
        string $subject = 'subject',
        int $capacity = 10,
        int $refill = 10,
        int $intervalNanoseconds = 60 * self::SECOND,
        int $leaseNanoseconds = 10 * self::SECOND,
    ): RetryBudgetPolicy {
        return new RetryBudgetPolicy(
            MemoryTestSupport::identity($subject),
            new PositiveInt($capacity),
            new PositiveInt($refill),
            Duration::nanoseconds($intervalNanoseconds),
            Duration::nanoseconds($leaseNanoseconds),
        );
    }

    private function reserve(MemoryRetryBudgetBackend $backend, RetryBudgetPolicy $policy, ?PermitOwnerId $owner = null): RetryBudgetReservation
    {
        $decision = $backend->reserve($policy, $this->ids()->attempt(), $owner ?? $this->ids()->owner(), $this->ids()->command());
        self::assertSame(RetryReservationStatus::Reserved, $decision->status);
        self::assertNotNull($decision->reservation);

        return $decision->reservation;
    }

    private function advance(int $nanoseconds): void
    {
        $this->clock()->now += $nanoseconds;
    }

    // ---- construction and capabilities --------------------------------------------------------------

    public function testCapabilitiesDescribeAProcessLocalNanosecondClockWithNoIoTimeout(): void
    {
        $capabilities = $this->backend()->capabilities();

        self::assertSame(StateScope::ProcessLocal, $capabilities->scope);
        self::assertNull($capabilities->ioTimeout);
        self::assertSame(1, $capabilities->clockResolution->nanoseconds);
    }

    public function testTheInjectedIdentityGeneratorIssuesTheIncarnation(): void
    {
        $reservation = $this->reserve($this->backend(), $this->policy());

        self::assertTrue($reservation->incarnation->equals(IncarnationId::fromLowerHex(Fixtures::hex(0x1001))));
    }

    // ---- owner uniqueness ---------------------------------------------------------------------------

    public function testReusingTheOwnerOfAPendingReservationIsRejected(): void
    {
        $backend = $this->backend();
        $policy = $this->policy();
        $owner = $this->ids()->owner();
        $this->reserve($backend, $policy, $owner);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('owner identity must be unique');
        $backend->reserve($policy, $this->ids()->attempt(), $owner, $this->ids()->command());
    }

    public function testReusingTheOwnerOfAFinalizedReservationIsRejectedWhileItsTombstoneLives(): void
    {
        $backend = $this->backend();
        $policy = $this->policy();
        $owner = $this->ids()->owner();
        $reservation = $this->reserve($backend, $policy, $owner);
        self::assertSame(RetryBudgetFinalizationStatus::Committed, $backend->commit($policy, $reservation, $this->ids()->command())->status);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('owner identity must be unique');
        $backend->reserve($policy, $this->ids()->attempt(), $owner, $this->ids()->command());
    }

    // ---- identity binding: every setting is part of it ------------------------------------------------

    /** @return iterable<string, array{0: array<string, int>}> */
    public static function conflictingSettings(): iterable
    {
        yield 'capacity' => [['capacity' => 11]];
        yield 'refill tokens' => [['refill' => 11]];
        yield 'refill interval' => [['intervalNanoseconds' => 61 * self::SECOND]];
        yield 'reservation lease' => [['leaseNanoseconds' => 11 * self::SECOND]];
    }

    /** @param array<string, int> $change */
    #[\PHPUnit\Framework\Attributes\DataProvider('conflictingSettings')]
    public function testChangingAnySettingOfABoundIdentityIsRejectedByEveryOperation(array $change): void
    {
        $backend = $this->backend();
        $bound = $this->policy();
        $reservation = $this->reserve($backend, $bound);
        $other = $this->policy('subject', ...array_merge(['capacity' => 10, 'refill' => 10, 'intervalNanoseconds' => 60 * self::SECOND, 'leaseNanoseconds' => 10 * self::SECOND], $change));

        foreach ([
            'inspect' => fn() => $backend->inspect($other),
            'reserve' => fn() => $backend->reserve($other, $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command()),
            'commit' => fn() => $backend->commit($other, $reservation, $this->ids()->command()),
            'release' => fn() => $backend->release($other, $reservation, $this->ids()->command()),
        ] as $name => $operation) {
            try {
                $operation();
                self::fail($name . ' accepted different settings for a bound identity.');
            } catch (InvalidConfigurationException $e) {
                self::assertStringContainsString('different retry budget settings', $e->getMessage(), $name);
            }
        }
        // The bound settings still work and the reservation is untouched by the failed attempts.
        self::assertSame(RetryBudgetFinalizationStatus::Committed, $backend->commit($bound, $reservation, $this->ids()->command())->status);
    }

    /** Settings whose digits concatenate to the same string must still be told apart. */
    public function testNeighbouringSettingsWithTheSameDigitsDoNotCollide(): void
    {
        $pairs = [
            [['capacity' => 1, 'refill' => 23], ['capacity' => 12, 'refill' => 3]],
            [['refill' => 1, 'intervalNanoseconds' => 23], ['refill' => 12, 'intervalNanoseconds' => 3]],
            [['intervalNanoseconds' => 1_000, 'leaseNanoseconds' => 24_000_000_000], ['intervalNanoseconds' => 10_002, 'leaseNanoseconds' => 4_000_000_000]],
        ];
        foreach ($pairs as [$first, $second]) {
            $backend = $this->backend();
            $base = ['capacity' => 10, 'refill' => 10, 'intervalNanoseconds' => 60 * self::SECOND, 'leaseNanoseconds' => 10 * self::SECOND];
            $this->reserve($backend, $this->policy('collide', ...array_merge($base, $first)));

            try {
                $backend->inspect($this->policy('collide', ...array_merge($base, $second)));
                self::fail('Settings ' . json_encode($first) . ' and ' . json_encode($second) . ' collided.');
            } catch (InvalidConfigurationException $e) {
                self::assertStringContainsString('different retry budget settings', $e->getMessage());
            }
        }
    }

    // ---- finalization ownership ---------------------------------------------------------------------

    public function testAReservationOfAnotherPolicyIdentityIsStaleEvenWhenThePartitionExists(): void
    {
        $backend = $this->backend();
        $policyA = $this->policy('a');
        $policyB = $this->policy('b');
        $reservationA = $this->reserve($backend, $policyA);
        $this->reserve($backend, $policyB);

        self::assertSame(RetryBudgetFinalizationStatus::StaleOwnership, $backend->commit($policyB, $reservationA, $this->ids()->command())->status);
        self::assertSame(RetryBudgetFinalizationStatus::StaleOwnership, $backend->release($policyB, $reservationA, $this->ids()->command())->status);
        // The reservation still belongs to A and is intact.
        self::assertSame(RetryBudgetFinalizationStatus::Committed, $backend->commit($policyA, $reservationA, $this->ids()->command())->status);
    }

    public function testAnUnknownPartitionIsStale(): void
    {
        $backend = $this->backend();
        $reservation = $this->reserve($backend, $this->policy('a'));
        $other = $this->backend();

        self::assertSame(RetryBudgetFinalizationStatus::StaleOwnership, $other->commit($this->policy('a'), $reservation, $this->ids()->command())->status);
    }

    // ---- accounting under the record ceiling ---------------------------------------------------------

    public function testReservePrunesElapsedTombstonesAndGivesTheirSlotsBack(): void
    {
        $backend = $this->backend(10, 2);
        $policy = $this->policy();
        $this->backend(); // keep ids deterministic
        $first = $this->reserve($backend, $policy);
        $backend->release($policy, $first, $this->ids()->command());
        $this->advance(121 * self::SECOND);

        // The tombstone is reclaimed by the next reserve, so two concurrent reservations fit in a ceiling of two.
        $this->reserve($backend, $policy);
        $this->reserve($backend, $policy);

        $this->expectException(StateCapacityExceededException::class);
        $this->reserve($backend, $policy);
    }

    public function testMaintainReturnsTheNumberOfTombstonesRemovedAndGivesTheirSlotsBack(): void
    {
        $backend = $this->backend(10, 2);
        $policyA = $this->policy('a');
        $policyB = $this->policy('b');
        $backend->release($policyA, $this->reserve($backend, $policyA), $this->ids()->command());
        $backend->release($policyB, $this->reserve($backend, $policyB), $this->ids()->command());
        $this->advance(121 * self::SECOND);

        self::assertSame(2, $backend->maintain());
        // Both slots are free again: two new reservations fit in the ceiling of two, a third does not.
        $this->reserve($backend, $policyA);
        $this->reserve($backend, $policyB);
        $this->expectException(StateCapacityExceededException::class);
        $this->reserve($backend, $policyA);
    }

    public function testMaintainSpendsItsBudgetOnTombstonesAcrossPartitions(): void
    {
        $backend = $this->backend();
        $policyA = $this->policy('a');
        $policyB = $this->policy('b');
        foreach ([$policyA, $policyB] as $policy) {
            for ($i = 0; $i < 2; ++$i) {
                $backend->commit($policy, $this->reserve($backend, $policy), $this->ids()->command());
            }
        }
        $this->advance(121 * self::SECOND);

        // Budget three: the first partition spends two, so the second may spend only one (not two).
        self::assertSame(3, $backend->maintain(3));
        self::assertSame(1, $backend->maintain(3));
    }

    // ---- partition deletion rules --------------------------------------------------------------------

    public function testMaintainDeletesEveryIdlePartitionItVisitsInOneCall(): void
    {
        $backend = $this->backend();
        $policies = [$this->policy('a'), $this->policy('b'), $this->policy('c')];
        $incarnations = [];
        foreach ($policies as $policy) {
            $reservation = $this->reserve($backend, $policy);
            $incarnations[] = $reservation->incarnation;
            $backend->release($policy, $reservation, $this->ids()->command());
        }
        $this->advance(121 * self::SECOND);
        $backend->maintain();

        foreach ($policies as $index => $policy) {
            self::assertNull($backend->snapshot($policy), 'partition ' . $index . ' must be deleted in the same pass');
            self::assertFalse($this->reserve($backend, $policy)->incarnation->equals($incarnations[$index]), 'a fresh incarnation proves recreation');
        }
    }

    public function testAPartitionIsDeletedExactlyWhenItsRetentionEnds(): void
    {
        $backend = $this->backend();
        $policy = $this->policy();
        $backend->release($policy, $this->reserve($backend, $policy), $this->ids()->command());

        $this->advance(120 * self::SECOND - 1);
        $backend->maintain();
        self::assertNotNull($backend->snapshot($policy), 'one nanosecond before the retention ends the tombstone and partition remain');

        $this->advance(1);
        self::assertSame(1, $backend->maintain());
        self::assertNull($backend->snapshot($policy), 'at exactly 120 s the partition is idle long enough');
    }

    public function testAPartitionThatHasNotRefilledToCapacityIsNeverDeleted(): void
    {
        $backend = $this->backend();
        // One token per 1000 s: a committed retry leaves a deficit that 120 s cannot repair.
        $policy = $this->policy('slow', 10, 1, 1_000 * self::SECOND);
        $backend->commit($policy, $this->reserve($backend, $policy), $this->ids()->command());
        $this->advance(121 * self::SECOND);

        self::assertSame(1, $backend->maintain());
        $snapshot = $backend->snapshot($policy);
        self::assertNotNull($snapshot);
        self::assertLessThan(10_000_000, $snapshot['balance'] + 1, 'balance is below capacity');
    }

    public function testAPartitionWhoseOnlyTombstoneWasEvictedForCapacityIsStillKeptUntilItHasBeenIdle(): void
    {
        $backend = $this->backend(10, 1);
        $policyA = $this->policy('a');
        $policyB = $this->policy('b');
        $reservationA = $this->reserve($backend, $policyA);
        $backend->release($policyA, $reservationA, $this->ids()->command());
        // B needs the only record slot: A's unexpired tombstone is evicted to make room.
        $this->reserve($backend, $policyB);

        $backend->maintain();

        $snapshot = $backend->snapshot($policyA);
        self::assertNotNull($snapshot, 'A has no records and a full bucket, but it was active a moment ago');
    }

    // ---- capacity pressure ---------------------------------------------------------------------------

    public function testRoomIsReclaimedFromExpiredReservationsBeforeAnythingIsEvicted(): void
    {
        $backend = $this->backend(10, 1);
        $policy = $this->policy('first');
        $other = $this->policy('other');
        $expired = $this->reserve($backend, $policy);
        $this->advance(11 * self::SECOND); // the lease (10 s) has ended

        // The ceiling is one record and the expired reservation sits in ANOTHER partition: maintain() must reclaim it.
        $second = $this->reserve($backend, $other);

        self::assertNotSame($expired->owner->lowerHex, $second->owner->lowerHex);
        // The expired reservation was refunded when it was reclaimed.
        self::assertSame(['balance' => 10_000_000, 'pending' => 0], $backend->snapshot($policy));
        self::assertSame(['balance' => 9_000_000, 'pending' => 1_000_000], $backend->snapshot($other));
    }

    public function testFreeRoomEvictsNoTombstoneThatStillProtectsADuplicateFinalization(): void
    {
        $backend = $this->backend(10, 3);
        $policy = $this->policy();
        $first = $this->reserve($backend, $policy);
        $backend->commit($policy, $first, $this->ids()->command());
        // Two records in use (one tombstone) of three: this reserve needs no eviction.
        $this->reserve($backend, $policy);
        $this->reserve($backend, $policy);

        self::assertSame(RetryBudgetFinalizationStatus::AlreadyCommitted, $backend->commit($policy, $first, $this->ids()->command())->status);
    }

    public function testBalanceAndPendingStayConsistentWhenReleasingOneOfSeveralReservations(): void
    {
        $backend = $this->backend();
        $policy = $this->policy('p', 2, 1, 1_000 * self::SECOND);
        $a = $this->reserve($backend, $policy);
        $b = $this->reserve($backend, $policy);
        self::assertSame(['balance' => 0, 'pending' => 2_000_000], $backend->snapshot($policy));

        $backend->release($policy, $a, $this->ids()->command());
        self::assertSame(['balance' => 1_000_000, 'pending' => 1_000_000], $backend->snapshot($policy));
        $backend->release($policy, $b, $this->ids()->command());
        self::assertSame(['balance' => 2_000_000, 'pending' => 0], $backend->snapshot($policy));
    }
}
