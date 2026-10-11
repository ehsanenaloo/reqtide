<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\State\Memory;

use Enaxon\Reqtide\Outcome\CircuitVerdict;
use Enaxon\Reqtide\State\CircuitAdmissionStatus;
use Enaxon\Reqtide\State\CircuitPermitKind;
use Enaxon\Reqtide\State\FinalizationStatus;

/**
 * Naive reference implementation of the CIR transition table: plain lists, full history scans and no
 * incremental counters. Written from the specification text, independently of the backend.
 */
final class ReferenceCircuit
{
    private const CLOSED = 'closed';
    private const OPEN = 'open';
    private const HALF = 'half';

    private string $phase = self::CLOSED;
    private int $generation = 1;
    private int $openUntil = 0;
    private int $successes = 0;
    private bool $exists = false;
    private int $nextId = 0;

    /** @var list<array{int, bool}> completed non-neutral outcomes as [time, failed] */
    private array $history = [];

    /** @var array<int, array{CircuitPermitKind, int, int}> id => [kind, generation, expiry] */
    private array $owners = [];

    /** @var array<int, int> id => expiry */
    private array $tombstones = [];

    public function __construct(
        private readonly bool $timeWindow,
        private readonly int $windowNs,
        private readonly int $countWindow,
        private readonly int $minimum,
        private readonly int $numerator,
        private readonly int $denominator,
        private readonly int $openNs,
        private readonly int $capacity,
        private readonly int $threshold,
        private readonly int $leaseNs,
    ) {}

    /** @return array{status: CircuitAdmissionStatus, kind: ?CircuitPermitKind, generation: int, retryAfter: ?int, id: int} */
    public function acquire(int $now): array
    {
        $this->exists = true;
        $this->expire($now);
        if ($this->phase === self::OPEN) {
            if ($now < $this->openUntil) {
                return ['status' => CircuitAdmissionStatus::Open, 'kind' => null, 'generation' => 0, 'retryAfter' => $this->openUntil - $now, 'id' => -1];
            }
            $this->phase = self::HALF;
            ++$this->generation;
            $this->successes = 0;
            $this->owners = [];
        }
        if ($this->phase === self::HALF) {
            if (count($this->owners) >= $this->capacity || $this->successes >= $this->threshold) {
                return ['status' => CircuitAdmissionStatus::ProbeCapacityExhausted, 'kind' => null, 'generation' => 0, 'retryAfter' => null, 'id' => -1];
            }
            $kind = CircuitPermitKind::HalfOpenProbe;
        } else {
            $kind = CircuitPermitKind::ClosedSample;
        }
        $id = $this->nextId++;
        $this->owners[$id] = [$kind, $this->generation, $now + $this->leaseNs];

        return ['status' => CircuitAdmissionStatus::Accepted, 'kind' => $kind, 'generation' => $this->generation, 'retryAfter' => null, 'id' => $id];
    }

    public function finalize(int $now, int $id, ?CircuitVerdict $verdict): FinalizationStatus
    {
        $this->expire($now);
        if (!isset($this->owners[$id])) {
            return isset($this->tombstones[$id]) ? FinalizationStatus::AlreadyFinalized : FinalizationStatus::StaleOwnership;
        }
        $kind = $this->owners[$id][0];
        unset($this->owners[$id]);
        $this->tombstones[$id] = $now + 120 * CircuitRig::SECOND;

        if ($verdict === null || $verdict === CircuitVerdict::Neutral) {
            $this->settle();

            return FinalizationStatus::Applied;
        }
        $failed = $verdict === CircuitVerdict::Failure;
        if ($kind === CircuitPermitKind::ClosedSample) {
            $this->history[] = [$now, $failed];
            if ($this->shouldOpen($now)) {
                $this->open($now);
            }
        } elseif ($failed) {
            $this->open($now);
        } else {
            ++$this->successes;
            $this->settle();
        }

        return FinalizationStatus::Applied;
    }

    public function reset(): ?int
    {
        if (!$this->exists) {
            return null;
        }
        $this->phase = self::CLOSED;
        ++$this->generation;
        $this->history = [];
        $this->owners = [];
        $this->successes = 0;

        return $this->generation;
    }

    private function expire(int $now): void
    {
        $freedProbe = false;
        foreach ($this->owners as $id => [$kind, , $expiry]) {
            if ($expiry <= $now) {
                unset($this->owners[$id]);
                $freedProbe = $freedProbe || $kind === CircuitPermitKind::HalfOpenProbe;
            }
        }
        foreach ($this->tombstones as $id => $expiry) {
            if ($expiry <= $now) {
                unset($this->tombstones[$id]);
            }
        }
        if ($freedProbe) {
            $this->settle();
        }
    }

    private function settle(): void
    {
        if ($this->phase === self::HALF && $this->owners === [] && $this->successes >= $this->threshold) {
            $this->phase = self::CLOSED;
            ++$this->generation;
            $this->history = [];
            $this->successes = 0;
        }
    }

    private function open(int $now): void
    {
        $this->phase = self::OPEN;
        ++$this->generation;
        $this->openUntil = $now + $this->openNs;
        $this->owners = [];
        $this->successes = 0;
    }

    private function shouldOpen(int $now): bool
    {
        if ($this->timeWindow) {
            // Whole intersecting 1 s buckets of (now - W, now]: bucket b = [b s, (b + 1) s) intersects iff (b + 1) s > now - W.
            $cutoff = $now - $this->windowNs;
            $inside = array_filter(
                $this->history,
                static fn(array $sample): bool => (intdiv($sample[0], CircuitRig::SECOND) + 1) * CircuitRig::SECOND > $cutoff,
            );
        } else {
            $inside = array_slice($this->history, -$this->countWindow);
        }
        $samples = count($inside);
        $failures = count(array_filter($inside, static fn(array $sample): bool => $sample[1]));

        return $samples >= $this->minimum && $failures * $this->denominator >= $samples * $this->numerator;
    }
}
