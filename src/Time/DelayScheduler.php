<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Time;

use Enaxon\Reqtide\Runtime\SchedulingCapability;
use Enaxon\Reqtide\Value\Duration;

interface DelayScheduler
{
    public function capability(): SchedulingCapability;

    /**
     * Waits for $duration unless the deadline or cancellation fires first. At each checkpoint the
     * deadline is evaluated before cancellation, so a simultaneous sample selects DeadlineExceeded.
     */
    public function delay(Duration $duration, Deadline $deadline, Cancellation $cancellation): DelayDisposition;
}
