<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Pipeline\Internal;

use Enaxon\Reqtide\Admission\AdmissionDecision;
use Enaxon\Reqtide\Admission\AdmissionStatus;
use Enaxon\Reqtide\Completion\ActualAttemptDisposition;
use Enaxon\Reqtide\Completion\AttemptRecord;
use Enaxon\Reqtide\Completion\CompletionDisposition;
use Enaxon\Reqtide\Completion\ExecutionCompletion;
use Enaxon\Reqtide\Completion\ExecutionSummary;
use Enaxon\Reqtide\Completion\SettlementOverride;
use Enaxon\Reqtide\Error\ReasonCode;
use Enaxon\Reqtide\Error\Stage;
use Enaxon\Reqtide\Exception\AdmissionDeferredException;
use Enaxon\Reqtide\Exception\AdmissionRejectedException;
use Enaxon\Reqtide\Exception\ClassificationFailedException;
use Enaxon\Reqtide\Exception\ClockFailureException;
use Enaxon\Reqtide\Exception\CorruptStateException;
use Enaxon\Reqtide\Exception\DeadlineExceededException;
use Enaxon\Reqtide\Exception\ImpossibleAdmissionException;
use Enaxon\Reqtide\Exception\IndeterminateStateException;
use Enaxon\Reqtide\Exception\InfrastructureException;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Exception\OperationCancelledException;
use Enaxon\Reqtide\Exception\PolicyTerminationException;
use Enaxon\Reqtide\Exception\PreparationFailedException;
use Enaxon\Reqtide\Exception\ReqtideException;
use Enaxon\Reqtide\Exception\StateCapacityExceededException;
use Enaxon\Reqtide\Exception\StateUnavailableException;
use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Execution\BoundedMetadata;
use Enaxon\Reqtide\Execution\ExecutionContext;
use Enaxon\Reqtide\Execution\OperationSafetyKind;
use Enaxon\Reqtide\Execution\ReplayPreparation;
use Enaxon\Reqtide\Fallback\FallbackOperation;
use Enaxon\Reqtide\Fallback\FallbackScope;
use Enaxon\Reqtide\Internal\Identity\IdentityGenerator;
use Enaxon\Reqtide\Outcome\Acceptability;
use Enaxon\Reqtide\Outcome\CircuitVerdict;
use Enaxon\Reqtide\Outcome\FallbackEligibility;
use Enaxon\Reqtide\Outcome\Outcome;
use Enaxon\Reqtide\Outcome\OutcomeKind;
use Enaxon\Reqtide\Outcome\RetryEligibility;
use Enaxon\Reqtide\Pipeline\PipelineDefinition;
use Enaxon\Reqtide\Pipeline\RuntimeEnvironment;
use Enaxon\Reqtide\Policy\BackoffMode;
use Enaxon\Reqtide\Policy\JitterMode;
use Enaxon\Reqtide\Runtime\AttemptControls;
use Enaxon\Reqtide\Runtime\AttemptOperation;
use Enaxon\Reqtide\Runtime\PreparedAttempt;
use Enaxon\Reqtide\Runtime\SettlementObservation;
use Enaxon\Reqtide\State\CircuitAdmissionStatus;
use Enaxon\Reqtide\State\CircuitPermitKind;
use Enaxon\Reqtide\State\CircuitTransition;
use Enaxon\Reqtide\State\ConcurrencyAdmissionStatus;
use Enaxon\Reqtide\State\CooldownStatus;
use Enaxon\Reqtide\State\FinalizationStatus;
use Enaxon\Reqtide\State\RetryReservationStatus;
use Enaxon\Reqtide\Telemetry\AccountingDegraded;
use Enaxon\Reqtide\Telemetry\AdmissionRejected;
use Enaxon\Reqtide\Telemetry\AttemptCompleted;
use Enaxon\Reqtide\Telemetry\BackendErrorObserved;
use Enaxon\Reqtide\Telemetry\CircuitTransitioned;
use Enaxon\Reqtide\Telemetry\Event;
use Enaxon\Reqtide\Telemetry\OperationCompleted;
use Enaxon\Reqtide\Telemetry\RetryScheduled;
use Enaxon\Reqtide\Time\DelayDisposition;
use Enaxon\Reqtide\Value\AttemptId;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\PartitionIdentity;

/**
 * All mutable state of ONE logical execution. It is created per call by CompiledPipeline and discarded
 * afterwards; nothing here is ever stored on the reusable pipeline.
 *
 * Logical execution (this class) and physical attempts (HeldOwnership + Settled) are kept separate. No state
 * lock is ever held across user work: every backend command is a single atomic call that returns before the
 * callback, fallback, observer or delay runs.
 *
 * @internal
 * @template T
 */
final class OperationRun
{
    private DiagnosticLog $log;

    /** @var list<AttemptRecord<T>> */
    private array $attempts = [];

    private int $admittedNotDispatched = 0;

    /** Indeterminate rate debits: possibly consumed, never claimed as known. */
    private int $admissionUncertain = 0;

    /** A needed timing read failed after the fact; the overall elapsed time is reported as unavailable. */
    private bool $elapsedUnavailable = false;

    private int $fallbackExecutions = 0;

    private bool $accountingComplete = true;

    /** Uncertain ownership/accounting, unavailable settlement timing or failed cooldown extension. */
    private bool $blockFurther = false;

    private bool $retryDenied = false;

    private ?int $startNanoseconds = null;

    /** @var Settled<T>|null */
    private ?Settled $pendingDiscard = null;

    /**
     * @param AttemptOperation<T> $operation
     * @param ExecutionContext<T> $context
     */
    public function __construct(
        private readonly PipelineDefinition $definition,
        private readonly RuntimeEnvironment $env,
        private readonly IdentityGenerator $ids,
        private readonly AttemptOperation $operation,
        private readonly ExecutionContext $context,
        private readonly bool $genericCallable,
    ) {
        $this->log = new DiagnosticLog();
    }

    /**
     * @return ExecutionCompletion<T>
     * @throws InvalidConfigurationException before any admission, state mutation or dispatch
     */
    public function run(): ExecutionCompletion
    {
        $this->validateConfiguration();

        try {
            $this->startNanoseconds = $this->readClock();
            $this->captureReplayHorizon();
        } catch (ClockFailureException $failure) {
            $this->backendFailure('clock', $failure);

            return $this->complete(CompletionDisposition::Failed, $failure, null, null);
        }

        $number = 1;
        while (true) {
            $result = $this->attempt($number);
            if ($result->termination !== null) {
                if ($result->dispatchedRecord !== null) {
                    /** @var AttemptRecord<T> $record */
                    $record = $result->dispatchedRecord;
                    $this->attempts[] = $record;
                    $this->discardPrevious();
                }
                if (self::isAdmissionRefusal($result->termination)) {
                    return $this->finishRefusal($result->termination);
                }

                return $this->completeWithCause($result->termination);
            }
            /** @var Settled<T>|null $settled */
            $settled = $result->settled;
            if ($settled === null) {
                // Retry declined before dispatch: the previous business result stays the logical result.
                return $this->finishBusiness($this->lastSettled());
            }

            $this->attempts[] = $settled->record;
            $this->discardPrevious();
            $this->pendingDiscard = $settled;

            if ($settled->override === SettlementOverride::Cancelled) {
                return $this->completeWithCause(new OperationCancelledException('The operation was cancelled before its attempt settled.'));
            }
            if ($settled->override === SettlementOverride::DeadlineExceeded) {
                return $this->completeWithCause(new DeadlineExceededException('The attempt settled at or after the operation deadline.'));
            }
            if ($settled->classifierFailure !== null) {
                return $this->complete(CompletionDisposition::Failed, null, $settled->classifierFailure, null);
            }

            $decision = $this->decideRetry($settled);
            if ($decision instanceof ReqtideException) {
                return $this->completeWithCause($decision);
            }
            if ($decision === null) {
                return $this->finishBusiness($settled);
            }

            $wait = $this->waitForRetry($decision);
            if ($wait instanceof ReqtideException) {
                return $this->completeWithCause($wait);
            }
            if ($wait === false) {
                return $this->finishBusiness($settled);
            }
            ++$number;
        }
    }

    // ---------------------------------------------------------------- Stage 0/1: configuration and preflight

    private function validateConfiguration(): void
    {
        $safety = $this->context->metadata->safety;
        if ($this->definition->retry->maxAttempts->value > 1) {
            if ($safety->kind === OperationSafetyKind::Unsafe || $safety->contract === null) {
                throw new InvalidConfigurationException('maxAttempts greater than one requires an explicit replay-safety contract; the operation is Unsafe.');
            }
            if ($this->genericCallable && $safety->contract->preparation !== ReplayPreparation::CallableAssertion) {
                throw new InvalidConfigurationException('A generic callable can only be retried under a CallableAssertion replay contract.');
            }
        }

        $partition = $this->context->partition;
        foreach ([$this->definition->rateLimit, $this->definition->circuit, $this->definition->concurrency, $this->definition->retryBudget, $this->definition->cooldown] as $policy) {
            if ($policy !== null && !$this->samePartition($policy->identity->partition, $partition)) {
                throw new InvalidConfigurationException('The execution context partition must equal every selected policy partition.');
            }
        }

        // Rejects a Deadline created from another Clock instance before any mutation; a clock failure is
        // handled by the lifecycle itself.
        try {
            $this->context->deadline->remaining($this->env->clock);
        } catch (ClockFailureException) {
        }
    }

    private function samePartition(PartitionIdentity $a, PartitionIdentity $b): bool
    {
        return $a->equals($b);
    }

    /**
     * Converts the external replay-contract expiry once into a captured monotonic horizon. An expired or
     * inadequate contract denies retry; it never silently extends it.
     *
     * @throws ClockFailureException
     */
    private function captureReplayHorizon(): void
    {
        if ($this->definition->retry->maxAttempts->value <= 1) {
            return;
        }
        $contract = $this->context->metadata->safety->contract;
        if ($contract === null) {
            $this->retryDenied = true;

            return;
        }
        $wall = $this->env->clock->wallNow();
        if ($contract->establishedAt > $wall) {
            $this->denyRetry(ReasonCode::ReplayContractNotValid);

            return;
        }
        $untilNanoseconds = $this->nanosecondsUntil($contract->validUntil, $wall);
        if ($untilNanoseconds !== null && $untilNanoseconds <= 0) {
            $this->denyRetry(ReasonCode::ReplayContractNotValid);

            return;
        }
        if ($untilNanoseconds === null) {
            // validUntil lies beyond the representable monotonic domain: the horizon saturates explicitly.
            $this->log->add(Stage::Preflight, ReasonCode::ReplayHorizonSaturated);
            $untilNanoseconds = PHP_INT_MAX;
        }
        $start = $this->startNanoseconds ?? 0;
        $horizon = $start > PHP_INT_MAX - $untilNanoseconds ? PHP_INT_MAX : $start + $untilNanoseconds;
        $remaining = $this->context->deadline->remaining($this->env->clock);
        if ($horizon < $this->context->deadline->monotonicNanoseconds || $contract->retention->compare($remaining) < 0) {
            $this->denyRetry(ReasonCode::ReplayHorizonInadequate);
        }
    }

    /**
     * Exact signed nanoseconds from $now to $until using checked integer arithmetic only (no float ever
     * participates). Returns null when the span is positive but not representable as a PHP integer
     * nanosecond count; a span of two or more seconds in the past is reported as -1 (always expired).
     */
    private function nanosecondsUntil(\DateTimeImmutable $until, \DateTimeImmutable $now): ?int
    {
        $a = $until->getTimestamp();
        $b = $now->getTimestamp();
        if ($b < 0 && $a > PHP_INT_MAX + $b) {
            return null;
        }
        if ($b > 0 && $a < PHP_INT_MIN + $b) {
            return -1;
        }
        $seconds = $a - $b;
        $micros = (int) $until->format('u') - (int) $now->format('u');
        if ($seconds <= -2) {
            return -1;
        }
        if ($seconds >= intdiv(PHP_INT_MAX, 1_000_000_000) - 1) {
            return null;
        }

        return $seconds * 1_000_000_000 + $micros * 1_000;
    }

    private function denyRetry(ReasonCode $reason): void
    {
        $this->retryDenied = true;
        $this->log->add(Stage::Preflight, $reason);
    }

    // ---------------------------------------------------------------- Stages 2-9: one candidate attempt

    /** Settled payloads are always Settled<T>. */
    private function attempt(int $number): AttemptResult
    {
        $held = new HeldOwnership();
        $attemptId = $this->ids->attempt();
        $phase = Stage::Preparation;

        try {
            $remaining = $this->checkpointRemaining();
            $prepareContext = $this->attemptContext($attemptId, $number, $remaining);
            $prepared = $this->prepare($prepareContext);

            if ($number > 1) {
                // Preparation may have used part of the allowance: read the time again before reserving a token.
                $this->requireAllowance($this->checkpointRemaining());
                $phase = Stage::RetryBudget;
                $this->reserveRetry($held, $attemptId);
            }
            $phase = Stage::Admission;
            $this->acquireCircuit($held, $attemptId);
            $this->acquireConcurrency($held);
            $this->admitCooldownAndRate($held);

            // Stage 7: final pre-dispatch barrier.
            $phase = Stage::Barrier;
            $this->verifyReplay($prepared, $prepareContext);
            $remaining = $this->checkpointRemaining();
            if ($number > 1) {
                $this->requireAllowance($remaining);
                $this->commitRetry($held);
                // The commit may call a backend: the last check before dispatch follows it. Nothing else runs
                // before the dispatch; the outcome this retry replaces is disposed in run() once the retry was
                // dispatched, so a refused or never-started retry leaves it open as the terminal outcome.
                $remaining = $this->checkpointRemaining();
                $this->requireAllowance($remaining);
            }

            $this->requireOwnershipFor($held, $this->definition->attempt->maximumDuration->min($remaining));

            // Stage 8/9: dispatch and settlement observation.
            $phase = Stage::Dispatch;
            $dispatchContext = $this->attemptContext($attemptId, $number, $remaining);
            $controls = new AttemptControls($dispatchContext->attemptTimeout, $this->context->deadline, $this->context->cancellation, $this->env->clock);
            try {
                $execution = $this->env->runtime->invoke($prepared, $dispatchContext, $controls);
            } catch (ClockFailureException $failure) {
                // Adapter contract: an unreadable start time means the operation was never invoked.
                throw $failure;
            } catch (\Throwable $failure) {
                // Invocation had begun: the attempt is a dispatch even though no settlement was observed.
                return $this->failDispatched($held, $attemptId, $number, $failure);
            }
            try {
                /** @var SettlementObservation<T> $observation */
                $observation = $execution->awaitObservedSettlement($controls);
            } catch (\Throwable $failure) {
                return $this->failDispatched($held, $attemptId, $number, $failure);
            }
        } catch (RetryDeclined) {
            $this->cleanupBeforeDispatch($held);

            return AttemptResult::declined();
        } catch (\Throwable $failure) {
            $this->cleanupBeforeDispatch($held);

            return AttemptResult::terminated($this->toTermination($failure, $phase));
        }

        return AttemptResult::settled($this->finalizeAttempt($held, $prepared, $attemptId, $number, $observation));
    }

    /**
     * Deadline is read before cancellation; equality is expiry. Returns the strictly positive remaining time.
     *
     * @throws DeadlineExceededException|OperationCancelledException|ClockFailureException
     */
    private function checkpointRemaining(): Duration
    {
        try {
            $remaining = $this->context->deadline->remaining($this->env->clock);
        } catch (ClockFailureException $failure) {
            throw $failure;
        } catch (\Throwable $failure) {
            // A clock that throws something else is unreadable all the same.
            throw new ClockFailureException('The clock could not be read.', 0, $failure);
        }
        if ($remaining->isZero()) {
            throw new DeadlineExceededException('The operation deadline has been reached.');
        }
        if ($this->cancellationRequested()) {
            throw new OperationCancelledException('The operation was cancelled.');
        }

        return $remaining;
    }

    /** A cancellation that cannot be sampled is not a cancellation: the established result stays, with a diagnostic. */
    private function cancellationRequested(): bool
    {
        try {
            return $this->context->cancellation->isRequested();
        } catch (\Throwable) {
            $this->log->add(Stage::Settlement, ReasonCode::CancellationSampleFailed);
            $this->markIncomplete(ReasonCode::CancellationSampleFailed);

            return false;
        }
    }

    /** @throws RetryDeclined when less than the configured minimum allowance is left for this retry */
    private function requireAllowance(Duration $remaining): void
    {
        if ($remaining->compare($this->definition->retry->minimumRemainingAttemptAllowance) < 0) {
            $this->log->add(Stage::Retry, ReasonCode::AttemptAllowanceUnavailable);
            throw new RetryDeclined('The minimum remaining attempt allowance is no longer available.');
        }
    }

    private function attemptContext(AttemptId $attemptId, int $number, Duration $remaining): AttemptContext
    {
        return new AttemptContext(
            $this->context->operationId,
            $attemptId,
            $number,
            $this->context->deadline,
            $this->context->cancellation,
            $this->definition->attempt->maximumDuration->min($remaining),
        );
    }

    /** @return PreparedAttempt<T> */
    private function prepare(AttemptContext $context): PreparedAttempt
    {
        try {
            return $this->operation->prepare($context);
        } catch (PreparationFailedException $failure) {
            throw $failure;
        } catch (\Throwable $failure) {
            throw new PreparationFailedException($failure);
        }
    }

    /** @param PreparedAttempt<T> $prepared */
    private function verifyReplay(PreparedAttempt $prepared, AttemptContext $context): void
    {
        try {
            $prepared->verifyReplay($context);
        } catch (PreparationFailedException $failure) {
            throw $failure;
        } catch (\Throwable $failure) {
            throw new PreparationFailedException($failure);
        }
    }

    // ---------------------------------------------------------------- Stage 3: retry budget

    private function reserveRetry(HeldOwnership $held, AttemptId $attemptId): void
    {
        $backend = $this->env->retryBudget;
        $policy = $this->definition->retryBudget;
        if ($backend === null || $policy === null) {
            return;
        }
        try {
            $decision = $backend->reserve($policy, $attemptId, $this->ids->owner(), $this->ids->command());
        } catch (\Throwable $raw) {
            // A reservation that may exist has no handle here: nothing to release, its lease expiry covers it.
            $held->pendingReservation = null;
            $failure = $this->infrastructureFailure($raw);
            $this->backendFailure('retry_budget', $failure);
            throw $failure;
        }
        if ($decision->status !== RetryReservationStatus::Reserved) {
            $this->recordBudgetExhausted();
            throw new RetryDeclined('The retry budget declined the reservation.');
        }
        $held->pendingReservation = $decision->reservation;
    }

    /** The retry policy's budget refused the retry (before or after the delay); the business result is kept. */
    private function recordBudgetExhausted(): void
    {
        $this->emit(fn(): Event => new AdmissionRejected($this->context->operationId, $this->definition->profileVersion, 'retry_budget', ReasonCode::RetryBudgetExhausted->value));
        $this->log->add(Stage::Retry, ReasonCode::RetryBudgetExhausted);
    }

    /**
     * Advisory, non-mutating budget check made BEFORE the retry delay. It never reserves: the
     * authoritative reservation still happens after the delay in Stage 3 of the next attempt. A failed read is
     * an infrastructure termination, exactly like a failed reservation.
     *
     * A budget that is empty now but expected to refill no later than the end of the delay (`retryAfter` not
     * longer than `$delay`) still has headroom: stopping would discard a retry the authoritative reservation
     * would grant. Without that hint, or with a longer one, the retry stops before sleeping.
     *
     * @return bool|InfrastructureException true when the budget would grant a token by the end of the delay, or
     *                                      no budget is installed
     */
    private function retryBudgetHasHeadroom(Duration $delay): bool|InfrastructureException
    {
        $backend = $this->env->retryBudget;
        $policy = $this->definition->retryBudget;
        if ($backend === null || $policy === null) {
            return true;
        }
        try {
            $inspection = $backend->inspect($policy);
        } catch (\Throwable $raw) {
            $failure = $this->infrastructureFailure($raw);
            $this->backendFailure('retry_budget', $failure);

            return $failure;
        }

        return $inspection->wouldReserve
            || ($inspection->retryAfter !== null && $inspection->retryAfter->compare($delay) <= 0);
    }

    private function commitRetry(HeldOwnership $held): void
    {
        $backend = $this->env->retryBudget;
        $policy = $this->definition->retryBudget;
        $reservation = $held->pendingReservation;
        if ($backend === null || $policy === null || $reservation === null) {
            return;
        }
        try {
            $result = $backend->commit($policy, $reservation, $this->ids->command());
        } catch (\Throwable $raw) {
            // Any failure of commit (Reqtide or foreign): the reservation may already be spent. Never release
            // or refund it.
            $held->pendingReservation = null;
            $failure = $this->infrastructureFailure($raw);
            $this->backendFailure('retry_budget', $failure);
            $this->log->add(Stage::RetryBudget, ReasonCode::CommitNotConfirmed);
            throw $failure;
        }
        $held->pendingReservation = null;
        if (!$result->authorizesDispatch()) {
            $this->log->add(Stage::Retry, ReasonCode::RetryBudgetCommitRefused);
            throw new RetryDeclined('The retry budget commit did not authorize a dispatch.');
        }
    }

    // ---------------------------------------------------------------- Stages 4-6: admission

    private function acquireCircuit(HeldOwnership $held, AttemptId $attemptId): void
    {
        $backend = $this->env->circuit;
        $policy = $this->definition->circuit;
        if ($backend === null || $policy === null) {
            return;
        }
        $requestedAt = $this->readClockBeforeRequest();
        try {
            $admission = $backend->acquire($policy, $attemptId, $this->ids->owner(), $this->ids->command());
        } catch (\Throwable $raw) {
            $failure = $this->infrastructureFailure($raw);
            $this->backendFailure('circuit', $failure);
            throw $failure;
        }
        $this->observeTransition($admission->transition);
        if ($admission->status !== CircuitAdmissionStatus::Accepted) {
            $reason = $admission->status === CircuitAdmissionStatus::Open ? ReasonCode::CircuitOpen : ReasonCode::CircuitProbeCapacityExhausted;
            $wait = $admission->retryAfter !== null && $admission->retryAfter->isPositive() ? $admission->retryAfter : null;
            $this->emit(fn(): Event => new AdmissionRejected($this->context->operationId, $this->definition->profileVersion, 'circuit', $reason->value));
            throw new AdmissionRejectedException(new AdmissionDecision(AdmissionStatus::Rejected, $reason->value, null, $wait), $this->lastBusinessException());
        }
        $held->circuit = $admission->permit;
        $held->circuitRequestedAt = $requestedAt;
    }

    private function acquireConcurrency(HeldOwnership $held): void
    {
        $backend = $this->env->concurrency;
        $policy = $this->definition->concurrency;
        if ($backend === null || $policy === null) {
            return;
        }
        $requestedAt = $this->readClockBeforeRequest();
        try {
            $admission = $backend->acquire($policy, $this->ids->owner(), $this->ids->command());
        } catch (\Throwable $raw) {
            $failure = $this->infrastructureFailure($raw);
            $this->backendFailure('concurrency', $failure);
            throw $failure;
        }
        if ($admission->status !== ConcurrencyAdmissionStatus::Accepted) {
            $reason = ReasonCode::ConcurrencyCapacityExhausted;
            $this->emit(fn(): Event => new AdmissionRejected($this->context->operationId, $this->definition->profileVersion, 'concurrency', $reason->value));
            throw new AdmissionRejectedException(new AdmissionDecision(AdmissionStatus::Rejected, $reason->value, null, null), $this->lastBusinessException());
        }
        $held->concurrency = $admission->permit;
        $held->concurrencyRequestedAt = $requestedAt;
    }

    /**
     * The monotonic reading taken before a permit is requested. The backend starts the lease at some moment after the
     * request was sent, never before, so a lease measured from this reading is never overestimated.
     *
     * @throws ClockFailureException
     */
    private function readClockBeforeRequest(): int
    {
        try {
            return $this->readClock();
        } catch (ClockFailureException $failure) {
            throw $failure;
        } catch (\Throwable $failure) {
            throw new ClockFailureException('The clock could not be read.', 0, $failure);
        }
    }

    /**
     * Last local check before dispatch: a permit that limits concurrent work (the concurrency permit and a half-open probe)
     * must still be valid for the whole attempt, with the clock resolution of its backend as the smallest margin. A permit
     * that would not outlast the attempt may already have been handed to another worker, so the attempt is refused and
     * everything it holds is released. The check is local and conservative; it is not a fencing token, and a pause after
     * it cannot be seen. A closed-circuit sample limits nothing and is not checked.
     *
     * @throws AdmissionRejectedException
     * @throws ClockFailureException
     */
    private function requireOwnershipFor(HeldOwnership $held, Duration $attemptBudget): void
    {
        $checks = [];
        if ($held->concurrency !== null && $held->concurrencyRequestedAt !== null && $this->env->concurrency !== null) {
            $checks[] = ['concurrency', $held->concurrency->lease, $held->concurrencyRequestedAt, $this->env->concurrency->capabilities()->clockResolution];
        }
        if ($held->circuit !== null && $held->circuit->kind === CircuitPermitKind::HalfOpenProbe && $held->circuitRequestedAt !== null && $this->env->circuit !== null) {
            $checks[] = ['circuit', $held->circuit->lease, $held->circuitRequestedAt, $this->env->circuit->capabilities()->clockResolution];
        }
        if ($checks === []) {
            return;
        }
        $now = $this->readClock();
        foreach ($checks as [$kind, $lease, $requestedAt, $resolution]) {
            $left = $lease->nanoseconds - max(0, $now - $requestedAt);
            if ($left > $attemptBudget->nanoseconds && $left - $attemptBudget->nanoseconds > $resolution->nanoseconds) {
                continue;
            }
            $reason = ReasonCode::OwnershipLeaseInsufficient;
            $this->emit(fn(): Event => new AdmissionRejected($this->context->operationId, $this->definition->profileVersion, $kind, $reason->value));
            throw new AdmissionRejectedException(new AdmissionDecision(AdmissionStatus::Rejected, $reason->value, null, null), $this->lastBusinessException());
        }
    }

    private function admitCooldownAndRate(HeldOwnership $held): void
    {
        $cooldownBackend = $this->env->cooldown;
        $cooldownPolicy = $this->definition->cooldown;
        if ($cooldownBackend !== null && $cooldownPolicy !== null) {
            try {
                $cooldown = $cooldownBackend->inspect($cooldownPolicy);
            } catch (\Throwable $raw) {
                $failure = $this->infrastructureFailure($raw);
                $this->backendFailure('cooldown', $failure);
                throw $failure;
            }
            if ($cooldown->status === CooldownStatus::Active) {
                // Initial mode is immediate deferral, always `Deferred`: no permit is held while waiting and no
                // work is enqueued. The reason only says whether the wait could ever fit this deadline.
                $reason = $this->fitsRemaining($cooldown->remaining, $this->context->deadline->remaining($this->env->clock))
                    ? ReasonCode::CooldownActive
                    : ReasonCode::CooldownExceedsDeadline;
                $this->emit(fn(): Event => new AdmissionRejected($this->context->operationId, $this->definition->profileVersion, 'cooldown', $reason->value));
                throw new AdmissionDeferredException(new AdmissionDecision(AdmissionStatus::Deferred, $reason->value, null, $cooldown->remaining), $this->lastBusinessException());
            }
        }

        $backend = $this->env->rateLimit;
        $policy = $this->definition->rateLimit;
        $cost = $this->definition->rateCost;
        if ($backend === null || $policy === null || $cost === null) {
            return;
        }
        try {
            $decision = $backend->consume($policy, $cost, $this->ids->command());
        } catch (\Throwable $raw) {
            // A lost reply may or may not have debited; the debit is not claimed and accounting is incomplete.
            $failure = $this->infrastructureFailure($raw);
            $this->backendFailure('rate_limit', $failure);
            if ($failure instanceof IndeterminateStateException) {
                ++$this->admissionUncertain;
                $this->markIncomplete(ReasonCode::RateConsumeIndeterminate);
            }
            throw $failure;
        }
        if ($decision->status === AdmissionStatus::Accepted) {
            $held->rateDebited = true;

            return;
        }
        $this->emit(fn(): Event => new AdmissionRejected($this->context->operationId, $this->definition->profileVersion, 'rate_limit', $decision->reasonCode));
        $previous = $this->lastBusinessException();
        throw match ($decision->status) {
            AdmissionStatus::Deferred => new AdmissionDeferredException($decision, $previous),
            AdmissionStatus::Impossible => new ImpossibleAdmissionException($decision, $previous),
            default => new AdmissionRejectedException($decision, $previous),
        };
    }

    /** The smallest time a further attempt needs after a wait: the configured allowance, and never less than 1 ns. */
    private function attemptNeed(): Duration
    {
        $allowance = $this->definition->retry->minimumRemainingAttemptAllowance;

        return $allowance->isZero() ? new Duration(1) : $allowance;
    }

    /** True iff `wait + max(allowance, 1ns) < remaining` (deadline equality is expiry). */
    private function fitsRemaining(Duration $wait, Duration $remaining): bool
    {
        $need = $this->attemptNeed();

        return $remaining->compare($need) > 0 && $wait->compare($remaining->subtract($need)) < 0;
    }

    /** The business exception of the last dispatched attempt, if it ended in one. */
    private function lastBusinessException(): ?\Throwable
    {
        if ($this->attempts === []) {
            return null;
        }
        $outcome = $this->attempts[count($this->attempts) - 1]->outcome;

        return $outcome->kind === OutcomeKind::Exception ? $outcome->exception() : null;
    }

    /**
     * Maps any backend or adapter Throwable to an infrastructure failure. A foreign exception may have been
     * thrown after the state mutation committed, so it is Indeterminate; its message is never copied.
     */
    private function infrastructureFailure(\Throwable $failure): InfrastructureException
    {
        if ($failure instanceof InfrastructureException) {
            return $failure;
        }

        return new IndeterminateStateException('A state backend or runtime failed with an unexpected exception.', 0, $failure);
    }

    /** Independent, idempotent cleanup of everything an attempt owns when it ends without dispatch. */
    private function cleanupBeforeDispatch(HeldOwnership $held): void
    {
        $reservation = $held->pendingReservation;
        if ($reservation !== null && $this->env->retryBudget !== null && $this->definition->retryBudget !== null) {
            // Only a definitely pending reservation is released; its lease expiry covers a failed release.
            try {
                $this->env->retryBudget->release($this->definition->retryBudget, $reservation, $this->ids->command());
            } catch (\Throwable $failure) {
                $this->backendFailure('retry_budget', $failure);
                $this->log->add(Stage::Cleanup, ReasonCode::RetryReservationReleaseFailed);
                $this->markIncomplete(ReasonCode::RetryReservationReleaseFailed);
            }
            $held->pendingReservation = null;
        }
        $this->abandonCircuit($held, Stage::Cleanup);
        $this->releaseConcurrency($held, Stage::Cleanup);
        if ($held->rateDebited) {
            // Quota stays consumed: never refunded, and send-count equality is never implied.
            ++$this->admittedNotDispatched;
            $held->rateDebited = false;
        }
    }

    // ---------------------------------------------------------------- Stage 10: independent finalizers

    /**
     * @param PreparedAttempt<T> $prepared
     * @param SettlementObservation<T> $observation
     * @return Settled<T>
     */
    private function finalizeAttempt(HeldOwnership $held, PreparedAttempt $prepared, AttemptId $attemptId, int $number, SettlementObservation $observation): Settled
    {
        $override = $observation->logicalOverride;
        $timingUnavailable = $observation->observedMonotonicNanoseconds === null;
        foreach ($observation->diagnostics as $diagnostic) {
            $this->log->addDiagnostic($diagnostic);
        }
        if (!$observation->accountingComplete) {
            $this->markIncomplete(ReasonCode::SettlementAccounting);
        }
        if ($timingUnavailable) {
            $this->blockFurther = true;
        }

        // 1. Release concurrency.
        $this->releaseConcurrency($held, Stage::Finalize);

        // 2. Classify, unless a cancellation/deadline cause already owns the disposition.
        $classification = null;
        $classifierFailure = null;
        if ($override === SettlementOverride::None) {
            try {
                $classification = $this->env->classifier->classify($observation->outcome, $this->context->metadata);
            } catch (\Throwable $failure) {
                $classifierFailure = new ClassificationFailedException($observation->outcome, $failure);
                $this->log->add(Stage::Classification, ReasonCode::ClassifierFailed);
            }
        } else {
            // The late or cancelled outcome is never classified as a dependency result, but a Retry-After it
            // carries describes shared server state: read it observe-only and extend the cooldown.
            $this->extractLateSignal($observation->outcome);
        }

        // 3. Record the verdict or abandon neutrally. The attempt record only claims a verdict the backend
        // definitely applied: a stale or failed record() leaves the applied verdict Neutral.
        $forcedNeutral = $override !== SettlementOverride::None || $timingUnavailable || $classification === null;
        $applied = $forcedNeutral || $classification === null ? CircuitVerdict::Neutral : $classification->circuit;
        if ($forcedNeutral) {
            $this->abandonCircuit($held, Stage::Finalize);
        } elseif (!$this->recordCircuit($held, $applied)) {
            $applied = CircuitVerdict::Neutral;
        }

        // 5. Attempt accounting.
        $disposition = match ($classification?->circuit) {
            CircuitVerdict::Success => ActualAttemptDisposition::Succeeded,
            CircuitVerdict::Failure => ActualAttemptDisposition::Failed,
            CircuitVerdict::Neutral, null => ActualAttemptDisposition::Neutral,
        };
        $record = new AttemptRecord(
            $attemptId,
            $number,
            $disposition,
            $observation->outcome,
            $observation->elapsed,
            $override,
            $classification,
            $applied,
        );
        $this->emit(fn(): Event => new AttemptCompleted($this->context->operationId, $this->definition->profileVersion, $attemptId, $applied, $observation->elapsed));

        return new Settled($record, $prepared, $classification, $classifierFailure, $override);
    }

    private function releaseConcurrency(HeldOwnership $held, Stage $stage): void
    {
        $permit = $held->concurrency;
        if ($permit === null || $this->env->concurrency === null || $this->definition->concurrency === null) {
            return;
        }
        try {
            $result = $this->env->concurrency->release($this->definition->concurrency, $permit, $this->ids->command());
            $held->concurrency = null;
            if ($result->status === FinalizationStatus::StaleOwnership) {
                // The lease had already ended: the ownership accounting of this attempt cannot be trusted.
                $this->log->add($stage, ReasonCode::ConcurrencyReleaseStale);
                $this->markIncomplete(ReasonCode::ConcurrencyReleaseStale);
            }
        } catch (\Throwable $failure) {
            $this->backendFailure('concurrency', $failure);
            $this->log->add($stage, ReasonCode::ConcurrencyReleaseFailed);
            $this->uncertain(ReasonCode::ConcurrencyReleaseFailed);
        }
    }

    private function abandonCircuit(HeldOwnership $held, Stage $stage): void
    {
        $permit = $held->circuit;
        if ($permit === null || $this->env->circuit === null || $this->definition->circuit === null) {
            return;
        }
        try {
            $result = $this->env->circuit->abandon($this->definition->circuit, $permit, $this->ids->command());
            $this->observeTransition($result->transition);
            $held->circuit = null;
            if ($result->status === FinalizationStatus::StaleOwnership) {
                $this->log->add($stage, ReasonCode::CircuitAbandonStale);
            }
        } catch (\Throwable $failure) {
            $this->backendFailure('circuit', $failure);
            $this->log->add($stage, ReasonCode::CircuitAbandonFailed);
            $this->uncertain(ReasonCode::CircuitAbandonFailed);
        }
    }

    /** @return bool true iff the backend definitely applied the verdict (or no circuit is installed) */
    private function recordCircuit(HeldOwnership $held, CircuitVerdict $verdict): bool
    {
        $permit = $held->circuit;
        if ($permit === null || $this->env->circuit === null || $this->definition->circuit === null) {
            return true;
        }
        try {
            $result = $this->env->circuit->record($this->definition->circuit, $permit, $verdict, $this->ids->command());
            $this->observeTransition($result->transition);
            $held->circuit = null;
            if ($result->status === FinalizationStatus::StaleOwnership) {
                // The permit had expired or was superseded: the verdict was not applied to circuit health.
                $this->log->add(Stage::Finalize, ReasonCode::CircuitRecordStale);
                $this->markIncomplete(ReasonCode::CircuitRecordStale);

                return false;
            }

            return true;
        } catch (\Throwable $failure) {
            $this->backendFailure('circuit', $failure);
            $this->log->add(Stage::Finalize, ReasonCode::CircuitRecordFailed);
            $this->uncertain(ReasonCode::CircuitRecordFailed);

            return false;
        }
    }

    /**
     * Observe-only read of the Retry-After lower bound of a late or cancelled outcome. Every other classifier
     * output is discarded, an exception is isolated into a bounded diagnostic (never a classifier cause), and
     * the circuit verdict, retry and fallback decisions stay as the settlement override dictates.
     *
     * @param Outcome<T> $outcome
     */
    private function extractLateSignal(Outcome $outcome): void
    {
        try {
            $lowerBound = $this->env->classifier->classify($outcome, $this->context->metadata)->retryAfterLowerBound;
        } catch (\Throwable) {
            $this->log->add(Stage::Classification, ReasonCode::LateSignalExtractionFailed);

            return;
        }
        $this->extendCooldown($lowerBound);
    }

    /**
     * Failure of the runtime adapter after invocation had begun. The attempt counts as dispatched, carries an
     * exception outcome, ends the operation as an infrastructure failure and is never retried.
     *
     * @return AttemptResult
     */
    private function failDispatched(HeldOwnership $held, AttemptId $attemptId, int $number, \Throwable $raw): AttemptResult
    {
        $failure = $this->infrastructureFailure($raw);
        $this->backendFailure('runtime', $failure);
        $this->log->add(Stage::Dispatch, ReasonCode::RuntimeAdapterFailure);
        $this->markIncomplete(ReasonCode::RuntimeAdapterFailure);
        // The physical operation may still be running; the permits are ended as for any unobservable attempt.
        $this->releaseConcurrency($held, Stage::Finalize);
        $this->abandonCircuit($held, Stage::Finalize);
        // The debit belongs to a dispatched attempt now, not to an admitted-but-never-sent one.
        $held->rateDebited = false;

        $record = new AttemptRecord(
            $attemptId,
            $number,
            ActualAttemptDisposition::Neutral,
            Outcome::fromException($raw, BoundedMetadata::empty()),
            null,
            SettlementOverride::None,
            null,
            CircuitVerdict::Neutral,
        );
        $this->emit(fn(): Event => new AttemptCompleted($this->context->operationId, $this->definition->profileVersion, $attemptId, CircuitVerdict::Neutral, null));

        return AttemptResult::failedAfterDispatch($record, $failure);
    }

    /**
     * Stage 4 of finalization: dispose only an outcome that a dispatched retry actually replaced. It runs after that
     * retry was dispatched (settled or failed in the runtime), never between the last check and the dispatch, and never
     * for a retry that was refused or did not start.
     */
    private function discardPrevious(): void
    {
        $previous = $this->pendingDiscard;
        if ($previous === null) {
            return;
        }
        $this->pendingDiscard = null;
        $this->discard($previous);
    }

    /** @param Settled<T> $settled */
    private function discard(Settled $settled): void
    {
        try {
            $settled->prepared->discardOutcome($settled->record->outcome);
        } catch (\Throwable) {
            $this->log->add(Stage::Discard, ReasonCode::DiscardFailed);
        }
    }

    // ---------------------------------------------------------------- Stage 11: retry decision and delay

    /**
     * @param Settled<T> $settled
     * @return Duration|ReqtideException|null the scheduled delay, a terminal policy cause, or no retry
     */
    private function decideRetry(Settled $settled): Duration|ReqtideException|null
    {
        $classification = $settled->classification;
        if ($classification === null) {
            return null;
        }

        // A valid Retry-After extends the shared cooldown regardless of this operation's retry eligibility.
        $lowerBound = $this->extendCooldown($classification->retryAfterLowerBound);

        $options = $this->definition->retry;
        $contract = $this->context->metadata->safety->contract;
        if ($options->maxAttempts->value <= 1 || $classification->retry !== RetryEligibility::Eligible) {
            return null;
        }
        if ($this->retryDenied || $this->blockFurther) {
            // Already explained by a preflight diagnostic or by the uncertainty that blocks further actions.
            return null;
        }
        // Retry-policy denials below keep the business result and record a stable retry-stop reason.
        if (count($this->attempts) >= $options->maxAttempts->value) {
            return $this->stopRetry(ReasonCode::AttemptsExhausted);
        }
        if ($contract === null || !in_array($classification->reasonCode, $contract->retryableOutcomeCodes, true)) {
            return $this->stopRetry(ReasonCode::OutcomeNotRetryable);
        }

        try {
            $remaining = $this->checkpointRemaining();
        } catch (ClockFailureException) {
            $this->log->add(Stage::Retry, ReasonCode::ClockUnavailable);
            $this->markIncomplete(ReasonCode::RetryClockUnavailable);
            $this->blockFurther = true;

            return null;
        } catch (DeadlineExceededException) {
            // The settlement itself was on time: the business result is not rewritten, only the retry stops.
            return $this->stopRetry(ReasonCode::RetryDeadlineReached);
        } catch (OperationCancelledException) {
            return $this->stopRetry(ReasonCode::RetryCancelled);
        }

        $retryIndex = count($this->attempts);
        $computed = $this->backoff($retryIndex);
        $jittered = $options->jitter === JitterMode::Full
            ? new Duration($this->randomWithin($computed->nanoseconds))
            : $computed;
        $bound = $lowerBound !== null && $lowerBound->isPositive() ? $lowerBound : null;
        $need = $this->attemptNeed();
        // delay + max(allowance, 1ns) < remaining, strictly: a delay must be strictly shorter than the capacity.
        $hasCapacity = $remaining->compare($need) > 0;
        $capacity = $hasCapacity ? $remaining->subtract($need) : Duration::zero();

        if ($bound !== null) {
            if ($bound->compare($options->maximumDelay) > 0) {
                return $this->deferral(ReasonCode::RetryAfterExceedsMaximumDelay, $bound);
            }
            if (!$hasCapacity || $bound->compare($capacity) >= 0) {
                return $this->deferral(ReasonCode::RetryAfterExceedsDeadline, $bound);
            }
            try {
                $scheduled = $bound->add($jittered)->min($options->maximumDelay);
            } catch (InvalidConfigurationException) {
                $scheduled = $options->maximumDelay;
            }
            // Only the random part may be trimmed to fit; the lower bound itself is never clamped downward.
            $scheduled = $scheduled->min($capacity->subtract(new Duration(1)))->max($bound);
        } else {
            $scheduled = $jittered;
            if (!$hasCapacity) {
                return $this->stopRetry(ReasonCode::AttemptAllowanceUnavailable);
            }
            if ($scheduled->compare($capacity) >= 0) {
                return $this->stopRetry(ReasonCode::RetryDelayExceedsDeadline);
            }
        }

        if ($scheduled->isPositive()) {
            // Envoy and grpc-go decide on the budget before sleeping. With no delay the authoritative reservation
            // follows immediately, so a second backend call would add cost and no information.
            $headroom = $this->retryBudgetHasHeadroom($scheduled);
            if ($headroom instanceof InfrastructureException) {
                return $headroom;
            }
            if (!$headroom) {
                $this->recordBudgetExhausted();

                return null;
            }
        }

        $reason = $classification->reasonCode;
        $next = count($this->attempts) + 1;
        $this->emit(fn(): Event => new RetryScheduled($this->context->operationId, $this->definition->profileVersion, $next, $scheduled, $reason));

        return $scheduled;
    }

    /** Records why the retry policy itself ended the retry; the previous business result stays the result. */
    private function stopRetry(ReasonCode $reason): null
    {
        $this->log->add(Stage::Retry, $reason);

        return null;
    }

    private function deferral(ReasonCode $reason, Duration $wait): AdmissionDeferredException
    {
        $this->emit(fn(): Event => new AdmissionRejected($this->context->operationId, $this->definition->profileVersion, 'retry', $reason->value));

        return new AdmissionDeferredException(new AdmissionDecision(AdmissionStatus::Deferred, $reason->value, null, $wait), $this->lastBusinessException());
    }

    private function extendCooldown(?Duration $lowerBound): ?Duration
    {
        if ($lowerBound === null || !$lowerBound->isPositive()) {
            return $lowerBound;
        }
        $backend = $this->env->cooldown;
        $policy = $this->definition->cooldown;
        if ($backend === null || $policy === null) {
            return $lowerBound;
        }
        $extension = $lowerBound;
        if ($extension->compare($policy->maximumExtension) > 0) {
            // This operation keeps the full wait for its own retry decision; other operations see at most the cap.
            $extension = $policy->maximumExtension;
            $this->log->add(Stage::Retry, ReasonCode::CooldownExtensionCapped);
        }
        try {
            $decision = $backend->extend($policy, $extension, $this->ids->command());

            return $lowerBound->max($decision->remaining);
        } catch (\Throwable $failure) {
            $this->backendFailure('cooldown', $failure);
            $this->log->add(Stage::Retry, ReasonCode::CooldownExtendFailed);
            $this->uncertain(ReasonCode::CooldownExtendFailed);

            return $lowerBound;
        }
    }

    /** Constant, or exponential `min(maxDelay, base * 2^(r-1))` with saturating arithmetic. */
    private function backoff(int $retryIndex): Duration
    {
        $options = $this->definition->retry;
        $delay = $options->baseDelay;
        if ($options->backoff === BackoffMode::Exponential) {
            for ($i = 1; $i < $retryIndex; ++$i) {
                if ($delay->compare($options->maximumDelay) >= 0) {
                    break;
                }
                try {
                    $delay = $delay->multiply(2);
                } catch (InvalidConfigurationException) {
                    $delay = $options->maximumDelay;
                    break;
                }
            }
        }

        return $delay->min($options->maximumDelay);
    }

    private function randomWithin(int $maximum): int
    {
        if ($maximum <= 0) {
            return 0;
        }
        try {
            $value = $this->env->random->integer(0, $maximum);
        } catch (\Throwable) {
            // No jitter is better than losing the result: the delay stays at its computed upper bound.
            $this->log->add(Stage::Retry, ReasonCode::RandomOutOfRange);

            return $maximum;
        }
        if ($value < 0 || $value > $maximum) {
            $this->log->add(Stage::Retry, ReasonCode::RandomOutOfRange);

            return min(max($value, 0), $maximum);
        }

        return $value;
    }

    /** @return bool|ReqtideException true to proceed, false to abandon the retry, or a terminal cause */
    private function waitForRetry(Duration $delay): bool|ReqtideException
    {
        if ($delay->isZero()) {
            return true;
        }
        // Nothing is held here: permits ended in Stage 10 and the next reservation is made after the wait.
        try {
            $disposition = $this->env->scheduler->delay($delay, $this->context->deadline, $this->context->cancellation);
        } catch (ClockFailureException $failure) {
            $this->backendFailure('clock', $failure);

            return $failure;
        } catch (\Throwable) {
            $this->log->add(Stage::Retry, ReasonCode::DelayFailed);

            return false;
        }

        return match ($disposition) {
            DelayDisposition::Completed => true,
            DelayDisposition::Cancelled => new OperationCancelledException('The operation was cancelled during the retry delay.'),
            DelayDisposition::DeadlineExceeded => new DeadlineExceededException('The operation deadline was reached during the retry delay.'),
        };
    }

    // ---------------------------------------------------------------- Stage 12: terminal disposition and fallback

    /**
     * @param Settled<T> $settled
     * @return ExecutionCompletion<T>
     */
    private function finishBusiness(Settled $settled): ExecutionCompletion
    {
        $classification = $settled->classification;
        $outcome = $settled->record->outcome;
        if ($classification === null) {
            return $this->complete(CompletionDisposition::Failed, null, $settled->classifierFailure, null);
        }
        if ($outcome->kind === OutcomeKind::Value && $classification->acceptability === Acceptability::Acceptable) {
            return $this->complete(CompletionDisposition::Succeeded, null, null, null);
        }

        $fallback = $this->context->fallback;
        if (
            $fallback === null
            || $classification->fallback !== FallbackEligibility::Eligible
            || $this->blockFurther
        ) {
            return $this->complete(CompletionDisposition::Failed, null, null, null);
        }

        try {
            $this->checkpointRemaining();
        } catch (DeadlineExceededException|OperationCancelledException $policy) {
            return $this->completeWithCause($policy);
        } catch (ClockFailureException) {
            $this->log->add(Stage::Fallback, ReasonCode::ClockUnavailable);
            $this->markIncomplete(ReasonCode::FallbackClockUnavailable);

            return $this->complete(CompletionDisposition::Failed, null, null, null);
        }

        return $this->runFallback($fallback, $outcome, $settled, null);
    }

    /**
     * Terminal handling of an admission refusal (circuit, concurrency, rate or cooldown; Rejected, Deferred or
     * Impossible). By default the refusal owns the disposition. Only when the definition opts in with
     * FallbackScope::IncludeAdmissionRefusals, the context supplies a fallback and no uncertainty blocks further
     * action, the fallback runs ONCE here, outside the retry loop, with the refusal as its original outcome.
     * Cancellation, deadline, infrastructure, preparation and classifier terminations never reach this method.
     *
     * @return ExecutionCompletion<T>
     */
    private function finishRefusal(ReqtideException $refusal): ExecutionCompletion
    {
        $fallback = $this->context->fallback;
        if (
            $this->definition->fallbackScope !== FallbackScope::IncludeAdmissionRefusals
            || $fallback === null
            || $this->blockFurther
        ) {
            return $this->completeWithCause($refusal);
        }

        try {
            $this->checkpointRemaining();
        } catch (DeadlineExceededException|OperationCancelledException $policy) {
            return $this->completeWithCause($policy);
        } catch (ClockFailureException) {
            $this->log->add(Stage::Fallback, ReasonCode::ClockUnavailable);
            $this->markIncomplete(ReasonCode::FallbackClockUnavailable);

            return $this->completeWithCause($refusal);
        }

        return $this->runFallback($fallback, Outcome::fromException($refusal, BoundedMetadata::empty()), $this->pendingDiscard, $refusal);
    }

    private static function isAdmissionRefusal(ReqtideException $cause): bool
    {
        return $cause instanceof AdmissionRejectedException
            || $cause instanceof AdmissionDeferredException
            || $cause instanceof ImpossibleAdmissionException;
    }

    /**
     * Shared tail of Stage 12: invokes the fallback once, discards the replaced attempt outcome, and completes
     * with the fallback result. `$refusal` is the admission refusal the fallback replaced, or null for a
     * business outcome.
     *
     * @param FallbackOperation<T> $fallback
     * @param Outcome<T> $original
     * @param Settled<T>|null $replaced
     * @return ExecutionCompletion<T>
     */
    private function runFallback(FallbackOperation $fallback, Outcome $original, ?Settled $replaced, ?ReqtideException $refusal): ExecutionCompletion
    {
        ++$this->fallbackExecutions;
        try {
            $fallbackOutcome = Outcome::fromValue($fallback->invoke($original, $this->context), BoundedMetadata::empty());
        } catch (\Throwable $failure) {
            $fallbackOutcome = Outcome::fromException($failure, BoundedMetadata::empty());
        }
        if ($replaced !== null) {
            $this->discard($replaced);
        }

        try {
            $late = $this->context->deadline->remaining($this->env->clock)->isZero();
        } catch (\Throwable) {
            // The fallback result is kept as established; its timing is unavailable and is never reported as
            // on time: the overall elapsed time becomes unavailable and accounting incomplete.
            $this->log->add(Stage::Fallback, ReasonCode::TimingUnavailable);
            $this->markIncomplete(ReasonCode::FallbackTimingUnavailable);
            $this->elapsedUnavailable = true;
            $late = false;
        }
        if ($late) {
            return $this->complete(
                CompletionDisposition::DeadlineExceeded,
                new DeadlineExceededException('The fallback settled at or after the operation deadline.'),
                null,
                $fallbackOutcome,
            );
        }

        // The deadline wins over a cancellation, as for an attempt. The fallback outcome stays in the completion.
        if ($this->cancellationRequested()) {
            return $this->complete(
                CompletionDisposition::Cancelled,
                new OperationCancelledException('The operation was cancelled while the fallback ran.'),
                null,
                $fallbackOutcome,
            );
        }

        return $this->complete(
            $fallbackOutcome->kind === OutcomeKind::Value ? CompletionDisposition::FallbackSucceeded : CompletionDisposition::FallbackFailed,
            $refusal,
            null,
            $fallbackOutcome,
        );
    }

    /** @return Settled<T> */
    private function lastSettled(): Settled
    {
        $settled = $this->pendingDiscard;
        if ($settled === null) {
            throw new \LogicException('A declined retry requires a previous settled attempt.');
        }

        return $settled;
    }

    // ---------------------------------------------------------------- Stage 13: summary and completion event

    /** @return ExecutionCompletion<T> */
    private function completeWithCause(ReqtideException $cause): ExecutionCompletion
    {
        $disposition = match (true) {
            $cause instanceof AdmissionDeferredException => CompletionDisposition::Deferred,
            $cause instanceof AdmissionRejectedException, $cause instanceof ImpossibleAdmissionException => CompletionDisposition::Rejected,
            $cause instanceof OperationCancelledException => CompletionDisposition::Cancelled,
            $cause instanceof DeadlineExceededException => CompletionDisposition::DeadlineExceeded,
            default => CompletionDisposition::Failed,
        };

        return $this->complete($disposition, $cause, null, null);
    }

    /**
     * @param ClassificationFailedException<T>|null $classifier
     * @param Outcome<T>|null $fallbackOutcome
     * @return ExecutionCompletion<T>
     */
    private function complete(CompletionDisposition $disposition, ?ReqtideException $policy, ?ClassificationFailedException $classifier, ?Outcome $fallbackOutcome): ExecutionCompletion
    {
        $elapsed = null;
        try {
            $start = $this->startNanoseconds;
            if ($start === null || $this->elapsedUnavailable) {
                throw new ClockFailureException('The operation timing is unavailable.');
            }
            $end = $this->readClock();
            if ($end < $start) {
                throw new ClockFailureException('The monotonic clock moved backwards.');
            }
            $elapsed = new Duration($end - $start);
        } catch (ClockFailureException) {
            $this->log->add(Stage::Summary, ReasonCode::ElapsedUnavailable);
            $this->accountingComplete = false;
        }

        $completion = $this->build($disposition, $policy, $classifier, $fallbackOutcome, $elapsed);

        $delivered = true;
        try {
            $this->env->events->emit(new OperationCompleted(
                $this->context->operationId,
                $this->definition->profileVersion,
                $disposition,
                $elapsed,
                $this->accountingComplete,
            ));
        } catch (\Throwable) {
            $delivered = false;
        }
        if (!$delivered) {
            // The delivered snapshot is never mutated and no second completion event is attempted.
            $this->log->add(Stage::Observation, ReasonCode::CompletionSinkFailed);
            $this->accountingComplete = false;
            $completion = $this->build($disposition, $policy, $classifier, $fallbackOutcome, $elapsed);
        }

        return $completion;
    }

    /**
     * @param ClassificationFailedException<T>|null $classifier
     * @param Outcome<T>|null $fallbackOutcome
     * @return ExecutionCompletion<T>
     */
    private function build(CompletionDisposition $disposition, ?ReqtideException $policy, ?ClassificationFailedException $classifier, ?Outcome $fallbackOutcome, ?Duration $elapsed): ExecutionCompletion
    {
        $succeeded = $failed = $neutral = $values = $exceptions = 0;
        foreach ($this->attempts as $attempt) {
            match ($attempt->disposition) {
                ActualAttemptDisposition::Succeeded => ++$succeeded,
                ActualAttemptDisposition::Failed => ++$failed,
                ActualAttemptDisposition::Neutral => ++$neutral,
            };
            if ($attempt->outcome->kind === OutcomeKind::Value) {
                ++$values;
            } else {
                ++$exceptions;
            }
        }
        $dispatched = count($this->attempts);
        $summary = new ExecutionSummary(
            $this->context->operationId,
            $this->definition->profileVersion,
            $disposition,
            $dispatched,
            $this->admittedNotDispatched,
            max(0, $dispatched - 1),
            $succeeded,
            $failed,
            $neutral,
            $values,
            $exceptions,
            $this->fallbackExecutions,
            $this->accountingComplete && $elapsed !== null,
            $this->log->omitted(),
            $elapsed,
            $this->admissionUncertain,
        );
        $terminal = $this->attempts === [] ? null : $this->attempts[$dispatched - 1]->outcome;

        return new ExecutionCompletion(
            $disposition,
            $terminal,
            $fallbackOutcome,
            $policy,
            $classifier,
            $this->attempts,
            $this->log->entries(),
            $summary,
        );
    }

    // ---------------------------------------------------------------- shared helpers

    /** @throws ClockFailureException */
    private function readClock(): int
    {
        $now = $this->env->clock->monotonicNow();
        if ($now < 0) {
            throw new ClockFailureException('The monotonic clock returned a negative reading.');
        }

        return $now;
    }

    private function toTermination(\Throwable $failure, Stage $phase): ReqtideException
    {
        if ($failure instanceof PolicyTerminationException || $failure instanceof InfrastructureException || $failure instanceof PreparationFailedException) {
            return $failure;
        }
        $this->log->add($phase, $failure instanceof ReqtideException ? ReasonCode::LifecycleFailure : ReasonCode::UnexpectedFailure);

        return new PreparationFailedException($failure);
    }

    /**
     * Failure-isolated event delivery: a throwing sink adds a bounded diagnostic and never recurses.
     *
     * @param \Closure(): Event $factory
     */
    private function emit(\Closure $factory): void
    {
        try {
            $this->env->events->emit($factory());
        } catch (\Throwable) {
            $this->log->add(Stage::Observation, ReasonCode::EventSinkFailed);
            $this->accountingComplete = false;
        }
    }

    /** One event per circuit state change a backend call reported; absent transitions emit nothing. */
    private function observeTransition(?CircuitTransition $transition): void
    {
        if ($transition === null) {
            return;
        }
        $this->emit(fn(): Event => new CircuitTransitioned(
            $this->context->operationId,
            $this->definition->profileVersion,
            $transition->fromState,
            $transition->toState,
            $transition->reasonCode,
        ));
    }

    private function backendFailure(string $kind, \Throwable $failure): void
    {
        $code = match (true) {
            $failure instanceof StateUnavailableException => ReasonCode::StateUnavailable,
            $failure instanceof CorruptStateException => ReasonCode::StateCorrupt,
            $failure instanceof IndeterminateStateException => ReasonCode::StateIndeterminate,
            $failure instanceof StateCapacityExceededException => ReasonCode::StateCapacityExceeded,
            $failure instanceof ClockFailureException => ReasonCode::ClockFailure,
            default => ReasonCode::UnexpectedBackendFailure,
        };
        $this->emit(fn(): Event => new BackendErrorObserved($this->context->operationId, $this->definition->profileVersion, $kind, $code->value));
    }

    /** Ownership or protective accounting is uncertain: the result is kept but retry and fallback stop. */
    private function uncertain(ReasonCode $reason): void
    {
        $this->blockFurther = true;
        $this->markIncomplete($reason);
    }

    private function markIncomplete(ReasonCode $reason): void
    {
        $first = $this->accountingComplete;
        $this->accountingComplete = false;
        if ($first) {
            $this->emit(fn(): Event => new AccountingDegraded($this->context->operationId, $this->definition->profileVersion, $reason->value));
        }
    }
}
