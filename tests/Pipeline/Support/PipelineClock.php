<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Pipeline\Support;

use Enaxon\Reqtide\Exception\ClockFailureException;
use Enaxon\Reqtide\Time\Clock;

/** Controllable monotonic clock with injectable failures. Reads are counted so tests can prove "no I/O". */
final class PipelineClock implements Clock
{
    public int $reads = 0;

    /** Wall-clock reading; null keeps the fixed default instant. */
    public ?\DateTimeImmutable $wall = null;

    /** @var (\Closure(int): bool)|null decides whether the n-th read (1-based) fails */
    public ?\Closure $failWhen = null;

    public function __construct(public int $now = 1_000_000_000) {}

    public function monotonicNow(): int
    {
        ++$this->reads;
        if ($this->failWhen !== null && ($this->failWhen)($this->reads)) {
            throw new ClockFailureException('Injected monotonic clock failure.');
        }

        return $this->now;
    }

    public function wallNow(): \DateTimeImmutable
    {
        return $this->wall ?? new \DateTimeImmutable('2026-10-08T00:00:00+00:00');
    }

    public function advance(int $nanoseconds): void
    {
        $this->now += $nanoseconds;
    }
}
