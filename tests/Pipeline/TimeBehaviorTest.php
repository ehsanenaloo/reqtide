<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Pipeline;

use Enaxon\Reqtide\Completion\CompletionDisposition;
use Enaxon\Reqtide\Completion\Diagnostic;
use Enaxon\Reqtide\Completion\ExecutionCompletion;
use Enaxon\Reqtide\Completion\SettlementOverride;
use Enaxon\Reqtide\Exception\ClockFailureException;
use Enaxon\Reqtide\Exception\DeadlineExceededException;
use Enaxon\Reqtide\Exception\OperationCancelledException;
use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Outcome\CircuitVerdict;
use Enaxon\Reqtide\Outcome\OutcomeKind;
use Enaxon\Reqtide\Tests\Pipeline\Support\ClosureFallback;
use Enaxon\Reqtide\Tests\Pipeline\Support\DispatchSpy;
use Enaxon\Reqtide\Tests\Pipeline\Support\Harness;
use Enaxon\Reqtide\Time\DelayDisposition;
use Enaxon\Reqtide\Value\Duration;
use PHPUnit\Framework\TestCase;

/** Deadline and cancellation checkpoints and arbitration. */
final class TimeBehaviorTest extends TestCase
{
    /**
     * @param ExecutionCompletion<mixed> $completion
     * @return list<string>
     */
    private static function reasons(ExecutionCompletion $completion): array
    {
        return array_map(static fn(Diagnostic $d): string => $d->reasonCode, $completion->diagnostics);
    }

    public function testTimeA02ExpiryBeforeAdmissionDispatchesAndMutatesNothing(): void
    {
        $h = new Harness(true, true, true, true);
        $spy = DispatchSpy::returning('v', $h->log);

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $h->context(Duration::zero()));

        self::assertSame(CompletionDisposition::DeadlineExceeded, $completion->disposition);
        self::assertInstanceOf(DeadlineExceededException::class, $completion->policyCause);
        self::assertSame(0, $spy->dispatches);
        self::assertSame([], array_values(array_filter($h->log->entries, static fn(string $e): bool => !str_starts_with($e, 'event.'))));
        self::assertSame(0, $completion->summary->admittedNotDispatched);
    }

    public function testTimeA02LateCallbackSuccessIsALateValueWithNeutralHealthAndNoRetry(): void
    {
        $h = new Harness(true, true, false, true);
        $fallback = new ClosureFallback(static fn(): string => 'fallback');
        $context = $h->context(Duration::seconds(5), $fallback, Harness::replayable());
        $spy = DispatchSpy::of($h->log, static function (AttemptContext $attempt) use ($h): string {
            $h->clock->now = $attempt->deadline->monotonicNanoseconds + 1;

            return 'late';
        });
        $pipeline = $h->pipeline(Harness::retry(3));

        $completion = $pipeline->executeDetailed($spy->callable(), $context);
        self::assertSame(CompletionDisposition::DeadlineExceeded, $completion->disposition);
        self::assertSame(1, $spy->dispatches, 'no extra send');
        self::assertSame(0, $fallback->calls);
        self::assertSame(1, $h->classifier->calls, 'read observe-only for Retry-After; never recorded as a dependency classification');
        $record = $completion->attempts[0];
        self::assertNull($record->classification);
        self::assertSame('late', $record->outcome->value(), 'the late actual value is retained');
        self::assertSame(SettlementOverride::DeadlineExceeded, $record->logicalOverride);
        self::assertSame(CircuitVerdict::Neutral, $record->circuitVerdict);
        self::assertSame(1, $completion->summary->actualValues);
        self::assertSame(1, $completion->summary->neutralAttempts);
        self::assertSame(1, $h->circuit->abandoned);
        self::assertSame([], $h->circuit->recorded);
        self::assertSame(0, $h->concurrency->active);
        self::assertSame([], $h->scheduler->delays);

        $this->expectException(DeadlineExceededException::class);
        $pipeline->execute($spy->callable(), $h->context(Duration::seconds(5), null, Harness::replayable()));
    }

    public function testTimeA02SettlementExactlyAtTheDeadlineIsLate(): void
    {
        $h = new Harness(true);
        $context = $h->context(Duration::seconds(5));
        $spy = DispatchSpy::of($h->log, static function () use ($h, $context): string {
            $h->clock->now = $context->deadline->monotonicNanoseconds;

            return 'at-deadline';
        });

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $context);

        self::assertSame(CompletionDisposition::DeadlineExceeded, $completion->disposition);
        self::assertSame(SettlementOverride::DeadlineExceeded, $completion->attempts[0]->logicalOverride);
    }

    public function testTimeA02SettlementOneNanosecondBeforeTheDeadlineIsOnTime(): void
    {
        $h = new Harness(true);
        $context = $h->context(Duration::seconds(5));
        $spy = DispatchSpy::of($h->log, static function () use ($h, $context): string {
            $h->clock->now = $context->deadline->monotonicNanoseconds - 1;

            return 'on-time';
        });

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $context);

        self::assertSame(CompletionDisposition::Succeeded, $completion->disposition);
        self::assertSame(SettlementOverride::None, $completion->attempts[0]->logicalOverride);
        self::assertSame([CircuitVerdict::Success], $h->circuit->recorded);
    }

    public function testTimeA02LateFailureIsAlsoDeadlineExceededWithoutRetryOrFallback(): void
    {
        $h = new Harness(true, true, false, true);
        $fallback = new ClosureFallback(static fn(): string => 'fallback');
        $context = $h->context(Duration::seconds(5), $fallback, Harness::replayable());
        $business = new \RuntimeException('late failure');
        $spy = DispatchSpy::of($h->log, static function () use ($h, $context, $business): never {
            $h->clock->now = $context->deadline->monotonicNanoseconds + 10;
            throw $business;
        });

        $completion = $h->pipeline(Harness::retry(3))->executeDetailed($spy->callable(), $context);

        self::assertSame(CompletionDisposition::DeadlineExceeded, $completion->disposition);
        self::assertSame(1, $spy->dispatches);
        self::assertSame(0, $fallback->calls);
        self::assertSame($business, $completion->terminalOutcome?->exception());
        self::assertSame(1, $completion->summary->actualExceptions);
        self::assertSame(1, $h->circuit->abandoned);
    }

    public function testTimeA02ExpiryDuringBackoffEndsWithDeadlineDispositionAndNoExtraSend(): void
    {
        $h = new Harness(true, true, false, true);
        $h->scheduler->forced = DelayDisposition::DeadlineExceeded;
        $spy = DispatchSpy::throwing(new \RuntimeException('transient'), $h->log);

        $completion = $h->pipeline(Harness::retry(3))->executeDetailed($spy->callable(), $h->context(Duration::seconds(5), null, Harness::replayable()));

        self::assertSame(CompletionDisposition::DeadlineExceeded, $completion->disposition);
        self::assertSame(1, $spy->dispatches);
        self::assertSame(1, $completion->summary->dispatched);
        self::assertSame(0, $h->budget->reserved, 'nothing is reserved for an attempt that never starts');
        self::assertSame(0, $h->concurrency->active);
    }

    public function testTimeA02DeadlineReachedBetweenSettlementAndRetryDecisionKeepsTheBusinessResultAndSuppressesTheRetry(): void
    {
        $h = new Harness(true, true, false, true);
        $context = $h->context(Duration::seconds(5), null, Harness::replayable());
        $h->classifier->hook = static function () use ($h, $context): void {
            $h->clock->now = $context->deadline->monotonicNanoseconds;
        };
        $business = new \RuntimeException('transient');
        $spy = DispatchSpy::throwing($business, $h->log);

        $completion = $h->pipeline(Harness::retry(3))->executeDetailed($spy->callable(), $context);

        self::assertSame(CompletionDisposition::Failed, $completion->disposition, 'an on-time settlement is not rewritten');
        self::assertNull($completion->policyCause);
        self::assertSame(1, $spy->dispatches);
        self::assertSame(OutcomeKind::Exception, $completion->terminalOutcome?->kind);
        self::assertSame($business, $completion->terminalOutcome->exception());
        self::assertSame(SettlementOverride::None, $completion->attempts[0]->logicalOverride, 'the on-time settlement is not rewritten');
        self::assertSame([CircuitVerdict::Failure], $h->circuit->recorded);
        self::assertContains('retry_deadline_reached', self::reasons($completion), 'the stable retry-stop reason is recorded');
        self::assertSame([], $h->scheduler->delays);
    }

    public function testTimeA03CancellationObservedAtTheRetryDecisionCheckpointKeepsTheBusinessResultAndSuppressesTheRetry(): void
    {
        $h = new Harness(true, true, false, true);
        $h->classifier->hook = static function () use ($h): void {
            $h->cancel->cancel();
        };
        $business = new \RuntimeException('transient');
        $spy = DispatchSpy::throwing($business, $h->log);

        $completion = $h->pipeline(Harness::retry(3))->executeDetailed($spy->callable(), $h->context(Duration::seconds(5), null, Harness::replayable()));

        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertNull($completion->policyCause);
        self::assertSame(1, $spy->dispatches);
        self::assertSame($business, $completion->terminalOutcome?->exception());
        self::assertContains('retry_cancelled', self::reasons($completion));
    }

    public function testTimeA02FallbackStillHonoursItsOwnCheckpointAfterTheRetryCheckpointSuppressedTheRetry(): void
    {
        $h = new Harness(true, true, false, true);
        $fallback = new ClosureFallback(static fn(): string => 'fallback');
        $context = $h->context(Duration::seconds(5), $fallback, Harness::replayable());
        $h->classifier->hook = static function () use ($h, $context): void {
            $h->clock->now = $context->deadline->monotonicNanoseconds;
        };
        $spy = DispatchSpy::throwing(new \RuntimeException('transient'), $h->log);

        $completion = $h->pipeline(Harness::retry(3))->executeDetailed($spy->callable(), $context);

        // The retry checkpoint did not rewrite the result; the separate fallback checkpoint still
        // refuses to start a fallback at the expired deadline.
        self::assertContains('retry_deadline_reached', self::reasons($completion));
        self::assertSame(0, $fallback->calls);
        self::assertSame(CompletionDisposition::DeadlineExceeded, $completion->disposition);
        self::assertSame(1, $spy->dispatches);
    }

    public function testTimeA03CancellationOrDeadlineDuringTheRetryDelayStillYieldsCancelledOrDeadlineExceeded(): void
    {
        foreach ([[DelayDisposition::Cancelled, CompletionDisposition::Cancelled], [DelayDisposition::DeadlineExceeded, CompletionDisposition::DeadlineExceeded]] as [$forced, $expected]) {
            $h = new Harness(false, false, false, true);
            $h->scheduler->forced = $forced;
            $spy = DispatchSpy::throwing(new \RuntimeException('transient'), $h->log);

            $completion = $h->pipeline(Harness::retry(3))->executeDetailed($spy->callable(), $h->context(Duration::seconds(5), null, Harness::replayable()));

            self::assertSame($expected, $completion->disposition);
            self::assertSame(1, $spy->dispatches);
        }
    }

    public function testTimeA02BackoffThatCannotFitTheRemainingTimeStopsRetryWithoutWaiting(): void
    {
        $h = new Harness(false, false, false, true);
        $spy = DispatchSpy::throwing($business = new \RuntimeException('transient'), $h->log);

        $completion = $h->pipeline(Harness::retry(3, 400))->executeDetailed($spy->callable(), $h->context(Duration::milliseconds(300), null, Harness::replayable()));

        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertSame($business, $completion->terminalOutcome?->exception());
        self::assertSame(1, $spy->dispatches);
        self::assertSame([], $h->scheduler->delays, 'deferral never enqueues or waits');
    }

    public function testTimeA02ExpiryBeforeFallbackSkipsTheFallback(): void
    {
        $h = new Harness();
        $fallback = new ClosureFallback(static fn(): string => 'fallback');
        $context = $h->context(Duration::seconds(5), $fallback);
        $h->classifier->hook = static function () use ($h, $context): void {
            $h->clock->now = $context->deadline->monotonicNanoseconds;
        };
        $spy = DispatchSpy::throwing(new \RuntimeException('transient'), $h->log);

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $context);

        self::assertSame(CompletionDisposition::DeadlineExceeded, $completion->disposition);
        self::assertSame(0, $fallback->calls);
        self::assertSame(0, $completion->summary->fallbackExecutions);
    }

    public function testTimeA02LateFallbackIsDeadlineExceededAndRetainsItsOutcome(): void
    {
        $h = new Harness();
        $context = $h->context(Duration::seconds(5));
        $fallback = new ClosureFallback(static function () use ($h, $context): string {
            $h->clock->now = $context->deadline->monotonicNanoseconds + 1;

            return 'late-fallback';
        });
        $context = new \Enaxon\Reqtide\Execution\ExecutionContext(
            $context->operationId,
            $context->partition,
            $context->deadline,
            $context->cancellation,
            $context->metadata,
            $fallback,
        );
        $spy = DispatchSpy::throwing(new \RuntimeException('transient'), $h->log);

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $context);

        self::assertSame(CompletionDisposition::DeadlineExceeded, $completion->disposition);
        self::assertSame(1, $completion->summary->fallbackExecutions);
        self::assertSame('late-fallback', $completion->fallbackOutcome?->value());
        self::assertNotNull($completion->terminalOutcome);
    }

    public function testTimeA01ClockFailureBeforeDispatchIsAnInfrastructureFailureThatPreventsDispatch(): void
    {
        $h = new Harness(true, true);
        $context = $h->context(Duration::seconds(5));
        $h->clock->failWhen = static fn(): bool => true;
        $spy = DispatchSpy::returning('v', $h->log);

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $context);

        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertInstanceOf(ClockFailureException::class, $completion->policyCause);
        self::assertSame(0, $spy->dispatches);
        self::assertNull($completion->summary->elapsed);
        self::assertFalse($completion->summary->accountingComplete);
        self::assertFalse($h->log->has('circuit.acquire'));
    }

    public function testTimeA01ClockFailureAtSettlementKeepsTheResultAndStopsFurtherActions(): void
    {
        $h = new Harness(true, true, false, true);
        $fallback = new ClosureFallback(static fn(): string => 'fallback');
        $context = $h->context(Duration::seconds(5), $fallback, Harness::replayable());
        $business = new \RuntimeException('transient');
        $spy = DispatchSpy::of($h->log, static function () use ($h, $business): never {
            $h->clock->failWhen = static fn(): bool => true;
            throw $business;
        });

        $completion = $h->pipeline(Harness::retry(3))->executeDetailed($spy->callable(), $context);

        self::assertSame(1, $spy->dispatches, 'unknown timing is never declared on time: no retry');
        self::assertSame(0, $fallback->calls, 'and no fallback');
        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertSame($business, $completion->terminalOutcome?->exception());
        self::assertNull($completion->attempts[0]->elapsed);
        self::assertFalse($completion->summary->accountingComplete);
        self::assertContains('clock_unavailable', self::reasons($completion));
        self::assertSame(1, $h->circuit->abandoned, 'health is neutral when timing is unknown');
        self::assertSame(0, $h->concurrency->active);
    }

    public function testTimeA03CancellationBeforeSendPreventsDispatch(): void
    {
        $h = new Harness(true, true, true);
        $h->cancel->cancel();
        $spy = DispatchSpy::returning('v', $h->log);

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $h->context(Duration::seconds(5)));

        self::assertSame(CompletionDisposition::Cancelled, $completion->disposition);
        self::assertInstanceOf(OperationCancelledException::class, $completion->policyCause);
        self::assertSame(0, $spy->dispatches);
        self::assertFalse($h->log->has('circuit.acquire'));
    }

    public function testTimeA03CancellationDuringTransportKeepsTheActualValueAndNeutralHealth(): void
    {
        $h = new Harness(true, true);
        $spy = DispatchSpy::of($h->log, static function () use ($h): string {
            $h->cancel->cancel();
            $h->cancel->cancel();

            return 'late-success';
        });
        $fallback = new ClosureFallback(static fn(): string => 'fallback');

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $h->context(Duration::seconds(5), $fallback));

        self::assertSame(CompletionDisposition::Cancelled, $completion->disposition);
        self::assertSame('late-success', $completion->attempts[0]->outcome->value());
        self::assertSame(SettlementOverride::Cancelled, $completion->attempts[0]->logicalOverride);
        self::assertSame(1, $completion->summary->actualValues);
        self::assertSame(1, $h->circuit->abandoned);
        self::assertSame([], $h->circuit->recorded);
        self::assertSame(0, $h->concurrency->active, 'cleanup runs only after actual settlement');
        self::assertSame(0, $fallback->calls);
        self::assertSame(1, $h->classifier->calls, 'observe-only Retry-After read, no classification recorded');
        self::assertNull($completion->attempts[0]->classification);
    }

    public function testTimeA03CancellationDuringTransportNeverTriggersRetry(): void
    {
        $h = new Harness(true, true, false, true);
        $spy = DispatchSpy::of($h->log, static function () use ($h): never {
            $h->cancel->cancel();
            throw new \RuntimeException('transient');
        });

        $completion = $h->pipeline(Harness::retry(3))->executeDetailed($spy->callable(), $h->context(Duration::seconds(5), null, Harness::replayable()));

        self::assertSame(CompletionDisposition::Cancelled, $completion->disposition);
        self::assertSame(1, $spy->dispatches);
        self::assertSame(1, $completion->summary->actualExceptions);
    }

    public function testTimeA03SettlementObservedBeforeCancellationKeepsItsResult(): void
    {
        $h = new Harness(true, true);
        $h->classifier->hook = static function () use ($h): void {
            $h->cancel->cancel();
        };
        $spy = DispatchSpy::returning('settled-first', $h->log);

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $h->context(Duration::seconds(5)));

        self::assertSame(CompletionDisposition::Succeeded, $completion->disposition);
        self::assertSame('settled-first', $completion->terminalOutcome?->value());
        self::assertSame([CircuitVerdict::Success], $h->circuit->recorded);
    }

    public function testTimeA03CancellationDuringRetryDelayEndsTheDelayWithoutANewAttempt(): void
    {
        $h = new Harness(true, true, false, true);
        $h->scheduler->forced = DelayDisposition::Cancelled;
        $spy = DispatchSpy::throwing(new \RuntimeException('transient'), $h->log);

        $completion = $h->pipeline(Harness::retry(3))->executeDetailed($spy->callable(), $h->context(Duration::seconds(5), null, Harness::replayable()));

        self::assertSame(CompletionDisposition::Cancelled, $completion->disposition);
        self::assertSame(1, $spy->dispatches);
        self::assertSame(1, $completion->summary->dispatched);
    }

    public function testTimeA03DeadlineIsSampledBeforeCancellationAtTheSameSettlement(): void
    {
        $h = new Harness(true);
        $context = $h->context(Duration::seconds(5));
        $spy = DispatchSpy::of($h->log, static function () use ($h, $context): string {
            $h->cancel->cancel();
            $h->clock->now = $context->deadline->monotonicNanoseconds;

            return 'both';
        });

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $context);

        self::assertSame(CompletionDisposition::DeadlineExceeded, $completion->disposition);
        self::assertSame(SettlementOverride::DeadlineExceeded, $completion->attempts[0]->logicalOverride);
    }
}
