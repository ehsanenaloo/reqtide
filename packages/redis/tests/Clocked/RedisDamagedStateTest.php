<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Clocked;

use Enaxon\Reqtide\Exception\CorruptStateException;
use Enaxon\Reqtide\Exception\StateCapacityExceededException;
use Enaxon\Reqtide\Policy\ConcurrencyPolicy;
use Enaxon\Reqtide\Policy\CooldownPolicy;
use Enaxon\Reqtide\Policy\RateLimitPolicy;
use Enaxon\Reqtide\Policy\RetryBudgetPolicy;
use Enaxon\Reqtide\Policy\TokenBucketOptions;
use Enaxon\Reqtide\Redis\RedisConcurrencyBackend;
use Enaxon\Reqtide\Redis\RedisCooldownBackend;
use Enaxon\Reqtide\Redis\RedisRateLimitBackend;
use Enaxon\Reqtide\Redis\RedisRegistry;
use Enaxon\Reqtide\Redis\RedisRegistryAdmin;
use Enaxon\Reqtide\Redis\RedisRegistryMaintenance;
use Enaxon\Reqtide\Redis\RedisRetryBudgetBackend;
use Enaxon\Reqtide\Redis\State\StateKind;
use Enaxon\Reqtide\Redis\Tests\Support\RedisBackendHarness;
use Enaxon\Reqtide\State\ConcurrencyAdmissionStatus;
use Enaxon\Reqtide\State\ConcurrencyPermit;
use Enaxon\Reqtide\Tests\Contract\ContractIdentities;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\PositiveInt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Damaged records inside a registry: a call that finds one must not lose the counters of the cleanup it already did, and a
 * damaged queue entry or index member must not make every later call fail. Cleanup is neutral, so it removes
 * what is expired, leaves what it cannot read, and the registry counters keep matching the stored records.
 */
final class RedisDamagedStateTest extends TestCase
{
    private const SECOND = 1_000_000_000;

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

    private function lease(string $subject, int $capacity = 3): ConcurrencyPolicy
    {
        return new ConcurrencyPolicy(ContractIdentities::identity($subject), new PositiveInt($capacity), Duration::seconds(10));
    }

    private function acquire(RedisConcurrencyBackend $backend, ConcurrencyPolicy $policy): ConcurrencyPermit
    {
        $admission = $backend->acquire($policy, $this->ids()->owner(), $this->ids()->command());
        self::assertSame(ConcurrencyAdmissionStatus::Accepted, $admission->status);
        self::assertNotNull($admission->permit);

        return $admission->permit;
    }

    /** @return array{int, int} partitions and records as the registry counts them */
    private function counted(RedisRegistry $registry): array
    {
        $status = (new RedisRegistryAdmin($this->harness()->options(), $registry))->verify();

        return [$status->partitions, $status->records];
    }

    /**
     * Partitions and lease or tombstone records as they are stored (cooldown partitions are not counted by record fields).
     *
     * @return array{int, int}
     */
    private function stored(RedisRegistry $registry): array
    {
        $client = $this->harness()->client();
        $partitions = 0;
        $records = 0;
        $cursor = '0';
        do {
            /** @var array{0: string, 1: list<string>} $page */
            $page = $client->scan($cursor, ['MATCH' => $registry->keyPrefix . ':p:*', 'COUNT' => 500]);
            $cursor = (string) $page[0];
            foreach ($page[1] as $key) {
                if (preg_match('~:[0-9a-f]{32}\z~', $key) !== 1) {
                    continue;
                }
                ++$partitions;
                /** @var list<string> $fields */
                $fields = $client->hkeys($key);
                foreach ($fields as $field) {
                    if (str_starts_with($field, 'a:') || str_starts_with($field, 't:')) {
                        ++$records;
                    }
                }
            }
        } while ($cursor !== '0');

        return [$partitions, $records];
    }

    /** @return iterable<string, array{string}> */
    public static function malformedEntries(): iterable
    {
        $owner = str_repeat('ab', 16);
        yield 'not an entry' => ['broken'];
        yield 'owner too short' => ['1 2 some:key abcd'];
        yield 'owner not lowercase hexadecimal' => ['1 2 some:key ' . strtoupper($owner)];
        yield 'seconds beyond the exact range' => ['99999999999999999999999 0 some:key ' . $owner];
        yield 'microseconds not normalized' => ['1 1000000 some:key ' . $owner];
    }

    #[DataProvider('malformedEntries')]
    public function testAMalformedQueueHeadIsRemovedAndNeitherBlocksTheCallNorMovesTheCounters(string $entry): void
    {
        $registry = $this->harness()->registry();
        $backend = new RedisConcurrencyBackend($this->harness()->options(), $registry);
        $policy = $this->lease('a');
        $first = $this->acquire($backend, $policy);
        $backend->release($policy, $first, $this->ids()->command());
        $this->acquire($backend, $policy);

        // The second lease ends at +10 s: the next call removes it before it reaches the queue.
        $this->harness()->time()->advance(11 * self::SECOND);
        $queue = $registry->keys()->tombstoneQueue();
        $this->harness()->client()->lpush($queue, [$entry]);

        $this->acquire($backend, $policy);

        self::assertSame(1, $this->harness()->client()->llen($queue), 'only the real tombstone is left in the queue');
        self::assertSame($this->stored($registry), $this->counted($registry), 'the counters match the stored records');
        self::assertSame([1, 2], $this->counted($registry), 'the new lease and the retained tombstone');

        $this->harness()->time()->advance(125 * self::SECOND);
        $this->acquire($backend, $policy);
        self::assertSame(0, $this->harness()->client()->llen($queue));
        self::assertSame($this->stored($registry), $this->counted($registry));
    }

    public function testAMalformedQueueEntryDoesNotBlockMaintenanceEitherAndCountsAsConsumed(): void
    {
        $registry = $this->harness()->registry();
        $backend = new RedisConcurrencyBackend($this->harness()->options(), $registry);
        $policy = $this->lease('a');
        $this->harness()->client()->rpush($registry->keys()->tombstoneQueue(), ['broken', 'also broken']);
        $this->acquire($backend, $policy);

        $step = (new RedisRegistryMaintenance($this->harness()->options(), $registry))->step();
        self::assertSame(0, $this->harness()->client()->llen($registry->keys()->tombstoneQueue()));
        self::assertSame($this->stored($registry), $this->counted($registry));
        self::assertFalse($step['more']);
    }

    public function testADamagedLeaseIsLeftAloneAndTheOtherExpiredLeasesAreStillRemoved(): void
    {
        $registry = $this->harness()->registry();
        $backend = new RedisConcurrencyBackend($this->harness()->options(), $registry);
        $policy = $this->lease('a');
        $this->acquire($backend, $policy);
        $this->acquire($backend, $policy);
        $partition = $registry->keys()->policyPartition(StateKind::Concurrency, $policy->identity);
        /** @var list<string> $all */
        $all = $this->harness()->client()->hkeys($partition);
        $fields = array_filter($all, static fn(string $field): bool => str_starts_with($field, 'a:'));
        self::assertCount(2, $fields);
        $damaged = array_values($fields)[1];
        $this->harness()->client()->hset($partition, $damaged, 'not a lease');

        $this->harness()->time()->advance(11 * self::SECOND);
        $this->acquire($backend, $policy);

        self::assertSame($this->stored($registry), $this->counted($registry), 'the counters match the stored records');
        self::assertSame([1, 2], $this->counted($registry), 'the damaged lease stays counted, the healthy one expired, the new one is held');
        self::assertSame('not a lease', $this->harness()->client()->hget($partition, $damaged), 'the damaged record is not repaired');
    }

    public function testAReleaseOfADamagedLeaseFailsBeforeItRemovesAnythingSoTheCountersStayTrue(): void
    {
        $registry = $this->harness()->registry();
        $backend = new RedisConcurrencyBackend($this->harness()->options(), $registry);
        $policy = $this->lease('a');
        $this->acquire($backend, $policy);
        $this->harness()->time()->advance(5 * self::SECOND);
        $held = $this->acquire($backend, $policy);
        $partition = $registry->keys()->policyPartition(StateKind::Concurrency, $policy->identity);
        $this->harness()->client()->hset($partition, 'a:' . $held->owner->lowerHex, 'not a lease');

        // The first lease has expired and the damaged one has not: removing the first would come before the damage is found.
        $this->harness()->time()->advance(6 * self::SECOND);
        try {
            $backend->release($policy, $held, $this->ids()->command());
            self::fail('expected CorruptStateException');
        } catch (CorruptStateException) {
        }

        self::assertSame([1, 2], $this->stored($registry));
        self::assertSame([1, 2], $this->counted($registry));
    }

    public function testADamagedIndexMemberIsMovedBehindTheOthersAndMaintenanceKeepsWorking(): void
    {
        $registry = $this->harness()->registry();
        $client = $this->harness()->client();
        $cooldowns = new RedisCooldownBackend($this->harness()->options(), $registry);
        $cooldowns->extend(new CooldownPolicy(ContractIdentities::identity('good'), Duration::seconds(5)), Duration::seconds(5), $this->ids()->command());

        $index = $registry->keys()->rateIndex();
        $notAHash = $registry->keyPrefix . ':p:zz:string';
        $halfHash = $registry->keyPrefix . ':p:zz:half';
        $client->set($notAHash, 'x');
        $client->hset($halfHash, 'kind', 'rb');
        $client->zadd($index, [$notAHash => 0, $halfHash => 0]);

        $this->harness()->time()->advance(70 * self::SECOND);
        $maintenance = new RedisRegistryMaintenance($this->harness()->options(), $registry);
        $first = $maintenance->step();
        self::assertSame(1, $first['partitions'], 'the cooldown that ended leaves although damaged members come first');
        self::assertSame(2, $first['damaged'], 'both damaged members are reported to the operator');
        self::assertSame(0, $maintenance->step()['damaged'], 'a deferred member is not reported again in the same retention');

        self::assertGreaterThan(0.0, (float) $client->zscore($index, $notAHash));
        self::assertGreaterThan(0.0, (float) $client->zscore($index, $halfHash));
        self::assertSame('x', $client->get($notAHash), 'a damaged partition is not deleted or repaired');
        self::assertSame([0, 0], $this->counted($registry));

        // A deferred member comes up again later, is deferred again without a failure and is reported again.
        $this->harness()->time()->advance(200 * self::SECOND);
        self::assertSame(2, $maintenance->step()['damaged']);
    }

    public function testADamagedIndexMemberDoesNotStopReclamationAtThePartitionCeiling(): void
    {
        $registry = $this->harness()->registry(maxPartitions: 1);
        $rates = new RedisRateLimitBackend($this->harness()->options(), $registry);
        $rates->consume($this->rate('first'), new PositiveInt(1), $this->ids()->command());
        $client = $this->harness()->client();
        $broken = $registry->keyPrefix . ':p:zz:broken';
        $client->set($broken, 'x');
        $client->zadd($registry->keys()->rateIndex(), [$broken => 0]);

        try {
            $rates->consume($this->rate('second'), new PositiveInt(1), $this->ids()->command());
            self::fail('The first partition is not idle yet, so the ceiling stands.');
        } catch (StateCapacityExceededException) {
        }
        self::assertSame([1, 0], $this->counted($registry));

        $this->harness()->time()->advance(125 * self::SECOND);
        $rates->consume($this->rate('second'), new PositiveInt(1), $this->ids()->command());
        self::assertSame([1, 0], $this->counted($registry), 'the idle partition made room for the new one');
    }

    public function testABudgetReservationReclaimsIdlePartitionsBeforeItRaisesTheCeiling(): void
    {
        $registry = $this->harness()->registry(maxPartitions: 1);
        $rates = new RedisRateLimitBackend($this->harness()->options(), $registry);
        $rates->consume($this->rate('idle'), new PositiveInt(1), $this->ids()->command());
        $budget = new RedisRetryBudgetBackend($this->harness()->options(), $registry);
        $policy = $this->budget('retries');

        try {
            $budget->reserve($policy, $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command());
            self::fail('The rate partition is still protected, so the ceiling stands.');
        } catch (StateCapacityExceededException) {
        }

        $this->harness()->time()->advance(125 * self::SECOND);
        $decision = $budget->reserve($policy, $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command());
        self::assertNotNull($decision->reservation, 'the idle rate partition was reclaimed');
        self::assertSame([1, 1], $this->counted($registry));
    }

    public function testInspectingABudgetWritesNothingEvenWhenAnIndexMemberIsStale(): void
    {
        $registry = $this->harness()->registry();
        $budget = new RedisRetryBudgetBackend($this->harness()->options(), $registry);
        $policy = $this->budget('retries');
        $budget->reserve($policy, $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command());
        $pending = $registry->keys()->policyPartition(StateKind::RetryBudget, $policy->identity) . ':a';
        $client = $this->harness()->client();
        $stale = str_repeat('cd', 16);
        $client->zadd($pending, [$stale => 0]);
        $before = $client->zrange($pending, 0, -1);
        $hash = $client->hgetall($registry->keys()->policyPartition(StateKind::RetryBudget, $policy->identity));

        $budget->inspect($policy);

        self::assertSame($before, $client->zrange($pending, 0, -1), 'inspection leaves the stale member where it is');
        self::assertSame($hash, $client->hgetall($registry->keys()->policyPartition(StateKind::RetryBudget, $policy->identity)));

        $budget->reserve($policy, $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command());
        self::assertNotContains($stale, $client->zrange($pending, 0, -1), 'a mutating call does remove it');
    }

    private function rate(string $subject): RateLimitPolicy
    {
        return new RateLimitPolicy(ContractIdentities::identity($subject), new TokenBucketOptions(new PositiveInt(5), new PositiveInt(1), Duration::seconds(1)));
    }

    private function budget(string $subject): RetryBudgetPolicy
    {
        return new RetryBudgetPolicy(ContractIdentities::identity($subject), new PositiveInt(5), new PositiveInt(1), Duration::seconds(3600), Duration::seconds(60));
    }
}
