<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Pipeline;

use Enaxon\Reqtide\Admission\AdmissionDecision;
use Enaxon\Reqtide\Admission\AdmissionStatus;
use Enaxon\Reqtide\Completion\CompletionDisposition;
use Enaxon\Reqtide\Completion\Diagnostic;
use Enaxon\Reqtide\Completion\ExecutionCompletion;
use Enaxon\Reqtide\Completion\ExecutionSummary;
use Enaxon\Reqtide\Exception\AdmissionDeferredException;
use Enaxon\Reqtide\Exception\AdmissionRejectedException;
use Enaxon\Reqtide\Exception\CapabilityMismatchException;
use Enaxon\Reqtide\Exception\ClockFailureException;
use Enaxon\Reqtide\Exception\IndeterminateStateException;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Exception\OperationCancelledException;
use Enaxon\Reqtide\Exception\PreparationFailedException;
use Enaxon\Reqtide\Exception\StateUnavailableException;
use Enaxon\Reqtide\Execution\OperationSafety;
use Enaxon\Reqtide\Execution\ReplayPreparation;
use Enaxon\Reqtide\Execution\ReplaySafetyContract;
use Enaxon\Reqtide\Outcome\CircuitVerdict;
use Enaxon\Reqtide\Outcome\OutcomeKind;
use Enaxon\Reqtide\Pipeline\PipelineDefinition;
use Enaxon\Reqtide\Policy\BackoffMode;
use Enaxon\Reqtide\Policy\CircuitPolicy;
use Enaxon\Reqtide\Policy\ConcurrencyPolicy;
use Enaxon\Reqtide\Policy\CountCircuitWindow;
use Enaxon\Reqtide\Policy\JitterMode;
use Enaxon\Reqtide\Policy\RetryBudgetPolicy;
use Enaxon\Reqtide\State\CircuitAdmissionStatus;
use Enaxon\Reqtide\State\FinalizationStatus;
use Enaxon\Reqtide\State\RetryBudgetFinalizationStatus;
use Enaxon\Reqtide\State\RetryReservationStatus;
use Enaxon\Reqtide\State\StateScope;
use Enaxon\Reqtide\Telemetry\AccountingDegraded;
use Enaxon\Reqtide\Telemetry\AttemptCompleted;
use Enaxon\Reqtide\Tests\Pipeline\Support\ClosureFallback;
use Enaxon\Reqtide\Tests\Pipeline\Support\DispatchSpy;
use Enaxon\Reqtide\Tests\Pipeline\Support\Harness;
use Enaxon\Reqtide\Tests\Pipeline\Support\ThrowingRuntimeAdapter;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\OperationId;
use Enaxon\Reqtide\Value\PositiveInt;
use Enaxon\Reqtide\Value\ProfileVersion;
use Enaxon\Reqtide\Value\Ratio;
use PHPUnit\Framework\TestCase;

/**
 * Edge cases of the execution pipeline: stale ownership and lease margins, foreign throwables from backends, strict
 * delay fit, late Retry-After signals, who refused, cooldown, dispatch counting, fallback timing, finite backend I/O
 * and checked replay-horizon arithmetic.
 */
final class ExecutionEdgeCasesTest extends TestCase
{
    /**
     * @param ExecutionCompletion<mixed> $completion
     * @return list<string>
     */
    private static function reasons(ExecutionCompletion $completion): array
    {
        return array_map(static fn(Diagnostic $d): string => $d->reasonCode, $completion->diagnostics);
    }

    private static function failing(string $message = 'transient'): \RuntimeException
    {
        return new \RuntimeException($message);
    }

    // ------------------------------------------------------------------ stale ownership and lease margin

    public function testStaleCircuitRecordMarksAccountingIncompleteAndTheAttemptRecordDoesNotClaimTheVerdict(): void
    {
        $h = new Harness(true);
        $h->circuit->recordStatus = FinalizationStatus::StaleOwnership;
        $spy = DispatchSpy::throwing(self::failing(), $h->log);

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $h->context(Duration::seconds(5)));

        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertFalse($completion->summary->accountingComplete);
        $record = $completion->attempts[0];
        self::assertSame(CircuitVerdict::Neutral, $record->circuitVerdict, 'the backend did not apply the Failure verdict');
        self::assertSame(CircuitVerdict::Failure, $record->classification?->circuit, 'the classification itself is still retained');
        self::assertContains('circuit_record_stale', self::reasons($completion));
        self::assertSame(CircuitVerdict::Neutral, $h->sink->of(AttemptCompleted::class)[0]->verdict);
        self::assertSame('circuit_record_stale', $h->sink->of(AccountingDegraded::class)[0]->reasonCode);
    }

    public function testFailedCircuitRecordAlsoLeavesTheAppliedVerdictUnclaimed(): void
    {
        $h = new Harness(true);
        $h->circuit->recordFailure = new StateUnavailableException('down');
        $spy = DispatchSpy::returning('ok', $h->log);

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $h->context(Duration::seconds(5)));

        self::assertSame(CompletionDisposition::Succeeded, $completion->disposition);
        self::assertSame(CircuitVerdict::Neutral, $completion->attempts[0]->circuitVerdict);
        self::assertFalse($completion->summary->accountingComplete);
    }

    public function testStaleConcurrencyReleaseMarksAccountingIncomplete(): void
    {
        $h = new Harness(false, true);
        $h->concurrency->releaseStatus = FinalizationStatus::StaleOwnership;
        $spy = DispatchSpy::returning('ok', $h->log);

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $h->context(Duration::seconds(5)));

        self::assertSame(CompletionDisposition::Succeeded, $completion->disposition, 'the business result is preserved');
        self::assertFalse($completion->summary->accountingComplete);
        self::assertContains('concurrency_release_stale', self::reasons($completion));
        self::assertSame('concurrency_release_stale', $h->sink->of(AccountingDegraded::class)[0]->reasonCode);
    }

    private static function leaseDefinition(Duration $circuit, Duration $concurrency, Duration $budget): PipelineDefinition
    {
        $h = new Harness();
        $base = $h->definition();

        return new PipelineDefinition(
            $base->profileId,
            $base->profileVersion,
            $base->retry,
            $base->attempt,
            null,
            null,
            new CircuitPolicy(
                Harness::identity('circuit'),
                new CountCircuitWindow(new PositiveInt(10)),
                new PositiveInt(5),
                new Ratio(new PositiveInt(1), new PositiveInt(2)),
                Duration::seconds(30),
                new PositiveInt(1),
                new PositiveInt(1),
                $circuit,
            ),
            new ConcurrencyPolicy(Harness::identity('concurrency'), new PositiveInt(2), $concurrency),
            new RetryBudgetPolicy(Harness::identity('budget'), new PositiveInt(10), new PositiveInt(10), Duration::seconds(60), $budget),
            null,
            false,
        );
    }

    public function testEveryLeaseMustExceedTheAttemptMaximumPlusTheOneSecondMarginStrictly(): void
    {
        // attempt.maximumDuration is 30 s, so each lease must be strictly greater than 31 s.
        $ok = Duration::seconds(60);
        $exactly = Duration::seconds(31);
        $justAbove = new Duration(31_000_000_001);

        self::assertInstanceOf(PipelineDefinition::class, self::leaseDefinition($justAbove, $justAbove, $justAbove));

        foreach (['circuit permitLease' => [$exactly, $ok, $ok], 'concurrency lease' => [$ok, $exactly, $ok], 'reservationLease' => [$ok, $ok, $exactly]] as $label => [$circuit, $concurrency, $budget]) {
            try {
                self::leaseDefinition($circuit, $concurrency, $budget);
                self::fail($label . ' equal to the attempt maximum plus margin must be rejected.');
            } catch (InvalidConfigurationException $rejected) {
                self::assertStringContainsString('cleanup margin', $rejected->getMessage(), $label);
            }
        }

        // A lease shorter than the attempt itself is rejected too.
        $this->expectException(InvalidConfigurationException::class);
        self::leaseDefinition(Duration::seconds(10), $ok, $ok);
    }

    // ------------------------------------------------------------------ foreign throwables from backends

    public function testForeignThrowableFromRetryBudgetCommitClearsTheReservationAndIsIndeterminateInfrastructure(): void
    {
        $h = new Harness(true, true, true, true);
        $foreign = new \RuntimeException('driver exploded');
        $h->budget->commitFailure = $foreign;
        $spy = DispatchSpy::throwing(self::failing(), $h->log);

        $completion = $h->pipeline(Harness::retry(3))->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, Harness::replayable()));

        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertInstanceOf(IndeterminateStateException::class, $completion->policyCause);
        self::assertNotInstanceOf(PreparationFailedException::class, $completion->policyCause);
        self::assertSame($foreign, $completion->policyCause->getPrevious());
        self::assertSame(0, $h->budget->released, 'a possibly committed reservation is never released');
        self::assertSame(1, $spy->dispatches);
        self::assertSame(1, $completion->summary->admittedNotDispatched, 'the known debit is counted');
        self::assertSame(0, $completion->summary->admissionUncertain);
    }

    public function testForeignThrowableFromRetryBudgetReserveIsIndeterminateInfrastructure(): void
    {
        $h = new Harness(true, true, true, true);
        $foreign = new \LogicException('driver exploded');
        $h->budget->reserveFailure = $foreign;
        $spy = DispatchSpy::throwing(self::failing(), $h->log);

        $completion = $h->pipeline(Harness::retry(3))->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, Harness::replayable()));

        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertInstanceOf(IndeterminateStateException::class, $completion->policyCause);
        self::assertSame($foreign, $completion->policyCause->getPrevious());
        self::assertSame(0, $h->budget->released);
        self::assertSame(1, $spy->dispatches);
    }

    public function testForeignThrowablesFromEveryOtherAdmissionBackendAreIndeterminateInfrastructureAndPreventDispatch(): void
    {
        $setters = [
            'circuit' => [new Harness(true), static function (Harness $h, \Throwable $f): void {
                $h->circuit->acquireFailure = $f;
            }],
            'concurrency' => [new Harness(false, true), static function (Harness $h, \Throwable $f): void {
                $h->concurrency->acquireFailure = $f;
            }],
            'cooldown' => [new Harness(false, false, false, false, true), static function (Harness $h, \Throwable $f): void {
                $h->cooldown->inspectFailure = $f;
            }],
            'rate' => [new Harness(false, false, true), static function (Harness $h, \Throwable $f): void {
                $h->rate->failure = $f;
            }],
        ];
        foreach ($setters as $label => [$h, $arm]) {
            $foreign = new \RuntimeException('driver ' . $label);
            $arm($h, $foreign);
            $spy = DispatchSpy::returning('never', $h->log);

            $completion = $h->pipeline()->executeDetailed($spy->callable(), $h->context(Duration::seconds(5)));

            self::assertSame(CompletionDisposition::Failed, $completion->disposition, $label);
            self::assertInstanceOf(IndeterminateStateException::class, $completion->policyCause, $label);
            self::assertSame($foreign, $completion->policyCause->getPrevious(), $label);
            self::assertSame(0, $spy->dispatches, $label);
        }
    }

    // ------------------------------------------------------------------ strict delay-fit

    public function testRetryIsScheduledOnlyWhenDelayPlusMinimumAllowanceIsStrictlyLessThanRemaining(): void
    {
        // Allowance 0 means a one nanosecond allowance: delay 100 ms needs remaining > 100 ms + 1 ns.
        foreach ([[100_000_001, 1, true], [100_000_002, 2, false]] as [$budget, $dispatches, $stopped]) {
            $h = new Harness(false, false, false, true);
            $spy = DispatchSpy::throwing(self::failing(), $h->log);

            $completion = $h->pipeline(Harness::retry(2))->executeDetailed($spy->callable(), $h->context(new Duration($budget), null, Harness::replayable()));

            self::assertSame($dispatches, $spy->dispatches, (string) $budget);
            self::assertSame($stopped, in_array('retry_delay_exceeds_deadline', self::reasons($completion), true), (string) $budget);
        }
    }

    public function testRetryAfterLowerBoundObeysTheSameStrictFit(): void
    {
        $deferred = new Harness(false, false, false, true);
        $deferred->classifier->retryAfter = Duration::milliseconds(100);
        $spy = DispatchSpy::throwing(self::failing(), $deferred->log);
        $completion = $deferred->pipeline(Harness::retry(2, 10))->executeDetailed($spy->callable(), $deferred->context(new Duration(100_000_001), null, Harness::replayable()));
        self::assertSame(CompletionDisposition::Deferred, $completion->disposition);
        self::assertInstanceOf(AdmissionDeferredException::class, $completion->policyCause);
        self::assertSame('retry_after_exceeds_deadline', $completion->policyCause->decision->reasonCode);

        $fits = new Harness(false, false, false, true);
        $fits->classifier->retryAfter = Duration::milliseconds(100);
        $spy = DispatchSpy::of($fits->log, static fn(): never => throw self::failing(), static fn(): string => 'ok');
        $completion = $fits->pipeline(Harness::retry(2, 10))->executeDetailed($spy->callable(), $fits->context(new Duration(100_000_002), null, Harness::replayable()));
        self::assertSame(CompletionDisposition::Succeeded, $completion->disposition);
        self::assertSame([100_000_000], $fits->scheduler->delays, 'jitter-free random part is trimmed; the lower bound itself is untouched');
    }

    // ------------------------------------------------------------------ observe-only Retry-After from late outcomes

    public function testLateOutcomeRetryAfterExtendsTheSharedCooldownWithoutChangingVerdictRetryOrClassification(): void
    {
        $h = new Harness(true, true, false, true, true);
        $h->classifier->retryAfter = Duration::seconds(7);
        $fallback = new ClosureFallback(static fn(): string => 'fallback');
        $context = $h->context(Duration::seconds(5), $fallback, Harness::replayable());
        $spy = DispatchSpy::of($h->log, static function () use ($h, $context): never {
            $h->clock->now = $context->deadline->monotonicNanoseconds + 1;
            throw self::failing('late');
        });

        $completion = $h->pipeline(Harness::retry(3))->executeDetailed($spy->callable(), $context);

        self::assertSame(CompletionDisposition::DeadlineExceeded, $completion->disposition);
        self::assertEquals([Duration::seconds(7)], $h->cooldown->extensions);
        self::assertSame(1, $spy->dispatches, 'no retry');
        self::assertSame(0, $fallback->calls);
        self::assertNull($completion->attempts[0]->classification, 'the observe-only classification is never recorded as applied');
        self::assertSame(CircuitVerdict::Neutral, $completion->attempts[0]->circuitVerdict);
        self::assertSame(1, $h->circuit->abandoned);
        self::assertSame([], $h->circuit->recorded);
    }

    public function testCancelledOutcomeRetryAfterExtendsTheSharedCooldownToo(): void
    {
        $h = new Harness(false, false, false, true, true);
        $h->classifier->retryAfter = Duration::seconds(3);
        $spy = DispatchSpy::of($h->log, static function () use ($h): never {
            $h->cancel->cancel();
            throw self::failing();
        });

        $completion = $h->pipeline(Harness::retry(3))->executeDetailed($spy->callable(), $h->context(Duration::seconds(5), null, Harness::replayable()));

        self::assertSame(CompletionDisposition::Cancelled, $completion->disposition);
        self::assertInstanceOf(OperationCancelledException::class, $completion->policyCause);
        self::assertEquals([Duration::seconds(3)], $h->cooldown->extensions);
        self::assertSame(1, $spy->dispatches);
    }

    public function testClassifierFailureDuringLateSignalExtractionIsIsolatedAsABoundedDiagnostic(): void
    {
        $h = new Harness(false, false, false, true, true);
        $h->classifier->throw = new \RuntimeException('classifier broke');
        $context = $h->context(Duration::seconds(5));
        $spy = DispatchSpy::of($h->log, static function () use ($h, $context): string {
            $h->clock->now = $context->deadline->monotonicNanoseconds;

            return 'late';
        });

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $context);

        self::assertSame(CompletionDisposition::DeadlineExceeded, $completion->disposition);
        self::assertNull($completion->classifierCause, 'never a classifier cause');
        self::assertContains('late_signal_extraction_failed', self::reasons($completion));
        self::assertSame([], $h->cooldown->extensions);
    }

    public function testFailedCooldownExtensionOfALateSignalMarksAccountingIncomplete(): void
    {
        $h = new Harness(false, false, false, true, true);
        $h->classifier->retryAfter = Duration::seconds(3);
        $h->cooldown->extendFailure = new StateUnavailableException('cooldown down');
        $context = $h->context(Duration::seconds(5));
        $spy = DispatchSpy::of($h->log, static function () use ($h, $context): never {
            $h->clock->now = $context->deadline->monotonicNanoseconds;
            throw self::failing();
        });

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $context);

        self::assertSame(CompletionDisposition::DeadlineExceeded, $completion->disposition);
        self::assertFalse($completion->summary->accountingComplete);
        self::assertContains('cooldown_extend_failed', self::reasons($completion));
    }

    // ------------------------------------------------------------------ who refused

    public function testRetryPolicyDenialsKeepTheBusinessResultAndRecordAStableRetryStopReason(): void
    {
        $cases = [];

        // attempts exhausted
        $h = new Harness(false, false, false, true);
        $cases['attempts_exhausted'] = [$h, Harness::retry(2), Duration::seconds(60), Harness::replayable(), 2];

        // outcome not in the replay allowlist
        $h = new Harness(false, false, false, true);
        $cases['outcome_not_retryable'] = [$h, Harness::retry(3), Duration::seconds(60), Harness::replayable('other-code'), 1];

        // budget exhausted
        $h = new Harness(false, false, false, true);
        $h->budget->status = RetryReservationStatus::Exhausted;
        $cases['retry_budget_exhausted'] = [$h, Harness::retry(3), Duration::seconds(60), Harness::replayable(), 1];

        // budget commit refused
        $h = new Harness(false, false, false, true);
        $h->budget->commitStatus = RetryBudgetFinalizationStatus::Released;
        $cases['retry_budget_commit_refused'] = [$h, Harness::retry(3), Duration::seconds(60), Harness::replayable(), 1];

        // delay does not fit
        $h = new Harness(false, false, false, true);
        $cases['retry_delay_exceeds_deadline'] = [$h, Harness::retry(3, 400), Duration::milliseconds(300), Harness::replayable(), 1];

        // minimum allowance cannot fit at all
        $h = new Harness(false, false, false, true);
        $cases['attempt_allowance_unavailable'] = [$h, Harness::retry(2, 100, 10_000, BackoffMode::Exponential, JitterMode::None, 600), Duration::milliseconds(600), Harness::replayable(), 1];

        foreach ($cases as $reason => [$h, $retry, $budget, $safety, $dispatches]) {
            $business = self::failing($reason);
            $spy = DispatchSpy::throwing($business, $h->log);

            $completion = $h->pipeline($retry)->executeDetailed($spy->callable(), $h->context($budget, null, $safety));

            self::assertSame(CompletionDisposition::Failed, $completion->disposition, $reason);
            self::assertNull($completion->policyCause, $reason);
            self::assertSame($business, $completion->terminalOutcome?->exception(), $reason);
            self::assertSame($dispatches, $spy->dispatches, $reason);
            self::assertContains($reason, self::reasons($completion), $reason);
        }
    }

    public function testRefusalByAnotherProtectionOnALaterAttemptKeepsThePolicyCauseAndChainsTheLastBusinessException(): void
    {
        $arm = [
            'circuit' => static function (Harness $h): void {
                $h->circuit->status = CircuitAdmissionStatus::Open;
            },
            'concurrency' => static function (Harness $h): void {
                $h->concurrency->exhausted = true;
            },
            'rate' => static function (Harness $h): void {
                $h->rate->script = [new AdmissionDecision(AdmissionStatus::Rejected, 'rate_limited', null, null)];
            },
            'cooldown' => static function (Harness $h): void {
                $h->cooldown->active = Duration::seconds(5);
            },
        ];
        foreach ($arm as $kind => $trigger) {
            // Refusal appears only once the retry has been authorised (at the retry-budget reservation).
            $build = static function () use ($trigger, $kind): array {
                $h = new Harness(true, true, true, true, true);
                $h->budget->onCall = static function (string $name) use ($h, $trigger): void {
                    if ($name === 'budget.reserve') {
                        $trigger($h);
                    }
                };
                $business = new \RuntimeException($kind);

                return [$h, $business, DispatchSpy::throwing($business, $h->log)];
            };

            [$h, $business, $spy] = $build();
            $completion = $h->pipeline(Harness::retry(3))->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, Harness::replayable()));
            self::assertContains($completion->disposition, [CompletionDisposition::Rejected, CompletionDisposition::Deferred], $kind);
            self::assertNotNull($completion->policyCause, $kind);
            self::assertSame($business, $completion->policyCause->getPrevious(), $kind);
            self::assertSame($business, $completion->terminalOutcome?->exception(), $kind . ': detailed mode retains the terminal outcome');
            self::assertSame(1, $spy->dispatches, $kind);

            [$h, $business, $spy] = $build();
            try {
                $h->pipeline(Harness::retry(3))->execute($spy->callable(), $h->context(Duration::seconds(60), null, Harness::replayable()));
                self::fail($kind . ' must throw its policy exception, not the business exception.');
            } catch (AdmissionRejectedException|AdmissionDeferredException $thrown) {
                self::assertSame($business, $thrown->getPrevious(), $kind . ' (simple mode)');
            }
        }
    }

    public function testFirstAttemptRefusalHasNoPreviousAndInfrastructureStaysTerminal(): void
    {
        $open = new Harness(true);
        $open->circuit->status = CircuitAdmissionStatus::Open;
        $spy = DispatchSpy::returning('never', $open->log);
        $completion = $open->pipeline()->executeDetailed($spy->callable(), $open->context(Duration::seconds(5)));
        self::assertInstanceOf(AdmissionRejectedException::class, $completion->policyCause);
        self::assertNull($completion->policyCause->getPrevious());

        $down = new Harness(false, false, false, true);
        $down->budget->reserveFailure = new StateUnavailableException('budget down');
        $spy = DispatchSpy::throwing(self::failing(), $down->log);
        $completion = $down->pipeline(Harness::retry(3))->executeDetailed($spy->callable(), $down->context(Duration::seconds(60), null, Harness::replayable()));
        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertInstanceOf(StateUnavailableException::class, $completion->policyCause);
        self::assertNull($completion->policyCause->getPrevious());
    }

    // ------------------------------------------------------------------ cooldown

    public function testActiveCooldownIsAlwaysDeferredAfterCircuitAndConcurrencyAndBeforeTheRateDebit(): void
    {
        $h = new Harness(true, true, true, false, true);
        $h->cooldown->active = Duration::seconds(5);
        $spy = DispatchSpy::returning('never', $h->log);

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $h->context(Duration::seconds(60)));

        self::assertSame(CompletionDisposition::Deferred, $completion->disposition);
        self::assertInstanceOf(AdmissionDeferredException::class, $completion->policyCause);
        self::assertSame('cooldown_active', $completion->policyCause->decision->reasonCode);
        self::assertEquals(Duration::seconds(5), $completion->policyCause->decision->retryAfter);
        self::assertSame(0, $spy->dispatches);
        $order = array_values(array_filter($h->log->entries, static fn(string $e): bool => !str_starts_with($e, 'event.')));
        self::assertSame(['circuit.acquire', 'concurrency.acquire', 'cooldown.inspect', 'circuit.abandon', 'concurrency.release'], $order, 'Stage 6 order kept; no rate debit');
    }

    public function testCooldownThatCannotFitTheRemainingDeadlineIsStillDeferredWithItsOwnReasonCode(): void
    {
        // wait + 1 ns must be strictly less than remaining.
        foreach ([[9_000_000_001, 'cooldown_exceeds_deadline'], [9_000_000_002, 'cooldown_active'], [4_000_000_000, 'cooldown_exceeds_deadline']] as [$budget, $reason]) {
            $h = new Harness(false, false, false, false, true);
            $h->cooldown->active = $budget === 4_000_000_000 ? Duration::seconds(60) : Duration::seconds(9);
            $spy = DispatchSpy::returning('never', $h->log);

            $completion = $h->pipeline()->executeDetailed($spy->callable(), $h->context(new Duration($budget)));

            self::assertSame(CompletionDisposition::Deferred, $completion->disposition, $reason . $budget);
            self::assertInstanceOf(AdmissionDeferredException::class, $completion->policyCause);
            self::assertSame($reason, $completion->policyCause->decision->reasonCode, (string) $budget);
            self::assertTrue($completion->policyCause->decision->retryAfter?->isPositive());
        }
    }

    // ------------------------------------------------------------------ dispatch counting and uncertain admission

    public function testAdapterThatThrowsAfterInvocationBeganIsADispatchWithAnInfrastructureTerminationAndNoRetry(): void
    {
        $h = new Harness(true, true, true, true);
        $thrown = new \RuntimeException('adapter exploded');
        $adapter = new ThrowingRuntimeAdapter($thrown);
        $h->runtimeOverride = $adapter;
        $spy = DispatchSpy::returning('never', $h->log);
        $pipeline = $h->pipeline(Harness::retry(3));

        $completion = $pipeline->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, Harness::replayable()));

        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertInstanceOf(IndeterminateStateException::class, $completion->policyCause);
        self::assertNotInstanceOf(PreparationFailedException::class, $completion->policyCause);
        self::assertSame($thrown, $completion->policyCause->getPrevious());
        self::assertSame(1, $adapter->invocations, 'never retried');
        self::assertSame(1, $completion->summary->dispatched);
        self::assertSame(0, $completion->summary->admittedNotDispatched, 'the debit belongs to a dispatched attempt');
        self::assertSame(1, $h->rate->consumed);
        self::assertSame(1, $completion->summary->actualExceptions);
        self::assertSame(1, $completion->summary->neutralAttempts);
        self::assertSame($thrown, $completion->attempts[0]->outcome->exception());
        self::assertNull($completion->attempts[0]->classification);
        self::assertSame(OutcomeKind::Exception, $completion->terminalOutcome?->kind);
        self::assertFalse($completion->summary->accountingComplete);
        self::assertContains('runtime_adapter_failure', self::reasons($completion));
        self::assertSame(1, $h->circuit->abandoned);
        self::assertSame(0, $h->concurrency->active);
        self::assertSame(0, $h->budget->released);

        $this->expectException(IndeterminateStateException::class);
        $pipeline->execute($spy->callable(), $h->context(Duration::seconds(60), null, Harness::replayable()));
    }

    public function testSettlementObservationThatThrowsIsAlsoADispatch(): void
    {
        $h = new Harness(false, true, true);
        $thrown = new \RuntimeException('await exploded');
        $h->runtimeOverride = new ThrowingRuntimeAdapter(null, $thrown);
        $spy = DispatchSpy::returning('never', $h->log);

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $h->context(Duration::seconds(5)));

        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertSame(1, $completion->summary->dispatched);
        self::assertSame(0, $completion->summary->admittedNotDispatched);
        self::assertSame($thrown, $completion->policyCause?->getPrevious());
        self::assertSame(0, $h->concurrency->active);
    }

    public function testAClockFailureThatPreventedInvocationIsNotADispatch(): void
    {
        $h = new Harness(false, false, true);
        $h->runtimeOverride = new ThrowingRuntimeAdapter(new ClockFailureException('no start time'));
        $spy = DispatchSpy::returning('never', $h->log);

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $h->context(Duration::seconds(5)));

        self::assertInstanceOf(ClockFailureException::class, $completion->policyCause);
        self::assertSame(0, $completion->summary->dispatched);
        self::assertSame(1, $completion->summary->admittedNotDispatched, 'known debit, never started');
    }

    public function testIndeterminateRateConsumeIsCountedAsAnUncertainAdmissionNotAsAKnownDebit(): void
    {
        $h = new Harness(false, false, true);
        $h->rate->failure = new IndeterminateStateException('lost reply');
        $spy = DispatchSpy::returning('never', $h->log);

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $h->context(Duration::seconds(5)));

        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertSame(1, $completion->summary->admissionUncertain);
        self::assertSame(0, $completion->summary->admittedNotDispatched);
        self::assertFalse($completion->summary->accountingComplete);
        self::assertSame(0, $spy->dispatches);
    }

    public function testExecutionSummaryValidatesAdmissionUncertain(): void
    {
        $make = static fn(int $uncertain, bool $complete): ExecutionSummary => new ExecutionSummary(
            new OperationId('op-1'),
            new ProfileVersion('v1'),
            CompletionDisposition::Failed,
            0,
            0,
            0,
            0,
            0,
            0,
            0,
            0,
            0,
            $complete,
            0,
            $complete ? Duration::zero() : null,
            $uncertain,
        );

        self::assertSame(0, $make(0, true)->admissionUncertain, 'additive: the default is 0');
        self::assertSame(2, $make(2, false)->admissionUncertain);
        foreach ([[-1, false], [65, false], [1, true]] as [$uncertain, $complete]) {
            try {
                $make($uncertain, $complete);
                self::fail('admissionUncertain ' . $uncertain . ' with complete=' . var_export($complete, true) . ' must be rejected.');
            } catch (InvalidConfigurationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    // ------------------------------------------------------------------ fallback timing

    public function testUnreadableTimingAfterTheFallbackLeavesElapsedNullAndAccountingIncomplete(): void
    {
        $h = new Harness();
        $fallback = new ClosureFallback(static function () use ($h): string {
            $failed = false;
            $h->clock->failWhen = static function () use (&$failed): bool {
                if ($failed) {
                    return false;
                }
                $failed = true;

                return true;
            };

            return 'fb';
        });
        $spy = DispatchSpy::throwing(self::failing(), $h->log);

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $h->context(Duration::seconds(5), $fallback));

        self::assertSame(CompletionDisposition::FallbackSucceeded, $completion->disposition, 'the value is retained');
        self::assertSame('fb', $completion->fallbackOutcome?->value());
        self::assertNull($completion->summary->elapsed, 'never reported as on time');
        self::assertFalse($completion->summary->accountingComplete);
        self::assertContains('timing_unavailable', self::reasons($completion));
    }

    // ------------------------------------------------------------------ finite backend I/O

    public function testFiniteBackendIoIsRequiredOnlyOfSharedAuthorityBackends(): void
    {
        $local = new Harness(true, true, true, true, true);
        self::assertSame(StateScope::ProcessLocal, $local->circuit->capabilities()->scope);
        self::assertNull($local->circuit->capabilities()->ioTimeout);
        $local->definition(Harness::retry(2), true)->compile($local->environment());
        self::addToAssertionCount(1);

        foreach (['rate', 'circuit', 'concurrency', 'budget', 'cooldown'] as $kind) {
            $h = new Harness(true, true, true, true, true);
            foreach ([$h->rate, $h->circuit, $h->concurrency, $h->budget, $h->cooldown] as $backend) {
                $backend->ioTimeout = Duration::milliseconds(50);
            }
            $backend = match ($kind) {
                'rate' => $h->rate,
                'circuit' => $h->circuit,
                'concurrency' => $h->concurrency,
                'budget' => $h->budget,
                default => $h->cooldown,
            };
            $backend->scope = StateScope::SharedAuthority;
            $backend->ioTimeout = null;

            try {
                $h->definition(Harness::retry(2), true)->compile($h->environment());
                self::fail($kind . ' is shared-authority without a finite I/O timeout.');
            } catch (CapabilityMismatchException) {
                $backend->ioTimeout = Duration::milliseconds(50);
                $h->definition(Harness::retry(2), true)->compile($h->environment());
            }
        }
    }

    // ------------------------------------------------------------------ checked replay-horizon arithmetic

    private static function safety(\DateTimeImmutable $established, \DateTimeImmutable $validUntil): OperationSafety
    {
        return OperationSafety::replayableRead(new ReplaySafetyContract(
            'contract-1',
            'identity-1',
            'scope-1',
            'safe-repeat',
            $established,
            $validUntil,
            Duration::seconds(3600),
            ReplayPreparation::CallableAssertion,
            ['transient'],
        ));
    }

    public function testAbsurdValidUntilSaturatesTheHorizonExplicitlyWithoutFloatArithmetic(): void
    {
        $now = new \DateTimeImmutable('2026-10-08T00:00:00+00:00');
        $beyondTheMonotonicDomain = (new \DateTimeImmutable('@0'))->setTimestamp(PHP_INT_MAX);

        foreach ([$beyondTheMonotonicDomain, new \DateTimeImmutable('9999-12-31T23:59:59+00:00')] as $validUntil) {
            $h = new Harness(false, false, false, true);
            $spy = DispatchSpy::of($h->log, static fn(): never => throw self::failing(), static fn(): string => 'ok');

            $completion = $h->pipeline(Harness::retry(3))->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, self::safety($now->modify('-1 hour'), $validUntil)));

            self::assertContains('replay_horizon_saturated', self::reasons($completion), $validUntil->format('c'));
            self::assertNotContains('replay_horizon_inadequate', self::reasons($completion));
            self::assertSame(2, $spy->dispatches, 'a saturated horizon covers any representable deadline');
            self::assertSame(CompletionDisposition::Succeeded, $completion->disposition);
        }
    }

    public function testRepresentableFarHorizonNeedsNoSaturationAndAncientExpiryIsDeniedExactly(): void
    {
        $now = new \DateTimeImmutable('2026-10-08T00:00:00+00:00');

        $h = new Harness(false, false, false, true);
        $spy = DispatchSpy::of($h->log, static fn(): never => throw self::failing(), static fn(): string => 'ok');
        $completion = $h->pipeline(Harness::retry(3))->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, self::safety($now->modify('-1 hour'), $now->modify('+100 years'))));
        self::assertSame([], self::reasons($completion));
        self::assertSame(2, $spy->dispatches);

        $h = new Harness(false, false, false, true);
        $spy = DispatchSpy::throwing(self::failing(), $h->log);
        $ancient = self::safety(new \DateTimeImmutable('1970-01-01T00:00:00+00:00'), new \DateTimeImmutable('1970-01-02T00:00:00+00:00'));
        $completion = $h->pipeline(Harness::retry(3))->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, $ancient));
        self::assertContains('replay_contract_not_valid', self::reasons($completion));
        self::assertSame(1, $spy->dispatches);
    }
}
