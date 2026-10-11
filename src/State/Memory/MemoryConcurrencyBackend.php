<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\State\Memory;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Exception\StateCapacityExceededException;
use Enaxon\Reqtide\Internal\Identity\IdentityGenerator;
use Enaxon\Reqtide\Internal\Identity\RandomIdentityGenerator;
use Enaxon\Reqtide\Policy\ConcurrencyPolicy;
use Enaxon\Reqtide\State\ConcurrencyAdmission;
use Enaxon\Reqtide\State\ConcurrencyAdmissionStatus;
use Enaxon\Reqtide\State\ConcurrencyBackend;
use Enaxon\Reqtide\State\ConcurrencyPermit;
use Enaxon\Reqtide\State\FinalizationResult;
use Enaxon\Reqtide\State\FinalizationStatus;
use Enaxon\Reqtide\State\StateBackendCapabilities;
use Enaxon\Reqtide\State\StateScope;
use Enaxon\Reqtide\Time\Clock;
use Enaxon\Reqtide\Value\BackendExpiry;
use Enaxon\Reqtide\Value\CommandId;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\Generation;
use Enaxon\Reqtide\Value\PermitOwnerId;

/**
 * Process-local concurrency leases.
 *
 * Single-threaded PHP makes each public method atomic; no callback, delay or observer runs inside one.
 * Process-local only: it gives no cross-process guarantee. `CommandId` is accepted for contract symmetry but
 * needs no deduplication record because a local call cannot lose its reply; repeat safety comes from owner
 * tombstones (120 s) and, after they expire, from the absence of an active owner.
 *
 * Capacity pressure first reclaims expired records, then evicts the OLDEST unexpired tombstones (globally,
 * in bounded batches) before rejecting new allocation. Active owners are never evicted, and an evicted tombstone
 * only turns a duplicate release from AlreadyFinalized into StaleOwnership; it can never free a slot twice because
 * release acts only on a matching active owner (incarnation, generation, owner and exact expiry).
 *
 * `maintain()` is a backend-specific administrative API of the memory backends. It is not part of the
 * {@see ConcurrencyBackend} interface and callers must not rely on it through that contract.
 *
 * @internal Process-local state; not a supported extension point.
 */
final class MemoryConcurrencyBackend implements ConcurrencyBackend
{
    /** @var array<string, ConcurrencyPartition> */
    private array $partitions = [];

    /** @var \SplQueue<string> maintenance rotation, each live partition exactly once */
    private \SplQueue $rotation;

    /** @var array<string, true> keys currently present in the rotation queue (a deleted partition may leave a ghost entry) */
    private array $queued = [];

    private int $records = 0;

    private readonly MonotonicWatermark $time;

    private readonly IdentityGenerator $identities;

    private readonly int $maxPartitions;

    private readonly int $maxRecords;

    public function __construct(
        Clock $clock,
        ?IdentityGenerator $identities = null,
        int $maxPartitions = MemoryLimits::DEFAULT_MAX_PARTITIONS,
        int $maxRecords = MemoryLimits::DEFAULT_MAX_RECORDS,
    ) {
        $this->time = new MonotonicWatermark($clock);
        $this->identities = $identities ?? new RandomIdentityGenerator();
        $this->maxPartitions = MemoryLimits::positive($maxPartitions, 'maxPartitions');
        $this->maxRecords = MemoryLimits::positive($maxRecords, 'maxRecords');
        $this->rotation = new \SplQueue();
    }

    public function capabilities(): StateBackendCapabilities
    {
        return new StateBackendCapabilities(StateScope::ProcessLocal, null, Duration::nanoseconds(1));
    }

    public function acquire(ConcurrencyPolicy $policy, PermitOwnerId $owner, CommandId $command): ConcurrencyAdmission
    {
        $now = $this->time->advance();
        $key = $policy->identity->key();
        $fingerprint = $policy->capacity->value . '|' . $policy->lease->nanoseconds;
        $ownerKey = $owner->lowerHex;

        $partition = $this->partitions[$key] ?? null;
        if ($partition !== null) {
            $this->assertFingerprint($partition, $fingerprint);
            $this->expire($partition, $now, PHP_INT_MAX);
            if (isset($partition->owners[$ownerKey]) || isset($partition->tombstones[$ownerKey])) {
                throw new InvalidConfigurationException('A permit owner identity must be unique.');
            }
            if (count($partition->owners) >= $policy->capacity->value) {
                return new ConcurrencyAdmission(ConcurrencyAdmissionStatus::CapacityExhausted, null);
            }
        }
        $expiresAt = MemoryLimits::checkedAdd($now, $policy->lease->nanoseconds);

        // New allocation is checked before any mutation. Maintenance may reclaim expired records and capacity
        // pressure may evict the oldest nonprotective tombstones.
        $this->reserveCapacity($key);
        $partition = $this->partitions[$key] ?? null;
        if ($partition === null) {
            $partition = new ConcurrencyPartition($fingerprint, $this->identities->incarnation(), Generation::first());
            $this->partitions[$key] = $partition;
            if (!isset($this->queued[$key])) {
                $this->queued[$key] = true;
                $this->rotation->enqueue($key);
            }
        }

        $partition->owners[$ownerKey] = $expiresAt;
        ++$this->records;

        return new ConcurrencyAdmission(
            ConcurrencyAdmissionStatus::Accepted,
            new ConcurrencyPermit(
                $policy->identity,
                $partition->incarnation,
                $partition->generation,
                $owner,
                $policy->lease,
                BackendExpiry::local($expiresAt),
            ),
        );
    }

    public function release(ConcurrencyPolicy $policy, ConcurrencyPermit $permit, CommandId $command): FinalizationResult
    {
        $now = $this->time->advance();
        $partition = $this->partitions[$policy->identity->key()] ?? null;
        if ($partition === null || !$permit->policy->equals($policy->identity)) {
            return new FinalizationResult(FinalizationStatus::StaleOwnership);
        }
        $this->assertFingerprint($partition, $policy->capacity->value . '|' . $policy->lease->nanoseconds);
        if (!$partition->incarnation->equals($permit->incarnation) || !$partition->generation->equals($permit->generation)) {
            return new FinalizationResult(FinalizationStatus::StaleOwnership);
        }

        // An expired lease (including exactly at expiry) is removed neutrally first, so it is stale.
        $this->expire($partition, $now, PHP_INT_MAX);

        $ownerKey = $permit->owner->lowerHex;
        if (isset($partition->owners[$ownerKey])) {
            if ($permit->expiresAt->localMonotonicNanoseconds !== $partition->owners[$ownerKey]) {
                return new FinalizationResult(FinalizationStatus::StaleOwnership);
            }
            // The owner's slot becomes its tombstone in place: no new allocation, so release cannot hit a ceiling.
            unset($partition->owners[$ownerKey]);
            $partition->tombstones[$ownerKey] = MemoryLimits::saturatingAdd($now, MemoryLimits::TOMBSTONE_RETENTION_NANOSECONDS);

            return new FinalizationResult(FinalizationStatus::Applied);
        }
        if (isset($partition->tombstones[$ownerKey])) {
            return new FinalizationResult(FinalizationStatus::AlreadyFinalized);
        }

        return new FinalizationResult(FinalizationStatus::StaleOwnership);
    }

    /**
     * Bounded cleanup: examines at most `$maxRecords` (capped at 128) partitions/records, removes expired owners
     * and elapsed tombstones, and deletes a partition once it holds nothing. Never evicts a valid lease.
     *
     * @phpstan-impure
     * @return int number of records removed
     */
    public function maintain(int $maxRecords = MemoryLimits::MAX_MAINTENANCE_BATCH): int
    {
        $budget = MemoryLimits::batch($maxRecords);
        $now = $this->time->advance();
        $removed = 0;
        $visits = min(count($this->rotation), $budget);
        while ($visits-- > 0) {
            $key = $this->rotation->dequeue();
            unset($this->queued[$key]);
            $partition = $this->partitions[$key] ?? null;
            if ($partition === null) {
                continue;
            }
            $count = $this->expire($partition, $now, $budget);
            $removed += $count;
            $budget -= $count;
            if ($partition->isEmpty()) {
                unset($this->partitions[$key]);
                continue;
            }
            $this->queued[$key] = true;
            $this->rotation->enqueue($key);
        }

        return $removed;
    }

    /** @return int removed records (owners and tombstones), at most $limit */
    private function expire(ConcurrencyPartition $partition, int $now, int $limit): int
    {
        $owners = MemoryLimits::pruneExpired($partition->owners, $now, $limit);
        $tombstones = MemoryLimits::pruneExpired($partition->tombstones, $now, max(0, $limit - $owners));
        $this->records -= $owners + $tombstones;

        return $owners + $tombstones;
    }

    /** Makes room for one new owner (and its partition when absent), or throws before any mutation. */
    private function reserveCapacity(string $key): void
    {
        if ($this->hasRoom(!isset($this->partitions[$key]))) {
            return;
        }
        $this->maintain();
        if ($this->hasRoom(!isset($this->partitions[$key]))) {
            return;
        }
        $this->evictTombstones($key);
        if (!$this->hasRoom(!isset($this->partitions[$key]))) {
            throw new StateCapacityExceededException('Concurrency state registry is at capacity; new allocation rejected.');
        }
    }

    /**
     * Evicts the globally oldest tombstones (bounded batch). Active owners are never touched; a partition left
     * with nothing at all is deleted, except `$keep`, the partition the caller is about to allocate in.
     */
    private function evictTombstones(string $keep): void
    {
        $heads = [];
        foreach ($this->partitions as $key => $partition) {
            $first = array_key_first($partition->tombstones);
            if ($first !== null) {
                $heads[(string) $key] = $partition->tombstones[$first];
            }
        }
        MemoryLimits::evictOldest($heads, function (string $key) use ($keep): ?int {
            $partition = $this->partitions[$key];
            $first = array_key_first($partition->tombstones);
            if ($first === null) {
                return null;
            }
            unset($partition->tombstones[$first]);
            --$this->records;
            $next = array_key_first($partition->tombstones);
            if ($next !== null) {
                return $partition->tombstones[$next];
            }
            if ($key !== $keep && $partition->isEmpty()) {
                unset($this->partitions[$key]);
            }

            return null;
        }, MemoryLimits::evictionBatch($this->maxRecords));
    }

    private function hasRoom(bool $needsPartition): bool
    {
        return $this->records < $this->maxRecords && (!$needsPartition || count($this->partitions) < $this->maxPartitions);
    }

    private function assertFingerprint(ConcurrencyPartition $partition, string $fingerprint): void
    {
        if ($partition->fingerprint !== $fingerprint) {
            throw new InvalidConfigurationException('Policy identity is already bound to different concurrency settings.');
        }
    }
}
