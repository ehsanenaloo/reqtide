<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Pipeline\Support;

use Enaxon\Reqtide\Execution\BoundedMetadata;
use Enaxon\Reqtide\Execution\ExecutionContext;
use Enaxon\Reqtide\Execution\OperationMetadata;
use Enaxon\Reqtide\Execution\OperationSafety;
use Enaxon\Reqtide\Execution\ReplayPreparation;
use Enaxon\Reqtide\Execution\ReplaySafetyContract;
use Enaxon\Reqtide\Fallback\FallbackOperation;
use Enaxon\Reqtide\Fallback\FallbackScope;
use Enaxon\Reqtide\Pipeline\CompiledPipeline;
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
use Enaxon\Reqtide\Runtime\RuntimeAdapter;
use Enaxon\Reqtide\Runtime\SchedulingCapability;
use Enaxon\Reqtide\Runtime\SynchronousRuntimeAdapter;
use Enaxon\Reqtide\State\RetryBudgetBackend;
use Enaxon\Reqtide\Tests\Support\Fixtures;
use Enaxon\Reqtide\Time\CancellationToken;
use Enaxon\Reqtide\Time\Deadline;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\OperationId;
use Enaxon\Reqtide\Value\PolicyId;
use Enaxon\Reqtide\Value\PolicyIdentity;
use Enaxon\Reqtide\Value\PositiveInt;
use Enaxon\Reqtide\Value\ProfileId;
use Enaxon\Reqtide\Value\ProfileVersion;
use Enaxon\Reqtide\Value\Ratio;

/**
 * Assembles a pipeline over hand-written fakes. Every fake always exists; the constructor flags choose which
 * policies and backends are actually installed in the definition and environment.
 */
final class Harness
{
    public readonly PipelineClock $clock;

    public readonly CallLog $log;

    public readonly RecordingSink $sink;

    public readonly FakeScheduler $scheduler;

    public readonly ScriptedRandom $random;

    public readonly ScriptedClassifier $classifier;

    public readonly CancellationToken $cancel;

    public readonly SynchronousRuntimeAdapter $runtime;

    public readonly FakeCircuitBackend $circuit;

    public readonly FakeConcurrencyBackend $concurrency;

    public readonly FakeRateLimitBackend $rate;

    public readonly FakeRetryBudgetBackend $budget;

    public readonly FakeCooldownBackend $cooldown;

    public int $rateCost = 1;

    /** Replaces the synchronous runtime in environment() when set. */
    public ?RuntimeAdapter $runtimeOverride = null;

    /** Replaces the fake retry-budget backend in environment() when set (for example a memory backend). */
    public ?RetryBudgetBackend $budgetOverride = null;

    public FallbackScope $fallbackScope = FallbackScope::BusinessOutcomesOnly;

    public function __construct(
        private readonly bool $useCircuit = false,
        private readonly bool $useConcurrency = false,
        private readonly bool $useRate = false,
        private readonly bool $useBudget = false,
        private readonly bool $useCooldown = false,
        bool $highJitter = true,
    ) {
        $this->clock = new PipelineClock();
        $this->log = new CallLog();
        $this->sink = new RecordingSink($this->log);
        $this->scheduler = new FakeScheduler($this->clock, $this->log);
        $this->random = new ScriptedRandom($highJitter);
        $this->classifier = new ScriptedClassifier();
        $this->cancel = new CancellationToken();
        $this->runtime = new SynchronousRuntimeAdapter(SchedulingCapability::BoundedBlocking);
        $this->circuit = new FakeCircuitBackend($this->log);
        $this->concurrency = new FakeConcurrencyBackend($this->log);
        $this->rate = new FakeRateLimitBackend($this->log);
        $this->budget = new FakeRetryBudgetBackend($this->log);
        $this->cooldown = new FakeCooldownBackend($this->log);
    }

    public static function identity(string $policy): PolicyIdentity
    {
        return new PolicyIdentity(Fixtures::partition(), new ProfileVersion('v1'), new PolicyId($policy));
    }

    public static function retry(
        int $attempts,
        int $baseMilliseconds = 100,
        int $maxMilliseconds = 10_000,
        BackoffMode $backoff = BackoffMode::Exponential,
        JitterMode $jitter = JitterMode::None,
        int $allowanceMilliseconds = 0,
    ): RetryOptions {
        return new RetryOptions(
            new PositiveInt($attempts),
            $backoff,
            Duration::milliseconds($baseMilliseconds),
            Duration::milliseconds($maxMilliseconds),
            $jitter,
            Duration::milliseconds($allowanceMilliseconds),
        );
    }

    public function definition(
        ?RetryOptions $retry = null,
        bool $requireFiniteBackendIo = false,
        AttemptTimeoutRequirement $timeout = AttemptTimeoutRequirement::Cooperative,
    ): PipelineDefinition {
        return new PipelineDefinition(
            new ProfileId('profile'),
            new ProfileVersion('v1'),
            $retry ?? RetryOptions::noRetry(),
            new AttemptOptions(Duration::seconds(30), $timeout),
            $this->useRate ? new RateLimitPolicy(self::identity('rate'), new TokenBucketOptions(new PositiveInt(10), new PositiveInt(10), Duration::seconds(1))) : null,
            $this->useRate ? new PositiveInt($this->rateCost) : null,
            $this->useCircuit ? new CircuitPolicy(
                self::identity('circuit'),
                new CountCircuitWindow(new PositiveInt(10)),
                new PositiveInt(5),
                new Ratio(new PositiveInt(1), new PositiveInt(2)),
                Duration::seconds(30),
                new PositiveInt(1),
                new PositiveInt(1),
                Duration::seconds(60),
            ) : null,
            $this->useConcurrency ? new ConcurrencyPolicy(self::identity('concurrency'), new PositiveInt(2), Duration::seconds(60)) : null,
            $this->useBudget ? new RetryBudgetPolicy(self::identity('budget'), new PositiveInt(10), new PositiveInt(10), Duration::seconds(60), Duration::seconds(60)) : null,
            $this->useCooldown ? new CooldownPolicy(self::identity('cooldown'), Duration::seconds(60)) : null,
            $requireFiniteBackendIo,
            fallbackScope: $this->fallbackScope,
        );
    }

    public function environment(): RuntimeEnvironment
    {
        return new RuntimeEnvironment(
            $this->clock,
            $this->scheduler,
            $this->random,
            $this->classifier,
            $this->runtimeOverride ?? $this->runtime,
            $this->sink,
            $this->useRate ? $this->rate : null,
            $this->useCircuit ? $this->circuit : null,
            $this->useConcurrency ? $this->concurrency : null,
            $this->useBudget ? ($this->budgetOverride ?? $this->budget) : null,
            $this->useCooldown ? $this->cooldown : null,
        );
    }

    public function pipeline(?RetryOptions $retry = null, bool $requireFiniteBackendIo = false): CompiledPipeline
    {
        return $this->definition($retry, $requireFiniteBackendIo)->compile($this->environment());
    }

    /**
     * @param FallbackOperation<mixed>|null $fallback
     * @return ExecutionContext<mixed>
     */
    public function context(Duration $budget, ?FallbackOperation $fallback = null, ?OperationSafety $safety = null): ExecutionContext
    {
        return new ExecutionContext(
            new OperationId('op-1'),
            Fixtures::partition(),
            Deadline::after($budget, $this->clock),
            $this->cancel,
            new OperationMetadata($safety ?? OperationSafety::unsafe(), BoundedMetadata::empty()),
            $fallback,
        );
    }

    /** Explicit callable replay assertion: the operation may be repeated within the hour. */
    public static function replayable(string ...$retryableCodes): OperationSafety
    {
        $now = new \DateTimeImmutable('2026-10-08T00:00:00+00:00');

        return OperationSafety::replayableRead(new ReplaySafetyContract(
            'contract-1',
            'identity-1',
            'scope-1',
            'safe-repeat',
            $now->modify('-1 hour'),
            $now->modify('+1 hour'),
            Duration::seconds(3600),
            ReplayPreparation::CallableAssertion,
            $retryableCodes === [] ? ['transient'] : array_values($retryableCodes),
        ));
    }
}
