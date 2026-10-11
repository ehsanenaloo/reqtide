<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Telemetry;

use Enaxon\Reqtide\Completion\CompletionDisposition;
use Enaxon\Reqtide\Error\Severity;

/**
 * Severity mapping helper for event sinks that write to a logger.
 *
 * Follow the "log the terminal failure once" rule: map `RetryScheduled` and per-attempt events to low
 * severities and rely on the `OperationCompleted` mapping for the final outcome. Use
 * `EventSeverity::of($event)->psr3Level()` for the logger level string.
 */
final class EventSeverity
{
    private function __construct() {}

    public static function of(Event $event): Severity
    {
        return match (true) {
            $event instanceof AdmissionRejected,
            $event instanceof AccountingDegraded,
            $event instanceof BackendErrorObserved,
            $event instanceof CircuitTransitioned => $event->severity(),
            $event instanceof OperationCompleted => self::ofDisposition($event->disposition),
            $event instanceof AttemptCompleted => Severity::Debug,
            default => Severity::Info,
        };
    }

    public static function ofDisposition(CompletionDisposition $disposition): Severity
    {
        return match ($disposition) {
            CompletionDisposition::Succeeded => Severity::Info,
            CompletionDisposition::Rejected, CompletionDisposition::Deferred => Severity::Info,
            CompletionDisposition::Cancelled, CompletionDisposition::FallbackSucceeded => Severity::Notice,
            CompletionDisposition::DeadlineExceeded => Severity::Warning,
            CompletionDisposition::Failed, CompletionDisposition::FallbackFailed => Severity::Error,
        };
    }
}
