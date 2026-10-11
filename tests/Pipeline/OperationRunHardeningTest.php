<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Pipeline;

use Enaxon\Reqtide\Completion\ActualAttemptDisposition;
use Enaxon\Reqtide\Completion\CompletionDisposition;
use Enaxon\Reqtide\Completion\Diagnostic;
use Enaxon\Reqtide\Completion\ExecutionCompletion;
use Enaxon\Reqtide\Error\ReasonCode;
use Enaxon\Reqtide\Exception\AdmissionDeferredException;
use Enaxon\Reqtide\Exception\ClockFailureException;
use Enaxon\Reqtide\Exception\IndeterminateStateException;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Exception\PreparationFailedException;
use Enaxon\Reqtide\Exception\StateCapacityExceededException;
use Enaxon\Reqtide\Exception\StateUnavailableException;
use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Execution\BoundedMetadata;
use Enaxon\Reqtide\Execution\ExecutionContext;
use Enaxon\Reqtide\Execution\OperationMetadata;
use Enaxon\Reqtide\Execution\OperationSafety;
use Enaxon\Reqtide\Execution\ReplayPreparation;
use Enaxon\Reqtide\Execution\ReplaySafetyContract;
use Enaxon\Reqtide\Outcome\CircuitVerdict;
use Enaxon\Reqtide\Outcome\Outcome;
use Enaxon\Reqtide\Pipeline\RuntimeEnvironment;
use Enaxon\Reqtide\Policy\BackoffMode;
use Enaxon\Reqtide\Policy\JitterMode;
use Enaxon\Reqtide\Runtime\AttemptOperation;
use Enaxon\Reqtide\Runtime\PreparedAttempt;
use Enaxon\Reqtide\State\FinalizationStatus;
use Enaxon\Reqtide\Telemetry\AdmissionRejected;
use Enaxon\Reqtide\Telemetry\BackendErrorObserved;
use Enaxon\Reqtide\Tests\Pipeline\Support\ClosureFallback;
use Enaxon\Reqtide\Tests\Pipeline\Support\DispatchSpy;
use Enaxon\Reqtide\Tests\Pipeline\Support\Harness;
use Enaxon\Reqtide\Tests\Support\Fixtures;
use Enaxon\Reqtide\Time\Deadline;
use Enaxon\Reqtide\Value\ApplicationId;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\EnvironmentId;
use Enaxon\Reqtide\Value\OperationId;
use Enaxon\Reqtide\Value\PartitionIdentity;
use Enaxon\Reqtide\Value\SubjectId;
use PHPUnit\Framework\TestCase;

/**
 * Boundary and ownership behaviour of the single-operation lifecycle that the broader lifecycle tests leave open:
 * the replay horizon arithmetic, preparation failure typing, budget use only for retries, backends installed without
 * a policy, the typed reason of every infrastructure failure and the degraded accounting after clock failures.
 */
final class OperationRunHardeningTest extends TestCase
{
    private const WALL = '2026-10-08T00:00:00+00:00';

    /**
     * @param ExecutionCompletion<mixed> $completion
     * @return list<string>
     */
    private static function reasons(ExecutionCompletion $completion): array
    {
        return array_map(static fn(Diagnostic $d): string => $d->reasonCode, $completion->diagnostics);
    }

    private static function wall(string $when = self::WALL): \DateTimeImmutable
    {
        return new \DateTimeImmutable($when);
    }

    private static function safety(\DateTimeImmutable $established, \DateTimeImmutable $until, Duration $retention = new Duration(3_600_000_000_000)): OperationSafety
    {
        return OperationSafety::replayableRead(new ReplaySafetyContract(
            'contract-1',
            'identity-1',
            'scope-1',
            'safe-repeat',
            $established,
            $until,
            $retention,
            ReplayPreparation::CallableAssertion,
            ['transient'],
        ));
    }

    /**
     * One failing dispatch then success.
     *
     * @return array{0: ExecutionCompletion<mixed>, 1: int} completion and number of dispatches
     */
    private function runRetry(Harness $h, OperationSafety $safety, Duration $budget = new Duration(60_000_000_000), int $attempts = 2): array
    {
        $spy = DispatchSpy::of($h->log, static fn(): never => throw new \RuntimeException('transient'), static fn(): string => 'ok');
        $completion = $h->pipeline(Harness::retry($attempts))->executeDetailed($spy->callable(), $h->context($budget, null, $safety));

        return [$completion, $spy->dispatches];
    }

    // ---- replay contract and horizon ------------------------------------------------------------------

    public function testAContractEstablishedExactlyNowIsValidButOneMicrosecondInTheFutureIsNot(): void
    {
        $now = self::wall();
        [$completion, $dispatches] = $this->runRetry(new Harness(false, false, false, true), self::safety($now, $now->modify('+1 hour')));
        self::assertSame(2, $dispatches);
        self::assertSame([], array_filter(self::reasons($completion), static fn(string $r): bool => str_starts_with($r, 'replay_')));

        [$completion, $dispatches] = $this->runRetry(new Harness(false, false, false, true), self::safety(self::wall('2026-10-08T00:00:00.000001+00:00'), $now->modify('+1 hour')));
        self::assertSame(1, $dispatches);
        self::assertContains(ReasonCode::ReplayContractNotValid->value, self::reasons($completion));
    }

    public function testAContractThatEndsNowIsExpiredButOneThatEndsAMicrosecondLaterIsOnlyInadequate(): void
    {
        $now = self::wall();
        [$completion, $dispatches] = $this->runRetry(new Harness(false, false, false, true), self::safety($now->modify('-1 hour'), $now));
        self::assertSame(1, $dispatches);
        self::assertContains(ReasonCode::ReplayContractNotValid->value, self::reasons($completion));

        [$completion, $dispatches] = $this->runRetry(new Harness(false, false, false, true), self::safety($now->modify('-1 hour'), self::wall('2026-10-08T00:00:00.000001+00:00')));
        self::assertSame(1, $dispatches);
        self::assertContains(ReasonCode::ReplayHorizonInadequate->value, self::reasons($completion));
        self::assertNotContains(ReasonCode::ReplayContractNotValid->value, self::reasons($completion));
    }

    public function testAContractExpiredByMoreThanOneSecondIsNotValid(): void
    {
        $now = self::wall();
        [$completion, $dispatches] = $this->runRetry(new Harness(false, false, false, true), self::safety($now->modify('-1 day'), $now->modify('-2 seconds')));

        self::assertSame(1, $dispatches);
        self::assertContains(ReasonCode::ReplayContractNotValid->value, self::reasons($completion));
    }

    public function testTheHorizonMustReachTheDeadlineButMayEndExactlyOnIt(): void
    {
        $now = self::wall();
        $budget = Duration::seconds(60);

        [, $dispatches] = $this->runRetry(new Harness(false, false, false, true), self::safety($now->modify('-1 hour'), $now->modify('+60 seconds')), $budget);
        self::assertSame(2, $dispatches, 'a horizon equal to the deadline is adequate');

        [$completion, $dispatches] = $this->runRetry(new Harness(false, false, false, true), self::safety($now->modify('-1 hour'), self::wall('2026-10-08T00:00:59.999999+00:00')), $budget);
        self::assertSame(1, $dispatches);
        self::assertContains(ReasonCode::ReplayHorizonInadequate->value, self::reasons($completion));
    }

    public function testTheHorizonIsMeasuredWithMicrosecondPrecisionAcrossSecondBoundaries(): void
    {
        $wall = self::wall('2026-10-08T00:00:00.900000+00:00');
        $until = self::wall('2026-10-08T00:01:01.100000+00:00'); // exactly 60.2 s later
        $make = static function () use ($wall): Harness {
            $h = new Harness(false, false, false, true);
            $h->clock->wall = $wall;

            return $h;
        };

        [, $dispatches] = $this->runRetry($make(), self::safety($wall->modify('-1 hour'), $until), Duration::microseconds(60_200_000));
        self::assertSame(2, $dispatches, '60.2 s of horizon cover a 60.2 s deadline');

        [$completion, $dispatches] = $this->runRetry($make(), self::safety($wall->modify('-1 hour'), $until), Duration::microseconds(60_200_001));
        self::assertSame(1, $dispatches);
        self::assertContains(ReasonCode::ReplayHorizonInadequate->value, self::reasons($completion));
    }

    public function testTheRetentionMustCoverTheRemainingTimeButMayEqualIt(): void
    {
        $now = self::wall();
        $until = $now->modify('+1 hour');

        [, $dispatches] = $this->runRetry(new Harness(false, false, false, true), self::safety($now->modify('-1 hour'), $until, Duration::seconds(60)), Duration::seconds(60));
        self::assertSame(2, $dispatches);

        [$completion, $dispatches] = $this->runRetry(new Harness(false, false, false, true), self::safety($now->modify('-1 hour'), $until, Duration::nanoseconds(59_999_999_999)), Duration::seconds(60));
        self::assertSame(1, $dispatches);
        self::assertContains(ReasonCode::ReplayHorizonInadequate->value, self::reasons($completion));
    }

    public function testASingleAttemptPipelineNeverLooksAtTheReplayContract(): void
    {
        $now = self::wall();
        $h = new Harness();
        $spy = DispatchSpy::returning('ok', $h->log);

        $completion = $h->pipeline(Harness::retry(1))->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, self::safety($now->modify('-1 day'), $now->modify('-1 hour'))));

        self::assertSame(CompletionDisposition::Succeeded, $completion->disposition);
        self::assertSame([], self::reasons($completion));
    }

    public function testAHorizonBeyondTheRepresentableRangeSaturatesExplicitlyAtExactlyTheLimit(): void
    {
        $now = self::wall();
        // 9223372035 s is the first span that no longer fits an int nanosecond count with room for the start tick.
        [$completion, $dispatches] = $this->runRetry(new Harness(false, false, false, true), self::safety($now->modify('-1 hour'), $now->setTimestamp($now->getTimestamp() + 9_223_372_035)));
        self::assertSame(2, $dispatches);
        self::assertContains(ReasonCode::ReplayHorizonSaturated->value, self::reasons($completion));

        [$completion, $dispatches] = $this->runRetry(new Harness(false, false, false, true), self::safety($now->modify('-1 hour'), $now->setTimestamp($now->getTimestamp() + 9_223_372_034)));
        self::assertSame(2, $dispatches);
        self::assertNotContains(ReasonCode::ReplayHorizonSaturated->value, self::reasons($completion));
    }

    // ---- attempt allowance after the delay -----------------------------------------------------------

    public function testARetryMayStartWithExactlyTheMinimumAllowanceLeftButNotWithOneNanosecondLess(): void
    {
        foreach ([[1_400_000_000, 2, true], [1_400_000_001, 1, false]] as [$extra, $expectedDispatches, $proceeds]) {
            $h = new Harness(false, false, false, true);
            $h->scheduler->during = static function () use ($h, $extra): void {
                $h->clock->advance($extra);
            };
            $spy = DispatchSpy::of($h->log, static fn(): never => throw new \RuntimeException('transient'), static fn(): string => 'ok');

            $completion = $h->pipeline(Harness::retry(2, 100, 10_000, BackoffMode::Exponential, JitterMode::None, 500))
                ->executeDetailed($spy->callable(), $h->context(Duration::seconds(2), null, Harness::replayable()));

            self::assertSame($expectedDispatches, $spy->dispatches, 'extra ' . $extra);
            if (!$proceeds) {
                self::assertContains(ReasonCode::AttemptAllowanceUnavailable->value, self::reasons($completion));
            }
        }
    }

    // ---- retry budget is for retries only ------------------------------------------------------------

    public function testTheFirstAttemptNeverTouchesTheRetryBudget(): void
    {
        $h = new Harness(false, false, false, true);
        $spy = DispatchSpy::returning('ok', $h->log);

        $h->pipeline(Harness::retry(3))->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, Harness::replayable()));

        self::assertSame(0, $h->budget->reserved);
        self::assertSame(0, $h->budget->committed);
        self::assertSame([], $h->log->withPrefix('budget.'));
    }

    public function testExactlyOneReservationIsMadeAndCommittedPerRetry(): void
    {
        $h = new Harness(false, false, false, true);
        [, $dispatches] = $this->runRetry($h, Harness::replayable());

        self::assertSame(2, $dispatches);
        self::assertSame(1, $h->budget->reserved);
        self::assertSame(1, $h->budget->committed);
        self::assertSame(0, $h->budget->released);
    }

    public function testThePreDelayBudgetCheckAllowsARetryWhoseRefillArrivesExactlyWithTheDelay(): void
    {
        foreach ([[100_000_000, 2], [100_000_001, 1]] as [$retryAfter, $expectedDispatches]) {
            $h = new Harness(false, false, false, true);
            $h->budget->inspectHeadroom = false;
            $h->budget->inspectRetryAfter = new Duration($retryAfter);
            [, $dispatches] = $this->runRetry($h, Harness::replayable());

            self::assertSame($expectedDispatches, $dispatches, 'refill hint ' . $retryAfter . ' ns vs a 100 ms delay');
        }
    }

    public function testAFailingPreDelayBudgetReadIsAnObservedInfrastructureFailure(): void
    {
        $h = new Harness(false, false, false, true);
        $h->budget->inspectFailure = new StateUnavailableException('budget store down');
        [$completion, $dispatches] = $this->runRetry($h, Harness::replayable());

        self::assertSame(1, $dispatches);
        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertInstanceOf(StateUnavailableException::class, $completion->policyCause);
        $events = $h->sink->of(BackendErrorObserved::class);
        self::assertCount(1, $events);
        self::assertSame('retry_budget', $events[0]->backendKind);
        self::assertSame(ReasonCode::StateUnavailable->value, $events[0]->failureCode);
    }

    // ---- preparation failures keep their type ---------------------------------------------------------

    /** @return AttemptOperation<mixed> */
    private static function operation(?\Throwable $prepare = null, ?\Throwable $verify = null): AttemptOperation
    {
        return new class ($prepare, $verify) implements AttemptOperation, PreparedAttempt {
            public function __construct(private readonly ?\Throwable $prepare, private readonly ?\Throwable $verify) {}

            public function prepare(AttemptContext $context): PreparedAttempt
            {
                if ($this->prepare !== null) {
                    throw $this->prepare;
                }

                return $this;
            }

            public function verifyReplay(AttemptContext $context): void
            {
                if ($this->verify !== null) {
                    throw $this->verify;
                }
            }

            public function invoke(AttemptContext $context): mixed
            {
                return 'value';
            }

            public function discardOutcome(Outcome $outcome): void {}
        };
    }

    public function testAnyThrowableFromPrepareIsWrappedAndAPreparationFailureIsKeptAsIs(): void
    {
        $raw = new \RuntimeException('boom');
        $h = new Harness();
        $completion = $h->pipeline()->executeOperationDetailed(self::operation($raw), $h->context(Duration::seconds(60)));
        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertInstanceOf(PreparationFailedException::class, $completion->policyCause);
        self::assertSame($raw, $completion->policyCause->preparationCause);
        self::assertSame([], self::reasons($completion), 'wrapped where it happened, not by the generic lifecycle fallback');

        $typed = new PreparationFailedException(new \LogicException('inner'));
        $h = new Harness();
        $completion = $h->pipeline()->executeOperationDetailed(self::operation($typed), $h->context(Duration::seconds(60)));
        self::assertSame($typed, $completion->policyCause, 'not wrapped a second time');
        self::assertSame([], $completion->attempts, 'nothing was dispatched');
    }

    public function testAnyThrowableFromTheFinalReplayCheckIsWrappedAndAPreparationFailureIsKeptAsIs(): void
    {
        $raw = new \RuntimeException('late boom');
        $h = new Harness();
        $completion = $h->pipeline()->executeOperationDetailed(self::operation(null, $raw), $h->context(Duration::seconds(60)));
        self::assertInstanceOf(PreparationFailedException::class, $completion->policyCause);
        self::assertSame($raw, $completion->policyCause->preparationCause);
        self::assertSame([], $completion->attempts);
        self::assertSame([], self::reasons($completion), 'wrapped where it happened, not by the generic lifecycle fallback');

        $typed = new PreparationFailedException(new \LogicException('inner'));
        $h = new Harness();
        $completion = $h->pipeline()->executeOperationDetailed(self::operation(null, $typed), $h->context(Duration::seconds(60)));
        self::assertSame($typed, $completion->policyCause);
    }

    // ---- backends installed without a policy are ignored ----------------------------------------------

    public function testBackendsWithoutAPolicyAreNeverCalled(): void
    {
        $h = new Harness();
        $h->classifier->retryAfter = Duration::seconds(2);
        $definition = $h->definition();
        $environment = new RuntimeEnvironment($h->clock, $h->scheduler, $h->random, $h->classifier, $h->runtime, $h->sink, $h->rate, $h->circuit, $h->concurrency, $h->budget, $h->cooldown);
        $pipeline = $definition->compile($environment);

        $success = $pipeline->executeDetailed(DispatchSpy::returning('ok', $h->log)->callable(), $h->context(Duration::seconds(60)));
        $failure = $pipeline->executeDetailed(DispatchSpy::throwing(new \RuntimeException('x'), $h->log)->callable(), $h->context(Duration::seconds(60)));

        self::assertSame(CompletionDisposition::Succeeded, $success->disposition);
        self::assertSame(CompletionDisposition::Failed, $failure->disposition);
        $backendCalls = array_filter($h->log->entries, static fn(string $e): bool => preg_match('/^(circuit|concurrency|rate|budget|cooldown)\./', $e) === 1);
        self::assertSame([], array_values($backendCalls));
    }

    public function testWithoutACircuitTheClassifiedVerdictIsWhatTheAttemptRecordClaims(): void
    {
        $h = new Harness();
        $pipeline = $h->pipeline();
        $success = $pipeline->executeDetailed(DispatchSpy::returning('ok')->callable(), $h->context(Duration::seconds(60)));
        $failure = $pipeline->executeDetailed(DispatchSpy::throwing(new \RuntimeException('x'))->callable(), $h->context(Duration::seconds(60)));

        self::assertSame(CircuitVerdict::Success, $success->attempts[0]->circuitVerdict);
        self::assertSame(ActualAttemptDisposition::Succeeded, $success->attempts[0]->disposition);
        self::assertSame(CircuitVerdict::Failure, $failure->attempts[0]->circuitVerdict);
    }

    public function testWithACircuitTheAppliedVerdictIsClaimedOnlyWhenTheBackendApplied(): void
    {
        $h = new Harness(true);
        $applied = $h->pipeline()->executeDetailed(DispatchSpy::throwing(new \RuntimeException('x'))->callable(), $h->context(Duration::seconds(60)));
        self::assertSame(CircuitVerdict::Failure, $applied->attempts[0]->circuitVerdict);
        self::assertTrue($applied->summary->accountingComplete);

        $stale = new Harness(true);
        $stale->circuit->recordStatus = FinalizationStatus::StaleOwnership;
        $completion = $stale->pipeline()->executeDetailed(DispatchSpy::throwing(new \RuntimeException('x'))->callable(), $stale->context(Duration::seconds(60)));
        self::assertSame(CircuitVerdict::Neutral, $completion->attempts[0]->circuitVerdict);
        self::assertContains(ReasonCode::CircuitRecordStale->value, self::reasons($completion));
        self::assertFalse($completion->summary->accountingComplete);
    }

    public function testAStaleAbandonOfAPermitHeldBeforeDispatchIsDiagnosed(): void
    {
        foreach ([[FinalizationStatus::StaleOwnership, true], [FinalizationStatus::Applied, false]] as [$status, $diagnosed]) {
            $h = new Harness(true, true);
            $h->concurrency->exhausted = true;
            $h->circuit->abandonStatus = $status;

            $completion = $h->pipeline()->executeDetailed(DispatchSpy::returning('ok')->callable(), $h->context(Duration::seconds(60)));

            self::assertSame(CompletionDisposition::Rejected, $completion->disposition);
            self::assertSame(1, $h->circuit->abandoned);
            self::assertSame($diagnosed, in_array(ReasonCode::CircuitAbandonStale->value, self::reasons($completion), true));
        }
    }

    // ---- partition equality is checked for every policy kind ------------------------------------------

    public function testAContextForAnotherPartitionIsRejectedForEveryKindOfPolicy(): void
    {
        $other = new PartitionIdentity(new ApplicationId('app'), new EnvironmentId('test'), null, new SubjectId('someone-else'));
        $cases = [
            'rate limit' => new Harness(false, false, true),
            'circuit' => new Harness(true),
            'concurrency' => new Harness(false, true),
            'retry budget' => new Harness(false, false, false, true),
            'cooldown' => new Harness(false, false, false, false, true),
        ];
        foreach ($cases as $name => $h) {
            $context = new ExecutionContext(
                new OperationId('op-1'),
                $other,
                Deadline::after(Duration::seconds(60), $h->clock),
                $h->cancel,
                new OperationMetadata(OperationSafety::unsafe(), BoundedMetadata::empty()),
                null,
            );
            $spy = DispatchSpy::returning('ok', $h->log);
            try {
                $h->pipeline()->executeDetailed($spy->callable(), $context);
                self::fail($name . ': a foreign partition must be rejected.');
            } catch (InvalidConfigurationException $e) {
                self::assertStringContainsString('partition must equal every selected policy partition', $e->getMessage(), $name);
            }
            self::assertSame(0, $spy->dispatches, $name);
            self::assertSame([], $h->log->entries, $name . ': no backend or runtime call before the check');
        }
    }

    // ---- infrastructure failures are typed -----------------------------------------------------------

    public function testAForeignBackendExceptionBecomesAnIndeterminateStateWithTheOriginalAsCause(): void
    {
        $h = new Harness(true);
        $raw = new \RuntimeException('private detail', 77);
        $h->circuit->acquireFailure = $raw;

        $completion = $h->pipeline()->executeDetailed(DispatchSpy::returning('ok')->callable(), $h->context(Duration::seconds(60)));

        self::assertInstanceOf(IndeterminateStateException::class, $completion->policyCause);
        self::assertSame(0, $completion->policyCause->getCode());
        self::assertSame($raw, $completion->policyCause->getPrevious());
        self::assertStringNotContainsString('private detail', $completion->policyCause->getMessage());
    }

    public function testEveryKindOfBackendFailureIsObservedWithItsOwnReasonCode(): void
    {
        $cases = [
            'capacity' => [new StateCapacityExceededException('full'), ReasonCode::StateCapacityExceeded],
            'clock' => [new ClockFailureException('tick'), ReasonCode::ClockFailure],
            'unavailable' => [new StateUnavailableException('down'), ReasonCode::StateUnavailable],
            'indeterminate' => [new IndeterminateStateException('lost'), ReasonCode::StateIndeterminate],
        ];
        foreach ($cases as $name => [$failure, $code]) {
            $h = new Harness(true);
            $h->circuit->acquireFailure = $failure;
            $h->pipeline()->executeDetailed(DispatchSpy::returning('ok')->callable(), $h->context(Duration::seconds(60)));

            $events = $h->sink->of(BackendErrorObserved::class);
            self::assertCount(1, $events, $name);
            self::assertSame('circuit', $events[0]->backendKind, $name);
            self::assertSame($code->value, $events[0]->failureCode, $name);
        }

        $h = new Harness(false, true);
        $h->concurrency->releaseFailure = new \RuntimeException('release blew up');
        $h->pipeline()->executeDetailed(DispatchSpy::returning('ok')->callable(), $h->context(Duration::seconds(60)));
        $events = $h->sink->of(BackendErrorObserved::class);
        self::assertCount(1, $events);
        self::assertSame(ReasonCode::UnexpectedBackendFailure->value, $events[0]->failureCode, 'a foreign exception during cleanup is classed as unexpected');
    }

    // ---- retry deferral and delay -------------------------------------------------------------------

    public function testARetryAfterBeyondTheMaximumDelayIsDeferredAndObservedAsARetryRefusal(): void
    {
        $h = new Harness(false, false, false, true);
        $h->classifier->retryAfter = Duration::seconds(30);
        $spy = DispatchSpy::throwing(new \RuntimeException('x'), $h->log);

        $completion = $h->pipeline(Harness::retry(2, 100, 10_000))->executeDetailed($spy->callable(), $h->context(Duration::seconds(120), null, Harness::replayable()));

        self::assertSame(CompletionDisposition::Deferred, $completion->disposition);
        self::assertInstanceOf(AdmissionDeferredException::class, $completion->policyCause);
        $events = $h->sink->of(AdmissionRejected::class);
        self::assertCount(1, $events);
        self::assertSame('retry', $events[0]->policyKind);
        self::assertSame(ReasonCode::RetryAfterExceedsMaximumDelay->value, $events[0]->reasonCode);
    }

    public function testNoRandomDrawIsMadeForAZeroDelay(): void
    {
        $h = new Harness(false, false, false, true);
        $spy = DispatchSpy::throwing(new \RuntimeException('x'), $h->log);

        $h->pipeline(Harness::retry(2, 0, 0, BackoffMode::Constant, JitterMode::Full))->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, Harness::replayable()));

        self::assertSame(2, $spy->dispatches);
        self::assertSame([], $h->random->ranges);
    }

    public function testARandomDrawOutsideItsRangeIsClampedAndDiagnosedButBoundaryDrawsAreNot(): void
    {
        $maximum = 100_000_000; // 100 ms constant delay
        /** @var array<string, array{0: int, 1: int, 2: bool}> $cases */
        $cases = [
            'below zero' => [-1, 0, true],
            'zero' => [0, 0, false],
            'maximum' => [$maximum, $maximum, false],
            'above maximum' => [$maximum + 1, $maximum, true],
        ];
        foreach ($cases as $name => [$drawn, $expectedDelay, $diagnosed]) {
            $h = new Harness(false, false, false, true);
            $h->random->forced = $drawn;
            $spy = DispatchSpy::of($h->log, static fn(): never => throw new \RuntimeException('x'), static fn(): string => 'ok');

            $completion = $h->pipeline(Harness::retry(2, 100, 100, BackoffMode::Constant, JitterMode::Full))
                ->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, Harness::replayable()));

            $delays = $expectedDelay === 0 ? [] : [$expectedDelay];
            self::assertSame($delays, $h->scheduler->delays, $name);
            self::assertSame($diagnosed, in_array(ReasonCode::RandomOutOfRange->value, self::reasons($completion), true), $name);
        }
    }

    public function testAClockFailureInTheSchedulerEndsTheOperationAsAnObservedClockFailure(): void
    {
        $h = new Harness(false, false, false, true);
        $failure = new ClockFailureException('scheduler clock');
        $h->scheduler->failure = $failure;
        $spy = DispatchSpy::throwing(new \RuntimeException('x'), $h->log);

        $completion = $h->pipeline(Harness::retry(2))->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, Harness::replayable()));

        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertSame($failure, $completion->policyCause);
        $events = $h->sink->of(BackendErrorObserved::class);
        self::assertCount(1, $events);
        self::assertSame('clock', $events[0]->backendKind);
        self::assertSame(ReasonCode::ClockFailure->value, $events[0]->failureCode);
    }

    public function testAnyOtherSchedulerFailureKeepsTheBusinessResultAndSaysWhy(): void
    {
        $h = new Harness(false, false, false, true);
        $h->scheduler->failure = new \RuntimeException('cannot sleep');
        $spy = DispatchSpy::throwing(new \RuntimeException('x'), $h->log);

        $completion = $h->pipeline(Harness::retry(2))->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, Harness::replayable()));

        self::assertSame(1, $spy->dispatches);
        self::assertNull($completion->policyCause);
        self::assertContains(ReasonCode::DelayFailed->value, self::reasons($completion));
    }

    // ---- clock failures degrade accounting instead of lying --------------------------------------------

    private static function failWithin(Harness $h, string $method): void
    {
        $h->clock->failWhen = static function () use ($method): bool {
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20) as $frame) {
                if ($frame['function'] === $method) {
                    return true;
                }
            }

            return false;
        };
    }

    public function testAClockFailureAtTheRetryDecisionStopsTheRetryAndMarksAccountingIncomplete(): void
    {
        $h = new Harness(false, false, false, true);
        self::failWithin($h, 'decideRetry');
        $spy = DispatchSpy::throwing(new \RuntimeException('x'), $h->log);

        $completion = $h->pipeline(Harness::retry(3))->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, Harness::replayable()));

        self::assertSame(1, $spy->dispatches);
        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertContains(ReasonCode::ClockUnavailable->value, self::reasons($completion));
        self::assertFalse($completion->summary->accountingComplete);
    }

    public function testAClockFailureBeforeTheFallbackSkipsItAndMarksAccountingIncomplete(): void
    {
        $h = new Harness();
        self::failWithin($h, 'finishBusiness');
        $fallback = new ClosureFallback(static fn(): string => 'fallback');

        $completion = $h->pipeline()->executeDetailed(DispatchSpy::throwing(new \RuntimeException('x'))->callable(), $h->context(Duration::seconds(60), $fallback));

        self::assertSame(0, $fallback->calls);
        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertContains(ReasonCode::ClockUnavailable->value, self::reasons($completion));
        self::assertFalse($completion->summary->accountingComplete);
    }

    public function testAClockFailureAfterTheFallbackKeepsItsResultButNeverReportsItOnTime(): void
    {
        $h = new Harness();
        self::failWithin($h, 'runFallback');
        $fallback = new ClosureFallback(static fn(): string => 'fallback');

        $completion = $h->pipeline()->executeDetailed(DispatchSpy::throwing(new \RuntimeException('x'))->callable(), $h->context(Duration::seconds(60), $fallback));

        self::assertSame(1, $fallback->calls);
        self::assertSame(CompletionDisposition::FallbackSucceeded, $completion->disposition);
        self::assertContains(ReasonCode::TimingUnavailable->value, self::reasons($completion));
        self::assertFalse($completion->summary->accountingComplete);
        self::assertNull($completion->summary->elapsed, 'the overall elapsed time is unavailable once fallback timing is');
    }

    public function testAClockFailureWhileSummarisingLeavesElapsedUnavailableAndAccountingIncomplete(): void
    {
        $h = new Harness();
        self::failWithin($h, 'complete');

        $completion = $h->pipeline()->executeDetailed(DispatchSpy::returning('ok')->callable(), $h->context(Duration::seconds(60)));

        self::assertSame(CompletionDisposition::Succeeded, $completion->disposition);
        self::assertNull($completion->summary->elapsed);
        self::assertFalse($completion->summary->accountingComplete);
        self::assertContains(ReasonCode::ElapsedUnavailable->value, self::reasons($completion));
    }

    public function testTheSummaryReportsExactlyTheElapsedTimeBetweenStartAndEnd(): void
    {
        $h = new Harness();
        $completion = $h->pipeline()->executeDetailed(
            static function () use ($h): string {
                $h->clock->advance(7_000_000);

                return 'ok';
            },
            $h->context(Duration::seconds(60)),
        );

        self::assertNotNull($completion->summary->elapsed);
        self::assertSame(7_000_000, $completion->summary->elapsed->nanoseconds);
        self::assertTrue($completion->summary->accountingComplete);
    }

    public function testAMonotonicReadingOfZeroIsValid(): void
    {
        $h = new Harness();
        $h->clock->now = 0;

        $completion = $h->pipeline()->executeDetailed(DispatchSpy::returning('ok')->callable(), $h->context(Duration::seconds(60)));

        self::assertSame(CompletionDisposition::Succeeded, $completion->disposition);
        self::assertTrue($completion->summary->accountingComplete);
        self::assertSame(0, $completion->summary->elapsed?->nanoseconds);
    }

    public function testASuccessfulFirstAttemptNeedsNoSafetyAssertionEvenWithoutAContext(): void
    {
        $h = new Harness();
        $completion = $h->pipeline(Harness::retry(1))->executeDetailed(DispatchSpy::returning('ok')->callable(), $h->context(Duration::seconds(60)));

        self::assertSame(CompletionDisposition::Succeeded, $completion->disposition);
        self::assertSame(1, $completion->summary->dispatched);
        self::assertSame(0, $completion->summary->retries);
        self::assertSame(Fixtures::partition()->key(), Fixtures::partition()->key());
    }
}
