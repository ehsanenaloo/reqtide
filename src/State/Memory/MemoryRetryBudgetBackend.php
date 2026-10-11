<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\State\Memory;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Exception\StateCapacityExceededException;
use Enaxon\Reqtide\Internal\Identity\IdentityGenerator;
use Enaxon\Reqtide\Internal\Identity\RandomIdentityGenerator;
use Enaxon\Reqtide\Policy\RetryBudgetPolicy;
use Enaxon\Reqtide\State\RetryBudgetBackend;
use Enaxon\Reqtide\State\RetryBudgetFinalizationResult;
use Enaxon\Reqtide\State\RetryBudgetFinalizationStatus;
use Enaxon\Reqtide\State\RetryBudgetInspection;
use Enaxon\Reqtide\State\RetryBudgetReservation;
use Enaxon\Reqtide\State\RetryReservationDecision;
use Enaxon\Reqtide\State\RetryReservationStatus;
use Enaxon\Reqtide\State\StateBackendCapabilities;
use Enaxon\Reqtide\State\StateScope;
use Enaxon\Reqtide\Time\Clock;
use Enaxon\Reqtide\Value\AttemptId;
use Enaxon\Reqtide\Value\BackendExpiry;
use Enaxon\Reqtide\Value\CommandId;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\Generation;
use Enaxon\Reqtide\Value\NonNegativeInt;
use Enaxon\Reqtide\Value\PermitOwnerId;

/**
 * Process-local retry budget with pending reservations.
 *
 * A token bucket scaled by 1_000_000 where `balance + pendingCost <= capacity`. Refill is accrued with the OLD
 * pending cost before every transition, so a held reservation can never mint tokens through retroactive refill
 * or refund. Process-local only; `CommandId` needs no deduplication record here (no lost replies), and repeat
 * safety comes from the owner's stored terminal disposition.
 *
 * Capacity pressure first reclaims expired records, then evicts the OLDEST unexpired terminal tombstones
 * (globally, in bounded batches) before rejecting new allocation. Pending reservations and bucket state are never
 * evicted. A missing tombstone yields StaleOwnership for a duplicate finalization (never a second commit or
 * refund), because finalization acts only on a matching pending reservation.
 *
 * `maintain()` and `snapshot()` are a backend-specific administrative/diagnostic API of the memory backends. They
 * are not part of the {@see RetryBudgetBackend} interface and callers must not rely on them through it.
 */
final class MemoryRetryBudgetBackend implements RetryBudgetBackend
{
    public const TOKEN_SCALE = 1_000_000;

    /** One reservation costs one retry token. */
    private const COST = self::TOKEN_SCALE;

    /** @var array<string, BudgetPartition> */
    private array $partitions = [];

    /** @var \SplQueue<string> */
    private \SplQueue $rotation;

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

    /**
     * Mutation-free: applies the protected effective time (`peek()`, nothing stored) and the accrue-then-expire
     * ordering to local values only. Absent state reads as a fresh full bucket without allocating a partition, and
     * no tombstone, record count, rotation entry or watermark changes.
     */
    public function inspect(RetryBudgetPolicy $policy): RetryBudgetInspection
    {
        $partition = $this->partitions[$policy->identity->key()] ?? null;
        if ($partition === null) {
            return new RetryBudgetInspection(new NonNegativeInt($policy->capacityTokens->value), true, null);
        }
        $this->assertFingerprint($partition, $policy);
        [$balance, $pendingCost, $remainder] = $partition->viewAt($this->time->peek());
        $available = new NonNegativeInt(intdiv($balance, self::TOKEN_SCALE));
        if ($balance >= self::COST) {
            return new RetryBudgetInspection($available, true, null);
        }

        return new RetryBudgetInspection($available, false, $this->waitFor($partition, $balance, $pendingCost, $remainder));
    }

    public function reserve(RetryBudgetPolicy $policy, AttemptId $attempt, PermitOwnerId $owner, CommandId $command): RetryReservationDecision
    {
        $now = $this->time->advance();
        $key = $policy->identity->key();
        $ownerKey = $owner->lowerHex;

        $partition = $this->partitions[$key] ?? null;
        if ($partition !== null) {
            $this->assertFingerprint($partition, $policy);
            $partition->transition($now);
            $this->records -= $partition->pruneTombstones($now, PHP_INT_MAX);
            if (isset($partition->pending[$ownerKey]) || isset($partition->tombstones[$ownerKey])) {
                throw new InvalidConfigurationException('A reservation owner identity must be unique.');
            }
            if ($partition->balance < self::COST) {
                return new RetryReservationDecision(RetryReservationStatus::Exhausted, null, $this->waitFor($partition, $partition->balance, $partition->pendingCost, $partition->remainder));
            }
        }
        $expiresAt = MemoryLimits::checkedAdd($now, $policy->reservationLease->nanoseconds);

        // New allocation is checked before any state change; maintenance may reclaim nonprotective records.
        $this->reserveCapacity($key);
        $partition = $this->partitions[$key] ?? null;
        if ($partition === null) {
            $capacity = $policy->capacityTokens->value * self::TOKEN_SCALE;
            $partition = new BudgetPartition(
                $this->fingerprint($policy),
                $this->identities->incarnation(),
                Generation::first(),
                $capacity,
                $policy->refillTokens->value * self::TOKEN_SCALE,
                $policy->refillInterval->nanoseconds,
                $capacity,
                $now,
                $now,
            );
            $this->partitions[$key] = $partition;
            $this->rotation->enqueue($key);
        }

        $partition->balance -= self::COST;
        $partition->pendingCost += self::COST;
        $partition->pending[$ownerKey] = ['attempt' => $attempt->lowerHex, 'expiresAt' => $expiresAt, 'cost' => self::COST];
        $partition->lastMutation = $now;
        ++$this->records;

        return new RetryReservationDecision(
            RetryReservationStatus::Reserved,
            new RetryBudgetReservation(
                $policy->identity,
                $partition->incarnation,
                $partition->generation,
                $attempt,
                $owner,
                $policy->reservationLease,
                BackendExpiry::local($expiresAt),
            ),
            null,
        );
    }

    public function commit(RetryBudgetPolicy $policy, RetryBudgetReservation $reservation, CommandId $command): RetryBudgetFinalizationResult
    {
        return $this->finalize($policy, $reservation, true);
    }

    public function release(RetryBudgetPolicy $policy, RetryBudgetReservation $reservation, CommandId $command): RetryBudgetFinalizationResult
    {
        return $this->finalize($policy, $reservation, false);
    }

    /**
     * Bounded cleanup: examines at most `$maxRecords` (capped at 128) partitions/records. Expired reservations are
     * refunded under the accrue-then-expire ordering; elapsed tombstones are removed; a partition is deleted only
     * when it is equivalent to a fresh full bucket and has been idle for 120 seconds.
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
            $partition = $this->partitions[$key] ?? null;
            if ($partition === null) {
                continue;
            }
            $partition->transition($now);
            $count = $partition->pruneTombstones($now, $budget);
            $this->records -= $count;
            $removed += $count;
            $budget -= $count;
            if (
                $partition->recordCount() === 0
                && $partition->balance === $partition->capacity
                && $now - $partition->lastMutation >= MemoryLimits::TOMBSTONE_RETENTION_NANOSECONDS
            ) {
                unset($this->partitions[$key]);
                continue;
            }
            $this->rotation->enqueue($key);
        }

        return $removed;
    }

    /**
     * Read-only view of the state at the current effective time, in scaled units (1 token = 1_000_000).
     * Writes nothing, allocates nothing.
     *
     * @internal Diagnostic for tests; not part of the backend contract.
     * @return array{balance: int, pending: int}|null null when no state exists
     */
    public function snapshot(RetryBudgetPolicy $policy): ?array
    {
        $partition = $this->partitions[$policy->identity->key()] ?? null;
        if ($partition === null) {
            return null;
        }
        $view = clone $partition;
        $view->transition($this->time->peek());

        return ['balance' => $view->balance, 'pending' => $view->pendingCost];
    }

    private function finalize(RetryBudgetPolicy $policy, RetryBudgetReservation $reservation, bool $commit): RetryBudgetFinalizationResult
    {
        $now = $this->time->advance();
        $partition = $this->partitions[$policy->identity->key()] ?? null;
        if ($partition === null || !$reservation->policy->equals($policy->identity)) {
            return new RetryBudgetFinalizationResult(RetryBudgetFinalizationStatus::StaleOwnership);
        }
        $this->assertFingerprint($partition, $policy);
        if (!$partition->incarnation->equals($reservation->incarnation) || !$partition->generation->equals($reservation->generation)) {
            return new RetryBudgetFinalizationResult(RetryBudgetFinalizationStatus::StaleOwnership);
        }

        // Accrue with the OLD pending cost, then expire (including this owner when its lease ended at or before now).
        $partition->transition($now);
        $this->records -= $partition->pruneTombstones($now, PHP_INT_MAX);

        $ownerKey = $reservation->owner->lowerHex;
        $pending = $partition->pending[$ownerKey] ?? null;
        if ($pending !== null) {
            if ($pending['attempt'] !== $reservation->attempt->lowerHex || $pending['expiresAt'] !== $reservation->expiresAt->localMonotonicNanoseconds) {
                return new RetryBudgetFinalizationResult(RetryBudgetFinalizationStatus::StaleOwnership);
            }
            // The pending slot becomes the tombstone in place: finalization never needs a new allocation.
            unset($partition->pending[$ownerKey]);
            $partition->pendingCost -= $pending['cost'];
            if (!$commit) {
                $partition->balance = min($partition->balance + $pending['cost'], $partition->capacity - $partition->pendingCost);
            }
            $status = $commit ? RetryBudgetFinalizationStatus::Committed : RetryBudgetFinalizationStatus::Released;
            $partition->tombstones[$ownerKey] = [
                'status' => $status,
                'until' => MemoryLimits::saturatingAdd($now, MemoryLimits::TOMBSTONE_RETENTION_NANOSECONDS),
            ];
            $partition->lastMutation = $now;

            return new RetryBudgetFinalizationResult($status);
        }

        $tombstone = $partition->tombstones[$ownerKey] ?? null;
        if ($tombstone === null) {
            return new RetryBudgetFinalizationResult(RetryBudgetFinalizationStatus::StaleOwnership);
        }

        return new RetryBudgetFinalizationResult(match ($tombstone['status']) {
            RetryBudgetFinalizationStatus::Committed => RetryBudgetFinalizationStatus::AlreadyCommitted,
            RetryBudgetFinalizationStatus::Released => RetryBudgetFinalizationStatus::AlreadyReleased,
            default => RetryBudgetFinalizationStatus::Expired,
        });
    }

    /** Earliest wait until one more token is available, or null when held reservations leave no headroom for it. */
    private function waitFor(BudgetPartition $partition, int $balance, int $pendingCost, int $remainder): ?Duration
    {
        if ($partition->capacity - $pendingCost < self::COST) {
            return null;
        }
        $ticks = ScaledArithmetic::ceilTicksToCover(self::COST - $balance, $partition->interval, $partition->rate, $remainder);

        return Duration::nanoseconds($ticks);
    }

    /** Makes room for one new reservation (and its partition when absent), or throws before any mutation. */
    private function reserveCapacity(string $key): void
    {
        if ($this->hasRoom(!isset($this->partitions[$key]))) {
            return;
        }
        $this->maintain();
        if ($this->hasRoom(!isset($this->partitions[$key]))) {
            return;
        }
        $this->evictTombstones();
        if (!$this->hasRoom(!isset($this->partitions[$key]))) {
            throw new StateCapacityExceededException('Retry budget state registry is at capacity; new allocation rejected.');
        }
    }

    /**
     * Evicts the globally oldest tombstones (bounded batch). Pending reservations and bucket balances are
     * protective and never touched; partitions are not deleted here (maintenance owns that rule).
     */
    private function evictTombstones(): void
    {
        $heads = [];
        foreach ($this->partitions as $key => $partition) {
            $first = array_key_first($partition->tombstones);
            if ($first !== null) {
                $heads[(string) $key] = $partition->tombstones[$first]['until'];
            }
        }
        MemoryLimits::evictOldest($heads, function (string $key): ?int {
            $partition = $this->partitions[$key];
            $first = array_key_first($partition->tombstones);
            if ($first === null) {
                return null;
            }
            unset($partition->tombstones[$first]);
            --$this->records;
            $next = array_key_first($partition->tombstones);

            return $next === null ? null : $partition->tombstones[$next]['until'];
        }, MemoryLimits::evictionBatch($this->maxRecords));
    }

    private function hasRoom(bool $needsPartition): bool
    {
        return $this->records < $this->maxRecords && (!$needsPartition || count($this->partitions) < $this->maxPartitions);
    }

    private function fingerprint(RetryBudgetPolicy $policy): string
    {
        return $policy->capacityTokens->value . '|' . $policy->refillTokens->value . '|'
            . $policy->refillInterval->nanoseconds . '|' . $policy->reservationLease->nanoseconds;
    }

    private function assertFingerprint(BudgetPartition $partition, RetryBudgetPolicy $policy): void
    {
        if ($partition->fingerprint !== $this->fingerprint($policy)) {
            throw new InvalidConfigurationException('Policy identity is already bound to different retry budget settings.');
        }
    }
}
