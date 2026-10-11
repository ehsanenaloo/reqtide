<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\State\Memory;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Exception\StateCapacityExceededException;
use Enaxon\Reqtide\Internal\NumericLimits;
use Enaxon\Reqtide\Policy\CircuitPolicy;
use Enaxon\Reqtide\Policy\CountCircuitWindow;
use Enaxon\Reqtide\Policy\TimeCircuitWindow;
use Enaxon\Reqtide\Value\Generation;
use Enaxon\Reqtide\Value\IncarnationId;

/**
 * Mutable per-policy-identity circuit record. All fields are private to the memory backend, which is
 * the only mutator; the algorithm settings are bound at creation and never change.
 *
 * @internal
 */
final class CircuitPartition
{
    public const CLOSED = 0;
    public const OPEN = 1;
    public const HALF_OPEN = 2;

    public int $phase = self::CLOSED;
    public Generation $generation;
    public int $openUntil = 0;
    public int $probeSuccesses = 0;
    public int $activeProbes = 0;
    public int $lastActivity;

    /** @var array<string, CircuitOwner> insertion order equals expiry order (fixed lease, monotonic time) */
    public array $owners = [];

    /** @var array<string, array{0: string, 1: int, 2: int}> owner hex => [attempt hex, generation, expiresAt] */
    public array $tombstones = [];

    public readonly int $minimumSamples;
    public readonly int $ratioNumerator;
    public readonly int $ratioDenominator;
    public readonly int $openDurationNanoseconds;
    public readonly int $probeCapacity;
    public readonly int $probeSuccessThreshold;
    public readonly int $leaseNanoseconds;

    /** Count window capacity N, or 0 for a time window. */
    public readonly int $countCapacity;
    public readonly int $windowNanoseconds;
    public readonly int $granularityNanoseconds;

    /** Count window ring: one '0'/'1' byte per outcome, grown to N and then overwritten oldest-first. */
    private string $ring = '';
    private int $ringHead = 0;
    private int $ringFailures = 0;

    /** @var array<int, array{0: int, 1: int}> bucket id => [total, failures], ascending by id */
    private array $buckets = [];

    public function __construct(
        public readonly IncarnationId $incarnation,
        public readonly string $fingerprint,
        CircuitPolicy $policy,
        int $now,
    ) {
        $this->generation = Generation::first();
        $this->lastActivity = $now;
        $this->minimumSamples = $policy->minimumSamples->value;
        $this->ratioNumerator = $policy->failureThreshold->numerator->value;
        $this->ratioDenominator = $policy->failureThreshold->denominator->value;
        $this->openDurationNanoseconds = $policy->openDuration->nanoseconds;
        $this->probeCapacity = $policy->probeCapacity->value;
        $this->probeSuccessThreshold = $policy->probeSuccessThreshold->value;
        $this->leaseNanoseconds = $policy->permitLease->nanoseconds;
        $window = $policy->window;
        if ($window instanceof CountCircuitWindow) {
            $this->countCapacity = $window->completedSamples->value;
            $this->windowNanoseconds = 0;
            $this->granularityNanoseconds = 0;
        } elseif ($window instanceof TimeCircuitWindow) {
            $this->countCapacity = 0;
            $this->windowNanoseconds = $window->window->nanoseconds;
            $this->granularityNanoseconds = $window->bucketGranularity->nanoseconds;
        } else {
            throw new InvalidConfigurationException('Unsupported circuit window options.');
        }
    }

    /**
     * Records a completed non-neutral outcome.
     *
     * @throws StateCapacityExceededException when the time bucket is full; nothing is changed then
     */
    public function addSample(bool $failure, int $now): void
    {
        if ($this->countCapacity > 0) {
            $char = $failure ? '1' : '0';
            if (strlen($this->ring) < $this->countCapacity) {
                $this->ring .= $char;
            } else {
                if ($this->ring[$this->ringHead] === '1') {
                    --$this->ringFailures;
                }
                $this->ring[$this->ringHead] = $char;
                $this->ringHead = ($this->ringHead + 1) % $this->countCapacity;
            }
            if ($failure) {
                ++$this->ringFailures;
            }

            return;
        }

        $this->prune($now);
        $id = intdiv($now, $this->granularityNanoseconds);
        [$total, $failures] = $this->buckets[$id] ?? [0, 0];
        if ($total >= NumericLimits::MAX_SAMPLES_PER_BUCKET) {
            throw new StateCapacityExceededException('A circuit time bucket reached its sample ceiling.');
        }
        $this->buckets[$id] = [$total + 1, $failures + ($failure ? 1 : 0)];
    }

    /** @return array{0: int, 1: int} [samples, failures] currently inside the window */
    public function totals(int $now): array
    {
        if ($this->countCapacity > 0) {
            return [strlen($this->ring), $this->ringFailures];
        }
        $this->prune($now);
        $samples = 0;
        $failures = 0;
        foreach ($this->buckets as [$total, $failed]) {
            $samples += $total;
            $failures += $failed;
        }

        return [$samples, $failures];
    }

    /** True when no outcome would be retained at $now. */
    public function windowIsEmpty(int $now): bool
    {
        if ($this->countCapacity > 0) {
            return $this->ring === '';
        }
        $this->prune($now);

        return $this->buckets === [];
    }

    public function clearWindow(): void
    {
        $this->ring = '';
        $this->ringHead = 0;
        $this->ringFailures = 0;
        $this->buckets = [];
    }

    /**
     * Drops buckets that no longer intersect (now - W, now]: bucket b is kept iff b >= floor((now - W) / G),
     * with every bucket kept while now - W <= 0.
     */
    public function prune(int $now): void
    {
        if ($this->countCapacity > 0 || $this->buckets === []) {
            return;
        }
        $cutoff = $now - $this->windowNanoseconds;
        $minimum = $cutoff <= 0 ? 0 : intdiv($cutoff, $this->granularityNanoseconds);
        $stale = [];
        foreach ($this->buckets as $id => $_) {
            if ($id >= $minimum) {
                break;
            }
            $stale[] = $id;
        }
        foreach ($stale as $id) {
            unset($this->buckets[$id]);
        }
    }
}
