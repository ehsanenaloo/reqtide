<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Testing;

use Enaxon\Reqtide\Runtime\SchedulingCapability;
use Enaxon\Reqtide\Time\Cancellation;
use Enaxon\Reqtide\Time\CancellationToken;
use Enaxon\Reqtide\Time\Deadline;
use Enaxon\Reqtide\Time\DelayDisposition;
use Enaxon\Reqtide\Time\DelayScheduler;
use Enaxon\Reqtide\Value\Duration;

/**
 * BoundedBlocking scheduler that jumps a VirtualClock instead of sleeping.
 *
 * Checkpoint order matches BoundedBlockingScheduler: deadline first, then cancellation, then
 * completion, so a delay ending exactly at the deadline reports DeadlineExceeded. Virtual time
 * never passes the earliest of delay end, deadline and scripted cancellation instant.
 * The clock must be the one that created the Deadline.
 *
 * @api-for-tests
 */
final class VirtualDelayScheduler implements DelayScheduler
{
    /** @var list<DelayRecord> */
    private array $records = [];
    /** @var list<array{CancellationToken, Duration}> */
    private array $cancelScripts = [];

    public function __construct(private readonly VirtualClock $clock) {}

    public function capability(): SchedulingCapability
    {
        return SchedulingCapability::BoundedBlocking;
    }

    /**
     * During the next delay() call, request cancellation on $token once $after of virtual time has
     * passed since that call started. Pass the token the operation under test observes.
     */
    public function cancelDuringNextDelay(CancellationToken $token, Duration $after): void
    {
        $this->cancelScripts[] = [$token, $after];
    }

    public function delay(Duration $duration, Deadline $deadline, Cancellation $cancellation): DelayDisposition
    {
        $script = array_shift($this->cancelScripts);
        $start = $this->clock->monotonicNow();
        $end = self::saturatingAdd($start, $duration->nanoseconds);
        $cancelAt = $script === null ? null : self::saturatingAdd($start, $script[1]->nanoseconds);

        $disposition = null;
        while ($disposition === null) {
            $now = $this->clock->monotonicNow();
            if ($script !== null && $cancelAt !== null && $now >= $cancelAt) {
                $script[0]->cancel();
                $script = null;
                $cancelAt = null;
            }
            if ($deadline->isExpired($this->clock)) {
                $disposition = DelayDisposition::DeadlineExceeded;
            } elseif ($cancellation->isRequested()) {
                $disposition = DelayDisposition::Cancelled;
            } elseif ($now >= $end) {
                $disposition = DelayDisposition::Completed;
            } else {
                $target = min($end, $deadline->monotonicNanoseconds);
                if ($cancelAt !== null) {
                    $target = min($target, $cancelAt);
                }
                $this->clock->advance(new Duration(max(1, $target - $now)));
            }
        }

        $this->records[] = new DelayRecord(
            $duration,
            new Duration(max(0, $this->clock->peekMonotonic() - $start)),
            $disposition,
        );

        return $disposition;
    }

    /** @return list<DelayRecord> */
    public function records(): array
    {
        return $this->records;
    }

    /** @return list<Duration> requested durations in call order */
    public function requestedDelays(): array
    {
        return array_map(static fn(DelayRecord $record): Duration => $record->requested, $this->records);
    }

    public function callCount(): int
    {
        return count($this->records);
    }

    /** Total virtual time consumed across all delays. */
    public function totalElapsed(): Duration
    {
        $total = Duration::zero();
        foreach ($this->records as $record) {
            $total = $total->add($record->elapsed);
        }

        return $total;
    }

    private static function saturatingAdd(int $a, int $b): int
    {
        return $a > PHP_INT_MAX - $b ? PHP_INT_MAX : $a + $b;
    }
}
