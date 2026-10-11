<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Time;

use Enaxon\Reqtide\Runtime\SchedulingCapability;
use Enaxon\Reqtide\Value\Duration;

/**
 * Blocks the calling thread in slices of at most five milliseconds, re-checking the deadline and
 * cancellation between slices. The Clock must be the instance that created the Deadline.
 */
final class BoundedBlockingScheduler implements DelayScheduler
{
    private const SLICE_NANOSECONDS = 5_000_000;

    public function __construct(private readonly Clock $clock) {}

    public function capability(): SchedulingCapability
    {
        return SchedulingCapability::BoundedBlocking;
    }

    public function delay(Duration $duration, Deadline $deadline, Cancellation $cancellation): DelayDisposition
    {
        $start = $this->clock->monotonicNow();
        $end = $start > PHP_INT_MAX - $duration->nanoseconds ? PHP_INT_MAX : $start + $duration->nanoseconds;

        while (true) {
            if ($deadline->isExpired($this->clock)) {
                return DelayDisposition::DeadlineExceeded;
            }
            if ($cancellation->isRequested()) {
                return DelayDisposition::Cancelled;
            }
            $now = $this->clock->monotonicNow();
            if ($now >= $end) {
                return DelayDisposition::Completed;
            }
            time_nanosleep(0, min(self::SLICE_NANOSECONDS, $end - $now));
        }
    }
}
