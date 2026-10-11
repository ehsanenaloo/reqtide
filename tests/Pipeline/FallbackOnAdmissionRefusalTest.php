<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Pipeline;

use Enaxon\Reqtide\Admission\AdmissionDecision;
use Enaxon\Reqtide\Admission\AdmissionStatus;
use Enaxon\Reqtide\Completion\CompletionDisposition;
use Enaxon\Reqtide\Completion\Diagnostic;
use Enaxon\Reqtide\Completion\ExecutionCompletion;
use Enaxon\Reqtide\Error\Category;
use Enaxon\Reqtide\Error\ProblemCarrier;
use Enaxon\Reqtide\Error\ReasonCode;
use Enaxon\Reqtide\Exception\AdmissionDeferredException;
use Enaxon\Reqtide\Exception\AdmissionRejectedException;
use Enaxon\Reqtide\Exception\DeadlineExceededException;
use Enaxon\Reqtide\Exception\FallbackFailedException;
use Enaxon\Reqtide\Exception\ImpossibleAdmissionException;
use Enaxon\Reqtide\Exception\IndeterminateStateException;
use Enaxon\Reqtide\Exception\OperationCancelledException;
use Enaxon\Reqtide\Exception\PreparationFailedException;
use Enaxon\Reqtide\Exception\StateUnavailableException;
use Enaxon\Reqtide\Fallback\FallbackScope;
use Enaxon\Reqtide\Outcome\Acceptability;
use Enaxon\Reqtide\Outcome\CircuitVerdict;
use Enaxon\Reqtide\Outcome\FallbackEligibility;
use Enaxon\Reqtide\Outcome\OutcomeClassification;
use Enaxon\Reqtide\Outcome\OutcomeKind;
use Enaxon\Reqtide\Outcome\RetryEligibility;
use Enaxon\Reqtide\State\CircuitAdmissionStatus;
use Enaxon\Reqtide\Telemetry\OperationCompleted;
use Enaxon\Reqtide\Tests\Pipeline\Support\ClosureFallback;
use Enaxon\Reqtide\Tests\Pipeline\Support\DispatchSpy;
use Enaxon\Reqtide\Tests\Pipeline\Support\Harness;
use Enaxon\Reqtide\Tests\Pipeline\Support\SpyOperation;
use Enaxon\Reqtide\Value\Duration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Opt-in fallback on admission refusal: FallbackScope::IncludeAdmissionRefusals.
 */
final class FallbackOnAdmissionRefusalTest extends TestCase
{
    /**
     * @param ExecutionCompletion<mixed> $completion
     * @return list<string>
     */
    private static function reasons(ExecutionCompletion $completion): array
    {
        return array_map(static fn(Diagnostic $d): string => $d->reasonCode, $completion->diagnostics);
    }

    private static function optIn(Harness $h): Harness
    {
        $h->fallbackScope = FallbackScope::IncludeAdmissionRefusals;

        return $h;
    }

    /** A fallback that records its position in the shared call log. */
    private static function loggedFallback(Harness $h, mixed $value = 'substitute'): ClosureFallback
    {
        return new ClosureFallback(static function () use ($h, $value): mixed {
            $h->log->add('fallback');

            return $value;
        });
    }

    /** @return array<string, array{string, \Closure(Harness): void, class-string, string}> */
    public static function refusals(): array
    {
        return [
            'circuit open' => ['circuit', static function (Harness $h): void {
                $h->circuit->status = CircuitAdmissionStatus::Open;
            }, AdmissionRejectedException::class, 'circuit_open'],
            'circuit probe capacity' => ['circuit', static function (Harness $h): void {
                $h->circuit->status = CircuitAdmissionStatus::ProbeCapacityExhausted;
            }, AdmissionRejectedException::class, 'circuit_probe_capacity_exhausted'],
            'concurrency exhausted' => ['concurrency', static function (Harness $h): void {
                $h->concurrency->exhausted = true;
            }, AdmissionRejectedException::class, 'concurrency_capacity_exhausted'],
            'rate rejected' => ['rate', static function (Harness $h): void {
                $h->rate->script = [new AdmissionDecision(AdmissionStatus::Rejected, 'rate.exhausted', null, Duration::seconds(2))];
            }, AdmissionRejectedException::class, 'rate.exhausted'],
            'rate deferred' => ['rate', static function (Harness $h): void {
                $h->rate->script = [new AdmissionDecision(AdmissionStatus::Deferred, 'rate.exhausted', null, Duration::seconds(2))];
            }, AdmissionDeferredException::class, 'rate.exhausted'],
            'rate impossible' => ['rate', static function (Harness $h): void {
                $h->rate->script = [new AdmissionDecision(AdmissionStatus::Impossible, 'rate.cost_exceeds_capacity', null, null)];
            }, ImpossibleAdmissionException::class, 'rate.cost_exceeds_capacity'],
            'cooldown deferred' => ['cooldown', static function (Harness $h): void {
                $h->cooldown->active = Duration::seconds(5);
            }, AdmissionDeferredException::class, 'cooldown_active'],
        ];
    }

    private static function harnessFor(string $kind): Harness
    {
        return match ($kind) {
            'circuit' => new Harness(true, false, false, true),
            'concurrency' => new Harness(false, true, false, true),
            'rate' => new Harness(false, false, true, true),
            default => new Harness(false, false, false, true, true),
        };
    }

    /**
     * @param \Closure(Harness): void $arm
     * @param class-string $exception
     */
    #[DataProvider('refusals')]
    public function testEveryAdmissionRefusalOfTheFirstAttemptRunsTheFallbackOnce(string $kind, \Closure $arm, string $exception, string $code): void
    {
        $h = self::optIn(self::harnessFor($kind));
        $arm($h);
        $fallback = self::loggedFallback($h);
        $spy = DispatchSpy::returning('never', $h->log);

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), $fallback));

        self::assertSame(CompletionDisposition::FallbackSucceeded, $completion->disposition);
        self::assertSame(0, $spy->dispatches);
        self::assertSame(1, $fallback->calls);
        self::assertSame('substitute', $completion->fallbackOutcome?->value());
        self::assertNull($completion->terminalOutcome, 'nothing settled');
        self::assertInstanceOf($exception, $completion->policyCause, 'the refusal stays visible');
        self::assertInstanceOf(ProblemCarrier::class, $completion->policyCause);
        self::assertSame($code, $completion->policyCause->reason()->value);
        self::assertSame([], $completion->attempts);
        self::assertNull($completion->problem(), 'the caller received a value');
        self::assertSame(1, $completion->summary->fallbackExecutions);
        self::assertSame(0, $completion->summary->dispatched);
        self::assertTrue($completion->summary->accountingComplete);

        $received = $fallback->received;
        self::assertNotNull($received);
        self::assertSame(OutcomeKind::Exception, $received->kind, 'the fallback is told about the refusal');
        self::assertSame($completion->policyCause, $received->exception());
    }

    /**
     * @param \Closure(Harness): void $arm
     * @param class-string $exception
     */
    #[DataProvider('refusals')]
    public function testSimpleExecutionReturnsTheFallbackValueForAnyRefusal(string $kind, \Closure $arm, string $exception, string $code): void
    {
        $h = self::optIn(self::harnessFor($kind));
        $arm($h);
        $spy = DispatchSpy::returning('never', $h->log);

        $value = $h->pipeline()->execute($spy->callable(), $h->context(Duration::seconds(60), self::loggedFallback($h, 'degraded')));

        self::assertSame('degraded', $value);
        self::assertSame(0, $spy->dispatches);
    }

    /**
     * @param \Closure(Harness): void $arm
     * @param class-string $exception
     */
    #[DataProvider('refusals')]
    public function testARefusalOfALaterAttemptRunsTheFallbackExactlyOnceAfterTheRetryLoop(string $kind, \Closure $arm, string $exception, string $code): void
    {
        $h = self::optIn(self::harnessFor($kind));
        // The refusal appears only once the retry has been authorised (at the retry-budget reservation).
        $h->budget->onCall = static function (string $name) use ($h, $arm): void {
            if ($name === 'budget.reserve') {
                $arm($h);
            }
        };
        $business = new \RuntimeException('transient');
        $spy = DispatchSpy::throwing($business, $h->log);
        $fallback = self::loggedFallback($h);

        $completion = $h->pipeline(Harness::retry(3))->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), $fallback, Harness::replayable()));

        self::assertSame(CompletionDisposition::FallbackSucceeded, $completion->disposition, $kind);
        self::assertInstanceOf($exception, $completion->policyCause, $kind);
        self::assertSame($business, $completion->policyCause->getPrevious(), 'the last business exception is chained');
        self::assertSame($business, $completion->terminalOutcome?->exception(), 'the earlier attempt stays recorded');
        self::assertSame(1, $spy->dispatches, $kind);
        self::assertSame(1, $fallback->calls, 'once, never between attempts');
        self::assertSame(1, $h->log->count('fallback'));
        // Strictly after the refusal, after the single delay, and nothing is dispatched afterwards.
        $log = $h->log;
        self::assertGreaterThan($log->indexOf('delay'), $log->indexOf('fallback'));
        self::assertGreaterThan($log->indexOf('budget.reserve'), $log->indexOf('fallback'));
        $dispatches = array_keys($log->entries, 'dispatch', true);
        self::assertCount(1, $dispatches);
        self::assertGreaterThan($dispatches[0], $log->indexOf('fallback'));
        self::assertSame($completion->policyCause, $fallback->received?->exception(), 'the fallback received the refusal, not the earlier failure');
        self::assertSame(1, $completion->summary->dispatched);
        self::assertSame(1, $completion->summary->fallbackExecutions);
        self::assertSame(0, $log->count('budget.commit'), 'no retry was authorised');
    }

    public function testTheFallbackFailingAfterARefusalIsFallbackFailedWithTheRefusalRetained(): void
    {
        $h = self::optIn(new Harness(true));
        $h->circuit->status = CircuitAdmissionStatus::Open;
        $boom = new \OverflowException('fallback broke');
        $fallback = new ClosureFallback(static fn(): never => throw $boom);
        $spy = DispatchSpy::returning('never', $h->log);

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), $fallback));

        self::assertSame(CompletionDisposition::FallbackFailed, $completion->disposition);
        self::assertInstanceOf(AdmissionRejectedException::class, $completion->policyCause);
        self::assertSame($boom, $completion->fallbackOutcome?->exception());
        self::assertNull($completion->terminalOutcome);
        $problem = $completion->problem();
        self::assertNotNull($problem);
        self::assertSame(ReasonCode::FallbackFailed, $problem->code);
        self::assertSame(Category::Fallback, $problem->category());
        self::assertSame(['OverflowException', 'AdmissionRejectedException'], array_map(static fn($cause): string => $cause->class, $problem->causes));
        self::assertSame(ReasonCode::CircuitOpen, $problem->causes[1]->code);
    }

    public function testSimpleExecutionThrowsFallbackFailedWithTheRefusalAsTheOriginalOutcome(): void
    {
        $h = self::optIn(new Harness(true, false, false, true));
        $h->budget->onCall = static function (string $name) use ($h): void {
            if ($name === 'budget.reserve') {
                $h->circuit->status = CircuitAdmissionStatus::Open;
            }
        };
        $boom = new \OverflowException('fallback broke');
        $fallback = new ClosureFallback(static fn(): never => throw $boom);
        $spy = DispatchSpy::throwing(new \RuntimeException('transient'), $h->log);

        try {
            $h->pipeline(Harness::retry(3))->execute($spy->callable(), $h->context(Duration::seconds(60), $fallback, Harness::replayable()));
            self::fail('A failed fallback must throw FallbackFailedException.');
        } catch (FallbackFailedException $thrown) {
            self::assertSame($boom, $thrown->getPrevious());
            self::assertSame($boom, $thrown->fallbackCause);
            self::assertSame(OutcomeKind::Exception, $thrown->originalOutcome->kind);
            self::assertInstanceOf(AdmissionRejectedException::class, $thrown->originalOutcome->exception(), 'the refusal, not the earlier business failure');
        }
    }

    public function testTheFallbackReplacedAttemptOutcomeIsDisposedAfterTheFallbackRan(): void
    {
        $h = self::optIn(new Harness(true, false, false, true));
        $h->budget->onCall = static function (string $name) use ($h): void {
            if ($name === 'budget.reserve') {
                $h->circuit->status = CircuitAdmissionStatus::Open;
            }
        };
        $operation = new SpyOperation($h->log);
        $operation->body = static fn(): string => throw new \RuntimeException('transient');
        $fallback = self::loggedFallback($h);

        $completion = $h->pipeline(Harness::retry(3))->executeOperationDetailed($operation, $h->context(Duration::seconds(60), $fallback, Harness::replayable()));

        self::assertSame(CompletionDisposition::FallbackSucceeded, $completion->disposition);
        self::assertCount(1, $operation->discarded);
        self::assertSame($completion->attempts[0]->outcome, $operation->discarded[0]);
        self::assertGreaterThan($h->log->indexOf('fallback'), $h->log->indexOf('discard'));
    }

    // ---- conditions under which the fallback must NOT run

    public function testDefaultScopeKeepsEveryRefusalAndNeverRunsTheFallback(): void
    {
        self::assertSame(FallbackScope::BusinessOutcomesOnly, (new Harness())->fallbackScope);

        foreach (self::refusals() as $label => [$kind, $arm, $exception]) {
            $h = self::harnessFor($kind);
            $arm($h);
            $fallback = self::loggedFallback($h);
            $spy = DispatchSpy::returning('never', $h->log);

            $completion = $h->pipeline()->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), $fallback));

            self::assertContains($completion->disposition, [CompletionDisposition::Rejected, CompletionDisposition::Deferred], $label);
            self::assertInstanceOf($exception, $completion->policyCause, $label);
            self::assertSame(0, $fallback->calls, $label);
            self::assertSame(0, $completion->summary->fallbackExecutions, $label);
            self::assertNull($completion->fallbackOutcome, $label);
        }
    }

    public function testDefaultScopeKeepsTheLaterAttemptRefusalAndItsPreviousExactlyAsBefore(): void
    {
        $h = new Harness(true, false, false, true);
        $h->budget->onCall = static function (string $name) use ($h): void {
            if ($name === 'budget.reserve') {
                $h->circuit->status = CircuitAdmissionStatus::Open;
            }
        };
        $business = new \RuntimeException('transient');
        $fallback = self::loggedFallback($h);
        $spy = DispatchSpy::throwing($business, $h->log);

        $completion = $h->pipeline(Harness::retry(3))->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), $fallback, Harness::replayable()));

        self::assertSame(CompletionDisposition::Rejected, $completion->disposition);
        self::assertSame($business, $completion->policyCause?->getPrevious());
        self::assertSame(0, $fallback->calls);
        self::assertNull($completion->fallbackOutcome);
    }

    public function testNoFallbackInTheContextMeansTheRefusalIsReturnedEvenWhenOptedIn(): void
    {
        $h = self::optIn(new Harness(true));
        $h->circuit->status = CircuitAdmissionStatus::Open;
        $spy = DispatchSpy::returning('never', $h->log);

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $h->context(Duration::seconds(60)));

        self::assertSame(CompletionDisposition::Rejected, $completion->disposition);
        self::assertSame(0, $completion->summary->fallbackExecutions);
    }

    public function testCancellationNeverRunsTheFallback(): void
    {
        $h = self::optIn(new Harness(true));
        $h->circuit->status = CircuitAdmissionStatus::Open;
        $fallback = self::loggedFallback($h);
        $h->cancel->cancel();
        $spy = DispatchSpy::returning('never', $h->log);

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), $fallback));

        self::assertSame(CompletionDisposition::Cancelled, $completion->disposition);
        self::assertInstanceOf(OperationCancelledException::class, $completion->policyCause);
        self::assertSame(0, $fallback->calls);
        self::assertSame(0, $h->log->count('circuit.acquire'), 'cancellation was seen before admission');
    }

    public function testADeadlineAlreadyReachedNeverRunsTheFallback(): void
    {
        $h = self::optIn(new Harness(true));
        $h->circuit->status = CircuitAdmissionStatus::Open;
        $fallback = self::loggedFallback($h);
        $context = $h->context(Duration::milliseconds(10), $fallback);
        $h->clock->advance(Duration::milliseconds(10)->nanoseconds);
        $spy = DispatchSpy::returning('never', $h->log);

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $context);

        self::assertSame(CompletionDisposition::DeadlineExceeded, $completion->disposition);
        self::assertInstanceOf(DeadlineExceededException::class, $completion->policyCause);
        self::assertSame(0, $fallback->calls);
    }

    public function testADeadlineReachedWhileTheRefusalWasBeingDecidedNeverRunsTheFallback(): void
    {
        $h = self::optIn(new Harness(true));
        $h->circuit->status = CircuitAdmissionStatus::Open;
        $h->circuit->onCall = static function () use ($h): void {
            $h->clock->advance(Duration::seconds(10)->nanoseconds);
        };
        $fallback = self::loggedFallback($h);
        $spy = DispatchSpy::returning('never', $h->log);

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $h->context(Duration::seconds(5), $fallback));

        self::assertSame(CompletionDisposition::DeadlineExceeded, $completion->disposition);
        self::assertSame(0, $fallback->calls);
        self::assertNull($completion->fallbackOutcome);
    }

    public function testCancellationBetweenTheRefusalAndTheFallbackNeverRunsTheFallback(): void
    {
        $h = self::optIn(new Harness(true));
        $h->circuit->status = CircuitAdmissionStatus::Open;
        $h->circuit->onCall = static function () use ($h): void {
            $h->cancel->cancel();
        };
        $fallback = self::loggedFallback($h);

        $completion = $h->pipeline()->executeDetailed(DispatchSpy::returning('never', $h->log)->callable(), $h->context(Duration::seconds(5), $fallback));

        self::assertSame(CompletionDisposition::Cancelled, $completion->disposition);
        self::assertSame(0, $fallback->calls);
    }

    public function testInfrastructureAndIndeterminateTerminationsNeverRunTheFallback(): void
    {
        $cases = [
            'circuit unavailable' => [static function (Harness $h): void {
                $h->circuit->acquireFailure = new StateUnavailableException('down');
            }, StateUnavailableException::class],
            'circuit foreign throwable' => [static function (Harness $h): void {
                $h->circuit->acquireFailure = new \RuntimeException('boom');
            }, IndeterminateStateException::class],
            'rate indeterminate' => [static function (Harness $h): void {
                $h->rate->failure = new IndeterminateStateException('lost reply');
            }, IndeterminateStateException::class],
        ];
        foreach ($cases as $label => [$arm, $expected]) {
            $h = self::optIn(new Harness(true, false, true));
            $arm($h);
            $fallback = self::loggedFallback($h);
            $spy = DispatchSpy::returning('never', $h->log);

            $completion = $h->pipeline()->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), $fallback));

            self::assertSame(CompletionDisposition::Failed, $completion->disposition, $label);
            self::assertInstanceOf($expected, $completion->policyCause, $label);
            self::assertSame(0, $fallback->calls, $label);
            self::assertNull($completion->fallbackOutcome, $label);
        }
    }

    public function testAnInfrastructureFailureOfALaterAttemptNeverRunsTheFallbackEither(): void
    {
        $h = self::optIn(new Harness(true, false, false, true));
        $h->budget->onCall = static function (string $name) use ($h): void {
            if ($name === 'budget.reserve') {
                $h->circuit->acquireFailure = new StateUnavailableException('down');
            }
        };
        $fallback = self::loggedFallback($h);
        $spy = DispatchSpy::throwing(new \RuntimeException('transient'), $h->log);

        $completion = $h->pipeline(Harness::retry(3))->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), $fallback, Harness::replayable()));

        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertInstanceOf(StateUnavailableException::class, $completion->policyCause);
        self::assertSame(0, $fallback->calls);
    }

    public function testPreparationAndConfigurationFailuresNeverRunTheFallback(): void
    {
        $h = self::optIn(new Harness(true));
        $h->circuit->status = CircuitAdmissionStatus::Open;
        $operation = new SpyOperation($h->log);
        $operation->prepareFailure = new \LogicException('cannot build the request');
        $fallback = self::loggedFallback($h);

        $completion = $h->pipeline()->executeOperationDetailed($operation, $h->context(Duration::seconds(60), $fallback));

        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertInstanceOf(PreparationFailedException::class, $completion->policyCause);
        self::assertSame(0, $fallback->calls);
        self::assertSame(0, $h->log->count('circuit.acquire'), 'preparation precedes admission');
    }

    public function testAClassifierFailureNeverRunsTheFallback(): void
    {
        $h = self::optIn(new Harness(true));
        $h->classifier->throw = new \RuntimeException('classifier bug');
        $fallback = self::loggedFallback($h);
        $spy = DispatchSpy::returning('ok', $h->log);

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), $fallback));

        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertNotNull($completion->classifierCause);
        self::assertSame(0, $fallback->calls);
    }

    public function testARetryPolicyStopIsNotAnAdmissionRefusalAndKeepsTheBusinessFallbackRules(): void
    {
        // Retry-After above the maximum delay is a Deferred decision of the RETRY policy, not of a protection:
        // the fallback does not run for it, with or without the option.
        $h = self::optIn(new Harness(false, false, false, true, true));
        $h->classifier->retryAfter = Duration::seconds(30);
        $fallback = self::loggedFallback($h);
        $spy = DispatchSpy::throwing(new \RuntimeException('transient'), $h->log);

        $completion = $h->pipeline(Harness::retry(3, 100, 1_000))->executeDetailed($spy->callable(), $h->context(Duration::seconds(600), $fallback, Harness::replayable()));

        self::assertSame(CompletionDisposition::Deferred, $completion->disposition);
        self::assertInstanceOf(AdmissionDeferredException::class, $completion->policyCause);
        self::assertSame(ReasonCode::RetryAfterExceedsMaximumDelay, $completion->policyCause->reason());
        self::assertSame(0, $fallback->calls);
    }

    public function testBusinessFallbackKeepsItsClassifierEligibilityAndStillRunsWithTheOptionOn(): void
    {
        $h = self::optIn(new Harness());
        $fallback = self::loggedFallback($h);
        $spy = DispatchSpy::throwing(new \RuntimeException('down'), $h->log);

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), $fallback));

        self::assertSame(CompletionDisposition::FallbackSucceeded, $completion->disposition);
        self::assertNull($completion->policyCause, 'a business fallback carries no policy cause');
        self::assertNotNull($completion->terminalOutcome);

        $h = self::optIn(new Harness());
        $h->classifier->exceptionFallback = FallbackEligibility::Ineligible;
        $fallback = self::loggedFallback($h);
        $completion = $h->pipeline()->executeDetailed(DispatchSpy::throwing(new \RuntimeException('down'), $h->log)->callable(), $h->context(Duration::seconds(60), $fallback));
        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertSame(0, $fallback->calls);
    }

    public function testUncertainOwnershipAfterARefusalBlocksTheFallback(): void
    {
        // The concurrency refusal makes the pipeline abandon the circuit permit it already holds; if that
        // abandon fails, ownership is uncertain and nothing further (including a fallback) may run.
        $h = self::optIn(new Harness(true, true));
        $h->concurrency->exhausted = true;
        $h->circuit->abandonFailure = new StateUnavailableException('down');
        $fallback = self::loggedFallback($h);
        $spy = DispatchSpy::returning('never', $h->log);

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), $fallback));

        self::assertSame(CompletionDisposition::Rejected, $completion->disposition);
        self::assertSame(0, $fallback->calls);
        self::assertFalse($completion->summary->accountingComplete);
        self::assertContains(ReasonCode::CircuitAbandonFailed->value, self::reasons($completion));
    }

    // ---- deadline shared with the fallback, events, explanation

    public function testAFallbackSettlingAtTheDeadlineIsDeadlineExceededWithItsOutcomeRetained(): void
    {
        $h = self::optIn(new Harness(true));
        $h->circuit->status = CircuitAdmissionStatus::Open;
        $fallback = new ClosureFallback(static function () use ($h): string {
            $h->clock->advance(Duration::seconds(5)->nanoseconds);

            return 'late';
        });

        $completion = $h->pipeline()->executeDetailed(DispatchSpy::returning('never', $h->log)->callable(), $h->context(Duration::seconds(5), $fallback));

        self::assertSame(CompletionDisposition::DeadlineExceeded, $completion->disposition);
        self::assertInstanceOf(DeadlineExceededException::class, $completion->policyCause);
        self::assertSame('late', $completion->fallbackOutcome?->value());
        self::assertSame(1, $completion->summary->fallbackExecutions);
    }

    public function testSimpleExecutionThrowsTheDeadlineExceptionForALateFallback(): void
    {
        $h = self::optIn(new Harness(true));
        $h->circuit->status = CircuitAdmissionStatus::Open;
        $late = new ClosureFallback(static function () use ($h): string {
            $h->clock->advance(Duration::seconds(5)->nanoseconds);

            return 'late';
        });

        $this->expectException(DeadlineExceededException::class);
        $h->pipeline()->execute(DispatchSpy::returning('never', $h->log)->callable(), $h->context(Duration::seconds(5), $late));
    }

    public function testAFallbackThatFitsTheSharedDeadlineIsOnTime(): void
    {
        $h = self::optIn(new Harness(true));
        $h->circuit->status = CircuitAdmissionStatus::Open;
        $fallback = new ClosureFallback(static function () use ($h): string {
            $h->clock->advance(Duration::seconds(4)->nanoseconds);

            return 'in time';
        });

        $completion = $h->pipeline()->executeDetailed(DispatchSpy::returning('never', $h->log)->callable(), $h->context(Duration::seconds(5), $fallback));

        self::assertSame(CompletionDisposition::FallbackSucceeded, $completion->disposition);
        self::assertSame('in time', $completion->fallbackOutcome?->value());
    }

    public function testTheCompletionEventCarriesTheFallbackDisposition(): void
    {
        $h = self::optIn(new Harness(true));
        $h->circuit->status = CircuitAdmissionStatus::Open;

        $h->pipeline()->executeDetailed(DispatchSpy::returning('never', $h->log)->callable(), $h->context(Duration::seconds(5), self::loggedFallback($h)));

        $completed = $h->sink->of(OperationCompleted::class);
        self::assertCount(1, $completed);
        self::assertSame(CompletionDisposition::FallbackSucceeded, $completed[0]->disposition);
    }

    public function testExplainReportsOnlyANonDefaultScope(): void
    {
        $default = (new Harness())->pipeline()->explain();
        $optIn = self::optIn(new Harness())->pipeline()->explain();

        foreach ($default->capabilityNotes as $note) {
            self::assertStringStartsNotWith('fallback_scope=', $note);
        }
        self::assertContains('fallback_scope=include_admission_refusals', $optIn->capabilityNotes);
        self::assertSame($default->enabledPolicies, $optIn->enabledPolicies);
    }

    public function testAnAcceptableValueNeverTouchesTheFallbackWithTheOptionOn(): void
    {
        $h = self::optIn(new Harness(true));
        $fallback = self::loggedFallback($h);

        $completion = $h->pipeline()->executeDetailed(DispatchSpy::returning('ok', $h->log)->callable(), $h->context(Duration::seconds(5), $fallback));

        self::assertSame(CompletionDisposition::Succeeded, $completion->disposition);
        self::assertSame(0, $fallback->calls);
    }

    public function testAnUnacceptableValueWithRetryEligibleClassificationThenRefusedUsesTheRefusalOutcome(): void
    {
        $h = self::optIn(new Harness(true, false, false, true));
        $h->classifier->custom = static fn($outcome) => $outcome->kind === OutcomeKind::Value
            ? new OutcomeClassification(RetryEligibility::Eligible, CircuitVerdict::Failure, FallbackEligibility::Ineligible, Acceptability::Unacceptable, 'transient', null)
            : null;
        $h->budget->onCall = static function (string $name) use ($h): void {
            if ($name === 'budget.reserve') {
                $h->circuit->status = CircuitAdmissionStatus::Open;
            }
        };
        $fallback = self::loggedFallback($h);

        $completion = $h->pipeline(Harness::retry(3))->executeDetailed(DispatchSpy::returning('bad', $h->log)->callable(), $h->context(Duration::seconds(60), $fallback, Harness::replayable()));

        // The opt-in is the definition's explicit statement; the classifier's verdict covers business outcomes.
        self::assertSame(CompletionDisposition::FallbackSucceeded, $completion->disposition);
        self::assertSame('bad', $completion->terminalOutcome?->value());
        self::assertSame(OutcomeKind::Exception, $fallback->received?->kind);
    }
}
