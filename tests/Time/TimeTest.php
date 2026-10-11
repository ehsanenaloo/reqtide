<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Time;

use Enaxon\Reqtide\Exception\CapabilityMismatchException;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Runtime\SchedulingCapability;
use Enaxon\Reqtide\Tests\Support\FakeClock;
use Enaxon\Reqtide\Time\BoundedBlockingScheduler;
use Enaxon\Reqtide\Time\CancellationToken;
use Enaxon\Reqtide\Time\Deadline;
use Enaxon\Reqtide\Time\DelayDisposition;
use Enaxon\Reqtide\Time\NeverCancelled;
use Enaxon\Reqtide\Time\NoDelayScheduler;
use Enaxon\Reqtide\Time\SecureRandomSource;
use Enaxon\Reqtide\Time\SystemClock;
use Enaxon\Reqtide\Value\Duration;
use PHPUnit\Framework\TestCase;

final class TimeTest extends TestCase
{
    public function testDeadlineRemainingAndExpiryEquality(): void
    {
        $clock = new FakeClock(100);
        $deadline = Deadline::after(new Duration(50), $clock);
        self::assertSame(150, $deadline->monotonicNanoseconds);
        self::assertSame(50, $deadline->remaining($clock)->nanoseconds);
        self::assertFalse($deadline->isExpired($clock));

        $clock->now = 149;
        self::assertSame(1, $deadline->remaining($clock)->nanoseconds);
        $clock->now = 150;
        self::assertTrue($deadline->isExpired($clock));
        self::assertTrue($deadline->remaining($clock)->isZero());
        $clock->now = 10_000;
        self::assertTrue($deadline->remaining($clock)->isZero());
    }

    public function testDeadlineConstructionOverflowBoundary(): void
    {
        $clock = new FakeClock(100);
        self::assertSame(PHP_INT_MAX, Deadline::after(new Duration(PHP_INT_MAX - 100), $clock)->monotonicNanoseconds);
        $this->expectException(InvalidConfigurationException::class);
        Deadline::after(new Duration(PHP_INT_MAX - 99), $clock);
    }

    public function testDeadlineRequiresItsSourceClockInstance(): void
    {
        $deadline = Deadline::after(new Duration(5), new FakeClock());
        $this->expectException(InvalidConfigurationException::class);
        $deadline->isExpired(new FakeClock());
    }

    public function testCancellationTokenIsIdempotent(): void
    {
        $token = new CancellationToken();
        self::assertFalse($token->isRequested());
        $token->cancel();
        $token->cancel();
        self::assertTrue($token->isRequested());
        self::assertFalse((new NeverCancelled())->isRequested());
    }

    public function testNoDelaySchedulerCheckpointOrderAndCapability(): void
    {
        $clock = new FakeClock(0);
        $scheduler = new NoDelayScheduler($clock);
        $token = new CancellationToken();
        $deadline = Deadline::after(new Duration(10), $clock);

        self::assertSame(SchedulingCapability::None, $scheduler->capability());
        self::assertSame(DelayDisposition::Completed, $scheduler->delay(Duration::zero(), $deadline, $token));

        $token->cancel();
        self::assertSame(DelayDisposition::Cancelled, $scheduler->delay(Duration::zero(), $deadline, $token));

        $clock->now = 10;
        self::assertSame(DelayDisposition::DeadlineExceeded, $scheduler->delay(Duration::zero(), $deadline, $token));
    }

    public function testNoDelaySchedulerRefusesPositiveDelay(): void
    {
        $clock = new FakeClock(0);
        $this->expectException(CapabilityMismatchException::class);
        (new NoDelayScheduler($clock))->delay(new Duration(1), Deadline::after(new Duration(10), $clock), new NeverCancelled());
    }

    public function testBlockingSchedulerCompletesRealShortDelay(): void
    {
        $clock = new SystemClock();
        $scheduler = new BoundedBlockingScheduler($clock);
        $start = $clock->monotonicNow();
        $result = $scheduler->delay(Duration::milliseconds(12), Deadline::after(Duration::seconds(5), $clock), new NeverCancelled());
        self::assertSame(DelayDisposition::Completed, $result);
        self::assertGreaterThanOrEqual(12_000_000, $clock->monotonicNow() - $start);
        self::assertSame(SchedulingCapability::BoundedBlocking, $scheduler->capability());
    }

    public function testBlockingSchedulerObservesCancellationAndDeadline(): void
    {
        $clock = new SystemClock();
        $scheduler = new BoundedBlockingScheduler($clock);
        $token = new CancellationToken();
        $token->cancel();
        self::assertSame(
            DelayDisposition::Cancelled,
            $scheduler->delay(Duration::seconds(30), Deadline::after(Duration::seconds(60), $clock), $token),
        );
        self::assertSame(
            DelayDisposition::DeadlineExceeded,
            $scheduler->delay(Duration::seconds(30), Deadline::after(Duration::milliseconds(10), $clock), new NeverCancelled()),
        );
    }

    public function testSecureRandomRespectsRangeAndRejectsInvertedRange(): void
    {
        $random = new SecureRandomSource();
        self::assertSame(7, $random->integer(7, 7));
        for ($i = 0; $i < 20; ++$i) {
            $value = $random->integer(3, 5);
            self::assertGreaterThanOrEqual(3, $value);
            self::assertLessThanOrEqual(5, $value);
        }
        $this->expectException(InvalidConfigurationException::class);
        $random->integer(2, 1);
    }

    public function testSystemClockIsMonotonicAndWallIsUtc(): void
    {
        $clock = new SystemClock();
        $first = $clock->monotonicNow();
        self::assertGreaterThanOrEqual($first, $clock->monotonicNow());
        self::assertSame(0, $clock->wallNow()->getOffset());
    }
}
