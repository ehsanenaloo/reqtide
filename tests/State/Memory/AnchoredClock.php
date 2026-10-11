<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\State\Memory;

use Enaxon\Reqtide\Time\Clock;

/**
 * Fake clock with a settable monotonic reading and a settable wall clock. By default the wall clock is
 * `epochBase + now` (nanoseconds, truncated to microseconds), so the epoch anchor a backend captures at
 * construction is exactly `epochBase` whenever the monotonic reading is a whole number of microseconds.
 */
final class AnchoredClock implements Clock
{
    /** 2026-01-01T00:00:00Z in epoch nanoseconds: a multiple of every whole-minute window used in tests. */
    public const DEFAULT_EPOCH_BASE = 1_767_225_600_000_000_000;

    public ?int $wallEpochNanoseconds = null;

    public function __construct(public int $now = 1_000, private readonly int $epochBase = self::DEFAULT_EPOCH_BASE) {}

    public function monotonicNow(): int
    {
        return $this->now;
    }

    public function wallNow(): \DateTimeImmutable
    {
        $epoch = $this->wallEpochNanoseconds ?? $this->epochBase + $this->now;
        $seconds = intdiv($epoch, 1_000_000_000);
        $micros = intdiv($epoch % 1_000_000_000, 1_000);

        return new \DateTimeImmutable(sprintf('@%d.%06d', $seconds, $micros));
    }
}
