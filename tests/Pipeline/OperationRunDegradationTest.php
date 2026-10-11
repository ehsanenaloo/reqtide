<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Pipeline;

use Enaxon\Reqtide\Admission\AdmissionDecision;
use Enaxon\Reqtide\Admission\AdmissionStatus;
use Enaxon\Reqtide\Completion\CompletionDisposition;
use Enaxon\Reqtide\Completion\Diagnostic;
use Enaxon\Reqtide\Completion\ExecutionCompletion;
use Enaxon\Reqtide\Completion\SettlementOverride;
use Enaxon\Reqtide\Error\ReasonCode;
use Enaxon\Reqtide\Error\Stage;
use Enaxon\Reqtide\Exception\AdmissionRejectedException;
use Enaxon\Reqtide\Exception\ClockFailureException;
use Enaxon\Reqtide\Exception\OperationCancelledException;
use Enaxon\Reqtide\Exception\StateUnavailableException;
use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Fallback\FallbackScope;
use Enaxon\Reqtide\Policy\BackoffMode;
use Enaxon\Reqtide\Policy\JitterMode;
use Enaxon\Reqtide\Runtime\AttemptControls;
use Enaxon\Reqtide\Runtime\PreparedAttempt;
use Enaxon\Reqtide\Runtime\RuntimeAdapter;
use Enaxon\Reqtide\Runtime\RuntimeCapabilities;
use Enaxon\Reqtide\Runtime\RuntimeExecution;
use Enaxon\Reqtide\Runtime\SchedulingCapability;
use Enaxon\Reqtide\Runtime\SettlementObservation;
use Enaxon\Reqtide\Runtime\SynchronousRuntimeAdapter;
use Enaxon\Reqtide\State\CircuitAdmissionStatus;
use Enaxon\Reqtide\Telemetry\AccountingDegraded;
use Enaxon\Reqtide\Telemetry\AdmissionRejected;
use Enaxon\Reqtide\Telemetry\AttemptCompleted;
use Enaxon\Reqtide\Telemetry\BackendErrorObserved;
use Enaxon\Reqtide\Telemetry\Event;
use Enaxon\Reqtide\Telemetry\EventSink;
use Enaxon\Reqtide\Telemetry\OperationCompleted;
use Enaxon\Reqtide\Tests\Pipeline\Support\ClosureFallback;
use Enaxon\Reqtide\Tests\Pipeline\Support\DispatchSpy;
use Enaxon\Reqtide\Tests\Pipeline\Support\Harness;
use Enaxon\Reqtide\Tests\Pipeline\Support\ThrowingRuntimeAdapter;
use Enaxon\Reqtide\Value\Duration;
use PHPUnit\Framework\TestCase;

/**
 * What a degraded dependency leaves behind: one typed backend-error event per failing call site, one rejection event
 * per refused admission, and accounting that is marked incomplete exactly when something was not observed.
 */
final class OperationRunDegradationTest extends TestCase
{
    /**
     * @param ExecutionCompletion<mixed> $completion
     * @return list<string>
     */
    private static function reasons(ExecutionCompletion $completion): array
    {
        return array_map(static fn(Diagnostic $d): string => $d->reasonCode, $completion->diagnostics);
    }

    /** @return list<array{string, string}> backend kind and failure code of every observed backend error */
    private static function backendErrors(Harness $h): array
    {
        return array_map(static fn(BackendErrorObserved $e): array => [$e->backendKind, $e->failureCode], $h->sink->of(BackendErrorObserved::class));
    }

    /** @return list<array{string, string}> policy kind and reason code of every observed admission refusal */
    private static function refusals(Harness $h): array
    {
        return array_map(static fn(AdmissionRejected $e): array => [$e->policyKind, $e->reasonCode], $h->sink->of(AdmissionRejected::class));
    }

    /** Makes the monotonic clock fail only for reads made while `$method` is on the stack and no summary is being built. */
    private static function failReadsIn(Harness $h, string $method, string $mustAlsoBeOnTheStack): void
    {
        $h->clock->failWhen = static function () use ($method, $mustAlsoBeOnTheStack): bool {
            $functions = array_column(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 25), 'function');

            return in_array($method, $functions, true) && in_array($mustAlsoBeOnTheStack, $functions, true) && !in_array('complete', $functions, true);
        };
    }

    // ---- start of the operation --------------------------------------------------------------------

    public function testAClockThatCannotBeReadAtStartFailsTheOperationBeforeAnyAttempt(): void
    {
        $h = new Harness();
        $h->clock->failWhen = static function (): bool {
            $functions = array_column(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 25), 'function');

            return in_array('readClock', $functions, true) && !in_array('complete', $functions, true);
        };
        $spy = DispatchSpy::returning('ok', $h->log);

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $h->context(Duration::seconds(60)));

        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertInstanceOf(ClockFailureException::class, $completion->policyCause);
        self::assertSame(0, $spy->dispatches);
        self::assertSame([['clock', ReasonCode::ClockFailure->value]], self::backendErrors($h));
    }

    // ---- one backend-error event per failing call site -----------------------------------------------

    public function testAFailingRetryReservationIsObservedAsARetryBudgetError(): void
    {
        $h = new Harness(false, false, false, true);
        $h->budget->reserveFailure = new StateUnavailableException('down');
        $spy = DispatchSpy::throwing(new \RuntimeException('x'), $h->log);

        $completion = $h->pipeline(Harness::retry(2))->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, Harness::replayable()));

        self::assertSame(1, $spy->dispatches, 'no second dispatch without a reservation');
        self::assertInstanceOf(StateUnavailableException::class, $completion->policyCause);
        self::assertSame([['retry_budget', ReasonCode::StateUnavailable->value]], self::backendErrors($h));
    }

    public function testAnUnconfirmedRetryCommitIsObservedAndDiagnosedAndNeverReleased(): void
    {
        $h = new Harness(false, false, false, true);
        $h->budget->commitFailure = new StateUnavailableException('lost reply');
        $spy = DispatchSpy::throwing(new \RuntimeException('x'), $h->log);

        $completion = $h->pipeline(Harness::retry(2))->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, Harness::replayable()));

        self::assertSame(1, $spy->dispatches);
        self::assertSame([['retry_budget', ReasonCode::StateUnavailable->value]], self::backendErrors($h));
        self::assertContains(ReasonCode::CommitNotConfirmed->value, self::reasons($completion));
        self::assertSame(0, $h->budget->released, 'a possibly spent reservation is never refunded');
    }

    public function testAFailingBulkheadAcquireAndARefusedOneAreBothObserved(): void
    {
        $failing = new Harness(false, true);
        $failing->concurrency->acquireFailure = new StateUnavailableException('down');
        $failing->pipeline()->executeDetailed(DispatchSpy::returning('ok')->callable(), $failing->context(Duration::seconds(60)));
        self::assertSame([['concurrency', ReasonCode::StateUnavailable->value]], self::backendErrors($failing));

        $full = new Harness(false, true);
        $full->concurrency->exhausted = true;
        $completion = $full->pipeline()->executeDetailed(DispatchSpy::returning('ok')->callable(), $full->context(Duration::seconds(60)));
        self::assertSame(CompletionDisposition::Rejected, $completion->disposition);
        self::assertSame([['concurrency', ReasonCode::ConcurrencyCapacityExhausted->value]], self::refusals($full));
    }

    public function testACooldownReadFailureAndAnActiveCooldownAreBothObserved(): void
    {
        $failing = new Harness(false, false, false, false, true);
        $failing->cooldown->inspectFailure = new StateUnavailableException('down');
        $failing->pipeline()->executeDetailed(DispatchSpy::returning('ok')->callable(), $failing->context(Duration::seconds(60)));
        self::assertSame([['cooldown', ReasonCode::StateUnavailable->value]], self::backendErrors($failing));

        $active = new Harness(false, false, false, false, true);
        $active->cooldown->active = Duration::seconds(5);
        $completion = $active->pipeline()->executeDetailed(DispatchSpy::returning('ok')->callable(), $active->context(Duration::seconds(60)));
        self::assertSame(CompletionDisposition::Deferred, $completion->disposition);
        self::assertSame([['cooldown', ReasonCode::CooldownActive->value]], self::refusals($active));
    }

    public function testARateLimitFailureAndARateRefusalAreBothObserved(): void
    {
        $failing = new Harness(false, false, true);
        $failing->rate->failure = new StateUnavailableException('down');
        $failing->pipeline()->executeDetailed(DispatchSpy::returning('ok')->callable(), $failing->context(Duration::seconds(60)));
        self::assertSame([['rate_limit', ReasonCode::StateUnavailable->value]], self::backendErrors($failing));

        $refused = new Harness(false, false, true);
        $refused->rate->script = [new AdmissionDecision(AdmissionStatus::Rejected, ReasonCode::RateExhausted->value, null, Duration::seconds(1))];
        $completion = $refused->pipeline()->executeDetailed(DispatchSpy::returning('ok')->callable(), $refused->context(Duration::seconds(60)));
        self::assertSame(CompletionDisposition::Rejected, $completion->disposition);
        self::assertSame([['rate_limit', ReasonCode::RateExhausted->value]], self::refusals($refused));
    }

    public function testCircuitRecordAndAbandonFailuresAreObservedAndDiagnosed(): void
    {
        $record = new Harness(true);
        $record->circuit->recordFailure = new StateUnavailableException('down');
        $completion = $record->pipeline()->executeDetailed(DispatchSpy::returning('ok')->callable(), $record->context(Duration::seconds(60)));
        self::assertSame([['circuit', ReasonCode::StateUnavailable->value]], self::backendErrors($record));
        self::assertContains(ReasonCode::CircuitRecordFailed->value, self::reasons($completion));
        self::assertFalse($completion->summary->accountingComplete);

        $abandon = new Harness(true, true);
        $abandon->concurrency->exhausted = true;
        $abandon->circuit->abandonFailure = new StateUnavailableException('down');
        $completion = $abandon->pipeline()->executeDetailed(DispatchSpy::returning('ok')->callable(), $abandon->context(Duration::seconds(60)));
        self::assertSame([['circuit', ReasonCode::StateUnavailable->value]], self::backendErrors($abandon));
        self::assertContains(ReasonCode::CircuitAbandonFailed->value, self::reasons($completion));
    }

    public function testACooldownExtensionFailureIsObservedAndDiagnosedButKeepsTheBusinessResult(): void
    {
        $h = new Harness(false, false, false, false, true);
        $h->classifier->retryAfter = Duration::seconds(2);
        $h->cooldown->extendFailure = new StateUnavailableException('down');

        $completion = $h->pipeline()->executeDetailed(DispatchSpy::throwing(new \RuntimeException('x'))->callable(), $h->context(Duration::seconds(60)));

        self::assertSame([['cooldown', ReasonCode::StateUnavailable->value]], self::backendErrors($h));
        self::assertContains(ReasonCode::CooldownExtendFailed->value, self::reasons($completion));
        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertNull($completion->policyCause);
    }

    public function testARuntimeAdapterFailureIsObservedAndStillReportsTheNeutralAttempt(): void
    {
        $h = new Harness();
        $h->runtimeOverride = new ThrowingRuntimeAdapter(new \RuntimeException('adapter exploded'));

        $completion = $h->pipeline()->executeDetailed(DispatchSpy::returning('ok')->callable(), $h->context(Duration::seconds(60)));

        self::assertSame([['runtime', ReasonCode::StateIndeterminate->value]], self::backendErrors($h));
        self::assertContains(ReasonCode::RuntimeAdapterFailure->value, self::reasons($completion));
        $completed = $h->sink->of(AttemptCompleted::class);
        self::assertCount(1, $completed);
        self::assertNull($completed[0]->elapsed);
        self::assertSame(1, $completion->summary->dispatched);
    }

    // ---- circuit refusal details -----------------------------------------------------------------------

    public function testAnOpenCircuitCarriesItsPositiveWaitAndAFullProbePoolCarriesNone(): void
    {
        $open = new Harness(true);
        $open->circuit->status = CircuitAdmissionStatus::Open;
        $completion = $open->pipeline()->executeDetailed(DispatchSpy::returning('ok')->callable(), $open->context(Duration::seconds(60)));
        self::assertInstanceOf(AdmissionRejectedException::class, $completion->policyCause);
        self::assertSame(ReasonCode::CircuitOpen->value, $completion->policyCause->decision->reasonCode);
        self::assertSame(Duration::seconds(30)->nanoseconds, $completion->policyCause->decision->retryAfter?->nanoseconds);

        $probes = new Harness(true);
        $probes->circuit->status = CircuitAdmissionStatus::ProbeCapacityExhausted;
        $completion = $probes->pipeline()->executeDetailed(DispatchSpy::returning('ok')->callable(), $probes->context(Duration::seconds(60)));
        self::assertInstanceOf(AdmissionRejectedException::class, $completion->policyCause);
        self::assertSame(ReasonCode::CircuitProbeCapacityExhausted->value, $completion->policyCause->decision->reasonCode);
        self::assertNull($completion->policyCause->decision->retryAfter);
    }

    // ---- settlement observations that are not fully known ---------------------------------------------

    /** Wraps the synchronous adapter and rewrites the settlement observation. */
    private static function rewritingRuntime(\Closure $rewrite): RuntimeAdapter
    {
        return new class ($rewrite) implements RuntimeAdapter {
            private readonly SynchronousRuntimeAdapter $inner;

            /** @param \Closure(SettlementObservation<mixed>): SettlementObservation<mixed> $rewrite */
            public function __construct(private readonly \Closure $rewrite)
            {
                $this->inner = new SynchronousRuntimeAdapter(SchedulingCapability::BoundedBlocking);
            }

            public function capabilities(): RuntimeCapabilities
            {
                return $this->inner->capabilities();
            }

            public function invoke(PreparedAttempt $operation, AttemptContext $context, AttemptControls $controls): RuntimeExecution
            {
                $inner = $this->inner->invoke($operation, $context, $controls);
                $observation = ($this->rewrite)($inner->awaitObservedSettlement($controls));

                return new class ($observation) implements RuntimeExecution {
                    /** @param SettlementObservation<mixed> $observation */
                    public function __construct(private readonly SettlementObservation $observation) {}

                    public function awaitObservedSettlement(AttemptControls $controls): SettlementObservation
                    {
                        return $this->observation;
                    }
                };
            }
        };
    }

    public function testAnIncompleteSettlementAccountingMarksTheRunAndEmitsTheDegradationOnce(): void
    {
        $h = new Harness();
        $h->runtimeOverride = self::rewritingRuntime(static fn(SettlementObservation $o): SettlementObservation => new SettlementObservation($o->outcome, $o->logicalOverride, $o->observedMonotonicNanoseconds, $o->elapsed, false, $o->diagnostics));

        $completion = $h->pipeline()->executeDetailed(DispatchSpy::returning('ok')->callable(), $h->context(Duration::seconds(60)));

        self::assertFalse($completion->summary->accountingComplete);
        $degraded = $h->sink->of(AccountingDegraded::class);
        self::assertCount(1, $degraded);
        self::assertSame(ReasonCode::SettlementAccounting->value, $degraded[0]->reasonCode);
    }

    public function testAnAttemptWhoseSettlementTimeIsUnknownBlocksRetryAndFallback(): void
    {
        $h = new Harness(false, false, false, true);
        $h->runtimeOverride = self::rewritingRuntime(static fn(SettlementObservation $o): SettlementObservation => new SettlementObservation($o->outcome, SettlementOverride::None, null, null, false, [Diagnostic::of(Stage::Settlement, ReasonCode::ClockUnavailable)]));
        $spy = DispatchSpy::throwing(new \RuntimeException('x'), $h->log);
        $fallback = new ClosureFallback(static fn(): string => 'fallback');

        $completion = $h->pipeline(Harness::retry(3))->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), $fallback, Harness::replayable()));

        self::assertSame(1, $spy->dispatches, 'no retry when the settlement time is unknown');
        self::assertSame(0, $fallback->calls, 'no fallback either');
        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertFalse($completion->summary->accountingComplete);
    }

    // ---- clock failures at the decision points ---------------------------------------------------------

    public function testAClockFailureAtTheRetryDecisionAlsoBlocksTheFallback(): void
    {
        $h = new Harness(false, false, false, true);
        self::failReadsIn($h, 'decideRetry', 'remaining');
        $fallback = new ClosureFallback(static fn(): string => 'fallback');

        $completion = $h->pipeline(Harness::retry(3))->executeDetailed(DispatchSpy::throwing(new \RuntimeException('x'))->callable(), $h->context(Duration::seconds(60), $fallback, Harness::replayable()));

        self::assertSame(0, $fallback->calls);
        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        $degraded = $h->sink->of(AccountingDegraded::class);
        self::assertSame([ReasonCode::RetryClockUnavailable->value], array_map(static fn(AccountingDegraded $e): string => $e->reasonCode, $degraded));
    }

    public function testAClockFailureBeforeTheFallbackNamesItAsTheReasonAccountingDegraded(): void
    {
        $h = new Harness();
        self::failReadsIn($h, 'finishBusiness', 'remaining');
        $fallback = new ClosureFallback(static fn(): string => 'fallback');

        $completion = $h->pipeline()->executeDetailed(DispatchSpy::throwing(new \RuntimeException('x'))->callable(), $h->context(Duration::seconds(60), $fallback));

        self::assertSame(0, $fallback->calls);
        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertNotNull($completion->summary->elapsed, 'the summary itself could still read the clock');
        self::assertSame([ReasonCode::FallbackClockUnavailable->value], array_map(static fn(AccountingDegraded $e): string => $e->reasonCode, $h->sink->of(AccountingDegraded::class)));
    }

    public function testAClockFailureAfterTheFallbackNamesItAsTheReasonAccountingDegraded(): void
    {
        $h = new Harness();
        self::failReadsIn($h, 'runFallback', 'remaining');
        $fallback = new ClosureFallback(static fn(): string => 'fallback');

        $completion = $h->pipeline()->executeDetailed(DispatchSpy::throwing(new \RuntimeException('x'))->callable(), $h->context(Duration::seconds(60), $fallback));

        self::assertSame(CompletionDisposition::FallbackSucceeded, $completion->disposition);
        self::assertSame([ReasonCode::FallbackTimingUnavailable->value], array_map(static fn(AccountingDegraded $e): string => $e->reasonCode, $h->sink->of(AccountingDegraded::class)));
    }

    public function testAClockFailureBeforeAnAdmissionRefusalFallbackKeepsTheRefusal(): void
    {
        $h = new Harness(true);
        $h->fallbackScope = FallbackScope::IncludeAdmissionRefusals;
        $h->circuit->status = CircuitAdmissionStatus::Open;
        self::failReadsIn($h, 'finishRefusal', 'remaining');
        $fallback = new ClosureFallback(static fn(): string => 'fallback');

        $completion = $h->pipeline()->executeDetailed(DispatchSpy::returning('ok')->callable(), $h->context(Duration::seconds(60), $fallback));

        self::assertSame(0, $fallback->calls);
        self::assertSame(CompletionDisposition::Rejected, $completion->disposition);
        self::assertInstanceOf(AdmissionRejectedException::class, $completion->policyCause);
        self::assertContains(ReasonCode::ClockUnavailable->value, self::reasons($completion));
        self::assertSame([ReasonCode::FallbackClockUnavailable->value], array_map(static fn(AccountingDegraded $e): string => $e->reasonCode, $h->sink->of(AccountingDegraded::class)));
    }

    public function testACancellationRequestedDuringTheAttemptEndsTheRunBeforeTheFallback(): void
    {
        $h = new Harness();
        $fallback = new ClosureFallback(static fn(): string => 'fallback');
        $callable = static function () use ($h): never {
            $h->cancel->cancel();

            throw new \RuntimeException('x');
        };

        $completion = $h->pipeline()->executeDetailed($callable, $h->context(Duration::seconds(60), $fallback));

        self::assertSame(CompletionDisposition::Cancelled, $completion->disposition);
        self::assertInstanceOf(OperationCancelledException::class, $completion->policyCause);
        self::assertSame(0, $fallback->calls);
    }

    public function testACancellationAfterSettlementStillStopsBeforeTheBusinessFallback(): void
    {
        $h = new Harness();
        $h->classifier->hook = static function () use ($h): void {
            $h->cancel->cancel(); // arrives after the runtime sampled the token, while the outcome is classified
        };
        $fallback = new ClosureFallback(static fn(): string => 'fallback');

        $completion = $h->pipeline()->executeDetailed(DispatchSpy::throwing(new \RuntimeException('x'))->callable(), $h->context(Duration::seconds(60), $fallback));

        self::assertSame(CompletionDisposition::Cancelled, $completion->disposition);
        self::assertInstanceOf(OperationCancelledException::class, $completion->policyCause);
        self::assertSame(0, $fallback->calls);
    }

    public function testADeadlineReachedWhileClassifyingStopsBeforeTheBusinessFallback(): void
    {
        $h = new Harness();
        $h->classifier->hook = static function () use ($h): void {
            $h->clock->advance(Duration::seconds(60)->nanoseconds);
        };
        $fallback = new ClosureFallback(static fn(): string => 'fallback');

        $completion = $h->pipeline()->executeDetailed(DispatchSpy::throwing(new \RuntimeException('x'))->callable(), $h->context(Duration::seconds(60), $fallback));

        self::assertSame(CompletionDisposition::DeadlineExceeded, $completion->disposition);
        self::assertSame(0, $fallback->calls);
    }

    public function testACancellationAfterAnAdmissionRefusalStopsBeforeTheRefusalFallback(): void
    {
        $h = new Harness(true);
        $h->fallbackScope = FallbackScope::IncludeAdmissionRefusals;
        $h->circuit->status = CircuitAdmissionStatus::Open;
        $h->circuit->onCall = static function () use ($h): void {
            $h->cancel->cancel();
        };
        $fallback = new ClosureFallback(static fn(): string => 'fallback');

        $completion = $h->pipeline()->executeDetailed(DispatchSpy::returning('ok')->callable(), $h->context(Duration::seconds(60), $fallback));

        self::assertSame(CompletionDisposition::Cancelled, $completion->disposition);
        self::assertSame(0, $fallback->calls);
    }

    // ---- the summary ------------------------------------------------------------------------------------

    public function testTheCompletionEventReportsIncompleteAccountingWhenTheElapsedTimeIsUnknown(): void
    {
        $h = new Harness();
        // Only the summary read (made while complete() is on the stack) fails.
        $h->clock->failWhen = static function (): bool {
            $functions = array_column(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 25), 'function');

            return in_array('complete', $functions, true) && in_array('readClock', $functions, true);
        };

        $completion = $h->pipeline()->executeDetailed(DispatchSpy::returning('ok')->callable(), $h->context(Duration::seconds(60)));

        self::assertNull($completion->summary->elapsed);
        $events = $h->sink->of(OperationCompleted::class);
        self::assertCount(1, $events);
        self::assertFalse($events[0]->accountingComplete);
        self::assertNull($events[0]->elapsed);
    }

    public function testASinkThatFailsOnAnAttemptEventMarksAccountingIncompleteAndIsDiagnosed(): void
    {
        $h = new Harness();
        $h->sink->throwOnEvery = false;
        $failingSink = new class implements EventSink {
            public function emit(Event $event): void
            {
                if ($event instanceof AttemptCompleted) {
                    throw new \RuntimeException('sink down');
                }
            }
        };
        $environment = new \Enaxon\Reqtide\Pipeline\RuntimeEnvironment($h->clock, $h->scheduler, $h->random, $h->classifier, $h->runtime, $failingSink, null, null, null, null, null);
        $completion = $h->definition()->compile($environment)->executeDetailed(DispatchSpy::returning('ok')->callable(), $h->context(Duration::seconds(60)));

        self::assertSame(CompletionDisposition::Succeeded, $completion->disposition);
        self::assertContains(ReasonCode::EventSinkFailed->value, self::reasons($completion));
        self::assertFalse($completion->summary->accountingComplete);
    }

    // ---- retry delay arithmetic -------------------------------------------------------------------------

    public function testARetryAfterEqualToTheMaximumDelayIsHonouredAndOneNanosecondMoreIsDeferred(): void
    {
        $maximum = Duration::seconds(10);

        $h = new Harness(false, false, false, true);
        $h->classifier->retryAfter = $maximum;
        $spy = DispatchSpy::of($h->log, static fn(): never => throw new \RuntimeException('x'), static fn(): string => 'ok');
        $completion = $h->pipeline(Harness::retry(2, 100, 10_000, BackoffMode::Constant, JitterMode::None))->executeDetailed($spy->callable(), $h->context(Duration::seconds(120), null, Harness::replayable()));
        self::assertSame(CompletionDisposition::Succeeded, $completion->disposition);
        self::assertSame([$maximum->nanoseconds], $h->scheduler->delays);

        $over = new Harness(false, false, false, true);
        $over->classifier->retryAfter = new Duration($maximum->nanoseconds + 1);
        $completion = $over->pipeline(Harness::retry(2, 100, 10_000, BackoffMode::Constant, JitterMode::None))->executeDetailed(DispatchSpy::throwing(new \RuntimeException('x'))->callable(), $over->context(Duration::seconds(120), null, Harness::replayable()));
        self::assertSame(CompletionDisposition::Deferred, $completion->disposition);
        self::assertSame([['retry', ReasonCode::RetryAfterExceedsMaximumDelay->value]], self::refusals($over));
    }

    public function testJitterOnTopOfARetryAfterIsTrimmedToLeaveRoomForTheNextAttempt(): void
    {
        $h = new Harness(false, false, false, true, false, true);
        $h->classifier->retryAfter = new Duration(2_000_000_000 - 10);
        $spy = DispatchSpy::of($h->log, static fn(): never => throw new \RuntimeException('x'), static fn(): string => 'ok');

        $completion = $h->pipeline(Harness::retry(2, 100, 10_000, BackoffMode::Constant, JitterMode::Full))
            ->executeDetailed($spy->callable(), $h->context(Duration::seconds(2), null, Harness::replayable()));

        self::assertSame(CompletionDisposition::Succeeded, $completion->disposition);
        // remaining 2 s, attempt need 1 ns: capacity 2 s - 1 ns; the delay may use at most capacity - 1 ns.
        self::assertSame([2_000_000_000 - 2], $h->scheduler->delays);
    }

    public function testAZeroDelayIsNeverPaddedByTheJitterDraw(): void
    {
        $h = new Harness(false, false, false, true);
        $spy = DispatchSpy::of($h->log, static fn(): never => throw new \RuntimeException('x'), static fn(): string => 'ok');

        $h->pipeline(Harness::retry(2, 0, 0, BackoffMode::Constant, JitterMode::Full))->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, Harness::replayable()));

        self::assertSame(2, $spy->dispatches);
        self::assertSame([], $h->scheduler->delays);
        self::assertSame([], $h->random->ranges);
    }

    // ---- replay horizon diagnostics are exact ------------------------------------------------------------

    public function testAnExpiredContractIsDeniedOnceWithoutASecondHorizonDiagnostic(): void
    {
        $now = new \DateTimeImmutable('2026-10-08T00:00:00+00:00');
        $h = new Harness(false, false, false, true);
        $spy = DispatchSpy::throwing(new \RuntimeException('x'), $h->log);
        $safety = \Enaxon\Reqtide\Execution\OperationSafety::replayableRead(new \Enaxon\Reqtide\Execution\ReplaySafetyContract(
            'contract-1',
            'identity-1',
            'scope-1',
            'safe-repeat',
            $now->modify('-1 hour'),
            $now,
            Duration::seconds(3600),
            \Enaxon\Reqtide\Execution\ReplayPreparation::CallableAssertion,
            ['transient'],
        ));

        $completion = $h->pipeline(Harness::retry(2))->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, $safety));

        self::assertSame([ReasonCode::ReplayContractNotValid->value], self::reasons($completion));
        self::assertSame(1, $spy->dispatches);
    }
}
