<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Clocked;

use Enaxon\Reqtide\Exception\CorruptStateException;
use Enaxon\Reqtide\Exception\StateCapacityExceededException;
use Enaxon\Reqtide\Exception\StateUnavailableException;
use Enaxon\Reqtide\Policy\ConcurrencyPolicy;
use Enaxon\Reqtide\Policy\CooldownPolicy;
use Enaxon\Reqtide\Redis\RedisConcurrencyBackend;
use Enaxon\Reqtide\Redis\RedisCooldownBackend;
use Enaxon\Reqtide\Redis\RedisRegistry;
use Enaxon\Reqtide\Redis\RedisRegistryAdmin;
use Enaxon\Reqtide\Redis\RegistryStatus;
use Enaxon\Reqtide\Redis\Tests\Support\RedisBackendHarness;
use Enaxon\Reqtide\State\ConcurrencyAdmissionStatus;
use Enaxon\Reqtide\State\ConcurrencyPermit;
use Enaxon\Reqtide\State\CooldownStatus;
use Enaxon\Reqtide\State\FinalizationStatus;
use Enaxon\Reqtide\State\Memory\MemoryConcurrencyBackend;
use Enaxon\Reqtide\Tests\Contract\ContractIdentities;
use Enaxon\Reqtide\Time\SystemClock;
use Enaxon\Reqtide\Value\ApplicationId;
use Enaxon\Reqtide\Value\BackendExpiry;
use Enaxon\Reqtide\Value\BackendInstant;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\EnvironmentId;
use Enaxon\Reqtide\Value\Generation;
use Enaxon\Reqtide\Value\IncarnationId;
use Enaxon\Reqtide\Value\PartitionIdentity;
use Enaxon\Reqtide\Value\PolicyId;
use Enaxon\Reqtide\Value\PolicyIdentity;
use Enaxon\Reqtide\Value\PositiveInt;
use Enaxon\Reqtide\Value\ProfileVersion;
use Enaxon\Reqtide\Value\SubjectId;
use Enaxon\Reqtide\Value\TenantId;
use PHPUnit\Framework\TestCase;

/**
 * What only a shared store can show beyond the shared contract: several backend instances over one registry
 * see one state, the registry counters follow every allocation and release, tombstones are evicted registry-wide, a
 * ceiling rejection changes nothing, identities do not leak across tenants, and a missing or foreign registry is never
 * created or trusted.
 */
final class RedisSharedStateTest extends TestCase
{
    private const SECOND = 1_000_000_000;

    private ?RedisBackendHarness $harness = null;

    private ?ContractIdentities $identities = null;

    protected function setUp(): void
    {
        $this->harness()->time()->moveTo(100 * self::SECOND);
    }

    private function ids(): ContractIdentities
    {
        return $this->identities ??= new ContractIdentities();
    }

    protected function tearDown(): void
    {
        $this->harness?->dispose();
    }

    private function harness(): RedisBackendHarness
    {
        return $this->harness ??= RedisBackendHarness::create();
    }

    private function cooldown(RedisRegistry $registry): RedisCooldownBackend
    {
        return new RedisCooldownBackend($this->harness()->options(), $registry);
    }

    private function concurrency(RedisRegistry $registry): RedisConcurrencyBackend
    {
        return new RedisConcurrencyBackend($this->harness()->options(), $registry);
    }

    private function registryStatus(RedisRegistry $registry): RegistryStatus
    {
        return (new RedisRegistryAdmin($this->harness()->options(), $registry))->verify();
    }

    private function lease(string $subject = 'subject', int $capacity = 1, int $seconds = 10): ConcurrencyPolicy
    {
        return new ConcurrencyPolicy(ContractIdentities::identity($subject), new PositiveInt($capacity), Duration::seconds($seconds));
    }

    private function cool(string $subject = 'subject'): CooldownPolicy
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

    public function testTwoInstancesOverOneRegistryShareACooldown(): void
    {
        $registry = $this->harness()->registry();
        $first = $this->cooldown($registry);
        $second = $this->cooldown($registry);

        $first->extend($this->cool(), Duration::seconds(5), $this->ids()->command());
        self::assertSame(5 * self::SECOND, $second->inspect($this->cool())->remaining->nanoseconds);

        $this->harness()->time()->advance(5 * self::SECOND);
        self::assertSame(CooldownStatus::Clear, $second->inspect($this->cool())->status);
    }

    public function testTwoInstancesShareConcurrencyCapacityAndEitherCanRelease(): void
    {
        $registry = $this->harness()->registry();
        $first = $this->concurrency($registry);
        $second = $this->concurrency($registry);
        $policy = $this->lease();

        $permit = $this->acquire($first, $policy);
        self::assertSame(ConcurrencyAdmissionStatus::CapacityExhausted, $second->acquire($policy, $this->ids()->owner(), $this->ids()->command())->status);

        self::assertSame(FinalizationStatus::Applied, $second->release($policy, $permit, $this->ids()->command())->status);
        $this->acquire($first, $policy);
    }

    public function testRegistryCountersFollowCooldownAllocation(): void
    {
        $registry = $this->harness()->registry();
        $backend = $this->cooldown($registry);

        $backend->extend($this->cool('a'), Duration::zero(), $this->ids()->command());
        self::assertSame([0, 0], [$this->registryStatus($registry)->partitions, $this->registryStatus($registry)->records], 'a zero extension allocates nothing');

        $backend->extend($this->cool('a'), Duration::seconds(5), $this->ids()->command());
        $backend->extend($this->cool('a'), Duration::seconds(9), $this->ids()->command());
        $backend->extend($this->cool('b'), Duration::seconds(5), $this->ids()->command());
        $status = $this->registryStatus($registry);
        self::assertSame([2, 2], [$status->partitions, $status->records], 'one partition and one record per cooldown');
    }

    public function testRegistryCountersFollowConcurrencyAcquireReleaseAndReclamation(): void
    {
        $registry = $this->harness()->registry();
        $backend = $this->concurrency($registry);
        $policy = $this->lease('a', 3);

        $first = $this->acquire($backend, $policy);
        $this->acquire($backend, $policy);
        $status = $this->registryStatus($registry);
        self::assertSame([1, 2], [$status->partitions, $status->records]);

        $backend->release($policy, $first, $this->ids()->command());
        $status = $this->registryStatus($registry);
        self::assertSame([1, 2], [$status->partitions, $status->records], 'the slot of the owner becomes its tombstone in place');

        // Both leases and the tombstone are long gone: the next acquire reclaims them, drops the empty partition and
        // starts a new incarnation.
        $this->harness()->time()->advance(121 * self::SECOND);
        $fresh = $this->acquire($backend, $policy);
        $status = $this->registryStatus($registry);
        self::assertSame([1, 1], [$status->partitions, $status->records]);
        self::assertFalse($fresh->incarnation->equals($first->incarnation));
    }

    public function testTheOldestTombstoneIsEvictedAcrossPartitionsUnderRecordPressure(): void
    {
        $registry = $this->harness()->registry(10, 3);
        $backend = $this->concurrency($registry);
        $a = $this->lease('a');
        $b = $this->lease('b');
        $c = $this->lease('c', 2);

        $permitA = $this->acquire($backend, $a);
        $backend->release($a, $permitA, $this->ids()->command());
        $this->harness()->time()->advance(1 * self::SECOND);
        $permitB = $this->acquire($backend, $b);
        $backend->release($b, $permitB, $this->ids()->command());
        $this->acquire($backend, $c);
        $status = $this->registryStatus($registry);
        self::assertSame([3, 3], [$status->partitions, $status->records], 'at the ceiling: two tombstones and one lease');

        // One more owner needs a record: the globally oldest tombstone (partition a) is evicted and its partition,
        // now empty, is deleted; the tombstone of partition b survives.
        $this->acquire($backend, $c);
        $status = $this->registryStatus($registry);
        self::assertSame([2, 3], [$status->partitions, $status->records]);
        self::assertSame(FinalizationStatus::StaleOwnership, $backend->release($a, $permitA, $this->ids()->command())->status);
        self::assertSame(FinalizationStatus::AlreadyFinalized, $backend->release($b, $permitB, $this->ids()->command())->status);
    }

    public function testACeilingRejectionChangesNeitherStateNorCounters(): void
    {
        $registry = $this->harness()->registry(10, 2);
        $backend = $this->concurrency($registry);
        $policy = $this->lease('a', 5);
        $first = $this->acquire($backend, $policy);
        $second = $this->acquire($backend, $policy);

        try {
            $backend->acquire($policy, $this->ids()->owner(), $this->ids()->command());
            self::fail('Expected a capacity rejection.');
        } catch (StateCapacityExceededException) {
        }

        $status = $this->registryStatus($registry);
        self::assertSame([1, 2], [$status->partitions, $status->records]);
        self::assertSame(FinalizationStatus::Applied, $backend->release($policy, $first, $this->ids()->command())->status);
        self::assertSame(FinalizationStatus::Applied, $backend->release($policy, $second, $this->ids()->command())->status);
    }

    public function testAnExpiredOwnerIdentityMayBeUsedAgain(): void
    {
        $backend = $this->concurrency($this->harness()->registry());
        $policy = $this->lease('a', 1, 10);
        $owner = $this->ids()->owner();

        self::assertSame(ConcurrencyAdmissionStatus::Accepted, $backend->acquire($policy, $owner, $this->ids()->command())->status);
        $this->harness()->time()->advance(10 * self::SECOND); // the lease is expired at equality
        $again = $backend->acquire($policy, $owner, $this->ids()->command());
        self::assertSame(ConcurrencyAdmissionStatus::Accepted, $again->status);

        // Finalized, tombstone retained, then gone: the identity is free again.
        self::assertNotNull($again->permit);
        $backend->release($policy, $again->permit, $this->ids()->command());
        $this->harness()->time()->advance(121 * self::SECOND);
        self::assertSame(ConcurrencyAdmissionStatus::Accepted, $backend->acquire($policy, $owner, $this->ids()->command())->status);
    }

    public function testAnExpiryThatDiffersByOneMicrosecondIsNotTheOwnersPermit(): void
    {
        $backend = $this->concurrency($this->harness()->registry());
        $policy = $this->lease();
        $permit = $this->acquire($backend, $policy);
        $instant = $permit->expiresAt->sharedInstant;
        self::assertNotNull($instant);

        foreach ([$instant->microseconds + 1, $instant->microseconds - 1] as $microseconds) {
            if ($microseconds < 0 || $microseconds > 999_999) {
                continue;
            }
            $forged = new ConcurrencyPermit(
                $permit->policy,
                $permit->incarnation,
                $permit->generation,
                $permit->owner,
                $permit->lease,
                BackendExpiry::shared(new BackendInstant($instant->seconds, $microseconds)),
            );
            self::assertSame(FinalizationStatus::StaleOwnership, $backend->release($policy, $forged, $this->ids()->command())->status);
        }
        self::assertSame(FinalizationStatus::Applied, $backend->release($policy, $permit, $this->ids()->command())->status);
    }

    public function testAnIncarnationOrGenerationThatDiffersIsNotTheOwnersPermit(): void
    {
        $backend = $this->concurrency($this->harness()->registry());
        $policy = $this->lease();
        $permit = $this->acquire($backend, $policy);

        $otherGeneration = new ConcurrencyPermit($permit->policy, $permit->incarnation, new Generation($permit->generation->value + 1), $permit->owner, $permit->lease, $permit->expiresAt);
        $otherIncarnation = new ConcurrencyPermit($permit->policy, IncarnationId::fromLowerHex(str_repeat('ab', 16)), $permit->generation, $permit->owner, $permit->lease, $permit->expiresAt);
        self::assertSame(FinalizationStatus::StaleOwnership, $backend->release($policy, $otherGeneration, $this->ids()->command())->status);
        self::assertSame(FinalizationStatus::StaleOwnership, $backend->release($policy, $otherIncarnation, $this->ids()->command())->status);
        self::assertSame(FinalizationStatus::Applied, $backend->release($policy, $permit, $this->ids()->command())->status);
    }

    public function testAStaleQueueEntryNeverRemovesANewerTombstoneOfTheSameOwner(): void
    {
        $registry = $this->harness()->registry();
        $backend = $this->concurrency($registry);
        $policy = $this->lease('a', 1, 10);

        // 256 tombstones stand ahead of the one of $owner in the registry queue; one reclamation batch is 128 entries and
        // both the acquire and the release below reclaim one batch, so the entry of $owner is still queued after them.
        for ($i = 0; $i < 256; ++$i) {
            $filler = $this->acquire($backend, $policy);
            $backend->release($policy, $filler, $this->ids()->command());
        }
        $owner = $this->ids()->owner();
        $first = $backend->acquire($policy, $owner, $this->ids()->command())->permit;
        self::assertNotNull($first);
        $backend->release($policy, $first, $this->ids()->command());

        $this->harness()->time()->advance(121 * self::SECOND);
        // The same owner identity is free again; its old tombstone is dropped here and its queue entry becomes stale.
        $second = $backend->acquire($policy, $owner, $this->ids()->command())->permit;
        self::assertNotNull($second);
        $backend->release($policy, $second, $this->ids()->command());
        $queue = $registry->keyPrefix . ':t';
        self::assertSame(2, $this->harness()->client()->llen($queue), 'the stale entry of the first tombstone and the entry of the second');

        // The next command meets the stale entry (expired, same owner, different deadline) and must skip it.
        $this->harness()->time()->advance(1 * self::SECOND);
        $this->acquire($backend, $policy);
        self::assertSame(FinalizationStatus::AlreadyFinalized, $backend->release($policy, $second, $this->ids()->command())->status);
    }

    public function testTenantsAndSubjectsAreSeparatePartitions(): void
    {
        $backend = $this->concurrency($this->harness()->registry());
        $identity = static fn(?string $tenant, string $subject): PolicyIdentity => new PolicyIdentity(
            new PartitionIdentity(new ApplicationId('app'), new EnvironmentId('test'), $tenant === null ? null : new TenantId($tenant), new SubjectId($subject)),
            new ProfileVersion('v1'),
            new PolicyId('policy'),
        );

        foreach ([[null, 's'], ['t1', 's'], ['t2', 's'], ['t1', 'other']] as [$tenant, $subject]) {
            $policy = new ConcurrencyPolicy($identity($tenant, $subject), new PositiveInt(1), Duration::seconds(10));
            self::assertSame(ConcurrencyAdmissionStatus::Accepted, $backend->acquire($policy, $this->ids()->owner(), $this->ids()->command())->status, 'each identity has its own capacity');
        }
    }

    public function testAPermitOfAnotherBackendKindIsStaleAndNothingIsStored(): void
    {
        $registry = $this->harness()->registry();
        $redis = $this->concurrency($registry);
        $policy = $this->lease();
        $local = (new MemoryConcurrencyBackend(new SystemClock()))->acquire($policy, $this->ids()->owner(), $this->ids()->command())->permit;
        self::assertNotNull($local);

        self::assertSame(FinalizationStatus::StaleOwnership, $redis->release($policy, $local, $this->ids()->command())->status);
        $status = $this->registryStatus($registry);
        self::assertSame([0, 0], [$status->partitions, $status->records]);
    }

    public function testAMissingRegistryIsUnavailableAndIsNeverCreated(): void
    {
        $missing = new RedisRegistry('rt_c_missing' . bin2hex(random_bytes(4)), 'contract', 'a-host-managed-secret-of-32-bytes!!');

        try {
            $this->concurrency($missing)->acquire($this->lease(), $this->ids()->owner(), $this->ids()->command());
            self::fail('Expected an unavailable backend.');
        } catch (StateUnavailableException) {
        }
        try {
            $this->cooldown($missing)->extend($this->cool(), Duration::seconds(1), $this->ids()->command());
            self::fail('Expected an unavailable backend.');
        } catch (StateUnavailableException) {
        }

        self::assertSame(0, $this->harness()->client()->exists($missing->keyPrefix . ':m'));
        self::assertSame([], $this->harness()->client()->keys($missing->keyPrefix . ':*'));
    }

    public function testARegistryWithAnotherIdentityIsRefused(): void
    {
        $real = $this->harness()->registry();
        $foreign = new RedisRegistry($real->keyPrefix, 'someone-else', 'a-host-managed-secret-of-32-bytes!!');

        $this->expectException(CorruptStateException::class);
        $this->concurrency($foreign)->acquire($this->lease(), $this->ids()->owner(), $this->ids()->command());
    }

    public function testCapacityAtScaleIsExact(): void
    {
        $registry = $this->harness()->registry();
        $backend = $this->concurrency($registry);
        $policy = $this->lease('a', 40);

        $permits = [];
        for ($i = 0; $i < 40; ++$i) {
            $permits[] = $this->acquire($backend, $policy);
        }
        self::assertSame(ConcurrencyAdmissionStatus::CapacityExhausted, $backend->acquire($policy, $this->ids()->owner(), $this->ids()->command())->status);

        foreach (array_slice($permits, 0, 10) as $permit) {
            self::assertSame(FinalizationStatus::Applied, $backend->release($policy, $permit, $this->ids()->command())->status);
        }
        for ($i = 0; $i < 10; ++$i) {
            $this->acquire($backend, $policy);
        }
        self::assertSame(ConcurrencyAdmissionStatus::CapacityExhausted, $backend->acquire($policy, $this->ids()->owner(), $this->ids()->command())->status);
    }

    public function testACooldownIsNeverShorterThanRequested(): void
    {
        $backend = $this->cooldown($this->harness()->registry());

        // The decision resolution is one microsecond and a duration is rounded up to it.
        self::assertSame(2_000, $backend->extend($this->cool('a'), Duration::nanoseconds(1_500), $this->ids()->command())->remaining->nanoseconds);
        self::assertSame(1_000, $backend->extend($this->cool('b'), Duration::nanoseconds(1), $this->ids()->command())->remaining->nanoseconds);
    }
}
