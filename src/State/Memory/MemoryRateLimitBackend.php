<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\State\Memory;

use Enaxon\Reqtide\Admission\AdmissionDecision;
use Enaxon\Reqtide\Admission\AdmissionStatus;
use Enaxon\Reqtide\Admission\RateLimitInspection;
use Enaxon\Reqtide\Error\ReasonCode;
use Enaxon\Reqtide\Exception\ClockFailureException;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Exception\StateCapacityExceededException;
use Enaxon\Reqtide\Policy\RateAlgorithmOptions;
use Enaxon\Reqtide\Policy\RateLimitPolicy;
use Enaxon\Reqtide\State\RateLimitBackend;
use Enaxon\Reqtide\State\StateBackendCapabilities;
use Enaxon\Reqtide\State\StateScope;
use Enaxon\Reqtide\Time\Clock;
use Enaxon\Reqtide\Value\CommandId;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\NonNegativeInt;
use Enaxon\Reqtide\Value\PositiveInt;

/**
 * Process-local rate-limit state for the token bucket, fixed window and approximate sliding counter.
 *
 * Time is the injected clock's monotonic nanoseconds (one tick = 1 ns). A regressing reading never
 * refills or resets anything: the effective tick is max(last write, now).
 *
 * Aligned fixed windows and sliding-counter windows are aligned to the Unix epoch like the shared (Redis)
 * backend, `floor(epoch / W) * W`. A process-local monotonic clock has no epoch, so the backend captures a
 * wall-clock anchor ONCE at construction (`anchorEpochNs = Clock::wallNow() as epoch ns - monotonicNow()`) and uses
 * it only to place window boundaries. All admission decisions and waits still use the monotonic clock; the anchor
 * is never persisted or compared with remote timestamps. A wall-clock step after construction therefore shifts
 * neither window length nor admission safety, only where the boundaries sit relative to the real calendar.
 * First-hit windows and the token bucket do not depend on the anchor.
 *
 * State is single-threaded process memory: every method runs to completion without callbacks, I/O or
 * delays, so each transition is atomic with respect to PHP code in this process. The registry is bounded;
 * at the partition ceiling the backend first runs one bounded `maintain()` pass and re-checks, and only then
 * rejects a new partition with StateCapacityExceededException before any mutation. Active protective state is
 * never evicted. Duplicate CommandId handling is not required locally and the command is ignored.
 *
 * `maintain()` and `partitionCount()` are a backend-specific administrative API of the memory backends. They are
 * not part of the {@see RateLimitBackend} interface and callers must not rely on them through that contract.
 *
 * @internal Process-local state; not a supported extension point.
 */
final class MemoryRateLimitBackend implements RateLimitBackend
{
    public const DEFAULT_MAX_PARTITIONS = 10_000;

    /** Maximum records one maintenance call may examine. */
    public const MAX_MAINTENANCE_RECORDS = 128;

    /** Idle time before a fresh-equivalent record may be removed. */
    private const RETENTION_NANOSECONDS = 120_000_000_000;

    /** @var array<string, RateRecord> */
    private array $records = [];

    /** Wall-clock epoch nanoseconds minus the monotonic reading, captured once; used only to place window boundaries. */
    private readonly int $epochAnchor;

    /** @throws ClockFailureException when the wall clock cannot be represented as epoch nanoseconds */
    public function __construct(private readonly Clock $clock, private readonly int $maxPartitions = self::DEFAULT_MAX_PARTITIONS)
    {
        if ($maxPartitions < 1) {
            throw new InvalidConfigurationException('Maximum active partitions must be at least one.');
        }
        $wall = $clock->wallNow();
        $seconds = $wall->getTimestamp();
        if ($seconds > intdiv(PHP_INT_MAX, 1_000_000_000) - 1 || $seconds < intdiv(PHP_INT_MIN, 1_000_000_000) + 1) {
            throw new ClockFailureException('The wall clock is outside the representable epoch-nanosecond range.');
        }
        $epochNanoseconds = $seconds * 1_000_000_000 + (int) $wall->format('u') * 1_000;
        $monotonic = $clock->monotonicNow();
        if ($epochNanoseconds < PHP_INT_MIN + $monotonic) {
            throw new ClockFailureException('The wall-clock anchor is outside the representable range.');
        }
        $this->epochAnchor = $epochNanoseconds - $monotonic;
    }

    public function capabilities(): StateBackendCapabilities
    {
        return new StateBackendCapabilities(StateScope::ProcessLocal, null, Duration::nanoseconds(1));
    }

    public function consume(RateLimitPolicy $policy, PositiveInt $cost, CommandId $command): AdmissionDecision
    {
        $options = $policy->algorithm;
        $key = $policy->identity->key();
        $record = $this->bound($key, $options);
        $capacity = RateAlgorithms::capacity($options);

        if ($cost->value > $capacity) {
            return new AdmissionDecision(AdmissionStatus::Impossible, ReasonCode::RateCostExceedsCapacity->value, null, null);
        }
        if ($record === null && count($this->records) >= $this->maxPartitions) {
            // One bounded maintenance pass may reclaim fresh-equivalent idle partitions before we give up.
            $this->maintain();
            if (count($this->records) >= $this->maxPartitions) {
                throw new StateCapacityExceededException('Process-local rate-limit partition capacity is exhausted.');
            }
        }

        $now = $this->clock->monotonicNow();
        $eff = $record === null ? $now : max($record->lastTick, $now);
        $evaluation = RateAlgorithms::evaluate($options, RateAlgorithms::project($options, $record, $eff, $this->epochAnchor), $eff, $cost->value);

        // Rejection still persists the projected (refilled / rolled) state: it is exactly equivalent and exact.
        $this->store($key, $options, $record, $eff, $evaluation->view);

        if ($evaluation->accepted) {
            return new AdmissionDecision(
                AdmissionStatus::Accepted,
                ReasonCode::RateAccepted->value,
                new NonNegativeInt($evaluation->available - $cost->value),
                null,
            );
        }

        // A wait that does not fit int64 is never truncated or wrapped: the decision is Rejected with no wait and
        // a distinct reason, so a caller can tell "no usable wait" from "wait not computed".
        return new AdmissionDecision(
            AdmissionStatus::Rejected,
            $evaluation->retryAfterNanoseconds === null ? ReasonCode::RateWaitUnrepresentable->value : ReasonCode::RateExhausted->value,
            new NonNegativeInt($evaluation->available),
            $evaluation->retryAfterNanoseconds === null ? null : Duration::nanoseconds($evaluation->retryAfterNanoseconds),
        );
    }

    public function inspect(RateLimitPolicy $policy, PositiveInt $requestedCost): RateLimitInspection
    {
        $options = $policy->algorithm;
        $record = $this->bound($policy->identity->key(), $options);

        $now = $this->clock->monotonicNow();
        $eff = $record === null ? $now : max($record->lastTick, $now);
        $view = RateAlgorithms::project($options, $record, $eff, $this->epochAnchor);

        if ($requestedCost->value > RateAlgorithms::capacity($options)) {
            return new RateLimitInspection(new NonNegativeInt(RateAlgorithms::available($options, $view, $eff)), false, null);
        }

        $evaluation = RateAlgorithms::evaluate($options, $view, $eff, $requestedCost->value);

        return new RateLimitInspection(
            new NonNegativeInt($evaluation->available),
            $evaluation->accepted,
            $evaluation->retryAfterNanoseconds === null ? null : Duration::nanoseconds($evaluation->retryAfterNanoseconds),
        );
    }

    /**
     * Bounded cleanup: examines at most $maxRecords (1..128) records, the ones not examined for the longest time, and
     * removes only those equivalent to fresh state after the retention period. Returns the number removed. A kept
     * record moves to the end of the examination order, so the cost of one call does not grow with the number of
     * partitions and every record is examined in turn. It never evicts protection that still matters, and a removed
     * record's settings binding ends with it.
     */
    public function maintain(int $maxRecords = self::MAX_MAINTENANCE_RECORDS): int
    {
        if ($maxRecords < 1 || $maxRecords > self::MAX_MAINTENANCE_RECORDS) {
            throw new InvalidConfigurationException('Maintenance batch size must be within 1..128.');
        }

        $now = $this->clock->monotonicNow();
        $keys = [];
        foreach ($this->records as $key => $record) {
            $keys[] = $key;
            if (count($keys) >= $maxRecords) {
                break;
            }
        }
        $removed = 0;
        foreach ($keys as $key) {
            $record = $this->records[$key];
            unset($this->records[$key]);
            $eff = max($record->lastTick, $now);
            if (
                $eff - $record->lastTick >= self::RETENTION_NANOSECONDS
                && RateAlgorithms::isFreshEquivalent($record->options, $record, $eff, $this->epochAnchor)
            ) {
                ++$removed;
                continue;
            }
            $this->records[$key] = $record;
        }

        return $removed;
    }

    /** Number of currently allocated partitions. Backend-specific administrative API, not part of the State contract. */
    public function partitionCount(): int
    {
        return count($this->records);
    }

    /** Returns the existing record after verifying its immutable settings, or null when none exists. */
    private function bound(string $key, RateAlgorithmOptions $options): ?RateRecord
    {
        $fingerprint = RateAlgorithms::fingerprint($options);
        $record = $this->records[$key] ?? null;
        if ($record !== null && $record->fingerprint !== $fingerprint) {
            throw new InvalidConfigurationException('Policy identity is already bound to different immutable settings; use a new policy identity.');
        }

        return $record;
    }

    private function store(string $key, RateAlgorithmOptions $options, ?RateRecord $record, int $eff, RateView $view): void
    {
        $record ??= $this->records[$key] = new RateRecord($options, RateAlgorithms::fingerprint($options), $eff);
        $record->lastTick = $eff;
        $record->primary = $view->primary;
        $record->secondary = $view->secondary;
        $record->windowStart = $view->windowStart;
    }
}
