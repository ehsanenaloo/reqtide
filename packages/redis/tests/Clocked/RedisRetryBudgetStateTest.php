<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Clocked;

use Enaxon\Reqtide\Exception\CorruptStateException;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Exception\StateCapacityExceededException;
use Enaxon\Reqtide\Exception\StateUnavailableException;
use Enaxon\Reqtide\Policy\ConcurrencyPolicy;
use Enaxon\Reqtide\Policy\RetryBudgetPolicy;
use Enaxon\Reqtide\Redis\RedisConcurrencyBackend;
use Enaxon\Reqtide\Redis\RedisRegistry;
use Enaxon\Reqtide\Redis\RedisRegistryAdmin;
use Enaxon\Reqtide\Redis\RedisRegistryMaintenance;
use Enaxon\Reqtide\Redis\RedisRetryBudgetBackend;
use Enaxon\Reqtide\Redis\Tests\Support\RedisBackendHarness;
use Enaxon\Reqtide\State\FinalizationStatus;
use Enaxon\Reqtide\State\Memory\MemoryRetryBudgetBackend;
use Enaxon\Reqtide\State\RetryBudgetFinalizationStatus;
use Enaxon\Reqtide\State\RetryBudgetReservation;
use Enaxon\Reqtide\State\RetryReservationStatus;
use Enaxon\Reqtide\Tests\Contract\ContractIdentities;
use Enaxon\Reqtide\Time\SystemClock;
use Enaxon\Reqtide\Value\AttemptId;
use Enaxon\Reqtide\Value\BackendExpiry;
use Enaxon\Reqtide\Value\BackendInstant;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\PositiveInt;
use PHPUnit\Framework\TestCase;
use Predis\Response\Status;

/**
 * What only a shared store can show for the retry budget: backends over
 * one registry share one budget, inspection writes nothing, the registry counters follow every allocation, terminal records
 * are evicted registry-wide across state kinds while balances and pending reservations never are, and idle full buckets are
 * reclaimed by a bounded maintenance step.
 */
final class RedisRetryBudgetStateTest extends TestCase
{
    private const SECOND = 1_000_000_000;

    private ?RedisBackendHarness $harness = null;

    private ?ContractIdentities $identities = null;

    protected function setUp(): void
    {
        $this->harness()->time()->moveTo(1_000 * self::SECOND);
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

    private function backend(RedisRegistry $registry): RedisRetryBudgetBackend
    {
        return new RedisRetryBudgetBackend($this->harness()->options(), $registry);
    }

    private function policy(int $capacity = 3, int $leaseSeconds = 120, string $subject = 'subject', int $intervalSeconds = 10): RetryBudgetPolicy
    {
        return new RetryBudgetPolicy(
            ContractIdentities::identity($subject),
            new PositiveInt($capacity),
            new PositiveInt(1),
            Duration::seconds($intervalSeconds),
            Duration::seconds($leaseSeconds),
        );
    }

    private function reserve(RedisRetryBudgetBackend $backend, RetryBudgetPolicy $policy): RetryBudgetReservation
    {
        $decision = $backend->reserve($policy, $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command());
        self::assertSame(RetryReservationStatus::Reserved, $decision->status);
        self::assertNotNull($decision->reservation);

        return $decision->reservation;
    }

    /** @return array{int, int} partitions and records */
    private function registryStatus(RedisRegistry $registry): array
    {
        $status = (new RedisRegistryAdmin($this->harness()->options(), $registry))->verify();

        return [$status->partitions, $status->records];
    }

    /** @return array<string, mixed> every key of the registry with its complete content */
    private function snapshot(RedisRegistry $registry): array
    {
        $client = $this->harness()->client();
        /** @var list<string> $keys */
        $keys = $client->keys($registry->keyPrefix . ':*');
        sort($keys);
        $content = [];
        foreach ($keys as $key) {
            $reply = $client->type($key);
            $type = $reply instanceof Status ? $reply->getPayload() : (is_string($reply) ? $reply : '');
            $content[$key] = match ($type) {
                'hash' => $client->hgetall($key),
                'zset' => $client->zrange($key, 0, -1, ['withscores' => true]),
                'list' => $client->lrange($key, 0, -1),
                default => $type,
            };
        }

        return $content;
    }

    public function testTwoInstancesOverOneRegistryShareOneBudget(): void
    {
        $registry = $this->harness()->registry();
        $first = $this->backend($registry);
        $second = $this->backend($registry);
        $policy = $this->policy(1, 120);

        $held = $this->reserve($first, $policy);
        self::assertSame(RetryReservationStatus::Exhausted, $second->reserve($policy, $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command())->status);
        self::assertFalse($second->inspect($policy)->wouldReserve);

        self::assertSame(RetryBudgetFinalizationStatus::Released, $second->release($policy, $held, $this->ids()->command())->status);
        self::assertSame(RetryBudgetFinalizationStatus::AlreadyReleased, $first->release($policy, $held, $this->ids()->command())->status);
        $this->reserve($first, $policy);
    }

    public function testInspectionWritesNothingForAnAbsentOrAnExistingBudget(): void
    {
        $registry = $this->harness()->registry();
        $backend = $this->backend($registry);
        $policy = $this->policy(2, 10);

        $empty = $this->snapshot($registry);
        self::assertTrue($backend->inspect($policy)->wouldReserve);
        self::assertSame($empty, $this->snapshot($registry), 'inspecting an absent budget creates no key');

        $this->reserve($backend, $policy);
        $this->reserve($backend, $policy);
        $this->harness()->time()->advance(10 * self::SECOND); // both leases are expired at equality, nothing converted yet
        $before = $this->snapshot($registry);
        $inspection = $backend->inspect($policy);
        self::assertTrue($inspection->wouldReserve, 'the view applies the expiry');
        self::assertSame($before, $this->snapshot($registry), 'inspecting an existing budget changes nothing, not even the watermark');
    }

    public function testRegistryCountersFollowReservationsFinalizationAndMaintenance(): void
    {
        $registry = $this->harness()->registry();
        $backend = $this->backend($registry);
        $policy = $this->policy(2, 120);

        $first = $this->reserve($backend, $policy);
        $this->reserve($backend, $policy);
        self::assertSame([1, 2], $this->registryStatus($registry));

        $backend->release($policy, $first, $this->ids()->command());
        self::assertSame([1, 2], $this->registryStatus($registry), 'the slot becomes its terminal record in place');

        // The lease of the second reservation and the record of the first have ended. A step removes the record and turns the
        // expired reservation into a terminal record of its own; 121 seconds later a step removes that record and, the bucket
        // being full again and idle, the partition.
        $this->harness()->time()->advance(400 * self::SECOND);
        $maintenance = new RedisRegistryMaintenance($this->harness()->options(), $registry);
        self::assertGreaterThan(0, $maintenance->step()['terminalRecords']);
        self::assertSame([1, 1], $this->registryStatus($registry));

        $this->harness()->time()->advance(121 * self::SECOND);
        $maintenance->step();
        self::assertSame([0, 0], $this->registryStatus($registry));
    }

    public function testMaintenanceKeepsABudgetThatIsNotFullOrStillHoldsReservations(): void
    {
        $registry = $this->harness()->registry();
        $backend = $this->backend($registry);
        $maintenance = new RedisRegistryMaintenance($this->harness()->options(), $registry);
        $held = $this->policy(2, 100_000, 'held', 1_000_000);
        $drained = $this->policy(2, 120, 'drained', 1_000_000);

        $this->reserve($backend, $held);
        $committed = $this->reserve($backend, $drained);
        $backend->commit($drained, $committed, $this->ids()->command());

        $this->harness()->time()->advance(500 * self::SECOND);
        for ($i = 0; $i < 3; ++$i) {
            $maintenance->step();
        }

        self::assertSame([2, 1], $this->registryStatus($registry), 'both partitions stay; only the committed record of the drained one ended');
        self::assertSame(1, $backend->inspect($drained)->availableTokens->value, 'the drained budget did not refill to full');
    }

    public function testTheOldestTerminalRecordIsEvictedAcrossBudgetsAndTheirBalancesAreKept(): void
    {
        $registry = $this->harness()->registry(10, 3);
        $backend = $this->backend($registry);
        $a = $this->policy(5, 120, 'a');
        $b = $this->policy(5, 120, 'b');
        $c = $this->policy(5, 120, 'c');

        $reservationA = $this->reserve($backend, $a);
        $backend->commit($a, $reservationA, $this->ids()->command());
        $this->harness()->time()->advance(1 * self::SECOND);
        $reservationB = $this->reserve($backend, $b);
        $backend->release($b, $reservationB, $this->ids()->command());
        $this->reserve($backend, $c);
        self::assertSame([3, 3], $this->registryStatus($registry));

        $this->reserve($backend, $c);
        self::assertSame([3, 3], $this->registryStatus($registry), 'the oldest record went; no partition was deleted');
        self::assertSame(RetryBudgetFinalizationStatus::StaleOwnership, $backend->commit($a, $reservationA, $this->ids()->command())->status);
        self::assertSame(RetryBudgetFinalizationStatus::AlreadyReleased, $backend->release($b, $reservationB, $this->ids()->command())->status);
        self::assertSame(4, $backend->inspect($a)->availableTokens->value, 'the committed token stays consumed: eviction never refunds');
    }

    public function testBudgetAndConcurrencyShareOneTerminalRecordQueue(): void
    {
        $registry = $this->harness()->registry(10, 2);
        $budget = $this->backend($registry);
        $concurrency = new RedisConcurrencyBackend($this->harness()->options(), $registry);
        $lease = new ConcurrencyPolicy(ContractIdentities::identity('lease'), new PositiveInt(1), Duration::seconds(10));
        $policy = $this->policy(5, 120);

        $permit = $concurrency->acquire($lease, $this->ids()->owner(), $this->ids()->command())->permit;
        self::assertNotNull($permit);
        $concurrency->release($lease, $permit, $this->ids()->command());
        $this->harness()->time()->advance(1 * self::SECOND);
        $reservation = $this->reserve($budget, $policy);
        $budget->release($policy, $reservation, $this->ids()->command());
        self::assertSame([2, 2], $this->registryStatus($registry));

        // A new reservation needs a record: the oldest terminal record is the permit's, whose emptied partition is deleted.
        $this->reserve($budget, $policy);
        self::assertSame([1, 2], $this->registryStatus($registry));
        self::assertSame(FinalizationStatus::StaleOwnership, $concurrency->release($lease, $permit, $this->ids()->command())->status);
        self::assertSame(RetryBudgetFinalizationStatus::AlreadyReleased, $budget->release($policy, $reservation, $this->ids()->command())->status);
    }

    public function testACeilingRejectionChangesNeitherBudgetNorCounters(): void
    {
        $registry = $this->harness()->registry(10, 1);
        $backend = $this->backend($registry);
        $policy = $this->policy(3, 120);
        $held = $this->reserve($backend, $policy);

        try {
            $backend->reserve($policy, $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command());
            self::fail('Expected a capacity rejection.');
        } catch (StateCapacityExceededException) {
            self::assertSame([1, 1], $this->registryStatus($registry));
        }
        self::assertSame(2, $backend->inspect($policy)->availableTokens->value);
        self::assertSame(RetryBudgetFinalizationStatus::Committed, $backend->commit($policy, $held, $this->ids()->command())->status);
    }

    public function testAReservationOfAnotherBackendKindIsStaleAndNothingIsStored(): void
    {
        $registry = $this->harness()->registry();
        $redis = $this->backend($registry);
        $policy = $this->policy();
        $local = (new MemoryRetryBudgetBackend(new SystemClock()))->reserve($policy, $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command())->reservation;
        self::assertNotNull($local);

        self::assertSame(RetryBudgetFinalizationStatus::StaleOwnership, $redis->commit($policy, $local, $this->ids()->command())->status);
        self::assertSame([0, 0], $this->registryStatus($registry));
    }

    public function testAMissingRegistryIsUnavailableAndAForeignOneIsRefused(): void
    {
        $missing = new RedisRegistry('rt_c_missing' . bin2hex(random_bytes(4)), 'contract', 'a-host-managed-secret-of-32-bytes!!');
        try {
            $this->backend($missing)->reserve($this->policy(), $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command());
            self::fail('Expected an unavailable backend.');
        } catch (StateUnavailableException) {
            self::assertSame([], $this->harness()->client()->keys($missing->keyPrefix . ':*'));
        }

        $real = $this->harness()->registry();
        $foreign = new RedisRegistry($real->keyPrefix, 'someone-else', 'a-host-managed-secret-of-32-bytes!!');
        $this->expectException(CorruptStateException::class);
        $this->backend($foreign)->inspect($this->policy());
    }

    public function testAnOwnerIdentityOfAnExpiredReservationIsNotReusableWhileItsRecordLives(): void
    {
        $backend = $this->backend($this->harness()->registry());
        $policy = $this->policy(3, 10);
        $owner = $this->ids()->owner();

        self::assertSame(RetryReservationStatus::Reserved, $backend->reserve($policy, $this->ids()->attempt(), $owner, $this->ids()->command())->status);
        $this->harness()->time()->advance(10 * self::SECOND); // the lease ended; the next command turns it into an Expired record
        $this->expectException(InvalidConfigurationException::class);
        $backend->reserve($policy, $this->ids()->attempt(), $owner, $this->ids()->command());
    }

    public function testAnExpiryOrAttemptThatDiffersIsNotTheOwnersReservation(): void
    {
        $backend = $this->backend($this->harness()->registry());
        $policy = $this->policy(3, 120);
        $reservation = $this->reserve($backend, $policy);
        $instant = $reservation->expiresAt->sharedInstant;
        self::assertNotNull($instant);

        $forge = static fn(?BackendExpiry $expiry = null, ?AttemptId $attempt = null): RetryBudgetReservation => new RetryBudgetReservation(
            $reservation->policy,
            $reservation->incarnation,
            $reservation->generation,
            $attempt ?? $reservation->attempt,
            $reservation->owner,
            $reservation->lease,
            $expiry ?? $reservation->expiresAt,
        );
        $microseconds = $instant->microseconds === 0 ? 1 : $instant->microseconds - 1;
        $forgeries = [
            $forge(BackendExpiry::shared(new BackendInstant($instant->seconds, $microseconds))),
            $forge(BackendExpiry::shared(new BackendInstant($instant->seconds + 1, $instant->microseconds))),
            $forge(null, $this->ids()->attempt()),
        ];
        foreach ($forgeries as $forged) {
            self::assertSame(RetryBudgetFinalizationStatus::StaleOwnership, $backend->commit($policy, $forged, $this->ids()->command())->status);
            self::assertSame(RetryBudgetFinalizationStatus::StaleOwnership, $backend->release($policy, $forged, $this->ids()->command())->status);
        }
        self::assertSame(2, $backend->inspect($policy)->availableTokens->value, 'the forgeries changed nothing');
        self::assertSame(RetryBudgetFinalizationStatus::Committed, $backend->commit($policy, $reservation, $this->ids()->command())->status);
    }

    public function testAnOwnerIdentityIsFreeAgainOnceItsRecordHasEnded(): void
    {
        $backend = $this->backend($this->harness()->registry());
        $policy = $this->policy(3, 10);
        $owner = $this->ids()->owner();

        $first = $backend->reserve($policy, $this->ids()->attempt(), $owner, $this->ids()->command())->reservation;
        self::assertNotNull($first);
        $backend->release($policy, $first, $this->ids()->command());
        $this->harness()->time()->advance(121 * self::SECOND);

        self::assertSame(RetryReservationStatus::Reserved, $backend->reserve($policy, $this->ids()->attempt(), $owner, $this->ids()->command())->status);
    }
}
