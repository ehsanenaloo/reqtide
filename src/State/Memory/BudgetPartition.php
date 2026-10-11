<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\State\Memory;

use Enaxon\Reqtide\State\RetryBudgetFinalizationStatus;
use Enaxon\Reqtide\Value\Generation;
use Enaxon\Reqtide\Value\IncarnationId;

/**
 * Mutable per-policy retry-budget state.
 *
 * All amounts are scaled units (1 token = 1_000_000). Invariant: `0 <= balance + pending <= capacity`.
 * `pending` and `tombstones` hold nondecreasing deadlines, oldest first, because the lease and the retention
 * are constant per policy identity and appended at nondecreasing effective time.
 *
 * @internal
 */
final class BudgetPartition
{
    /** @var array<string, array{attempt: string, expiresAt: int, cost: int}> owner hex => pending reservation */
    public array $pending = [];

    /** @var array<string, array{status: RetryBudgetFinalizationStatus, until: int}> owner hex => terminal record */
    public array $tombstones = [];

    public int $pendingCost = 0;

    public int $remainder = 0;

    public function __construct(
        public readonly string $fingerprint,
        public readonly IncarnationId $incarnation,
        public readonly Generation $generation,
        public readonly int $capacity,
        public readonly int $rate,
        public readonly int $interval,
        public int $balance,
        public int $lastRefill,
        public int $lastMutation,
    ) {}

    /**
     * Accrues elapsed refill to `$now` using the CURRENT pending cost: headroom is `capacity - pendingCost`,
     * excess refill and remainder are discarded at that cap, and the refill timestamp advances.
     */
    public function accrue(int $now): void
    {
        if ($now - $this->lastRefill <= 0) {
            return;
        }
        [$this->balance, $this->remainder] = $this->accruedAt($now);
        $this->lastRefill = $now;
    }

    /**
     * Pure form of {@see accrue()} followed by {@see expire()}: the balance, pending cost and refill remainder
     * this partition would hold at `$now`. Writes nothing; it backs mutation-free inspection.
     *
     * @return array{0: int, 1: int, 2: int} balance, pending cost, remainder (scaled units)
     */
    public function viewAt(int $now): array
    {
        [$balance, $remainder] = $this->accruedAt($now);
        $pendingCost = $this->pendingCost;
        foreach ($this->pending as $entry) {
            if ($entry['expiresAt'] > $now) {
                break;
            }
            $pendingCost -= $entry['cost'];
            $balance = min($balance + $entry['cost'], $this->capacity - $pendingCost);
        }

        return [$balance, $pendingCost, $remainder];
    }

    /** @return array{0: int, 1: int} balance and remainder after refill to `$now` with the CURRENT pending cost */
    private function accruedAt(int $now): array
    {
        $elapsed = $now - $this->lastRefill;
        if ($elapsed <= 0) {
            return [$this->balance, $this->remainder];
        }
        $headroom = $this->capacity - $this->pendingCost;
        $deficit = $headroom - $this->balance;
        if ($deficit <= 0) {
            return [$this->balance, 0];
        }
        [$added, $remainder, $capped] = ScaledArithmetic::mulAddDivFloorCapped($elapsed, $this->rate, $this->remainder, $this->interval, $deficit);
        if ($capped) {
            return [$headroom, 0];
        }

        return [$this->balance + $added, $remainder];
    }

    /**
     * Converts every lease-expired pending owner (expired at equality) into an Expired tombstone in place,
     * refunding only its pending cost, capped at the NEW headroom. Call after {@see accrue()}.
     */
    public function expire(int $now): void
    {
        while (($first = array_key_first($this->pending)) !== null && $this->pending[$first]['expiresAt'] <= $now) {
            $cost = $this->pending[$first]['cost'];
            unset($this->pending[$first]);
            $this->pendingCost -= $cost;
            $this->balance = min($this->balance + $cost, $this->capacity - $this->pendingCost);
            $this->tombstones[(string) $first] = [
                'status' => RetryBudgetFinalizationStatus::Expired,
                'until' => MemoryLimits::saturatingAdd($now, MemoryLimits::TOMBSTONE_RETENTION_NANOSECONDS),
            ];
            $this->lastMutation = $now;
        }
    }

    /** Applies the accrue-then-expire ordering required before any reserve, commit, release or expiry. */
    public function transition(int $now): void
    {
        $this->accrue($now);
        $this->expire($now);
    }

    /** @return int removed tombstones, at most $limit */
    public function pruneTombstones(int $now, int $limit): int
    {
        $removed = 0;
        while ($removed < $limit && ($first = array_key_first($this->tombstones)) !== null && $this->tombstones[$first]['until'] <= $now) {
            unset($this->tombstones[$first]);
            ++$removed;
        }

        return $removed;
    }

    public function recordCount(): int
    {
        return count($this->pending) + count($this->tombstones);
    }
}
