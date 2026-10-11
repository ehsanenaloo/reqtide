<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Testing;

use Enaxon\Reqtide\Exception\ClockFailureException;
use Enaxon\Reqtide\Runtime\SchedulingCapability;
use Enaxon\Reqtide\Telemetry\AccountingDegraded;
use Enaxon\Reqtide\Testing\RecordingEventSink;
use Enaxon\Reqtide\Testing\VirtualClock;
use Enaxon\Reqtide\Testing\VirtualDelayScheduler;
use Enaxon\Reqtide\Time\CancellationToken;
use Enaxon\Reqtide\Time\Deadline;
use Enaxon\Reqtide\Time\DelayDisposition;
use Enaxon\Reqtide\Time\NeverCancelled;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\OperationId;
use Enaxon\Reqtide\Value\ProfileVersion;
use PHPUnit\Framework\TestCase;

final class SchedulerAndSinkTest extends TestCase
{
    private ?VirtualClock $clockInstance = null;
    private ?VirtualDelayScheduler $schedulerInstance = null;

    private function clock(): VirtualClock
    {
        return $this->clockInstance ??= new VirtualClock(0);
    }

    private function scheduler(): VirtualDelayScheduler
    {
        return $this->schedulerInstance ??= new VirtualDelayScheduler($this->clock());
    }

    public function testCapabilityIsBoundedBlocking(): void
    {
        self::assertSame(SchedulingCapability::BoundedBlocking, $this->scheduler()->capability());
    }

    public function testDelayAdvancesVirtualTimeWithoutSleeping(): void
    {
        $deadline = Deadline::after(Duration::seconds(3600), $this->clock());
        $wall = microtime(true);

        $result = $this->scheduler()->delay(Duration::seconds(30), $deadline, new NeverCancelled());

        self::assertSame(DelayDisposition::Completed, $result);
        self::assertSame(30_000_000_000, $this->clock()->peekMonotonic());
        self::assertLessThan(1.0, microtime(true) - $wall);
        self::assertSame(1, $this->scheduler()->callCount());
        self::assertSame(30_000_000_000, $this->scheduler()->requestedDelays()[0]->nanoseconds);
        self::assertSame(30_000_000_000, $this->scheduler()->records()[0]->elapsed->nanoseconds);
        self::assertSame(DelayDisposition::Completed, $this->scheduler()->records()[0]->disposition);
    }

    public function testZeroDelayCompletesWithoutAdvancing(): void
    {
        $deadline = Deadline::after(Duration::seconds(1), $this->clock());
        self::assertSame(DelayDisposition::Completed, $this->scheduler()->delay(Duration::zero(), $deadline, new NeverCancelled()));
        self::assertSame(0, $this->clock()->peekMonotonic());
    }

    public function testDelayEndingBeforeDeadlineCompletes(): void
    {
        $deadline = Deadline::after(Duration::nanoseconds(101), $this->clock());
        self::assertSame(DelayDisposition::Completed, $this->scheduler()->delay(Duration::nanoseconds(100), $deadline, new NeverCancelled()));
    }

    public function testDelayEndingExactlyAtDeadlineIsDeadlineExceeded(): void
    {
        $deadline = Deadline::after(Duration::nanoseconds(100), $this->clock());
        $result = $this->scheduler()->delay(Duration::nanoseconds(100), $deadline, new NeverCancelled());

        self::assertSame(DelayDisposition::DeadlineExceeded, $result);
        self::assertSame(100, $this->clock()->peekMonotonic());
    }

    public function testDelayLongerThanDeadlineStopsAtTheDeadline(): void
    {
        $deadline = Deadline::after(Duration::nanoseconds(40), $this->clock());
        $result = $this->scheduler()->delay(Duration::seconds(10), $deadline, new NeverCancelled());

        self::assertSame(DelayDisposition::DeadlineExceeded, $result);
        self::assertSame(40, $this->clock()->peekMonotonic());
        self::assertSame(40, $this->scheduler()->records()[0]->elapsed->nanoseconds);
    }

    public function testAlreadyExpiredDeadlineWinsOverCancellation(): void
    {
        $deadline = Deadline::after(Duration::zero(), $this->clock());
        $token = new CancellationToken();
        $token->cancel();

        self::assertSame(DelayDisposition::DeadlineExceeded, $this->scheduler()->delay(Duration::seconds(1), $deadline, $token));
        self::assertSame(0, $this->clock()->peekMonotonic());
    }

    public function testAlreadyCancelledReturnsCancelledWithoutAdvancing(): void
    {
        $deadline = Deadline::after(Duration::seconds(1), $this->clock());
        $token = new CancellationToken();
        $token->cancel();

        self::assertSame(DelayDisposition::Cancelled, $this->scheduler()->delay(Duration::milliseconds(5), $deadline, $token));
        self::assertSame(0, $this->clock()->peekMonotonic());
    }

    public function testCancellationScriptedMidDelayStopsThereAndOnlyOnce(): void
    {
        $deadline = Deadline::after(Duration::seconds(10), $this->clock());
        $token = new CancellationToken();
        $this->scheduler()->cancelDuringNextDelay($token, Duration::seconds(2));

        $first = $this->scheduler()->delay(Duration::seconds(5), $deadline, $token);

        self::assertSame(DelayDisposition::Cancelled, $first);
        self::assertTrue($token->isRequested());
        self::assertSame(2_000_000_000, $this->clock()->peekMonotonic());
        self::assertSame(2_000_000_000, $this->scheduler()->records()[0]->elapsed->nanoseconds);

        // The script is consumed: the next delay with a fresh token is unaffected.
        $second = $this->scheduler()->delay(Duration::seconds(1), $deadline, new NeverCancelled());
        self::assertSame(DelayDisposition::Completed, $second);
        self::assertSame(3_000_000_000, $this->clock()->peekMonotonic());
        self::assertSame(3_000_000_000, $this->scheduler()->totalElapsed()->nanoseconds);
    }

    public function testScriptedCancellationAtDeadlineInstantReportsDeadline(): void
    {
        $deadline = Deadline::after(Duration::seconds(1), $this->clock());
        $token = new CancellationToken();
        $this->scheduler()->cancelDuringNextDelay($token, Duration::seconds(1));

        self::assertSame(DelayDisposition::DeadlineExceeded, $this->scheduler()->delay(Duration::seconds(5), $deadline, $token));
        self::assertTrue($token->isRequested());
    }

    public function testScriptedCancellationAfterDelayEndIsNeverFired(): void
    {
        $deadline = Deadline::after(Duration::seconds(10), $this->clock());
        $token = new CancellationToken();
        $this->scheduler()->cancelDuringNextDelay($token, Duration::seconds(8));

        self::assertSame(DelayDisposition::Completed, $this->scheduler()->delay(Duration::seconds(1), $deadline, $token));
        self::assertFalse($token->isRequested());
    }

    public function testClockFailureDuringDelayPropagates(): void
    {
        $deadline = Deadline::after(Duration::seconds(1), $this->clock());
        $this->clock()->failNextMonotonicReads();

        $this->expectException(ClockFailureException::class);
        $this->scheduler()->delay(Duration::milliseconds(1), $deadline, new NeverCancelled());
    }

    public function testRecordingSinkStoresEventsAndFiltersByType(): void
    {
        $sink = new RecordingEventSink();
        $event = new AccountingDegraded(new OperationId('op-1'), new ProfileVersion('v1'), 'late');
        $sink->emit($event);
        $sink->emit($event);

        self::assertSame(2, $sink->count());
        self::assertSame([$event, $event], $sink->events());
        self::assertSame([$event, $event], $sink->ofType(AccountingDegraded::class));
        $sink->clear();
        self::assertSame(0, $sink->count());
    }

    public function testRecordingSinkThrowsAfterRecording(): void
    {
        $sink = new RecordingEventSink();
        $failure = new \RuntimeException('observer down');
        $sink->throwOnEmit($failure);
        $event = new AccountingDegraded(new OperationId('op-1'), new ProfileVersion('v1'), 'late');

        for ($i = 0; $i < 2; ++$i) {
            try {
                $sink->emit($event);
                self::fail('failure expected');
            } catch (\RuntimeException $caught) {
                self::assertSame($failure, $caught);
            }
        }
        self::assertSame(2, $sink->count());

        $sink->stopThrowing();
        $sink->emit($event);
        self::assertSame(3, $sink->count());
    }

    public function testRecordingSinkLimitedFailureCount(): void
    {
        $sink = new RecordingEventSink();
        $sink->throwOnEmit(new \RuntimeException('once'), 1);
        $event = new AccountingDegraded(new OperationId('op-1'), new ProfileVersion('v1'), 'late');

        try {
            $sink->emit($event);
            self::fail('failure expected');
        } catch (\RuntimeException) {
        }
        $sink->emit($event);
        self::assertSame(2, $sink->count());
    }

    public function testRecordingSinkRejectsNegativeFailureCount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new RecordingEventSink())->throwOnEmit(new \RuntimeException('x'), -1);
    }
}
