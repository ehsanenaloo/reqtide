<?php

declare(strict_types=1);

/**
 * Scenario definitions for the local in-memory micro-benchmarks. Not part of the Reqtide API.
 *
 * Every scenario returns a closure that performs ONE logical operation on the success path: build an
 * ExecutionContext (with its Deadline), then call CompiledPipeline::execute() with a trivial callable.
 * Context construction is inside the timed region on purpose: a real caller pays for it per operation.
 * Failure, refusal, retry-delay and fallback paths are NOT measured here.
 */

namespace Reqtide\Benchmarks;

use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Execution\BoundedMetadata;
use Enaxon\Reqtide\Execution\ExecutionContext;
use Enaxon\Reqtide\Execution\OperationMetadata;
use Enaxon\Reqtide\Execution\OperationSafety;
use Enaxon\Reqtide\Execution\ReplayPreparation;
use Enaxon\Reqtide\Execution\ReplaySafetyContract;
use Enaxon\Reqtide\Outcome\Acceptability;
use Enaxon\Reqtide\Outcome\CircuitVerdict;
use Enaxon\Reqtide\Outcome\FallbackEligibility;
use Enaxon\Reqtide\Outcome\Outcome;
use Enaxon\Reqtide\Outcome\OutcomeClassification;
use Enaxon\Reqtide\Outcome\OutcomeClassifier;
use Enaxon\Reqtide\Outcome\OutcomeKind;
use Enaxon\Reqtide\Outcome\RetryEligibility;
use Enaxon\Reqtide\Pipeline\PipelineDefinition;
use Enaxon\Reqtide\Pipeline\RuntimeEnvironment;
use Enaxon\Reqtide\Policy\AttemptOptions;
use Enaxon\Reqtide\Policy\AttemptTimeoutRequirement;
use Enaxon\Reqtide\Policy\BackoffMode;
use Enaxon\Reqtide\Policy\CircuitPolicy;
use Enaxon\Reqtide\Policy\ConcurrencyPolicy;
use Enaxon\Reqtide\Policy\CooldownPolicy;
use Enaxon\Reqtide\Policy\CountCircuitWindow;
use Enaxon\Reqtide\Policy\JitterMode;
use Enaxon\Reqtide\Policy\RateLimitPolicy;
use Enaxon\Reqtide\Policy\RetryBudgetPolicy;
use Enaxon\Reqtide\Policy\RetryOptions;
use Enaxon\Reqtide\Policy\TokenBucketOptions;
use Enaxon\Reqtide\Runtime\SchedulingCapability;
use Enaxon\Reqtide\Runtime\SynchronousRuntimeAdapter;
use Enaxon\Reqtide\State\Memory\MemoryCircuitBackend;
use Enaxon\Reqtide\State\Memory\MemoryConcurrencyBackend;
use Enaxon\Reqtide\State\Memory\MemoryCooldownBackend;
use Enaxon\Reqtide\State\Memory\MemoryRateLimitBackend;
use Enaxon\Reqtide\State\Memory\MemoryRetryBudgetBackend;
use Enaxon\Reqtide\Telemetry\NullEventSink;
use Enaxon\Reqtide\Time\BoundedBlockingScheduler;
use Enaxon\Reqtide\Time\Deadline;
use Enaxon\Reqtide\Time\NeverCancelled;
use Enaxon\Reqtide\Time\SecureRandomSource;
use Enaxon\Reqtide\Time\SystemClock;
use Enaxon\Reqtide\Value\ApplicationId;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\EnvironmentId;
use Enaxon\Reqtide\Value\OperationId;
use Enaxon\Reqtide\Value\PartitionIdentity;
use Enaxon\Reqtide\Value\PolicyId;
use Enaxon\Reqtide\Value\PolicyIdentity;
use Enaxon\Reqtide\Value\PositiveInt;
use Enaxon\Reqtide\Value\ProfileId;
use Enaxon\Reqtide\Value\ProfileVersion;
use Enaxon\Reqtide\Value\Ratio;
use Enaxon\Reqtide\Value\SubjectId;

/** Value is success; every exception is a retryable circuit failure. Only the success path is benchmarked. */
final class BenchClassifier implements OutcomeClassifier
{
    public function classify(Outcome $outcome, OperationMetadata $metadata): OutcomeClassification
    {
        if ($outcome->kind === OutcomeKind::Value) {
            return new OutcomeClassification(RetryEligibility::Ineligible, CircuitVerdict::Success, FallbackEligibility::Ineligible, Acceptability::Acceptable, 'ok', null);
        }

        return new OutcomeClassification(RetryEligibility::Ineligible, CircuitVerdict::Failure, FallbackEligibility::Ineligible, Acceptability::Unacceptable, 'failed', null);
    }
}

final class Scenarios
{
    /** @return list<string> */
    public static function names(): array
    {
        return [
            'baseline_context_only',
            'empty_pipeline',
            'rate_limit_only',
            'circuit_only',
            'concurrency_only',
            'retry_budget_only',
            'cooldown_only',
            'combined_all_five',
        ];
    }

    /** @return \Closure(): mixed one operation */
    public static function build(string $name): \Closure
    {
        return match ($name) {
            'baseline_context_only' => self::baseline(),
            'empty_pipeline' => self::pipeline(),
            'rate_limit_only' => self::pipeline(rate: true),
            'circuit_only' => self::pipeline(circuit: true),
            'concurrency_only' => self::pipeline(concurrency: true),
            'retry_budget_only' => self::pipeline(retry: true),
            'cooldown_only' => self::pipeline(cooldown: true),
            'combined_all_five' => self::pipeline(rate: true, circuit: true, concurrency: true, retry: true, cooldown: true),
            default => throw new \InvalidArgumentException("Unknown scenario: {$name}"),
        };
    }

    private static function partition(): PartitionIdentity
    {
        return new PartitionIdentity(new ApplicationId('bench'), new EnvironmentId('local'), null, new SubjectId('subject'));
    }

    private static function identity(string $policy): PolicyIdentity
    {
        return new PolicyIdentity(self::partition(), new ProfileVersion('v1'), new PolicyId($policy));
    }

    private static function replayable(): OperationSafety
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return OperationSafety::replayableRead(new ReplaySafetyContract(
            'bench-contract',
            'bench-identity',
            'bench-scope',
            'safe-to-repeat-read',
            $now->modify('-1 hour'),
            $now->modify('+1 hour'),
            Duration::seconds(3600),
            ReplayPreparation::CallableAssertion,
            ['failed'],
        ));
    }

    /** Context construction plus a direct call of the callable: the cost floor every other scenario includes. */
    private static function baseline(): \Closure
    {
        $clock = new SystemClock();
        $partition = self::partition();
        $metadata = new OperationMetadata(OperationSafety::unsafe(), BoundedMetadata::empty());
        $never = new NeverCancelled();
        $i = 0;
        $callable = static fn(): int => 1;

        return static function () use ($clock, $partition, $metadata, $never, &$i, $callable): mixed {
            $context = new ExecutionContext(new OperationId('b' . ++$i), $partition, Deadline::after(Duration::seconds(30), $clock), $never, $metadata, null);

            return $context->operationId->value === '' ? null : $callable();
        };
    }

    private static function pipeline(
        bool $rate = false,
        bool $circuit = false,
        bool $concurrency = false,
        bool $retry = false,
        bool $cooldown = false,
    ): \Closure {
        $clock = new SystemClock();
        $retryOptions = $retry
            ? new RetryOptions(new PositiveInt(3), BackoffMode::Constant, Duration::milliseconds(1), Duration::milliseconds(1), JitterMode::None, Duration::zero())
            : RetryOptions::noRetry();

        $definition = new PipelineDefinition(
            new ProfileId('bench'),
            new ProfileVersion('v1'),
            $retryOptions,
            new AttemptOptions(Duration::seconds(2), AttemptTimeoutRequirement::Cooperative),
            // Capacity far above what any run consumes, so the success path is never refused.
            $rate ? new RateLimitPolicy(self::identity('rate'), new TokenBucketOptions(new PositiveInt(1_000_000_000), new PositiveInt(1_000_000_000), Duration::seconds(1))) : null,
            $rate ? new PositiveInt(1) : null,
            $circuit ? new CircuitPolicy(
                self::identity('circuit'),
                new CountCircuitWindow(new PositiveInt(100)),
                new PositiveInt(100),
                Ratio::of(1, 2),
                Duration::seconds(30),
                new PositiveInt(1),
                new PositiveInt(1),
                Duration::seconds(10),
            ) : null,
            $concurrency ? new ConcurrencyPolicy(self::identity('concurrency'), new PositiveInt(1000), Duration::seconds(10)) : null,
            $retry ? new RetryBudgetPolicy(self::identity('retry-budget'), new PositiveInt(1000), new PositiveInt(1000), Duration::seconds(60), Duration::seconds(10)) : null,
            $cooldown ? new CooldownPolicy(self::identity('cooldown'), Duration::seconds(60)) : null,
            false,
        );

        $pipeline = $definition->compile(new RuntimeEnvironment(
            $clock,
            new BoundedBlockingScheduler($clock),
            new SecureRandomSource(),
            new BenchClassifier(),
            new SynchronousRuntimeAdapter(SchedulingCapability::BoundedBlocking),
            new NullEventSink(),
            $rate ? new MemoryRateLimitBackend($clock) : null,
            $circuit ? new MemoryCircuitBackend($clock) : null,
            $concurrency ? new MemoryConcurrencyBackend($clock) : null,
            $retry ? new MemoryRetryBudgetBackend($clock) : null,
            $cooldown ? new MemoryCooldownBackend($clock) : null,
        ));

        $partition = self::partition();
        // A retry-enabled definition requires an explicit replay-safety statement even when no retry happens.
        $metadata = new OperationMetadata($retry ? self::replayable() : OperationSafety::unsafe(), BoundedMetadata::empty());
        $never = new NeverCancelled();
        $i = 0;
        $callable = static fn(AttemptContext $attempt): int => 1;

        return static function () use ($pipeline, $clock, $partition, $metadata, $never, &$i, $callable): mixed {
            return $pipeline->execute(
                $callable,
                new ExecutionContext(new OperationId('b' . ++$i), $partition, Deadline::after(Duration::seconds(30), $clock), $never, $metadata, null),
            );
        };
    }
}
