<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Pipeline;

use Enaxon\Reqtide\Completion\CompletionDisposition;
use Enaxon\Reqtide\Completion\ExecutionCompletion;
use Enaxon\Reqtide\Error\ProblemCarrier;
use Enaxon\Reqtide\Exception\AdmissionDeferredException;
use Enaxon\Reqtide\Exception\AdmissionRejectedException;
use Enaxon\Reqtide\Exception\ClassificationFailedException;
use Enaxon\Reqtide\Exception\ClockFailureException;
use Enaxon\Reqtide\Exception\DeadlineExceededException;
use Enaxon\Reqtide\Exception\FallbackFailedException;
use Enaxon\Reqtide\Exception\ImpossibleAdmissionException;
use Enaxon\Reqtide\Exception\InfrastructureException;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Exception\OperationCancelledException;
use Enaxon\Reqtide\Exception\PreparationFailedException;
use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Execution\BoundedMetadata;
use Enaxon\Reqtide\Execution\ExecutionContext;
use Enaxon\Reqtide\Execution\OperationMetadata;
use Enaxon\Reqtide\Execution\OperationSafety;
use Enaxon\Reqtide\Fallback\FallbackOperation;
use Enaxon\Reqtide\Fallback\FallbackScope;
use Enaxon\Reqtide\Internal\Identity\IdentityGenerator;
use Enaxon\Reqtide\Outcome\Outcome;
use Enaxon\Reqtide\Outcome\OutcomeKind;
use Enaxon\Reqtide\Pipeline\Internal\OperationRun;
use Enaxon\Reqtide\Runtime\AttemptOperation;
use Enaxon\Reqtide\Runtime\CallableAttemptOperation;
use Enaxon\Reqtide\Time\Cancellation;
use Enaxon\Reqtide\Time\Deadline;
use Enaxon\Reqtide\Time\NeverCancelled;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\OperationId;
use Enaxon\Reqtide\Value\PartitionIdentity;

/**
 * Reusable, validated pipeline. It holds only immutable configuration: every execution runs in its own
 * call-local OperationRun, so concurrent or re-entrant calls never share per-operation state.
 */
final class CompiledPipeline
{
    /** @internal Created by PipelineDefinition::compile(). */
    public function __construct(
        private readonly PipelineDefinition $definition,
        private readonly RuntimeEnvironment $environment,
        private readonly IdentityGenerator $identities,
    ) {}

    /**
     * Builds the per-operation {@see ExecutionContext} for this pipeline. The deadline is created on the
     * pipeline's own Clock, so it can never be mixed up with another clock, and the partition defaults to the one
     * all of this pipeline's policies share. Every call produces an independent context; share none between
     * operations.
     *
     * - `$timeout` is the total budget of the whole logical operation (all attempts and delays), must be positive.
     * - `$safety` defaults to {@see OperationSafety::unsafe()}: a pipeline with `maxAttempts > 1` then refuses to
     *   run. Pass an explicit replay-safety assertion to allow retries.
     * - `$operationId` defaults to a random 128-bit hex id; pass your own to correlate with your logs.
     * - `$partition` defaults to the definition's partition (always set for a {@see PipelineBuilder} pipeline, even
     *   one with no state policy). It is needed only for a hand-built {@see PipelineDefinition} that has neither a
     *   partition nor a policy.
     *
     * The context is typed `ExecutionContext<never>` without a fallback so that static analysis infers the result
     * type from the callable alone; with a fallback it is `ExecutionContext<T>` of the fallback's type.
     *
     * @template T = never
     * @param FallbackOperation<T>|null $fallback
     * @return ExecutionContext<T>
     * @throws InvalidConfigurationException when the timeout is not positive, the deadline overflows, the operation id is invalid or no partition can be determined
     * @throws ClockFailureException when the clock cannot be read
     */
    public function newContext(
        Duration $timeout,
        ?OperationSafety $safety = null,
        ?FallbackOperation $fallback = null,
        ?Cancellation $cancellation = null,
        ?BoundedMetadata $attributes = null,
        OperationId|string|null $operationId = null,
        ?PartitionIdentity $partition = null,
    ): ExecutionContext {
        if (!$timeout->isPositive()) {
            throw new InvalidConfigurationException('newContext() needs a positive timeout; an already expired deadline can only fail.');
        }
        $partition ??= $this->definition->partition;
        if ($partition === null) {
            throw new InvalidConfigurationException(
                'newContext() cannot determine the partition: this definition was constructed directly without a partition and without a rate limit, circuit breaker, bulkhead, retry budget or cooldown. Pass partition: explicitly.',
            );
        }
        if ($operationId === null) {
            $operationId = new OperationId(bin2hex(random_bytes(16)));
        } elseif (is_string($operationId)) {
            $operationId = new OperationId($operationId);
        }

        return new ExecutionContext(
            $operationId,
            $partition,
            Deadline::after($timeout, $this->environment->clock),
            $cancellation ?? new NeverCancelled(),
            new OperationMetadata($safety ?? OperationSafety::unsafe(), $attributes ?? BoundedMetadata::empty()),
            $fallback,
        );
    }

    /**
     * @template T
     * @param callable(AttemptContext):T $operation
     * @param ExecutionContext<T>|ExecutionContext<never> $context
     * @return T
     * @throws InvalidConfigurationException before any admission or dispatch
     * @throws AdmissionRejectedException when the rate limit, circuit breaker or bulkhead refuses
     * @throws AdmissionDeferredException when a cooldown or the rate limit defers the operation
     * @throws ImpossibleAdmissionException when an admission can never succeed
     * @throws DeadlineExceededException when the deadline is reached
     * @throws OperationCancelledException when the operation is cancelled
     * @throws InfrastructureException when shared state or the clock fails (state unavailable, indeterminate, corrupt, over capacity, clock failure)
     * @throws PreparationFailedException when attempt preparation or replay verification fails
     * @throws ClassificationFailedException when the classifier throws
     * @throws FallbackFailedException when the fallback throws
     *
     * The callable's own exception is rethrown unchanged when no policy decision replaces it.
     */
    public function execute(callable $operation, ExecutionContext $context): mixed
    {
        return self::unwrap($this->run(new CallableAttemptOperation($operation), $context, true));
    }

    /**
     * @template T
     * @param callable(AttemptContext):T $operation
     * @param ExecutionContext<T>|ExecutionContext<never> $context
     * @return ExecutionCompletion<T>
     * @throws InvalidConfigurationException before any admission or dispatch
     */
    public function executeDetailed(callable $operation, ExecutionContext $context): ExecutionCompletion
    {
        return $this->run(new CallableAttemptOperation($operation), $context, true);
    }

    /**
     * @template T
     * @param AttemptOperation<T> $operation
     * @param ExecutionContext<T>|ExecutionContext<never> $context
     * @return T
     * @throws InvalidConfigurationException before any admission or dispatch
     * @throws AdmissionRejectedException when the rate limit, circuit breaker or bulkhead refuses
     * @throws AdmissionDeferredException when a cooldown or the rate limit defers the operation
     * @throws ImpossibleAdmissionException when an admission can never succeed
     * @throws DeadlineExceededException when the deadline is reached
     * @throws OperationCancelledException when the operation is cancelled
     * @throws InfrastructureException when shared state or the clock fails (state unavailable, indeterminate, corrupt, over capacity, clock failure)
     * @throws PreparationFailedException when attempt preparation or replay verification fails
     * @throws ClassificationFailedException when the classifier throws
     * @throws FallbackFailedException when the fallback throws
     *
     * The callable's own exception is rethrown unchanged when no policy decision replaces it.
     */
    public function executeOperation(AttemptOperation $operation, ExecutionContext $context): mixed
    {
        return self::unwrap($this->run($operation, $context, false));
    }

    /**
     * @template T
     * @param AttemptOperation<T> $operation
     * @param ExecutionContext<T>|ExecutionContext<never> $context
     * @return ExecutionCompletion<T>
     * @throws InvalidConfigurationException before any admission or dispatch
     */
    public function executeOperationDetailed(AttemptOperation $operation, ExecutionContext $context): ExecutionCompletion
    {
        return $this->run($operation, $context, false);
    }

    /** Describes the compiled pipeline without any I/O, state mutation or dispatch. */
    public function explain(): PipelineExplanation
    {
        $definition = $this->definition;
        $enabled = ['attempt'];
        $enabled[] = 'retry(maxAttempts=' . $definition->retry->maxAttempts->value . ')';
        foreach ([
            'rate_limit' => $definition->rateLimit,
            'circuit' => $definition->circuit,
            'concurrency' => $definition->concurrency,
            'retry_budget' => $definition->retryBudget,
            'cooldown' => $definition->cooldown,
        ] as $name => $policy) {
            if ($policy !== null) {
                $enabled[] = $name;
            }
        }

        $capabilities = $this->environment->runtime->capabilities();
        $notes = [
            'invocation=' . $capabilities->invocation->value,
            'settlement=' . $capabilities->settlement->value,
            'attempt_timeout=' . $capabilities->attemptTimeout->value,
            'cancellation=' . $capabilities->cancellation->value,
            'scheduling=' . $this->environment->scheduler->capability()->value,
            'queue_ownership=' . $capabilities->queueOwnership->value,
            'detached_completion=' . $capabilities->detachedCompletion->value,
            'nonblocking=' . $capabilities->nonblocking->value,
            'physical_stop=' . $capabilities->physicalStop->value,
            'require_finite_backend_io=' . ($definition->requireFiniteBackendIo ? 'true' : 'false'),
        ];
        if ($definition->fallbackScope !== FallbackScope::BusinessOutcomesOnly) {
            // Only a non-default scope is reported, so the default explanation is unchanged.
            $notes[] = 'fallback_scope=' . $definition->fallbackScope->value;
        }

        return new PipelineExplanation($definition->profileId, $definition->profileVersion, $enabled, $notes);
    }

    /**
     * @template T
     * @param AttemptOperation<T> $operation
     * @param ExecutionContext<T>|ExecutionContext<never> $context
     * @return ExecutionCompletion<T>
     */
    private function run(AttemptOperation $operation, ExecutionContext $context, bool $genericCallable): ExecutionCompletion
    {
        /** @var OperationRun<T> $run */
        $run = new OperationRun($this->definition, $this->environment, $this->identities, $operation, $context, $genericCallable);

        try {
            return $run->run();
        } catch (InvalidConfigurationException $configuration) {
            // A defect in the call (for example a missing replay contract): name the operation that raised it.
            $configuration->attachProblemContext($context->operationId, $this->definition->profileVersion);

            throw $configuration;
        }
    }

    /**
     * Gives an exception thrown by simple mode the operation context of the completion that produced it, so
     * `problem()` names the operation, profile version and (when it is about one attempt) the attempt.
     *
     * @template E of \Throwable
     * @param ExecutionCompletion<mixed> $completion
     * @param E $exception
     * @return E
     */
    private static function withProblemContext(\Throwable $exception, ExecutionCompletion $completion): \Throwable
    {
        $problem = $completion->problem();
        if (
            $problem !== null
            && $problem->operationId !== null
            && $problem->profileVersion !== null
            && $exception instanceof ProblemCarrier
            && method_exists($exception, 'attachProblemContext')
        ) {
            $exception->attachProblemContext($problem->operationId, $problem->profileVersion, $problem->attemptId);
        }

        return $exception;
    }

    /**
     * Simple mode: original value, original exception, typed policy exception or fallback wrapper.
     *
     * @template T
     * @param ExecutionCompletion<T> $completion
     * @return T
     */
    private static function unwrap(ExecutionCompletion $completion): mixed
    {
        $classifier = $completion->classifierCause;
        if ($classifier instanceof ClassificationFailedException) {
            throw self::withProblemContext($classifier, $completion);
        }
        $fallbackRan = $completion->disposition === CompletionDisposition::FallbackSucceeded
            || $completion->disposition === CompletionDisposition::FallbackFailed;
        if ($completion->policyCause !== null && !$fallbackRan) {
            throw self::withProblemContext($completion->policyCause, $completion);
        }
        $fallback = $completion->fallbackOutcome;
        if ($fallback !== null) {
            if ($fallback->kind === OutcomeKind::Value) {
                return $fallback->value();
            }
            // A fallback that replaced an admission refusal keeps the refusal as its policy cause, and the
            // refusal (not an earlier attempt) is the outcome the fallback received.
            $original = $completion->policyCause !== null
                ? Outcome::fromException($completion->policyCause, BoundedMetadata::empty())
                : $completion->terminalOutcome;
            if ($original === null) {
                throw new \LogicException('A fallback completion always retains its original outcome.');
            }
            throw self::withProblemContext(new FallbackFailedException($original, $fallback->exception()), $completion);
        }
        $terminal = $completion->terminalOutcome;
        if ($terminal === null) {
            throw new \LogicException('A completion without a policy cause always has a terminal outcome.');
        }
        if ($completion->disposition === CompletionDisposition::Succeeded || $terminal->kind === OutcomeKind::Value) {
            return $terminal->value();
        }

        throw $terminal->exception();
    }
}
