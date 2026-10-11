<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Pipeline;

use Enaxon\Reqtide\Admission\AdmissionDecision;
use Enaxon\Reqtide\Admission\AdmissionStatus;
use Enaxon\Reqtide\Completion\CompletionDisposition;
use Enaxon\Reqtide\Completion\Diagnostic;
use Enaxon\Reqtide\Completion\ExecutionCompletion;
use Enaxon\Reqtide\Exception\AdmissionRejectedException;
use Enaxon\Reqtide\Exception\DeadlineExceededException;
use Enaxon\Reqtide\Exception\IndeterminateStateException;
use Enaxon\Reqtide\Exception\OperationCancelledException;
use Enaxon\Reqtide\Exception\PreparationFailedException;
use Enaxon\Reqtide\Exception\StateUnavailableException;
use Enaxon\Reqtide\Outcome\CircuitVerdict;
use Enaxon\Reqtide\Outcome\OutcomeKind;
use Enaxon\Reqtide\State\CircuitAdmissionStatus;
use Enaxon\Reqtide\Telemetry\AttemptCompleted;
use Enaxon\Reqtide\Telemetry\OperationCompleted;
use Enaxon\Reqtide\Tests\Pipeline\Support\ClosureFallback;
use Enaxon\Reqtide\Tests\Pipeline\Support\DispatchSpy;
use Enaxon\Reqtide\Tests\Pipeline\Support\Harness;
use Enaxon\Reqtide\Tests\Pipeline\Support\SpyOperation;
use Enaxon\Reqtide\Value\Duration;
use PHPUnit\Framework\TestCase;

/** Lifecycle ordering, ownership, accounting and finalization. */
final class ExecutionLifecycleTest extends TestCase
{
    /**
     * @param ExecutionCompletion<mixed> $completion
     * @return list<string>
     */
    private static function reasons(ExecutionCompletion $completion): array
    {
        return array_map(static fn(Diagnostic $d): string => $d->reasonCode, $completion->diagnostics);
    }

    /** @return list<int> positions of every log entry equal to $entry */
    private static function positions(Harness $h, string $entry): array
    {
        return array_keys(array_filter($h->log->entries, static fn(string $e): bool => $e === $entry));
    }

    public function testExecA01StableOperationDistinctAttemptsAndNoCapacityDuringBackoff(): void
    {
        $h = new Harness(true, true, true, true);
        $spy = DispatchSpy::of(
            $h->log,
            static fn(): never => throw new \RuntimeException('first'),
            static fn(): never => throw new \RuntimeException('second'),
            static fn(): string => 'done',
        );
        $held = [];
        $h->scheduler->during = static function () use ($h, &$held): void {
            $held[] = [$h->concurrency->active, $h->circuit->outstanding];
        };

        $completion = $h->pipeline(Harness::retry(3))->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, Harness::replayable()));

        self::assertSame(CompletionDisposition::Succeeded, $completion->disposition);
        self::assertSame(3, $spy->dispatches);
        self::assertSame(3, $completion->summary->dispatched);
        self::assertSame(2, $completion->summary->retries);
        self::assertSame(0, $completion->summary->admittedNotDispatched);

        $ids = [];
        foreach ($spy->contexts as $index => $context) {
            self::assertSame('op-1', $context->operationId->value);
            self::assertSame($index + 1, $context->dispatchedAttemptNumber);
            $ids[$context->attemptId->lowerHex] = true;
        }
        self::assertCount(3, $ids, 'every physical dispatch has a distinct attempt id');
        self::assertSame([[0, 0], [0, 0]], $held, 'no concurrency permit or circuit ownership is held during backoff');
        self::assertSame([100_000_000, 200_000_000], $h->scheduler->delays);

        self::assertSame(2, $h->budget->reserved);
        self::assertSame(2, $h->budget->committed);
        self::assertSame([$spy->contexts[1]->attemptId, $spy->contexts[2]->attemptId], $h->budget->reservedFor);
        self::assertSame(3, $h->rate->consumed);
        self::assertSame([CircuitVerdict::Failure, CircuitVerdict::Failure, CircuitVerdict::Success], $h->circuit->recorded);
        self::assertSame(3, $h->concurrency->released);

        // Standard order for the first attempt.
        $first = [
            $h->log->indexOf('circuit.acquire'),
            $h->log->indexOf('concurrency.acquire'),
            $h->log->indexOf('rate.consume'),
            $h->log->indexOf('dispatch'),
            $h->log->indexOf('concurrency.release'),
            $h->log->indexOf('circuit.record.failure'),
        ];
        $sorted = $first;
        sort($sorted);
        self::assertSame($sorted, $first);
        self::assertNotContains(-1, $first);
        // A retry reserves its budget token before any admission, and commits right before the dispatch.
        $reserve = self::positions($h, 'budget.reserve');
        $acquire = self::positions($h, 'circuit.acquire');
        $commit = self::positions($h, 'budget.commit');
        $dispatch = self::positions($h, 'dispatch');
        self::assertLessThan($acquire[1], $reserve[0]);
        self::assertLessThan($dispatch[1], $commit[0]);
        self::assertGreaterThan($h->log->indexOf('rate.consume'), $commit[0]);

        self::assertCount(3, $h->sink->of(AttemptCompleted::class));
        self::assertCount(1, $h->sink->of(OperationCompleted::class));
    }

    public function testExecA02AdmittedProbeThenRateRejectionAbandonsNeutrallyWithoutDispatch(): void
    {
        $h = new Harness(true, true, true);
        $h->rate->script = [new AdmissionDecision(AdmissionStatus::Rejected, 'rate_limited', null, Duration::seconds(1))];
        $spy = DispatchSpy::returning('v', $h->log);

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $h->context(Duration::seconds(5)));

        self::assertSame(CompletionDisposition::Rejected, $completion->disposition);
        self::assertInstanceOf(AdmissionRejectedException::class, $completion->policyCause);
        self::assertSame(0, $spy->dispatches);
        self::assertSame(1, $h->circuit->abandoned);
        self::assertSame([], $h->circuit->recorded);
        self::assertSame(0, $h->circuit->outstanding);
        self::assertSame(0, $h->concurrency->active);
        self::assertSame(0, $completion->summary->admittedNotDispatched);
    }

    public function testExecA02CapacityRejectionAbandonsTheProbeAndNeverReachesRate(): void
    {
        $h = new Harness(true, true, true);
        $h->concurrency->exhausted = true;
        $spy = DispatchSpy::returning('v', $h->log);

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $h->context(Duration::seconds(5)));

        self::assertSame(CompletionDisposition::Rejected, $completion->disposition);
        self::assertSame(0, $spy->dispatches);
        self::assertSame(1, $h->circuit->abandoned);
        self::assertFalse($h->log->has('rate.consume'));
    }

    public function testExecA02OpenCircuitStopsBeforeAnyOtherAdmission(): void
    {
        $h = new Harness(true, true, true);
        $h->circuit->status = CircuitAdmissionStatus::Open;
        $spy = DispatchSpy::returning('v', $h->log);

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $h->context(Duration::seconds(5)));

        self::assertSame(CompletionDisposition::Rejected, $completion->disposition);
        self::assertSame(0, $spy->dispatches);
        self::assertFalse($h->log->has('concurrency.acquire'));
        self::assertFalse($h->log->has('rate.consume'));
        self::assertSame(0, $completion->summary->dispatched);
    }

    public function testExecA02ReplayVerificationFailureAfterRateDebitPreventsDispatchAndKeepsDebit(): void
    {
        $h = new Harness(true, true, true);
        $operation = new SpyOperation($h->log);
        $operation->verifyFailure = new \RuntimeException('body cannot be rewound');

        $completion = $h->pipeline()->executeOperationDetailed($operation, $h->context(Duration::seconds(5)));

        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertInstanceOf(PreparationFailedException::class, $completion->policyCause);
        self::assertSame(0, $operation->invoked);
        self::assertSame(1, $h->rate->consumed, 'the debit is retained, never refunded');
        self::assertSame(1, $completion->summary->admittedNotDispatched);
        self::assertSame(1, $h->circuit->abandoned);
        self::assertSame(0, $h->concurrency->active);
    }

    public function testExecA02PreparationFailureHappensBeforeAnyAdmission(): void
    {
        $h = new Harness(true, true, true);
        $operation = new SpyOperation($h->log);
        $operation->prepareFailure = new \RuntimeException('cannot build the body');

        $completion = $h->pipeline()->executeOperationDetailed($operation, $h->context(Duration::seconds(5)));

        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertInstanceOf(PreparationFailedException::class, $completion->policyCause);
        self::assertSame(0, $operation->invoked);
        self::assertFalse($h->log->has('circuit.acquire'));
        self::assertFalse($h->log->has('rate.consume'));
        self::assertSame(0, $completion->summary->admittedNotDispatched);
    }

    public function testExecA03ReleaseAndRecordErrorsNeverReplaceASuccessfulBusinessResult(): void
    {
        $h = new Harness(true, true);
        $h->concurrency->releaseFailure = new StateUnavailableException('release');
        $h->circuit->recordFailure = new IndeterminateStateException('record');
        $spy = DispatchSpy::returning('business-value', $h->log);
        $pipeline = $h->pipeline();

        self::assertSame('business-value', $pipeline->execute($spy->callable(), $h->context(Duration::seconds(5))));

        $completion = $pipeline->executeDetailed($spy->callable(), $h->context(Duration::seconds(5)));
        self::assertSame(CompletionDisposition::Succeeded, $completion->disposition);
        self::assertNotNull($completion->terminalOutcome);
        self::assertSame('business-value', $completion->terminalOutcome->value());
        self::assertFalse($completion->summary->accountingComplete);
        self::assertContains('concurrency_release_failed', self::reasons($completion));
        self::assertContains('circuit_record_failed', self::reasons($completion));
    }

    public function testExecA03ReleaseAndRecordErrorsNeverReplaceTheOriginalBusinessException(): void
    {
        $h = new Harness(true, true);
        $h->concurrency->releaseFailure = new StateUnavailableException('release');
        $h->circuit->recordFailure = new StateUnavailableException('record');
        $business = new \DomainException('original');
        $spy = DispatchSpy::throwing($business, $h->log);
        $pipeline = $h->pipeline();

        try {
            $pipeline->execute($spy->callable(), $h->context(Duration::seconds(5)));
            self::fail('The original exception must be rethrown.');
        } catch (\DomainException $thrown) {
            self::assertSame($business, $thrown);
        }

        $completion = $pipeline->executeDetailed($spy->callable(), $h->context(Duration::seconds(5)));
        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertSame($business, $completion->terminalOutcome?->exception());
        self::assertFalse($completion->summary->accountingComplete);
    }

    public function testExecA04DeadlineReachedAfterRateDebitSendsNothingAndKeepsTheDebit(): void
    {
        $h = new Harness(true, true, true);
        $context = $h->context(Duration::seconds(5));
        // The deadline is reached exactly (equality is expiry) while the rate debit is in flight.
        $h->rate->onCall = static function (string $name) use ($h, $context): void {
            if ($name === 'rate.consume') {
                $h->clock->now = $context->deadline->monotonicNanoseconds;
            }
        };
        $spy = DispatchSpy::returning('v', $h->log);

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $context);

        self::assertSame(CompletionDisposition::DeadlineExceeded, $completion->disposition);
        self::assertInstanceOf(DeadlineExceededException::class, $completion->policyCause);
        self::assertSame(0, $spy->dispatches);
        self::assertSame(1, $h->rate->consumed);
        self::assertSame(1, $completion->summary->admittedNotDispatched);
        self::assertSame(0, $completion->summary->dispatched);
        self::assertSame(1, $h->circuit->abandoned);
        self::assertSame(0, $h->concurrency->active);
    }

    public function testExecA04CancellationAfterRateDebitSendsNothingAndKeepsTheDebit(): void
    {
        $h = new Harness(true, true, true);
        $h->rate->onCall = static function (string $name) use ($h): void {
            if ($name === 'rate.consume') {
                $h->cancel->cancel();
            }
        };
        $spy = DispatchSpy::returning('v', $h->log);

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $h->context(Duration::seconds(5)));

        self::assertSame(CompletionDisposition::Cancelled, $completion->disposition);
        self::assertInstanceOf(OperationCancelledException::class, $completion->policyCause);
        self::assertSame(0, $spy->dispatches);
        self::assertSame(1, $h->rate->consumed);
        self::assertSame(1, $completion->summary->admittedNotDispatched);
    }

    public function testExecA05SimpleAndDetailedAgreeAndEmitOneFinalSummary(): void
    {
        $success = new Harness(true, true);
        $value = DispatchSpy::returning(['a' => 1], $success->log);
        $pipeline = $success->pipeline();
        $returned = $pipeline->execute($value->callable(), $success->context(Duration::seconds(5)));
        $detailed = $pipeline->executeDetailed($value->callable(), $success->context(Duration::seconds(5)));
        self::assertSame(CompletionDisposition::Succeeded, $detailed->disposition);
        self::assertSame($returned, $detailed->terminalOutcome?->value());
        self::assertCount(2, $success->sink->of(OperationCompleted::class));
        self::assertSame(CompletionDisposition::Succeeded, $success->sink->of(OperationCompleted::class)[0]->disposition);

        $failing = new Harness(true, true);
        $exception = new \LogicException('boom');
        $throwing = DispatchSpy::throwing($exception, $failing->log);
        $pipeline = $failing->pipeline();
        try {
            $pipeline->execute($throwing->callable(), $failing->context(Duration::seconds(5)));
            self::fail('expected the original exception');
        } catch (\LogicException $thrown) {
            self::assertSame($exception, $thrown);
        }
        $detailed = $pipeline->executeDetailed($throwing->callable(), $failing->context(Duration::seconds(5)));
        self::assertSame(CompletionDisposition::Failed, $detailed->disposition);
        self::assertSame(OutcomeKind::Exception, $detailed->terminalOutcome?->kind);
        self::assertSame($exception, $detailed->terminalOutcome->exception());
        self::assertCount(2, $failing->sink->of(OperationCompleted::class), 'exactly one summary event per execution');
    }

    public function testExecA05SuccessfulNullIsAValueNotAnAbsence(): void
    {
        $h = new Harness();
        $spy = DispatchSpy::returning(null, $h->log);

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $h->context(Duration::seconds(5)));

        self::assertSame(CompletionDisposition::Succeeded, $completion->disposition);
        self::assertNotNull($completion->terminalOutcome);
        self::assertSame(OutcomeKind::Value, $completion->terminalOutcome->kind);
        self::assertNull($completion->terminalOutcome->value());
    }

    public function testExecA06IndeterminateRetryCommitAfterRateDebitDoesNotDispatchAndRetainsTheDebit(): void
    {
        $h = new Harness(true, true, true, true);
        $h->budget->commitFailure = new IndeterminateStateException('reply lost');
        $spy = DispatchSpy::throwing(new \RuntimeException('transient'), $h->log);

        $completion = $h->pipeline(Harness::retry(2))->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, Harness::replayable()));

        self::assertSame(1, $spy->dispatches, 'the candidate retry attempt is never dispatched');
        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertInstanceOf(IndeterminateStateException::class, $completion->policyCause);
        self::assertSame(2, $h->rate->consumed, 'the retry debit stays consumed');
        self::assertSame(1, $completion->summary->admittedNotDispatched);
        self::assertSame(1, $completion->summary->dispatched);
        self::assertSame(0, $h->budget->released, 'an ambiguous commit is never released or refunded');
        self::assertSame(2, $h->concurrency->released);
        self::assertSame(1, $h->circuit->abandoned);
        self::assertSame([CircuitVerdict::Failure], $h->circuit->recorded);
        self::assertTrue($completion->summary->accountingComplete);
        self::assertSame(OutcomeKind::Exception, $completion->terminalOutcome?->kind, 'the earlier settled attempt is retained');
    }

    public function testExecA06AmbiguousCleanupOnlyChangesAccountingCompleteness(): void
    {
        $h = new Harness(true, true, true, true);
        $h->budget->commitFailure = new IndeterminateStateException('reply lost');
        $h->budget->onCall = static function (string $name) use ($h): void {
            if ($name === 'budget.commit') {
                $h->concurrency->releaseFailure = new StateUnavailableException('release');
            }
        };
        $spy = DispatchSpy::throwing(new \RuntimeException('transient'), $h->log);

        $completion = $h->pipeline(Harness::retry(2))->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, Harness::replayable()));

        self::assertSame(1, $spy->dispatches);
        self::assertInstanceOf(IndeterminateStateException::class, $completion->policyCause);
        self::assertSame(1, $completion->summary->admittedNotDispatched);
        self::assertFalse($completion->summary->accountingComplete);
        self::assertSame(1, $h->circuit->abandoned, 'circuit abandonment is independent of the failed release');
    }

    public function testExecA07EveryIndependentFinalizerRunsAndOneCompletionExists(): void
    {
        $h = new Harness(true, true, false, true);
        $h->concurrency->releaseFailure = new StateUnavailableException('release');
        $h->circuit->recordFailure = new StateUnavailableException('record');
        $h->sink->throwOnCompletion = true;
        $fallbackCalls = 0;
        $fallback = new ClosureFallback(static function () use (&$fallbackCalls): string {
            ++$fallbackCalls;

            return 'fallback';
        });
        $business = new \RuntimeException('transient');
        $spy = DispatchSpy::throwing($business, $h->log);

        $completion = $h->pipeline(Harness::retry(3))->executeDetailed(
            $spy->callable(),
            $h->context(Duration::seconds(60), $fallback, Harness::replayable()),
        );

        self::assertTrue($h->log->has('concurrency.release'));
        self::assertTrue($h->log->has('circuit.record.failure'));
        self::assertSame(1, $spy->dispatches, 'uncertain ownership stops retry');
        self::assertSame(0, $fallback->calls, 'uncertain ownership stops fallback');
        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertSame($business, $completion->terminalOutcome?->exception());
        self::assertFalse($completion->summary->accountingComplete);
        self::assertContains('concurrency_release_failed', self::reasons($completion));
        self::assertContains('circuit_record_failed', self::reasons($completion));
        self::assertContains('completion_sink_failed', self::reasons($completion));
        self::assertCount(1, $h->sink->of(OperationCompleted::class), 'one completion event and no second attempt');
        self::assertSame(0, $fallbackCalls);
    }

    public function testExecA07FailedCompletionSinkProducesARebuiltSummaryWithoutMutatingTheDeliveredSnapshot(): void
    {
        $h = new Harness();
        $h->sink->throwOnCompletion = true;
        $spy = DispatchSpy::returning('v', $h->log);

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $h->context(Duration::seconds(5)));

        self::assertSame(CompletionDisposition::Succeeded, $completion->disposition);
        self::assertSame('v', $completion->terminalOutcome?->value());
        $events = $h->sink->of(OperationCompleted::class);
        self::assertCount(1, $events);
        self::assertTrue($events[0]->accountingComplete, 'the delivered snapshot never claims the failure');
        self::assertFalse($completion->summary->accountingComplete, 'the returned summary is rebuilt with incomplete accounting');
        self::assertSame(['completion_sink_failed'], self::reasons($completion));
        self::assertSame(1, $h->sink->emitAttempts - count($h->sink->of(AttemptCompleted::class)), 'no second completion event');
    }
}
