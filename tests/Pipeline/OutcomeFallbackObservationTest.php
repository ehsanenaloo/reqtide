<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Pipeline;

use Enaxon\Reqtide\Completion\ActualAttemptDisposition;
use Enaxon\Reqtide\Completion\CompletionDisposition;
use Enaxon\Reqtide\Completion\Diagnostic;
use Enaxon\Reqtide\Completion\ExecutionCompletion;
use Enaxon\Reqtide\Exception\ClassificationFailedException;
use Enaxon\Reqtide\Exception\FallbackFailedException;
use Enaxon\Reqtide\Outcome\Acceptability;
use Enaxon\Reqtide\Outcome\CircuitVerdict;
use Enaxon\Reqtide\Outcome\FallbackEligibility;
use Enaxon\Reqtide\Outcome\Outcome;
use Enaxon\Reqtide\Outcome\OutcomeClassification;
use Enaxon\Reqtide\Outcome\OutcomeKind;
use Enaxon\Reqtide\Outcome\RetryEligibility;
use Enaxon\Reqtide\State\CircuitAdmissionStatus;
use Enaxon\Reqtide\Telemetry\AttemptCompleted;
use Enaxon\Reqtide\Telemetry\OperationCompleted;
use Enaxon\Reqtide\Telemetry\RetryScheduled;
use Enaxon\Reqtide\Tests\Pipeline\Support\ClosureFallback;
use Enaxon\Reqtide\Tests\Pipeline\Support\DispatchSpy;
use Enaxon\Reqtide\Tests\Pipeline\Support\Harness;
use Enaxon\Reqtide\Tests\Pipeline\Support\SpyOperation;
use Enaxon\Reqtide\Value\Duration;
use PHPUnit\Framework\TestCase;

/** Outcome classification, fallback and observation. */
final class OutcomeFallbackObservationTest extends TestCase
{
    /**
     * @param ExecutionCompletion<mixed> $completion
     * @return list<string>
     */
    private static function reasons(ExecutionCompletion $completion): array
    {
        return array_map(static fn(Diagnostic $d): string => $d->reasonCode, $completion->diagnostics);
    }

    public function testOutA01RetryHealthAndFallbackAreClassifiedIndependently(): void
    {
        $h = new Harness(true, true, false, true);
        // Not retryable, but a dependency failure and fallback-eligible.
        $h->classifier->exceptionRetry = RetryEligibility::Ineligible;
        $fallback = new ClosureFallback(static fn(): string => 'substitute');
        $spy = DispatchSpy::throwing(new \RuntimeException('down'), $h->log);

        $completion = $h->pipeline(Harness::retry(3))->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), $fallback, Harness::replayable()));

        self::assertSame(1, $spy->dispatches, 'retry ineligibility stops the retry');
        self::assertSame([CircuitVerdict::Failure], $h->circuit->recorded, 'the same outcome still counts against health');
        self::assertSame(CompletionDisposition::FallbackSucceeded, $completion->disposition);
        self::assertSame(1, $fallback->calls);
        self::assertSame(ActualAttemptDisposition::Failed, $completion->attempts[0]->disposition);
    }

    public function testOutA01NeutralVerdictIsRecordedAsNeutralAndStillRetryable(): void
    {
        $h = new Harness(true, true, false, true);
        $h->classifier->exceptionVerdict = CircuitVerdict::Neutral;
        $spy = DispatchSpy::of($h->log, static fn(): never => throw new \RuntimeException('local overload'), static fn(): string => 'ok');

        $completion = $h->pipeline(Harness::retry(2))->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, Harness::replayable()));

        self::assertSame(CompletionDisposition::Succeeded, $completion->disposition);
        self::assertSame([CircuitVerdict::Neutral, CircuitVerdict::Success], $h->circuit->recorded);
        self::assertSame(ActualAttemptDisposition::Neutral, $completion->attempts[0]->disposition);
    }

    public function testOutA01UnacceptableValueStaysAValueAndSimpleExecutionReturnsIt(): void
    {
        $h = new Harness(true);
        $h->classifier->custom = static fn(Outcome $outcome): OutcomeClassification => new OutcomeClassification(
            RetryEligibility::Ineligible,
            CircuitVerdict::Failure,
            FallbackEligibility::Ineligible,
            Acceptability::Unacceptable,
            'service_unavailable',
            null,
        );
        $spy = DispatchSpy::returning('status-503-body', $h->log);
        $pipeline = $h->pipeline();

        $completion = $pipeline->executeDetailed($spy->callable(), $h->context(Duration::seconds(5)));
        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertSame(OutcomeKind::Value, $completion->terminalOutcome?->kind);
        self::assertSame([CircuitVerdict::Failure], $h->circuit->recorded);

        self::assertSame('status-503-body', $pipeline->execute($spy->callable(), $h->context(Duration::seconds(5))));
    }

    public function testOutA01ClassifierFailureIsTerminalAndFinalizesNeutrally(): void
    {
        $h = new Harness(true, true, false, true);
        $cause = new \UnexpectedValueException('classifier bug');
        $h->classifier->throw = $cause;
        $dependency = new \RuntimeException('dependency down');
        $fallback = new ClosureFallback(static fn(): string => 'substitute');
        $spy = DispatchSpy::throwing($dependency, $h->log);
        $pipeline = $h->pipeline(Harness::retry(3));

        $completion = $pipeline->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), $fallback, Harness::replayable()));

        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertInstanceOf(ClassificationFailedException::class, $completion->classifierCause);
        self::assertSame($cause, $completion->classifierCause->classifierCause);
        self::assertSame($dependency, $completion->terminalOutcome?->exception(), 'the unclassified actual outcome is retained');
        self::assertSame($completion->terminalOutcome, $completion->classifierCause->actualOutcome);
        self::assertNull($completion->attempts[0]->classification);
        self::assertSame(ActualAttemptDisposition::Neutral, $completion->attempts[0]->disposition);
        self::assertSame(1, $spy->dispatches, 'no retry');
        self::assertSame(0, $fallback->calls, 'no fallback');
        self::assertSame(1, $h->circuit->abandoned, 'the permit is abandoned neutrally');
        self::assertSame([], $h->circuit->recorded);
        self::assertSame(0, $h->concurrency->active);
        self::assertContains('classifier_failed', self::reasons($completion));

        try {
            $pipeline->execute($spy->callable(), $h->context(Duration::seconds(60), $fallback, Harness::replayable()));
            self::fail('A classification failure must be thrown.');
        } catch (ClassificationFailedException $thrown) {
            self::assertSame($cause, $thrown->getPrevious());
            self::assertSame($dependency, $thrown->actualOutcome->exception());
        }
    }

    public function testFallA01EligibleFallbackSucceedsAndRetainsTheOriginalOutcome(): void
    {
        $h = new Harness(true, true, false, true);
        $fallback = new ClosureFallback(static fn(): string => 'cached');
        $original = new \RuntimeException('down');
        $spy = DispatchSpy::throwing($original, $h->log);
        $pipeline = $h->pipeline(Harness::retry(2));

        $completion = $pipeline->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), $fallback, Harness::replayable()));

        self::assertSame(CompletionDisposition::FallbackSucceeded, $completion->disposition);
        self::assertSame($original, $completion->terminalOutcome?->exception());
        self::assertSame('cached', $completion->fallbackOutcome?->value());
        self::assertSame(1, $completion->summary->fallbackExecutions);
        self::assertSame(2, $spy->dispatches, 'fallback runs once after the attempts are exhausted and is never retried');
        self::assertSame(1, $fallback->calls);
        self::assertSame($completion->terminalOutcome, $fallback->received);
        self::assertSame('cached', $pipeline->execute($spy->callable(), $h->context(Duration::seconds(60), $fallback, Harness::replayable())));
    }

    public function testFallA01FallbackExceptionIsWrappedWithBothCauses(): void
    {
        $h = new Harness();
        $original = new \RuntimeException('down');
        $fallbackFailure = new \LogicException('fallback broke');
        $fallback = new ClosureFallback(static fn(): never => throw $fallbackFailure);
        $spy = DispatchSpy::throwing($original, $h->log);
        $pipeline = $h->pipeline();

        $completion = $pipeline->executeDetailed($spy->callable(), $h->context(Duration::seconds(5), $fallback));
        self::assertSame(CompletionDisposition::FallbackFailed, $completion->disposition);
        self::assertSame($fallbackFailure, $completion->fallbackOutcome?->exception());
        self::assertSame($original, $completion->terminalOutcome?->exception());
        self::assertSame(1, $spy->dispatches, 'a throwing fallback is not retried');
        self::assertSame(1, $fallback->calls);

        try {
            $pipeline->execute($spy->callable(), $h->context(Duration::seconds(5), $fallback));
            self::fail('A fallback failure wrapper must be thrown.');
        } catch (FallbackFailedException $thrown) {
            self::assertSame($fallbackFailure, $thrown->getPrevious());
            self::assertSame($fallbackFailure, $thrown->fallbackCause);
            self::assertSame($original, $thrown->originalOutcome->exception());
        }
    }

    public function testFallA01IneligibleOutcomeNeverRunsTheFallback(): void
    {
        $h = new Harness();
        $h->classifier->exceptionFallback = FallbackEligibility::Ineligible;
        $fallback = new ClosureFallback(static fn(): string => 'cached');
        $spy = DispatchSpy::throwing(new \RuntimeException('programming error'), $h->log);

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $h->context(Duration::seconds(5), $fallback));

        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertSame(0, $fallback->calls);
        self::assertNull($completion->fallbackOutcome);
    }

    public function testFallA01AdmissionRejectionNeverRunsTheFallback(): void
    {
        $h = new Harness(true);
        $h->circuit->status = CircuitAdmissionStatus::Open;
        $fallback = new ClosureFallback(static fn(): string => 'cached');
        $spy = DispatchSpy::returning('v', $h->log);

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $h->context(Duration::seconds(5), $fallback));

        self::assertSame(CompletionDisposition::Rejected, $completion->disposition);
        self::assertSame(0, $fallback->calls);
    }

    public function testFallA01SuccessfulResultNeverRunsTheFallback(): void
    {
        $h = new Harness();
        $fallback = new ClosureFallback(static fn(): string => 'cached');
        $spy = DispatchSpy::returning('fresh', $h->log);

        $result = $h->pipeline()->execute($spy->callable(), $h->context(Duration::seconds(5), $fallback));

        self::assertSame('fresh', $result);
        self::assertSame(0, $fallback->calls);
    }

    public function testReplacedOutcomeIsDiscardedOnlyWhenTheRetryDispatchesAndTheTerminalOneNever(): void
    {
        $h = new Harness(false, false, false, true);
        $operation = new SpyOperation($h->log);
        $calls = 0;
        $operation->body = static function () use (&$calls): string {
            ++$calls;
            if ($calls === 1) {
                throw new \RuntimeException('transient');
            }

            return 'final';
        };

        $completion = $h->pipeline(Harness::retry(2))->executeOperationDetailed($operation, $h->context(Duration::seconds(60), null, Harness::replayable()));

        self::assertSame(CompletionDisposition::Succeeded, $completion->disposition);
        self::assertCount(1, $operation->discarded, 'only the replaced first outcome is disposed');
        self::assertSame(OutcomeKind::Exception, $operation->discarded[0]->kind);
        self::assertSame($completion->attempts[0]->outcome, $operation->discarded[0]);
        $log = $h->log;
        self::assertGreaterThan($log->indexOf('budget.commit'), $log->indexOf('discard'));
        self::assertLessThan($log->entries === [] ? 0 : (int) array_keys($log->entries, 'dispatch', true)[1], $log->indexOf('discard'));
    }

    public function testTheTerminalOutcomeIsNeverDiscarded(): void
    {
        $h = new Harness();
        $operation = new SpyOperation($h->log);
        $operation->body = static fn(): string => throw new \RuntimeException('final failure');

        $completion = $h->pipeline()->executeOperationDetailed($operation, $h->context(Duration::seconds(5)));

        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertSame([], $operation->discarded);
    }

    public function testAFallbackDisposesTheReplacedOriginalAfterItRan(): void
    {
        $h = new Harness();
        $operation = new SpyOperation($h->log);
        $operation->body = static fn(): string => throw new \RuntimeException('failure');
        $fallback = new ClosureFallback(static fn(): string => 'cached');

        $completion = $h->pipeline()->executeOperationDetailed($operation, $h->context(Duration::seconds(5), $fallback));

        self::assertSame(CompletionDisposition::FallbackSucceeded, $completion->disposition);
        self::assertCount(1, $operation->discarded);
    }

    public function testObsA01RepeatedSinkFailuresNeverChangeTheResultAndStayBounded(): void
    {
        $h = new Harness(false, false, false, true);
        $h->sink->throwOnEvery = true;
        $last = new \RuntimeException('last');
        $spy = DispatchSpy::of(
            $h->log,
            static function (\Enaxon\Reqtide\Execution\AttemptContext $context, int $index) use ($last): never {
                throw $index === 63 ? $last : new \RuntimeException('transient');
            },
        );

        $completion = $h->pipeline(Harness::retry(64, 0, 0))->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, Harness::replayable()));

        self::assertSame(64, $spy->dispatches);
        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertSame($last, $completion->terminalOutcome?->exception(), 'the business result is unchanged');
        self::assertCount(32, $completion->diagnostics, 'diagnostics are bounded');
        self::assertGreaterThan(0, $completion->summary->omittedDiagnostics);
        self::assertFalse($completion->summary->accountingComplete);
        self::assertCount(1, $h->sink->of(OperationCompleted::class), 'one completion event, never retried');
        self::assertCount(64, $h->sink->of(AttemptCompleted::class));
        self::assertCount(63, $h->sink->of(RetryScheduled::class));
        self::assertSame(64 + 63 + 1, $h->sink->emitAttempts, 'a failing sink is never re-entered by an error hook');
    }

    public function testObsA01CompletionEventCarriesTheSummaryFacts(): void
    {
        $h = new Harness();
        $spy = DispatchSpy::returning('v', $h->log);

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $h->context(Duration::seconds(5)));

        $events = $h->sink->of(OperationCompleted::class);
        self::assertCount(1, $events);
        self::assertSame(CompletionDisposition::Succeeded, $events[0]->disposition);
        self::assertTrue($events[0]->accountingComplete);
        self::assertEquals($completion->summary->elapsed, $events[0]->elapsed);
        self::assertSame('op-1', $events[0]->operationId()->value);
        self::assertSame('v1', $events[0]->profileVersion()->value);
    }
}
