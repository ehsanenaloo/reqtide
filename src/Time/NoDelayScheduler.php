<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Time;

use Enaxon\Reqtide\Exception\CapabilityMismatchException;
use Enaxon\Reqtide\Runtime\SchedulingCapability;
use Enaxon\Reqtide\Value\Duration;

/**
 * Scheduler that cannot wait. A zero delay is a checkpoint only; a positive delay is a capability
 * mismatch and is never silently skipped or shortened.
 */
final class NoDelayScheduler implements DelayScheduler
{
    public function __construct(private readonly Clock $clock) {}

    public function capability(): SchedulingCapability
    {
        return SchedulingCapability::None;
    }

    public function delay(Duration $duration, Deadline $deadline, Cancellation $cancellation): DelayDisposition
    {
        if ($deadline->isExpired($this->clock)) {
            return DelayDisposition::DeadlineExceeded;
        }
        if ($cancellation->isRequested()) {
            return DelayDisposition::Cancelled;
        }
        if ($duration->isPositive()) {
            throw new CapabilityMismatchException('NoDelayScheduler cannot perform a positive delay.');
        }

        return DelayDisposition::Completed;
    }
}
