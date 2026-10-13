<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Clocked;

use Enaxon\Reqtide\Exception\StateCapacityExceededException;
use Enaxon\Reqtide\Outcome\CircuitVerdict;
use Enaxon\Reqtide\Policy\ConcurrencyPolicy;
use Enaxon\Reqtide\Policy\CooldownPolicy;
use Enaxon\Reqtide\Policy\RetryBudgetPolicy;
use Enaxon\Reqtide\Redis\RedisCircuitAdmin;
use Enaxon\Reqtide\Redis\RedisCircuitBackend;
use Enaxon\Reqtide\Redis\RedisConcurrencyBackend;
use Enaxon\Reqtide\Redis\RedisCooldownBackend;
use Enaxon\Reqtide\Redis\RedisRegistry;
use Enaxon\Reqtide\Redis\RedisRegistryAdmin;
use Enaxon\Reqtide\Redis\RedisRegistryMaintenance;
use Enaxon\Reqtide\Redis\Tests\Support\RedisBackendHarness;
use Enaxon\Reqtide\State\CircuitAdmissionStatus;
use Enaxon\Reqtide\State\CircuitPermitKind;
use Enaxon\Reqtide\State\ConcurrencyAdmissionStatus;
use Enaxon\Reqtide\State\ConcurrencyPermit;
use Enaxon\Reqtide\State\CooldownStatus;
use Enaxon\Reqtide\State\FinalizationStatus;
use Enaxon\Reqtide\State\RetryReservationStatus;
use Enaxon\Reqtide\Tests\Contract\ContractCircuitRig;
use Enaxon\Reqtide\Tests\Contract\ContractIdentities;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\PositiveInt;
use PHPUnit\Framework\TestCase;

/**
 * Bounded maintenance and the operator reset: expired leases and finished cooldowns of any
 * partition leave through maintenance and at a ceiling, live protection never does, a full batch is reported, and a
 * manual circuit reset closes the circuit, forgets the history and makes earlier permits stale.
 */
final class RedisMaintenanceTest extends TestCase
{
    private const SECOND = 1_000_000_000;

    /** The pending cost of one reservation: one token in millionths. */
    private const TOKEN = 1_000_000;

    private ?RedisBackendHarness $harness = null;

    private ?ContractIdentities $identities = null;

    protected function setUp(): void
    {
        $this->harness()->time()->moveTo(100 * self::SECOND);
    }

    protected function tearDown(): void
    {
        $this->harness?->dispose();
    }

    private function harness(): RedisBackendHarness
    {
        return $this->harness ??= RedisBackendHarness::create();
    }

    private function ids(): ContractIdentities
    {
        return $this->identities ??= new ContractIdentities();
    }

    private function maintenance(RedisRegistry $registry): RedisRegistryMaintenance
    {
        return new RedisRegistryMaintenance($this->harness()->options(), $registry);
    }

    /**
     * @phpstan-impure
     * @return array{int, int} partitions and records
     */
    private function counters(RedisRegistry $registry): array
    {
        $status = (new RedisRegistryAdmin($this->harness()->options(), $registry))->verify();

        return [$status->partitions, $status->records];
    }

    private function lease(string $subject, int $capacity = 1): ConcurrencyPolicy
    {
        return new ConcurrencyPolicy(ContractIdentities::identity($subject), new PositiveInt($capacity), Duration::seconds(10));
    }

    private function cool(string $subject): CooldownPolicy
    {
        return new CooldownPolicy(ContractIdentities::identity($subject), Duration::seconds(60));
    }

    private function acquire(RedisConcurrencyBackend $backend, ConcurrencyPolicy $policy): ConcurrencyPermit
    {
        $admission = $backend->acquire($policy, $this->ids()->owner(), $this->ids()->command());
        self::assertSame(ConcurrencyAdmissionStatus::Accepted, $admission->status);
        self::assertNotNull($admission->permit);

        return $admission->permit;
    }

    public function testMaintenanceRemovesAnExpiredLeaseOfAnotherPartitionAndKeepsALiveOne(): void
    {
        $registry = $this->harness()->registry();
        $backend = new RedisConcurrencyBackend($this->harness()->options(), $registry);
        $this->acquire($backend, $this->lease('early'));
        $this->harness()->time()->advance(6 * self::SECOND);
        $live = $this->acquire($backend, $this->lease('late'));

        // The first lease ends at +10 s, the second at +16 s.
        $this->harness()->time()->advance(5 * self::SECOND);
        $step = $this->maintenance($registry)->step();
        self::assertSame(1, $step['partitions']);
        self::assertFalse($step['more']);
        self::assertSame([1, 1], $this->counters($registry));

        self::assertSame(FinalizationStatus::Applied, $backend->release($this->lease('late'), $live, $this->ids()->command())->status);
        $this->harness()->time()->advance(121 * self::SECOND);
        $this->maintenance($registry)->step();
        self::assertSame([0, 0], $this->counters($registry), 'the tombstone and the empty partition are gone');
    }

    public function testMaintenanceKeepsALeaseAtItsLastInstantAndRemovesItAtExpiry(): void
    {
        $registry = $this->harness()->registry();
        $backend = new RedisConcurrencyBackend($this->harness()->options(), $registry);
        $this->acquire($backend, $this->lease('a'));

        $this->harness()->time()->advance(10 * self::SECOND - 1_000);
        $this->maintenance($registry)->step();
        self::assertSame([1, 1], $this->counters($registry), 'one microsecond before the end the lease still counts');

        $this->harness()->time()->advance(1_000);
        $this->maintenance($registry)->step();
        self::assertSame([0, 0], $this->counters($registry));
    }

    public function testACooldownLeavesWhenItsNotBeforeInstantAndGraceHavePassed(): void
    {
        $registry = $this->harness()->registry();
        $backend = new RedisCooldownBackend($this->harness()->options(), $registry);
        $backend->extend($this->cool('a'), Duration::seconds(5), $this->ids()->command());

        $this->harness()->time()->advance(64 * self::SECOND);
        $this->maintenance($registry)->step();
        self::assertSame([1, 1], $this->counters($registry), 'five seconds of cooldown and 60 seconds of grace are not over yet');
        self::assertSame(CooldownStatus::Clear, $backend->inspect($this->cool('a'))->status);

        $this->harness()->time()->advance(1 * self::SECOND);
        $step = $this->maintenance($registry)->step();
        self::assertSame(1, $step['partitions']);
        self::assertSame([0, 0], $this->counters($registry));
        self::assertSame(CooldownStatus::Clear, $backend->inspect($this->cool('a'))->status, 'an absent cooldown admits, as the removed one did');
    }

    public function testAnExtendedCooldownIsNotRemovedAtItsOldEnd(): void
    {
        $registry = $this->harness()->registry();
        $backend = new RedisCooldownBackend($this->harness()->options(), $registry);
        $backend->extend($this->cool('a'), Duration::seconds(5), $this->ids()->command());
        $this->harness()->time()->advance(60 * self::SECOND);
        $backend->extend($this->cool('a'), Duration::seconds(100), $this->ids()->command());

        $this->harness()->time()->advance(10 * self::SECOND);
        $this->maintenance($registry)->step();
        self::assertSame([1, 1], $this->counters($registry));
        self::assertSame(CooldownStatus::Active, $backend->inspect($this->cool('a'))->status);
        self::assertSame(90 * self::SECOND, $backend->inspect($this->cool('a'))->remaining->nanoseconds);
    }

    public function testAFullBatchIsReportedAndTheNextStepContinues(): void
    {
        $registry = $this->harness()->registry();
        $backend = new RedisCooldownBackend($this->harness()->options(), $registry);
        for ($i = 0; $i < 130; ++$i) {
            $backend->extend($this->cool('c' . $i), Duration::seconds(1), $this->ids()->command());
        }
        self::assertSame([130, 130], $this->counters($registry));

        $this->harness()->time()->advance(62 * self::SECOND);
        $maintenance = $this->maintenance($registry);
        $first = $maintenance->step();
        self::assertSame(128, $first['partitions']);
        self::assertTrue($first['more']);
        self::assertSame([2, 2], $this->counters($registry));

        $second = $maintenance->step();
        self::assertSame(2, $second['partitions']);
        self::assertFalse($second['more']);
        self::assertSame([0, 0], $this->counters($registry));
    }

    public function testCandidatesDueOnlyByTheirSecondDoNotMakeEveryStepRepeatTheSameBatch(): void
    {
        $registry = $this->harness()->registry();
        $backend = new RedisCooldownBackend($this->harness()->options(), $registry);
        $this->harness()->time()->moveTo(100 * self::SECOND + self::SECOND / 2);
        for ($i = 0; $i < 130; ++$i) {
            $backend->extend($this->cool('c' . $i), Duration::seconds(5), $this->ids()->command());
        }

        // Removal is due at 165.5 s: the index second 165 has come, the last half second has not.
        $this->harness()->time()->moveTo(165 * self::SECOND + 400_000_000);
        $maintenance = $this->maintenance($registry);
        $first = $maintenance->step();
        self::assertSame(0, $first['partitions']);
        self::assertFalse($first['more'], 'a full batch without progress does not ask for another step in the same second');
        self::assertSame([130, 130], $this->counters($registry));

        $this->harness()->time()->moveTo(165 * self::SECOND + 500_000_000);
        $second = $maintenance->step();
        self::assertSame(128, $second['partitions'], 'removed at their exact instant, not a second later');
        self::assertTrue($second['more']);
        $third = $maintenance->step();
        self::assertSame(2, $third['partitions']);
        self::assertFalse($third['more']);
        self::assertSame([0, 0], $this->counters($registry));
    }

    public function testEvictionExaminesABoundedNumberOfStaleQueueEntriesPerCall(): void
    {
        $registry = $this->harness()->registry(maxPartitions: 10, maxRecords: 16);
        $backend = new RedisConcurrencyBackend($this->harness()->options(), $registry);
        $policy = $this->lease('a', 16);
        for ($i = 0; $i < 16; ++$i) {
            $permit = $this->acquire($backend, $policy);
            self::assertSame(FinalizationStatus::Applied, $backend->release($policy, $permit, $this->ids()->command())->status);
        }
        self::assertSame([1, 16], $this->counters($registry), 'sixteen tombstones fill the record ceiling');

        // Well-formed entries that name a partition which no longer exists: each one is work, none frees a record.
        $queue = $registry->keys()->tombstoneQueue();
        $gone = $registry->keys()->prefix . ':gone';
        $stale = [];
        for ($i = 0; $i < 1000; ++$i) {
            $stale[] = '100 0 ' . $gone . ' ' . str_pad(dechex($i), 32, '0', STR_PAD_LEFT);
        }
        $client = $this->harness()->client();
        $client->lpush($queue, $stale);
        $before = (int) $client->llen($queue);

        $refusals = 0;
        while (true) {
            $length = (int) $client->llen($queue);
            try {
                $this->acquire($backend, $this->lease('b'));
                break;
            } catch (StateCapacityExceededException) {
                ++$refusals;
                self::assertSame(128 + 256, $length - (int) $client->llen($queue), 'one call drains at most 128 expired entries and examines at most 256 more for eviction');
            }
            self::assertLessThan(10, $refusals, 'every call makes progress');
        }

        self::assertSame(2, $refusals);
        self::assertSame($before - 1001, (int) $client->llen($queue), 'all stale entries and one real tombstone left the queue');
        self::assertSame([2, 16], $this->counters($registry), 'one tombstone made room for the new owner');
    }

    public function testExpiredLeasesAreReclaimedInBoundedBatches(): void
    {
        $registry = $this->harness()->registry();
        $backend = new RedisConcurrencyBackend($this->harness()->options(), $registry);
        $policy = $this->lease('wide', 300);
        for ($i = 0; $i < 300; ++$i) {
            $this->acquire($backend, $policy);
        }
        self::assertSame([1, 300], $this->counters($registry));

        $this->harness()->time()->advance(10 * self::SECOND);
        $this->acquire($backend, $policy);
        self::assertSame([1, 300 - 128 + 1], $this->counters($registry), 'one call reclaims at most 128 expired leases');

        $this->acquire($backend, $policy);
        self::assertSame([1, 300 - 256 + 2], $this->counters($registry));
    }

    public function testExpiredCircuitPermitsAreReclaimedInBoundedBatches(): void
    {
        $registry = $this->harness()->registry(maxRecords: 2_000);
        $backend = new RedisCircuitBackend($this->harness()->options(), $registry);
        $policy = ContractCircuitRig::countPolicy(window: 10_000, minimum: 10_000, leaseSeconds: 10);
        for ($i = 0; $i < 300; ++$i) {
            $permit = $backend->acquire($policy, $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command())->permit;
            self::assertNotNull($permit);
        }
        self::assertSame([1, 300], $this->counters($registry));

        $this->harness()->time()->advance(10 * self::SECOND);
        $backend->acquire($policy, $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command());
        self::assertSame([1, 300 - 128 + 1], $this->counters($registry), 'one call reclaims at most 128 expired permits');

        $backend->acquire($policy, $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command());
        self::assertSame([1, 300 - 256 + 2], $this->counters($registry));
    }

    /** The pending cost the production Lua computes for the partition now, in millionths of a token. */
    private function pendingCost(\Enaxon\Reqtide\State\RetryBudgetBackend $backend, RetryBudgetPolicy $policy): int
    {
        $observation = $this->harness()->observeBudget($backend, $policy);
        self::assertNotNull($observation);

        return $observation['pending'];
    }

    public function testExpiredBudgetReservationsAreReclaimedInBoundedBatches(): void
    {
        $backend = $this->harness()->retryBudget(maxRecords: 2_000);
        $policy = new RetryBudgetPolicy(ContractIdentities::identity('wide'), new PositiveInt(1_000), new PositiveInt(1), Duration::seconds(3_600), Duration::seconds(10));
        for ($i = 0; $i < 300; ++$i) {
            $decision = $backend->reserve($policy, $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command());
            self::assertSame(RetryReservationStatus::Reserved, $decision->status);
        }
        self::assertSame(300 * self::TOKEN, $this->pendingCost($backend, $policy));

        $this->harness()->time()->advance(10 * self::SECOND);
        // The view applies what one call would: at most 128 of the 300 expired reservations are given back. The rest stays
        // held; that can only refuse, never over-admit.
        self::assertSame((300 - 128) * self::TOKEN, $this->pendingCost($backend, $policy));

        $backend->reserve($policy, $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command());
        // The call stored that result plus its own reservation (173 held); the view of the next call gives back 128 more.
        self::assertSame((300 - 128 + 1 - 128) * self::TOKEN, $this->pendingCost($backend, $policy));
    }

    public function testAnEmptyRegistryHasNothingToDo(): void
    {
        $registry = $this->harness()->registry();
        $step = $this->maintenance($registry)->step();
        self::assertSame(['terminalRecords' => 0, 'partitions' => 0, 'more' => false, 'damaged' => 0], $step);
    }

    public function testAnExpiredLeaseElsewhereFreesTheRecordCeilingForANewOwner(): void
    {
        $registry = $this->harness()->registry(maxPartitions: 10, maxRecords: 2);
        $backend = new RedisConcurrencyBackend($this->harness()->options(), $registry);
        $this->acquire($backend, $this->lease('a'));
        $this->acquire($backend, $this->lease('b'));

        try {
            $backend->acquire($this->lease('c'), $this->ids()->owner(), $this->ids()->command());
            self::fail('Live leases fill the ceiling and nothing is evicted for a new owner.');
        } catch (StateCapacityExceededException) {
            self::assertSame([2, 2], $this->counters($registry));
        }

        $this->harness()->time()->advance(10 * self::SECOND);
        $this->acquire($backend, $this->lease('c'));
        self::assertSame([1, 1], $this->counters($registry), 'both expired leases of the other partitions were reclaimed');
    }

    public function testAnExpiredLeaseElsewhereFreesTheCeilingForAnOwnerOfAnExistingPartition(): void
    {
        $registry = $this->harness()->registry(maxPartitions: 10, maxRecords: 2);
        $backend = new RedisConcurrencyBackend($this->harness()->options(), $registry);
        $this->acquire($backend, $this->lease('b'));
        $this->harness()->time()->advance(5 * self::SECOND);
        $first = $this->acquire($backend, $this->lease('a', 2));

        $this->harness()->time()->advance(6 * self::SECOND);
        $second = $this->acquire($backend, $this->lease('a', 2));
        self::assertSame([1, 2], $this->counters($registry), 'the expired lease of the other partition made room; the live lease of this one stayed');
        self::assertSame(FinalizationStatus::Applied, $backend->release($this->lease('a', 2), $first, $this->ids()->command())->status);
        self::assertSame(FinalizationStatus::Applied, $backend->release($this->lease('a', 2), $second, $this->ids()->command())->status);
    }

    public function testAFinishedCooldownFreesThePartitionCeilingForANewCooldown(): void
    {
        $registry = $this->harness()->registry(maxPartitions: 1, maxRecords: 10);
        $backend = new RedisCooldownBackend($this->harness()->options(), $registry);
        $backend->extend($this->cool('a'), Duration::seconds(5), $this->ids()->command());

        try {
            $backend->extend($this->cool('b'), Duration::seconds(5), $this->ids()->command());
            self::fail('A cooldown in force is protective state and is never evicted.');
        } catch (StateCapacityExceededException) {
            self::assertSame([1, 1], $this->counters($registry));
            self::assertSame(CooldownStatus::Clear, $backend->inspect($this->cool('b'))->status, 'the rejected cooldown stored nothing');
        }

        $this->harness()->time()->advance(65 * self::SECOND);
        $backend->extend($this->cool('b'), Duration::seconds(5), $this->ids()->command());
        self::assertSame([1, 1], $this->counters($registry));
        self::assertSame(CooldownStatus::Active, $backend->inspect($this->cool('b'))->status);
    }

    public function testAnOpenCircuitIsNeverRemovedByMaintenance(): void
    {
        $registry = $this->harness()->registry();
        $backend = new RedisCircuitBackend($this->harness()->options(), $registry);
        $policy = ContractCircuitRig::countPolicy(minimum: 2, openSeconds: 100000);
        for ($i = 0; $i < 2; ++$i) {
            $permit = $backend->acquire($policy, $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command())->permit;
            self::assertNotNull($permit);
            $backend->record($policy, $permit, CircuitVerdict::Failure, $this->ids()->command());
        }

        $this->harness()->time()->advance(1_000 * self::SECOND);
        $maintenance = $this->maintenance($registry);
        $maintenance->step();
        $maintenance->step();
        self::assertSame(CircuitAdmissionStatus::Open, $backend->acquire($policy, $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command())->status);
    }

    public function testAResetClosesTheCircuitForgetsTheHistoryAndMakesEarlierPermitsStale(): void
    {
        $registry = $this->harness()->registry();
        $backend = new RedisCircuitBackend($this->harness()->options(), $registry);
        $admin = new RedisCircuitAdmin($this->harness()->options(), $registry);
        $policy = ContractCircuitRig::countPolicy(minimum: 2);

        self::assertNull($admin->reset($policy->identity), 'an absent partition is reported, not created');
        self::assertSame([0, 0], $this->counters($registry));

        $permits = [];
        for ($i = 0; $i < 2; ++$i) {
            $admission = $backend->acquire($policy, $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command());
            self::assertNotNull($admission->permit);
            $permits[] = $admission->permit;
        }
        $backend->record($policy, $permits[0], CircuitVerdict::Failure, $this->ids()->command());
        self::assertSame([1, 2], $this->counters($registry));

        $generation = $admin->reset($policy->identity);
        self::assertNotNull($generation);
        self::assertSame(2, $generation->value);
        self::assertSame(
            FinalizationStatus::StaleOwnership,
            $backend->record($policy, $permits[1], CircuitVerdict::Failure, $this->ids()->command())->status,
            'a permit issued before the reset is stale',
        );
        self::assertSame([1, 1], $this->counters($registry), 'the active permit is gone, the terminal record of the first stays');

        // The failure recorded before the reset is forgotten: one new failure does not reach the minimum of two.
        $fresh = $backend->acquire($policy, $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command());
        self::assertSame(CircuitAdmissionStatus::Accepted, $fresh->status);
        self::assertNotNull($fresh->permit);
        self::assertSame(CircuitPermitKind::ClosedSample, $fresh->permit->kind);
        self::assertSame(2, $fresh->permit->generation->value);
        self::assertNull($backend->record($policy, $fresh->permit, CircuitVerdict::Failure, $this->ids()->command())->transition);
    }

    public function testAResetClosesAnOpenCircuit(): void
    {
        $registry = $this->harness()->registry();
        $backend = new RedisCircuitBackend($this->harness()->options(), $registry);
        $admin = new RedisCircuitAdmin($this->harness()->options(), $registry);
        $policy = ContractCircuitRig::countPolicy(minimum: 2);
        for ($i = 0; $i < 2; ++$i) {
            $permit = $backend->acquire($policy, $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command())->permit;
            self::assertNotNull($permit);
            $backend->record($policy, $permit, CircuitVerdict::Failure, $this->ids()->command());
        }
        self::assertSame(CircuitAdmissionStatus::Open, $backend->acquire($policy, $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command())->status);

        $generation = $admin->reset($policy->identity);
        self::assertSame(3, $generation?->value, 'closed 1, open 2, reset 3');
        self::assertSame(CircuitAdmissionStatus::Accepted, $backend->acquire($policy, $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command())->status);
    }

    public function testChurnAtASmallCeilingNeverRejectsAndKeepsCountersAndKeysBounded(): void
    {
        $registry = $this->harness()->registry(maxPartitions: 20, maxRecords: 40);
        $concurrency = new RedisConcurrencyBackend($this->harness()->options(), $registry);
        $cooldown = new RedisCooldownBackend($this->harness()->options(), $registry);
        $maintenance = $this->maintenance($registry);
        $client = $this->harness()->client();
        $prefix = $registry->keyPrefix;
        $largestKeySet = 0;

        // 1,500 acquire and release cycles in 75 seconds of server time: the tombstones alone would be 37 times the
        // record ceiling if nothing were evicted.
        for ($i = 0; $i < 1_500; ++$i) {
            $policy = $this->lease('s' . ($i % 15));
            $permit = $this->acquire($concurrency, $policy);
            self::assertSame(FinalizationStatus::Applied, $concurrency->release($policy, $permit, $this->ids()->command())->status);
            if ($i % 100 === 0) {
                $cooldown->extend($this->cool('c' . ($i / 100 % 5)), Duration::seconds(1), $this->ids()->command());
            }
            if ($i % 97 === 0) {
                $maintenance->step();
            }
            $this->harness()->time()->advance(50_000_000);

            if ($i % 50 === 0) {
                [$partitions, $records] = $this->counters($registry);
                self::assertLessThanOrEqual(20, $partitions);
                self::assertLessThanOrEqual(40, $records);
                $keys = 0;
                $cursor = '0';
                do {
                    /** @var array{0: string, 1: list<string>} $page */
                    $page = $client->scan($cursor, ['MATCH' => $prefix . ':*', 'COUNT' => 1_000]);
                    $cursor = (string) $page[0];
                    $keys += count($page[1]);
                } while ($cursor !== '0');
                $largestKeySet = max($largestKeySet, $keys);
            }
        }

        self::assertLessThanOrEqual(40, $largestKeySet, 'the number of keys stays bounded by the partitions, not by the history');
    }
}
