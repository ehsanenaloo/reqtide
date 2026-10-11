<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Pipeline\Support;

use Enaxon\Reqtide\Runtime\SchedulingCapability;
use Enaxon\Reqtide\Time\Cancellation;
use Enaxon\Reqtide\Time\Deadline;
use Enaxon\Reqtide\Time\DelayDisposition;
use Enaxon\Reqtide\Time\DelayScheduler;
use Enaxon\Reqtide\Value\Duration;

/** Virtual-time bounded-blocking scheduler: advances the fake clock instead of sleeping. */
final class FakeScheduler implements DelayScheduler
{
    /** @var list<int> requested delays in nanoseconds */
    public array $delays = [];

    /** @var (\Closure(): void)|null runs while the delay is in progress (to sample held ownership) */
    public ?\Closure $during = null;

    public ?DelayDisposition $forced = null;

    /** Thrown by delay() after the call is recorded, to model a failing scheduler. */
    public ?\Throwable $failure = null;

    public function __construct(private readonly PipelineClock $clock, private readonly ?CallLog $log = null) {}

    public function capability(): SchedulingCapability
    {
        return SchedulingCapability::BoundedBlocking;
    }

    public function delay(Duration $duration, Deadline $deadline, Cancellation $cancellation): DelayDisposition
    {
        $this->delays[] = $duration->nanoseconds;
        $this->log?->add('delay');
        if ($this->during !== null) {
            ($this->during)();
        }
        if ($this->failure !== null) {
            throw $this->failure;
        }
        if ($this->forced !== null) {
            return $this->forced;
        }
        if ($deadline->isExpired($this->clock)) {
            return DelayDisposition::DeadlineExceeded;
        }
        if ($cancellation->isRequested()) {
            return DelayDisposition::Cancelled;
        }
        $end = $this->clock->now + $duration->nanoseconds;
        if ($end >= $deadline->monotonicNanoseconds) {
            $this->clock->now = $deadline->monotonicNanoseconds;

            return DelayDisposition::DeadlineExceeded;
        }
        $this->clock->now = $end;

        return DelayDisposition::Completed;
    }
}
