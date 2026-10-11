<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Pipeline\Support;

use Enaxon\Reqtide\Runtime\SchedulingCapability;
use Enaxon\Reqtide\Time\Cancellation;
use Enaxon\Reqtide\Time\Deadline;
use Enaxon\Reqtide\Time\DelayDisposition;
use Enaxon\Reqtide\Time\DelayScheduler;
use Enaxon\Reqtide\Value\Duration;

/** Scheduler that only declares a capability; it never waits. */
final class StubCapabilityScheduler implements DelayScheduler
{
    public function __construct(private readonly SchedulingCapability $capability) {}

    public function capability(): SchedulingCapability
    {
        return $this->capability;
    }

    public function delay(Duration $duration, Deadline $deadline, Cancellation $cancellation): DelayDisposition
    {
        return DelayDisposition::Completed;
    }
}
