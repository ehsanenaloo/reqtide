<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Runtime;

use Enaxon\Reqtide\Runtime\SchedulingCapability;
use Enaxon\Reqtide\Time\Cancellation;
use Enaxon\Reqtide\Time\Clock;
use Enaxon\Reqtide\Time\Deadline;
use Enaxon\Reqtide\Time\DelayDisposition;
use Enaxon\Reqtide\Time\DelayScheduler;
use Enaxon\Reqtide\Value\Duration;

/**
 * Waits for a retry delay while keeping the owned queue moving: each slice runs one drive step, re-checks the deadline
 * (first) and cancellation, and sleeps at most five milliseconds so an idle queue does not spin. The Clock must be the
 * instance that created the Deadline. The scheduling capability equals the promise runtime's, as Core requires.
 */
final class QueueDrivenDelayScheduler implements DelayScheduler
{
    private const SLICE_NANOSECONDS = 5_000_000;

    /** @param \Closure(): void $drive one step of progress of the owned queue */
    public function __construct(private readonly Clock $clock, private readonly \Closure $drive) {}

    public function capability(): SchedulingCapability
    {
        return SchedulingCapability::AdapterDrivenQueue;
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
            ($this->drive)();
            $after = $this->clock->monotonicNow();
            if ($after < $end) {
                time_nanosleep(0, min(self::SLICE_NANOSECONDS, $end - $after));
            }
        }
    }
}
