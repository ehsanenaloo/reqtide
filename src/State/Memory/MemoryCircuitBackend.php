<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\State\Memory;

use Enaxon\Reqtide\Error\ReasonCode;
use Enaxon\Reqtide\Exception\ClockFailureException;
use Enaxon\Reqtide\Exception\CorruptStateException;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Exception\StateCapacityExceededException;
use Enaxon\Reqtide\Internal\Identity\IdentityGenerator;
use Enaxon\Reqtide\Internal\Identity\RandomIdentityGenerator;
use Enaxon\Reqtide\Outcome\CircuitVerdict;
use Enaxon\Reqtide\Policy\CircuitPolicy;
use Enaxon\Reqtide\Policy\CountCircuitWindow;
use Enaxon\Reqtide\Policy\TimeCircuitWindow;
use Enaxon\Reqtide\State\CircuitAdmission;
use Enaxon\Reqtide\State\CircuitAdmissionStatus;
use Enaxon\Reqtide\State\CircuitBackend;
use Enaxon\Reqtide\State\CircuitPermit;
use Enaxon\Reqtide\State\CircuitPermitKind;
use Enaxon\Reqtide\State\CircuitTransition;
use Enaxon\Reqtide\State\FinalizationResult;
use Enaxon\Reqtide\State\FinalizationStatus;
use Enaxon\Reqtide\State\StateBackendCapabilities;
use Enaxon\Reqtide\State\StateScope;
use Enaxon\Reqtide\Time\Clock;
use Enaxon\Reqtide\Value\AttemptId;
use Enaxon\Reqtide\Value\BackendExpiry;
use Enaxon\Reqtide\Value\CommandId;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\Generation;
use Enaxon\Reqtide\Value\PermitOwnerId;
use Enaxon\Reqtide\Value\PolicyIdentity;

/**
 * Process-local circuit breaker state.
 *
 * All time is monotonic nanoseconds from the injected Clock; a reading below the highest one seen is
 * clamped to it, so time never moves backwards for this backend. State is bounded by a partition
 * ceiling and a ceiling on retained owner and deduplication records. Open and HalfOpen partitions,
 * partitions with active owners and closed count-window history are never evicted for capacity.
 *
 * The CommandId is accepted for contract compatibility. Duplicate finalization is recognised by the
 * permit itself (incarnation, generation, attempt, owner), which subsumes per-command deduplication
 * for the one command a permit can legitimately receive; recognition lasts 120 seconds.
 *
 * Capacity pressure on the record ceiling first reclaims expired records, then evicts the OLDEST unexpired
 * finalization tombstones (globally, in bounded batches) before rejecting new allocation. Active owners, Open and
 * HalfOpen state, and closed count-window history are never evicted. A missing tombstone turns a duplicate
 * finalization from AlreadyFinalized into StaleOwnership; it can never apply a second sample because finalization
 * acts only on a matching active owner (incarnation, generation, attempt, kind and exact expiry).
 *
 * A call that moves the circuit between states reports it as the optional `transition` of its
 * CircuitAdmission / FinalizationResult (closed, open, half_open). Only the call's own policy partition is
 * reported; transitions caused by `maintain()` or `reset()` are not reported through the contract results.
 *
 * `maintain()` and `reset()` are a backend-specific administrative API of the memory backends. They are not
 * part of the {@see CircuitBackend} interface and callers must not rely on them through that contract.
 *
 * Not thread-safe across PHP threads; PHP request execution is single-threaded and no method calls
 * user code while mutating, so each public method is atomic within the process.
 */
final class MemoryCircuitBackend implements CircuitBackend
{
    public const DEFAULT_MAX_PARTITIONS = 10_000;
    public const DEFAULT_MAX_RECORDS = 20_000;
    public const MAX_MAINTENANCE_RECORDS = 128;
    public const RETENTION_NANOSECONDS = 120_000_000_000;

    /** @var array<string, CircuitPartition> */
    private array $partitions = [];

    /** @var array<int, string> */
    private array $keys = [];

    /** @var array<string, int> */
    private array $keyIndex = [];

    private int $records = 0;
    private int $cursor = 0;
    private int $highWater = 0;

    /** Partition the current acquire/finalize call acts on; transitions of any other partition are not reported. */
    private ?CircuitPartition $subject = null;

    private ?CircuitTransition $transition = null;

    /**
     * @param int $maxPartitions ceiling on allocated partitions
     * @param int $maxRecords ceiling on retained active-owner plus tombstone records
     * @param IdentityGenerator $identities @internal seam used only for partition incarnations
     */
    public function __construct(
        private readonly Clock $clock,
        private readonly IdentityGenerator $identities = new RandomIdentityGenerator(),
        private readonly int $maxPartitions = self::DEFAULT_MAX_PARTITIONS,
        private readonly int $maxRecords = self::DEFAULT_MAX_RECORDS,
    ) {
        if ($maxPartitions < 1 || $maxRecords < 1) {
            throw new InvalidConfigurationException('Partition and record ceilings must be positive.');
        }
    }

    public function capabilities(): StateBackendCapabilities
    {
        return new StateBackendCapabilities(StateScope::ProcessLocal, null, Duration::nanoseconds(1));
    }

    public function acquire(CircuitPolicy $policy, AttemptId $attempt, PermitOwnerId $owner, CommandId $command): CircuitAdmission
    {
        $now = $this->now();
        $key = $policy->identity->key();
        $fingerprint = self::fingerprint($policy);
        $ownerKey = $owner->lowerHex;
        $part = $this->partitions[$key] ?? null;
        $this->transition = null;
        $this->subject = $part;

        if ($part !== null) {
            $this->assertBound($part, $fingerprint);
            $part->lastActivity = $now;
            $this->expire($part, $now, PHP_INT_MAX);

            $existing = $part->owners[$ownerKey] ?? null;
            if ($existing !== null) {
                if ($existing->attemptHex !== $attempt->lowerHex) {
                    throw new InvalidConfigurationException('The permit owner token is already in use by another attempt.');
                }

                return new CircuitAdmission(
                    CircuitAdmissionStatus::Accepted,
                    $this->permit($policy, $part, $attempt, $owner, $existing->kind, $existing->expiresAt),
                    null,
                    $this->transition,
                );
            }
        }

        if ($part !== null && $part->phase === CircuitPartition::OPEN && $now < $part->openUntil) {
            return new CircuitAdmission(CircuitAdmissionStatus::Open, null, new Duration($part->openUntil - $now), $this->transition);
        }
        if (
            $part !== null
            && $part->phase === CircuitPartition::HALF_OPEN
            && ($part->activeProbes >= $part->probeCapacity || $part->probeSuccesses >= $part->probeSuccessThreshold)
        ) {
            return new CircuitAdmission(CircuitAdmissionStatus::ProbeCapacityExhausted, null, null, $this->transition);
        }

        // From here the call admits: a Closed sample, a probe in HalfOpen, or the Open -> HalfOpen transition plus a probe.
        $expiresAt = self::add($now, $policy->permitLease->nanoseconds);
        if ($part !== null && $part->phase === CircuitPartition::CLOSED && count($part->owners) >= self::partitionPermitCap($this->maxRecords)) {
            throw new StateCapacityExceededException('The circuit partition holds the most active permits one partition may hold.');
        }
        $this->ensureRecordRoom();
        if ($part === null) {
            $this->ensurePartitionRoom();
            $part = $this->createPartition($key, $fingerprint, $policy, $now);
            $this->subject = $part;
        }

        if ($part->phase === CircuitPartition::OPEN) {
            $this->enterHalfOpen($part);
        }

        $kind = $part->phase === CircuitPartition::CLOSED
            ? CircuitPermitKind::ClosedSample
            : CircuitPermitKind::HalfOpenProbe;

        $part->owners[$ownerKey] = new CircuitOwner($attempt->lowerHex, $kind, $part->generation->value, $expiresAt);
        ++$this->records;
        if ($kind === CircuitPermitKind::HalfOpenProbe) {
            ++$part->activeProbes;
        }

        return new CircuitAdmission(
            CircuitAdmissionStatus::Accepted,
            $this->permit($policy, $part, $attempt, $owner, $kind, $expiresAt),
            null,
            $this->transition,
        );
    }

    public function record(CircuitPolicy $policy, CircuitPermit $permit, CircuitVerdict $verdict, CommandId $command): FinalizationResult
    {
        return $this->finalize($policy, $permit, $verdict);
    }

    public function abandon(CircuitPolicy $policy, CircuitPermit $permit, CommandId $command): FinalizationResult
    {
        return $this->finalize($policy, $permit, null);
    }

    /**
     * Host-authorized manual reset: closes the circuit, discards health history and every active owner
     * and advances the generation. Returns the new generation, or null when the partition does not exist.
     *
     * @throws CorruptStateException when the generation space is exhausted
     */
    public function reset(PolicyIdentity $identity): ?Generation
    {
        $now = $this->now();
        $this->subject = null;
        $part = $this->partitions[$identity->key()] ?? null;
        if ($part === null) {
            return null;
        }
        $next = $part->generation->next();
        $part->lastActivity = $now;
        $this->dropOwners($part);
        $part->clearWindow();
        $part->phase = CircuitPartition::CLOSED;
        $part->probeSuccesses = 0;
        $part->generation = $next;

        return $next;
    }

    /**
     * Bounded cleanup of expired owners and tombstones, and removal of idle empty closed partitions.
     * Examines and removes at most $maxRecords (partitions, owners and tombstones count as records) and
     * resumes from a cursor on the next call. Open, HalfOpen, owned and non-empty count-window state is kept.
     *
     * @return int number of records removed
     */
    public function maintain(int $maxRecords = self::MAX_MAINTENANCE_RECORDS): int
    {
        if ($maxRecords < 1 || $maxRecords > self::MAX_MAINTENANCE_RECORDS) {
            throw new InvalidConfigurationException('maxRecords must be in [1, 128].');
        }
        $now = $this->now();
        $budget = $maxRecords;
        $removed = 0;
        $examined = 0;
        $total = count($this->keys);

        while ($budget > 0 && $examined < $total && $this->keys !== []) {
            if ($this->cursor >= count($this->keys)) {
                $this->cursor = 0;
            }
            $key = $this->keys[$this->cursor];
            $part = $this->partitions[$key];
            --$budget;
            ++$examined;

            $count = $this->expire($part, $now, $budget);
            $removed += $count;
            $budget -= $count;
            if ($count > 0 && $budget <= 0) {
                break;
            }

            if (
                $part->phase === CircuitPartition::CLOSED
                && $part->owners === []
                && $part->tombstones === []
                && $now - $part->lastActivity >= self::RETENTION_NANOSECONDS
                && $part->windowIsEmpty($now)
            ) {
                $this->deletePartition($key);
                ++$removed;
            } else {
                $part->prune($now);
                ++$this->cursor;
            }
        }

        return $removed;
    }

    private function finalize(CircuitPolicy $policy, CircuitPermit $permit, ?CircuitVerdict $verdict): FinalizationResult
    {
        $now = $this->now();
        $this->transition = null;
        $this->subject = null;
        if (!$permit->policy->equals($policy->identity)) {
            return $this->result(FinalizationStatus::StaleOwnership);
        }
        $part = $this->partitions[$policy->identity->key()] ?? null;
        if ($part === null) {
            return $this->result(FinalizationStatus::StaleOwnership);
        }
        $this->assertBound($part, self::fingerprint($policy));
        $this->subject = $part;
        $this->expire($part, $now, PHP_INT_MAX);
        if (!$part->incarnation->equals($permit->incarnation)) {
            return $this->result(FinalizationStatus::StaleOwnership);
        }

        $ownerKey = $permit->owner->lowerHex;
        $owner = $part->owners[$ownerKey] ?? null;
        if ($owner === null) {
            $tombstone = $part->tombstones[$ownerKey] ?? null;
            if ($tombstone !== null && $tombstone[0] === $permit->attempt->lowerHex && $tombstone[1] === $permit->generation->value) {
                return $this->result(FinalizationStatus::AlreadyFinalized);
            }

            return $this->result(FinalizationStatus::StaleOwnership);
        }
        if (
            $owner->attemptHex !== $permit->attempt->lowerHex
            || $owner->kind !== $permit->kind
            || $owner->generation !== $permit->generation->value
            || $owner->expiresAt !== $permit->expiresAt->localMonotonicNanoseconds
        ) {
            return $this->result(FinalizationStatus::StaleOwnership);
        }

        $closedKind = $owner->kind === CircuitPermitKind::ClosedSample;
        if (($closedKind && $part->phase !== CircuitPartition::CLOSED) || (!$closedKind && $part->phase !== CircuitPartition::HALF_OPEN)) {
            $part->lastActivity = $now;
            $this->consume($part, $ownerKey, $owner, $now);

            return $this->result(FinalizationStatus::StaleOwnership);
        }

        $counts = $verdict !== null && $verdict !== CircuitVerdict::Neutral;
        $failure = $verdict === CircuitVerdict::Failure;

        // The open deadline is computed BEFORE the owner is consumed or a sample is added, so a deadline that cannot
        // be represented fails the call with no partial mutation. A call that may open the circuit must be able to
        // represent the deadline; policies bound openDuration (see Horizon), so this only fires for readings near 2^62.
        $openUntil = null;
        if ($counts && ($closedKind || $failure)) {
            $openUntil = self::add($now, $part->openDurationNanoseconds);
        }

        $part->lastActivity = $now;
        $this->consume($part, $ownerKey, $owner, $now);

        if (!$counts) {
            $this->settleHalfOpen($part);

            return $this->result(FinalizationStatus::Applied);
        }

        if ($closedKind) {
            $part->addSample($failure, $now);
            [$samples, $failures] = $part->totals($now);
            if (
                $samples >= $part->minimumSamples
                && ExactProduct::compare($failures, $part->ratioDenominator, $samples, $part->ratioNumerator) >= 0
            ) {
                $this->enterOpen($part, (int) $openUntil, ReasonCode::CircuitThresholdExceeded->value);
            }
        } elseif ($failure) {
            $this->enterOpen($part, (int) $openUntil, ReasonCode::CircuitProbeFailed->value);
        } else {
            ++$part->probeSuccesses;
            $this->settleHalfOpen($part);
        }

        return $this->result(FinalizationStatus::Applied);
    }

    /** Moves an active owner into a 120 second tombstone; the record slot it held is reused. */
    private function consume(CircuitPartition $part, string $ownerKey, CircuitOwner $owner, int $now): void
    {
        unset($part->owners[$ownerKey]);
        if ($owner->kind === CircuitPermitKind::HalfOpenProbe) {
            --$part->activeProbes;
        }
        if (isset($part->tombstones[$ownerKey])) {
            unset($part->tombstones[$ownerKey]);
            --$this->records;
        }
        $until = $now > PHP_INT_MAX - self::RETENTION_NANOSECONDS ? PHP_INT_MAX : $now + self::RETENTION_NANOSECONDS;
        $part->tombstones[$ownerKey] = [$owner->attemptHex, $owner->generation, $until];
    }

    /**
     * Removes expired owners (neutral: no sample, no recovery credit) and expired tombstones, at most
     * $limit in total. Owners and tombstones are inserted in expiry order.
     */
    private function expire(CircuitPartition $part, int $now, int $limit): int
    {
        $removed = 0;
        $freedProbe = false;

        $dead = [];
        foreach ($part->owners as $key => $owner) {
            if ($owner->expiresAt > $now || $removed >= $limit) {
                break;
            }
            $dead[] = $key;
            ++$removed;
        }
        foreach ($dead as $key) {
            if ($part->owners[$key]->kind === CircuitPermitKind::HalfOpenProbe) {
                --$part->activeProbes;
                $freedProbe = true;
            }
            unset($part->owners[$key]);
            --$this->records;
        }

        $dead = [];
        foreach ($part->tombstones as $key => $tombstone) {
            if ($tombstone[2] > $now || $removed >= $limit) {
                break;
            }
            $dead[] = $key;
            ++$removed;
        }
        foreach ($dead as $key) {
            unset($part->tombstones[$key]);
            --$this->records;
        }

        if ($freedProbe) {
            $this->settleHalfOpen($part);
        }

        return $removed;
    }

    /** Closes only when the success threshold is reached and no admitted probe is still unresolved. */
    private function settleHalfOpen(CircuitPartition $part): void
    {
        if (
            $part->phase === CircuitPartition::HALF_OPEN
            && $part->activeProbes === 0
            && $part->probeSuccesses >= $part->probeSuccessThreshold
        ) {
            $next = $part->generation->next();
            $this->dropOwners($part);
            $part->clearWindow();
            $this->note($part, CircuitPartition::HALF_OPEN, CircuitPartition::CLOSED, ReasonCode::CircuitProbesSucceeded->value);
            $part->phase = CircuitPartition::CLOSED;
            $part->probeSuccesses = 0;
            $part->generation = $next;
        }
    }

    /** @param int $until precomputed open deadline, so this transition itself cannot fail on overflow */
    private function enterOpen(CircuitPartition $part, int $until, string $reason): void
    {
        $next = $part->generation->next();
        $this->dropOwners($part);
        $this->note($part, $part->phase, CircuitPartition::OPEN, $reason);
        $part->phase = CircuitPartition::OPEN;
        $part->openUntil = $until;
        $part->probeSuccesses = 0;
        $part->generation = $next;
    }

    private function enterHalfOpen(CircuitPartition $part): void
    {
        $next = $part->generation->next();
        $this->dropOwners($part);
        $this->note($part, CircuitPartition::OPEN, CircuitPartition::HALF_OPEN, ReasonCode::CircuitOpenElapsed->value);
        $part->phase = CircuitPartition::HALF_OPEN;
        $part->probeSuccesses = 0;
        $part->generation = $next;
    }

    /**
     * Records a transition of the call's own partition. Several transitions inside one call compose into one fact
     * (first source state, last target state); a net no-op is dropped.
     */
    private function note(CircuitPartition $part, int $from, int $to, string $reason): void
    {
        if ($part !== $this->subject) {
            return;
        }
        $names = [
            CircuitPartition::CLOSED => CircuitTransition::CLOSED,
            CircuitPartition::OPEN => CircuitTransition::OPEN,
            CircuitPartition::HALF_OPEN => CircuitTransition::HALF_OPEN,
        ];
        $source = $this->transition->fromState ?? $names[$from];
        $this->transition = $source === $names[$to] ? null : new CircuitTransition($source, $names[$to], $reason);
    }

    /** Every owner issued under an earlier generation becomes stale at a transition. */
    private function dropOwners(CircuitPartition $part): void
    {
        $this->records -= count($part->owners);
        $part->owners = [];
        $part->activeProbes = 0;
    }

    private function permit(
        CircuitPolicy $policy,
        CircuitPartition $part,
        AttemptId $attempt,
        PermitOwnerId $owner,
        CircuitPermitKind $kind,
        int $expiresAt,
    ): CircuitPermit {
        return new CircuitPermit(
            $policy->identity,
            $part->incarnation,
            $part->generation,
            $attempt,
            $owner,
            $kind,
            $policy->permitLease,
            BackendExpiry::local($expiresAt),
        );
    }

    private function createPartition(string $key, string $fingerprint, CircuitPolicy $policy, int $now): CircuitPartition
    {
        $part = new CircuitPartition($this->identities->incarnation(), $fingerprint, $policy, $now);
        $this->partitions[$key] = $part;
        $this->keyIndex[$key] = count($this->keys);
        $this->keys[] = $key;

        return $part;
    }

    private function deletePartition(string $key): void
    {
        $index = $this->keyIndex[$key];
        $last = array_pop($this->keys);
        if ($last !== null && $last !== $key) {
            $this->keys[$index] = $last;
            $this->keyIndex[$last] = $index;
        }
        unset($this->keyIndex[$key], $this->partitions[$key]);
    }

    /**
     * Active permits one partition may hold: the record ceiling minus a quarter of it, so a slow dependency with many
     * calls in flight cannot take the records every other circuit needs. The Redis backend uses the same rule.
     */
    public static function partitionPermitCap(int $maxRecords): int
    {
        return max(1, $maxRecords - intdiv($maxRecords, 4));
    }

    private function ensureRecordRoom(): void
    {
        if ($this->records < $this->maxRecords) {
            return;
        }
        $this->maintain();
        if ($this->records < $this->maxRecords) {
            return;
        }
        $this->evictTombstones();
        if ($this->records >= $this->maxRecords) {
            throw new StateCapacityExceededException('The circuit owner and tombstone record ceiling is reached.');
        }
    }

    /**
     * Evicts the globally oldest finalization tombstones (bounded batch). Active owners, Open/HalfOpen state and
     * closed window history are protective and are never touched; partitions are not deleted here.
     */
    private function evictTombstones(): void
    {
        $heads = [];
        foreach ($this->partitions as $key => $part) {
            $first = array_key_first($part->tombstones);
            if ($first !== null) {
                $heads[(string) $key] = $part->tombstones[$first][2];
            }
        }
        MemoryLimits::evictOldest($heads, function (string $key): ?int {
            $part = $this->partitions[$key];
            $first = array_key_first($part->tombstones);
            if ($first === null) {
                return null;
            }
            unset($part->tombstones[$first]);
            --$this->records;
            $next = array_key_first($part->tombstones);

            return $next === null ? null : $part->tombstones[$next][2];
        }, MemoryLimits::evictionBatch($this->maxRecords));
    }

    private function ensurePartitionRoom(): void
    {
        if (count($this->partitions) >= $this->maxPartitions) {
            $this->maintain();
        }
        if (count($this->partitions) >= $this->maxPartitions) {
            throw new StateCapacityExceededException('The circuit partition ceiling is reached.');
        }
    }

    private function assertBound(CircuitPartition $part, string $fingerprint): void
    {
        if ($part->fingerprint !== $fingerprint) {
            throw new InvalidConfigurationException('The policy identity is already bound to different circuit settings.');
        }
    }

    /** @throws ClockFailureException */
    private function now(): int
    {
        $reading = $this->clock->monotonicNow();
        if ($reading < 0) {
            throw new ClockFailureException('The monotonic clock returned a negative reading.');
        }
        if ($reading > $this->highWater) {
            $this->highWater = $reading;
        }

        return $this->highWater;
    }

    private static function add(int $a, int $b): int
    {
        if ($a > PHP_INT_MAX - $b) {
            throw new InvalidConfigurationException('A circuit deadline overflows the monotonic clock domain.');
        }

        return $a + $b;
    }

    private function result(FinalizationStatus $status): FinalizationResult
    {
        return new FinalizationResult($status, $this->transition);
    }

    private static function fingerprint(CircuitPolicy $policy): string
    {
        $window = $policy->window;
        if ($window instanceof CountCircuitWindow) {
            $shape = 'c:' . $window->completedSamples->value;
        } elseif ($window instanceof TimeCircuitWindow) {
            $shape = 't:' . $window->window->nanoseconds . ':' . $window->bucketGranularity->nanoseconds;
        } else {
            throw new InvalidConfigurationException('Unsupported circuit window options.');
        }

        return implode('|', [
            $shape,
            $policy->minimumSamples->value,
            $policy->failureThreshold->numerator->value,
            $policy->failureThreshold->denominator->value,
            $policy->openDuration->nanoseconds,
            $policy->probeCapacity->value,
            $policy->probeSuccessThreshold->value,
            $policy->permitLease->nanoseconds,
        ]);
    }
}
