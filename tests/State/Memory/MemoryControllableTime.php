<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\State\Memory;

use Enaxon\Reqtide\Tests\Contract\ControllableTime;

/**
 * The contract axis for the process-local backends: nanoseconds of the injected monotonic clock. Its wall clock is the
 * AnchoredClock default, which sits on a day boundary at axis zero, so aligned windows land where the suites expect.
 */
final readonly class MemoryControllableTime implements ControllableTime
{
    public function __construct(private AnchoredClock $clock) {}

    public function now(): int
    {
        return $this->clock->now;
    }

    public function moveTo(int $nanoseconds): void
    {
        $this->clock->now = $nanoseconds;
    }

    public function advance(int $nanoseconds): void
    {
        $this->clock->now += $nanoseconds;
    }

    public function step(): int
    {
        return 1;
    }
}
