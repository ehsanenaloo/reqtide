<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Pipeline;

use Enaxon\Reqtide\Completion\CompletionDisposition;
use Enaxon\Reqtide\Completion\Diagnostic;
use Enaxon\Reqtide\Completion\ExecutionCompletion;
use Enaxon\Reqtide\Error\ReasonCode;
use Enaxon\Reqtide\Exception\CorruptStateException;
use Enaxon\Reqtide\Exception\IndeterminateStateException;
use Enaxon\Reqtide\Exception\StateUnavailableException;
use Enaxon\Reqtide\Internal\Identity\RandomIdentityGenerator;
use Enaxon\Reqtide\Policy\BackoffMode;
use Enaxon\Reqtide\Policy\JitterMode;
use Enaxon\Reqtide\State\Memory\MemoryRetryBudgetBackend;
use Enaxon\Reqtide\State\RetryReservationStatus;
use Enaxon\Reqtide\Telemetry\AdmissionRejected;
use Enaxon\Reqtide\Telemetry\BackendErrorObserved;
use Enaxon\Reqtide\Telemetry\RetryScheduled;
use Enaxon\Reqtide\Tests\Pipeline\Support\ClosureFallback;
use Enaxon\Reqtide\Tests\Pipeline\Support\DispatchSpy;
use Enaxon\Reqtide\Tests\Pipeline\Support\Harness;
use Enaxon\Reqtide\Value\Duration;
use PHPUnit\Framework\TestCase;

/**
 * Advisory retry-budget check before the retry delay: an empty budget ends the
 * retry without sleeping and keeps the business result; the authoritative reservation after the delay is
 * unchanged.
 */
final class RetryBudgetPreDelayTest extends TestCase
{
    /**
     * @param ExecutionCompletion<mixed> $completion
     * @return list<string>
     */
    private static function reasons(ExecutionCompletion $completion): array
    {
        return array_map(static fn(Diagnostic $d): string => $d->reasonCode, $completion->diagnostics);
    }

    private static function emptyBudget(Harness $h): Harness
    {
        $h->budget->inspectHeadroom = false;

        return $h;
    }

    public function testAnEmptyBudgetEndsTheRetryBeforeAnyDelayAndKeepsTheBusinessResult(): void
    {
        $h = self::emptyBudget(new Harness(false, false, false, true));
        $business = new \RuntimeException('transient');
        $spy = DispatchSpy::throwing($business, $h->log);

        $completion = $h->pipeline(Harness::retry(3))->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, Harness::replayable()));

        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertNull($completion->policyCause, 'the retry policy refused: the business result is kept');
        self::assertSame($business, $completion->terminalOutcome?->exception());
        self::assertSame(1, $spy->dispatches);
        self::assertSame([], $h->scheduler->delays, 'the scheduler is never called');
        self::assertFalse($h->log->has('delay'));
        self::assertSame(1, $h->log->count('budget.inspect'));
        self::assertSame(0, $h->budget->reserved, 'the advisory check reserves nothing');
        self::assertSame(0, $h->log->count('budget.reserve'));
        self::assertSame(0, $h->log->count('budget.commit'));
        self::assertSame(0, $h->log->count('budget.release'));
        self::assertContains(ReasonCode::RetryBudgetExhausted->value, self::reasons($completion));
        self::assertSame([], $h->sink->of(RetryScheduled::class), 'no retry was scheduled');
        $rejected = $h->sink->of(AdmissionRejected::class);
        self::assertCount(1, $rejected);
        self::assertSame('retry_budget', $rejected[0]->policyKind);
        self::assertSame(ReasonCode::RetryBudgetExhausted, $rejected[0]->reason());
        self::assertSame(ReasonCode::RetryBudgetExhausted, $completion->problem()?->code);
        self::assertTrue($completion->summary->accountingComplete);
        self::assertSame(0, $completion->summary->retries);
    }

    public function testSimpleExecutionRethrowsTheOriginalExceptionWithoutWaiting(): void
    {
        $h = self::emptyBudget(new Harness(false, false, false, true));
        $business = new \RuntimeException('transient');
        $spy = DispatchSpy::throwing($business, $h->log);

        try {
            $h->pipeline(Harness::retry(3))->execute($spy->callable(), $h->context(Duration::seconds(60), null, Harness::replayable()));
            self::fail('The business exception must be rethrown.');
        } catch (\RuntimeException $thrown) {
            self::assertSame($business, $thrown);
        }
        self::assertSame([], $h->scheduler->delays);
    }

    public function testAnEligibleFallbackStillRunsForTheKeptBusinessResult(): void
    {
        $h = self::emptyBudget(new Harness(false, false, false, true));
        $fallback = new ClosureFallback(static fn(): string => 'cached');
        $spy = DispatchSpy::throwing(new \RuntimeException('transient'), $h->log);

        $completion = $h->pipeline(Harness::retry(3))->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), $fallback, Harness::replayable()));

        self::assertSame(CompletionDisposition::FallbackSucceeded, $completion->disposition);
        self::assertSame(1, $fallback->calls);
        self::assertSame([], $h->scheduler->delays);
    }

    public function testHeadroomIsInspectedOnceBeforeTheDelayAndReservedAuthoritativelyAfterIt(): void
    {
        $h = new Harness(false, false, false, true);
        $spy = DispatchSpy::of($h->log, static fn(): never => throw new \RuntimeException('transient'), static fn(): string => 'ok');

        $completion = $h->pipeline(Harness::retry(3))->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, Harness::replayable()));

        self::assertSame(CompletionDisposition::Succeeded, $completion->disposition);
        self::assertSame(1, $h->budget->inspected);
        $dispatch = array_keys($h->log->entries, 'dispatch', true);
        self::assertCount(2, $dispatch);
        $log = $h->log;
        self::assertGreaterThan($dispatch[0], $log->indexOf('budget.inspect'));
        self::assertLessThan($log->indexOf('delay'), $log->indexOf('budget.inspect'), 'the check precedes the delay');
        self::assertLessThan($log->indexOf('budget.reserve'), $log->indexOf('delay'), 'the reservation follows the delay');
        self::assertLessThan($log->indexOf('budget.commit'), $log->indexOf('budget.reserve'));
        self::assertLessThan($dispatch[1], $log->indexOf('budget.commit'));
    }

    public function testABudgetDrainedDuringTheDelayIsStillRefusedByTheAuthoritativeReservation(): void
    {
        $h = new Harness(false, false, false, true);
        $h->scheduler->during = static function () use ($h): void {
            $h->budget->status = RetryReservationStatus::Exhausted; // another caller took the last token
        };
        $business = new \RuntimeException('transient');
        $spy = DispatchSpy::throwing($business, $h->log);

        $completion = $h->pipeline(Harness::retry(3))->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, Harness::replayable()));

        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertSame($business, $completion->terminalOutcome?->exception());
        self::assertSame(1, $spy->dispatches);
        self::assertCount(1, $h->scheduler->delays, 'the advisory pass was legal, so the delay ran');
        self::assertSame(1, $h->budget->inspected);
        self::assertSame(0, $h->budget->reserved);
        self::assertContains(ReasonCode::RetryBudgetExhausted->value, self::reasons($completion));
    }

    public function testABudgetThatRefillsDuringTheDelayIsStillReservedAuthoritatively(): void
    {
        $h = new Harness(false, false, false, true);
        $h->budgetOverride = $backend = new MemoryRetryBudgetBackend($h->clock);
        $this->drain($h, $backend);
        $spy = DispatchSpy::of($h->log, static fn(): never => throw new \RuntimeException('transient'), static fn(): string => 'ok');
        // Refill is one token per 6 s; the 7 s delay outlasts it, so the empty-now budget has headroom by then.
        $retry = Harness::retry(2, 7_000, 10_000, BackoffMode::Constant);

        $completion = $h->pipeline($retry)->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, Harness::replayable()));

        self::assertSame(CompletionDisposition::Succeeded, $completion->disposition);
        self::assertSame(2, $spy->dispatches);
        self::assertSame([7_000_000_000], $h->scheduler->delays);
    }

    public function testABudgetThatCannotRefillWithinTheDelayStopsBeforeSleeping(): void
    {
        $h = new Harness(false, false, false, true);
        $h->budgetOverride = $backend = new MemoryRetryBudgetBackend($h->clock);
        $this->drain($h, $backend);
        $business = new \RuntimeException('transient');
        $spy = DispatchSpy::throwing($business, $h->log);
        // The next token arrives after 6 s but the delay is only 5 s.
        $retry = Harness::retry(2, 5_000, 10_000, BackoffMode::Constant);

        $completion = $h->pipeline($retry)->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, Harness::replayable()));

        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertSame($business, $completion->terminalOutcome?->exception());
        self::assertSame(1, $spy->dispatches);
        self::assertSame([], $h->scheduler->delays);
        self::assertContains(ReasonCode::RetryBudgetExhausted->value, self::reasons($completion));
    }

    public function testAnEmptyBudgetWithNoRefillHintStopsEvenWhenTheDelayIsLong(): void
    {
        $h = new Harness(false, false, false, true);
        $h->budget->inspectHeadroom = false;
        $h->budget->inspectRetryAfter = null; // held reservations leave no headroom: no wait helps
        $spy = DispatchSpy::throwing(new \RuntimeException('transient'), $h->log);

        $h->pipeline(Harness::retry(2, 9_000, 10_000))->executeDetailed($spy->callable(), $h->context(Duration::seconds(600), null, Harness::replayable()));

        self::assertSame([], $h->scheduler->delays);
        self::assertSame(1, $spy->dispatches);
    }

    public function testTheCheckIsNotMadeWhenAnotherRetryRuleAlreadyEndedTheRetry(): void
    {
        // The delay cannot fit the deadline: the existing stop wins and the backend is not consulted.
        $h = self::emptyBudget(new Harness(false, false, false, true));
        $spy = DispatchSpy::throwing(new \RuntimeException('transient'), $h->log);
        $completion = $h->pipeline(Harness::retry(3, 400))->executeDetailed($spy->callable(), $h->context(Duration::milliseconds(300), null, Harness::replayable()));
        self::assertContains(ReasonCode::RetryDelayExceedsDeadline->value, self::reasons($completion));
        self::assertSame(0, $h->budget->inspected);

        // Attempts exhausted on the last attempt.
        $h = self::emptyBudget(new Harness(false, false, false, true));
        $spy = DispatchSpy::throwing(new \RuntimeException('transient'), $h->log);
        $completion = $h->pipeline(Harness::retry(1))->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, Harness::replayable()));
        self::assertSame(0, $h->budget->inspected);
        self::assertSame(1, $spy->dispatches);
        self::assertSame(CompletionDisposition::Failed, $completion->disposition);

        // A Retry-After above the maximum delay is a Deferred result, decided before the budget.
        $h = self::emptyBudget(new Harness(false, false, false, true));
        $h->classifier->retryAfter = Duration::seconds(30);
        $spy = DispatchSpy::throwing(new \RuntimeException('transient'), $h->log);
        $completion = $h->pipeline(Harness::retry(3, 100, 1_000))->executeDetailed($spy->callable(), $h->context(Duration::seconds(600), null, Harness::replayable()));
        self::assertSame(CompletionDisposition::Deferred, $completion->disposition);
        self::assertSame(0, $h->budget->inspected);
    }

    public function testASuccessfulFirstAttemptNeverTouchesTheBudget(): void
    {
        $h = self::emptyBudget(new Harness(false, false, false, true));
        $spy = DispatchSpy::returning('ok', $h->log);

        $h->pipeline(Harness::retry(3))->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, Harness::replayable()));

        self::assertSame(0, $h->budget->inspected);
    }

    public function testAZeroDelaySkipsTheAdvisoryCallBecauseTheReservationFollowsImmediately(): void
    {
        $h = new Harness(false, false, false, true, false, false); // low jitter draws zero
        $h->budget->inspectHeadroom = false; // would stop the retry if it were consulted
        $spy = DispatchSpy::of($h->log, static fn(): never => throw new \RuntimeException('transient'), static fn(): string => 'ok');

        $completion = $h->pipeline(Harness::retry(2, 100, 10_000, BackoffMode::Exponential, JitterMode::Full))
            ->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, Harness::replayable()));

        self::assertSame(CompletionDisposition::Succeeded, $completion->disposition);
        self::assertSame([], $h->scheduler->delays);
        self::assertSame(0, $h->budget->inspected);
        self::assertSame(1, $h->budget->reserved);
    }

    public function testAFailedInspectionIsAnInfrastructureTerminationLikeAFailedReservation(): void
    {
        $failures = [
            'unavailable' => [new StateUnavailableException('down'), StateUnavailableException::class, ReasonCode::StateUnavailable],
            'corrupt' => [new CorruptStateException('bad'), CorruptStateException::class, ReasonCode::StateCorrupt],
            'foreign' => [new \RuntimeException('boom'), IndeterminateStateException::class, ReasonCode::StateIndeterminate],
        ];
        foreach ($failures as $label => [$failure, $expected, $code]) {
            $h = new Harness(false, false, false, true);
            $h->budget->inspectFailure = $failure;
            $business = new \RuntimeException('transient');
            $spy = DispatchSpy::throwing($business, $h->log);

            $completion = $h->pipeline(Harness::retry(3))->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, Harness::replayable()));

            self::assertSame(CompletionDisposition::Failed, $completion->disposition, $label);
            self::assertInstanceOf($expected, $completion->policyCause, $label);
            if ($label === 'foreign') {
                self::assertSame($failure, $completion->policyCause->getPrevious());
            }
            self::assertSame(1, $spy->dispatches, $label);
            self::assertSame([], $h->scheduler->delays, $label);
            self::assertSame(0, $h->log->count('budget.reserve'), $label);
            self::assertSame($business, $completion->terminalOutcome?->exception(), $label . ': the attempt record is kept');
            $errors = $h->sink->of(BackendErrorObserved::class);
            self::assertCount(1, $errors, $label);
            self::assertSame('retry_budget', $errors[0]->backendKind, $label);
            self::assertSame($code, $errors[0]->reason(), $label);
        }
    }

    private function drain(Harness $h, MemoryRetryBudgetBackend $backend): void
    {
        $policy = $h->definition(Harness::retry(2))->retryBudget;
        self::assertNotNull($policy);
        $ids = new RandomIdentityGenerator();
        for ($i = 0; $i < $policy->capacityTokens->value; ++$i) {
            $decision = $backend->reserve($policy, $ids->attempt(), $ids->owner(), $ids->command());
            self::assertNotNull($decision->reservation);
            $backend->commit($policy, $decision->reservation, $ids->command());
        }
        self::assertFalse($backend->inspect($policy)->wouldReserve);
    }
}
