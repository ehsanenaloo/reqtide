<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Error;

use Enaxon\Reqtide\Completion\CompletionDisposition;
use Enaxon\Reqtide\Error\ReasonCode;
use Enaxon\Reqtide\Error\Severity;
use Enaxon\Reqtide\Outcome\CircuitVerdict;
use Enaxon\Reqtide\Telemetry\AccountingDegraded;
use Enaxon\Reqtide\Telemetry\AdmissionRejected;
use Enaxon\Reqtide\Telemetry\AttemptCompleted;
use Enaxon\Reqtide\Telemetry\BackendErrorObserved;
use Enaxon\Reqtide\Telemetry\CircuitTransitioned;
use Enaxon\Reqtide\Telemetry\EventSeverity;
use Enaxon\Reqtide\Telemetry\OperationCompleted;
use Enaxon\Reqtide\Telemetry\RetryScheduled;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\OperationId;
use Enaxon\Reqtide\Value\ProfileVersion;
use PHPUnit\Framework\TestCase;

/** Event reason() accessors and the sink severity mapping. */
final class EventSeverityTest extends TestCase
{
    private static function op(): OperationId
    {
        return new OperationId('op-1');
    }

    private static function pv(): ProfileVersion
    {
        return new ProfileVersion('v1');
    }

    public function testFailureBearingEventsExposeTheRegistryCodeAndItsSeverity(): void
    {
        $degraded = new AccountingDegraded(self::op(), self::pv(), ReasonCode::CircuitRecordStale->value);
        $backend = new BackendErrorObserved(self::op(), self::pv(), 'circuit', ReasonCode::StateCorrupt->value);
        $rejected = new AdmissionRejected(self::op(), self::pv(), 'rate_limit', ReasonCode::RateExhausted->value);
        $transition = new CircuitTransitioned(self::op(), self::pv(), 'closed', 'open', ReasonCode::CircuitThresholdExceeded->value);

        self::assertSame(ReasonCode::CircuitRecordStale, $degraded->reason());
        self::assertSame(Severity::Warning, $degraded->severity());
        self::assertSame(ReasonCode::StateCorrupt, $backend->reason());
        self::assertSame(Severity::Error, $backend->severity());
        self::assertSame(ReasonCode::RateExhausted, $rejected->reason());
        self::assertSame(Severity::Info, $rejected->severity());
        self::assertSame(ReasonCode::CircuitThresholdExceeded, $transition->reason());
        self::assertSame(Severity::Warning, $transition->severity());
    }

    public function testForeignCodesFallBackToAdvisoryDefaults(): void
    {
        self::assertNull((new AdmissionRejected(self::op(), self::pv(), 'rate_limit', 'vendor_quota'))->reason());
        self::assertSame(Severity::Info, (new AdmissionRejected(self::op(), self::pv(), 'rate_limit', 'vendor_quota'))->severity());
        self::assertSame(Severity::Warning, (new AccountingDegraded(self::op(), self::pv(), 'vendor'))->severity());
        self::assertSame(Severity::Error, (new BackendErrorObserved(self::op(), self::pv(), 'x', 'vendor'))->severity());
    }

    public function testEventSeverityMapsEveryEventKindToAPsr3LevelString(): void
    {
        $events = [
            [new AccountingDegraded(self::op(), self::pv(), ReasonCode::EventSinkFailed->value), 'warning'],
            [new BackendErrorObserved(self::op(), self::pv(), 'clock', ReasonCode::ClockFailure->value), 'error'],
            [new AdmissionRejected(self::op(), self::pv(), 'circuit', ReasonCode::CircuitOpen->value), 'info'],
            [new CircuitTransitioned(self::op(), self::pv(), 'open', 'half_open', ReasonCode::CircuitOpenElapsed->value), 'info'],
            [new RetryScheduled(self::op(), self::pv(), 2, Duration::seconds(1), 'transient'), 'info'],
            [new AttemptCompleted(self::op(), self::pv(), \Enaxon\Reqtide\Value\AttemptId::fromLowerHex(str_repeat('a', 32)), CircuitVerdict::Failure, null), 'debug'],
            [new OperationCompleted(self::op(), self::pv(), CompletionDisposition::Failed, null, false), 'error'],
        ];

        foreach ($events as [$event, $level]) {
            self::assertSame($level, EventSeverity::of($event)->psr3Level(), $event::class);
        }
    }

    public function testDispositionSeverityMatrix(): void
    {
        $expected = [
            'succeeded' => Severity::Info, 'rejected' => Severity::Info, 'deferred' => Severity::Info,
            'cancelled' => Severity::Notice, 'fallback_succeeded' => Severity::Notice,
            'deadline_exceeded' => Severity::Warning, 'failed' => Severity::Error, 'fallback_failed' => Severity::Error,
        ];

        foreach (CompletionDisposition::cases() as $disposition) {
            self::assertSame($expected[$disposition->value], EventSeverity::ofDisposition($disposition), $disposition->value);
        }
    }
}
