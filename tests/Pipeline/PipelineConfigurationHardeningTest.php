<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Pipeline;

use Enaxon\Reqtide\Exception\CapabilityMismatchException;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Execution\OperationSafety;
use Enaxon\Reqtide\Execution\ReplayPreparation;
use Enaxon\Reqtide\Execution\ReplaySafetyContract;
use Enaxon\Reqtide\Fallback\FallbackScope;
use Enaxon\Reqtide\Pipeline\PipelineBuilder;
use Enaxon\Reqtide\Pipeline\PipelineDefinition;
use Enaxon\Reqtide\Pipeline\RuntimeEnvironment;
use Enaxon\Reqtide\Policy\AttemptOptions;
use Enaxon\Reqtide\Policy\AttemptTimeoutRequirement;
use Enaxon\Reqtide\Policy\CircuitPolicy;
use Enaxon\Reqtide\Policy\ConcurrencyPolicy;
use Enaxon\Reqtide\Policy\CountCircuitWindow;
use Enaxon\Reqtide\Policy\RateLimitPolicy;
use Enaxon\Reqtide\Policy\RetryBudgetPolicy;
use Enaxon\Reqtide\Policy\RetryOptions;
use Enaxon\Reqtide\Policy\TokenBucketOptions;
use Enaxon\Reqtide\Runtime\SchedulingCapability;
use Enaxon\Reqtide\Runtime\SynchronousRuntimeAdapter;
use Enaxon\Reqtide\State\StateScope;
use Enaxon\Reqtide\Tests\Pipeline\Support\DispatchSpy;
use Enaxon\Reqtide\Tests\Pipeline\Support\Harness;
use Enaxon\Reqtide\Tests\Pipeline\Support\RecordingStrategy;
use Enaxon\Reqtide\Tests\Pipeline\Support\SpyOperation;
use Enaxon\Reqtide\Tests\Pipeline\Support\StubCapabilityScheduler;
use Enaxon\Reqtide\Time\NoDelayScheduler;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\PositiveInt;
use Enaxon\Reqtide\Value\ProfileId;
use Enaxon\Reqtide\Value\ProfileVersion;
use Enaxon\Reqtide\Value\Ratio;
use PHPUnit\Framework\TestCase;

/** Exact limits and messages of definition validation, compile checks, explain() and the builder. */
final class PipelineConfigurationHardeningTest extends TestCase
{
    private const MAX_TOKENS = 9_007_199_254;

    private static function attempt(int $seconds = 30): AttemptOptions
    {
        return new AttemptOptions(Duration::seconds($seconds), AttemptTimeoutRequirement::Cooperative);
    }

    /**
     * @param array<array-key, mixed> $extensions
     */
    private static function definition(
        ?RateLimitPolicy $rate = null,
        ?int $cost = null,
        ?CircuitPolicy $circuit = null,
        ?ConcurrencyPolicy $concurrency = null,
        ?RetryBudgetPolicy $budget = null,
        array $extensions = [],
        ?AttemptOptions $attempt = null,
        ?RetryOptions $retry = null,
    ): PipelineDefinition {
        return new PipelineDefinition(
            new ProfileId('profile'),
            new ProfileVersion('v1'),
            $retry ?? RetryOptions::noRetry(),
            $attempt ?? self::attempt(),
            $rate,
            $cost === null ? null : new PositiveInt($cost),
            $circuit,
            $concurrency,
            $budget,
            null,
            false,
            $extensions, // @phpstan-ignore argument.type (tests deliberately pass invalid extension lists)
        );
    }

    private static function rate(string $policy = 'rate'): RateLimitPolicy
    {
        return new RateLimitPolicy(Harness::identity($policy), new TokenBucketOptions(new PositiveInt(self::MAX_TOKENS), new PositiveInt(1), Duration::seconds(1)));
    }

    private static function concurrency(int $leaseSeconds, int $leaseNanosecondsExtra = 0): ConcurrencyPolicy
    {
        return new ConcurrencyPolicy(Harness::identity('concurrency'), new PositiveInt(1), new Duration($leaseSeconds * 1_000_000_000 + $leaseNanosecondsExtra));
    }

    // ---- construction limits ---------------------------------------------------------------------------

    public function testTheLargestPortableRateCostIsAcceptedAndOneMoreIsNot(): void
    {
        $definition = self::definition(self::rate(), self::MAX_TOKENS);
        self::assertSame(self::MAX_TOKENS, $definition->rateCost?->value);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('rateCost must not exceed 9007199254 tokens.');
        self::definition(self::rate(), self::MAX_TOKENS + 1);
    }

    public function testAtMostThirtyTwoExtensionsAreAcceptedAndTheyMustFormAList(): void
    {
        $thirtyTwo = array_map(static fn(int $i): RecordingStrategy => new RecordingStrategy(), range(1, 32));
        self::assertCount(32, self::definition(extensions: $thirtyTwo)->extensions);

        foreach ([
            'thirty-three' => [...$thirtyTwo, new RecordingStrategy()],
            'not a list' => ['a' => new RecordingStrategy()],
            'reindexed gap' => [1 => new RecordingStrategy()],
        ] as $name => $extensions) {
            try {
                self::definition(extensions: $extensions);
                self::fail($name . ' must be rejected.');
            } catch (InvalidConfigurationException $e) {
                self::assertSame('extensions must be a list of at most 32 strategies.', $e->getMessage(), $name);
            }
        }
    }

    public function testEveryExtensionMustBeAStrategy(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Every extension must implement Strategy.');
        self::definition(extensions: [new RecordingStrategy(), new \stdClass()]);
    }

    public function testEveryPolicyIdentityMustCarryTheDefinitionProfileVersion(): void
    {
        $foreign = new RateLimitPolicy(
            new \Enaxon\Reqtide\Value\PolicyIdentity(\Enaxon\Reqtide\Tests\Support\Fixtures::partition(), new ProfileVersion('v2'), new \Enaxon\Reqtide\Value\PolicyId('rate')),
            new TokenBucketOptions(new PositiveInt(5), new PositiveInt(1), Duration::seconds(1)),
        );

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Every policy identity must carry the definition profile version.');
        self::definition($foreign, 1);
    }

    public function testThePolicyVersionIsCheckedForEveryPolicyNotJustTheFirst(): void
    {
        $foreignConcurrency = new ConcurrencyPolicy(
            new \Enaxon\Reqtide\Value\PolicyIdentity(\Enaxon\Reqtide\Tests\Support\Fixtures::partition(), new ProfileVersion('v2'), new \Enaxon\Reqtide\Value\PolicyId('concurrency')),
            new PositiveInt(1),
            Duration::seconds(60),
        );

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Every policy identity must carry the definition profile version.');
        self::definition(self::rate(), 1, null, $foreignConcurrency);
    }

    // ---- lease versus attempt --------------------------------------------------------------------------

    public function testALeaseMustExceedTheAttemptByMoreThanTheMarginAndNamesTheOffendingPolicy(): void
    {
        // attempt 30 s: lease 31 s is exactly the margin (invalid); one nanosecond more is valid.
        $ok = self::definition(concurrency: self::concurrency(31, 1));
        self::assertNotNull($ok->concurrency);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The concurrency lease must exceed attempt.maximumDuration plus a 1 second cleanup margin.');
        self::definition(concurrency: self::concurrency(31));
    }

    public function testEachLeaseKindIsCheckedEvenWhenAnEarlierKindIsAbsent(): void
    {
        $circuit = new CircuitPolicy(
            Harness::identity('circuit'),
            new CountCircuitWindow(new PositiveInt(10)),
            new PositiveInt(5),
            Ratio::of(1, 2),
            Duration::seconds(30),
            new PositiveInt(1),
            new PositiveInt(1),
            Duration::seconds(31),
        );
        $budget = new RetryBudgetPolicy(Harness::identity('budget'), new PositiveInt(5), new PositiveInt(5), Duration::seconds(60), Duration::seconds(31));

        foreach ([
            'The circuit permitLease must exceed attempt.maximumDuration plus a 1 second cleanup margin.' => static fn(): PipelineDefinition => self::definition(circuit: $circuit),
            'The retry budget reservationLease must exceed attempt.maximumDuration plus a 1 second cleanup margin.' => static fn(): PipelineDefinition => self::definition(budget: $budget),
        ] as $message => $build) {
            try {
                $build();
                self::fail($message);
            } catch (InvalidConfigurationException $e) {
                self::assertSame($message, $e->getMessage());
            }
        }
    }

    // ---- compile-time capability checks ---------------------------------------------------------------

    private static function withRuntime(Harness $h, SynchronousRuntimeAdapter $runtime, \Enaxon\Reqtide\Time\DelayScheduler $scheduler): RuntimeEnvironment
    {
        $base = $h->environment();

        return new RuntimeEnvironment($base->clock, $scheduler, $base->random, $base->classifier, $runtime, $base->events, $base->rateLimit, $base->circuit, $base->concurrency, $base->retryBudget, $base->cooldown);
    }

    public function testAnAdapterDrivenQueueSchedulerNeedsAnAdapterOwnedQueueEvenWithoutRetries(): void
    {
        $h = new Harness();

        try {
            $h->definition()->compile(self::withRuntime($h, new SynchronousRuntimeAdapter(SchedulingCapability::BoundedBlocking), new StubCapabilityScheduler(SchedulingCapability::AdapterDrivenQueue)));
            self::fail('The scheduler and the runtime queue disagree.');
        } catch (CapabilityMismatchException $e) {
            self::assertSame('An adapter-driven queue scheduler requires an adapter-owned runtime queue.', $e->getMessage());
        }
    }

    public function testASingleAttemptNeverNeedsADelayScheduler(): void
    {
        $h = new Harness();

        $pipeline = $h->definition(Harness::retry(1, 100, 1_000))->compile(self::withRuntime($h, new SynchronousRuntimeAdapter(), new NoDelayScheduler($h->clock)));

        self::assertSame(['attempt', 'retry(maxAttempts=1)'], $pipeline->explain()->enabledPolicies);
    }

    public function testRetriesWithRealDelaysNeedASchedulerThatCanWaitAndTheMessageSaysSo(): void
    {
        $h = new Harness(false, false, false, true);

        try {
            $h->definition(Harness::retry(2, 100, 1_000))->compile(self::withRuntime($h, new SynchronousRuntimeAdapter(), new NoDelayScheduler($h->clock)));
            self::fail('Retry delays require a scheduler that can wait.');
        } catch (CapabilityMismatchException $e) {
            self::assertSame('Retry delays require a delay scheduler that can wait.', $e->getMessage());
        }
    }

    public function testTheFiniteIoRequirementNamesTheBackendThatDeclaresNoTimeout(): void
    {
        foreach ([
            'rate limit' => [new Harness(false, false, true), static fn(Harness $h) => $h->rate->scope = StateScope::SharedAuthority],
            'circuit' => [new Harness(true), static fn(Harness $h) => $h->circuit->scope = StateScope::SharedAuthority],
            'concurrency' => [new Harness(false, true), static fn(Harness $h) => $h->concurrency->scope = StateScope::SharedAuthority],
            'retry budget' => [new Harness(false, false, false, true), static fn(Harness $h) => $h->budget->scope = StateScope::SharedAuthority],
            'cooldown' => [new Harness(false, false, false, false, true), static fn(Harness $h) => $h->cooldown->scope = StateScope::SharedAuthority],
        ] as $label => [$h, $makeShared]) {
            $makeShared($h);
            try {
                $h->definition(null, true)->compile($h->environment());
                self::fail($label . ' without a timeout must be rejected.');
            } catch (CapabilityMismatchException $e) {
                self::assertSame('The ' . $label . ' backend declares no finite I/O timeout.', $e->getMessage());
            }
        }
    }

    // ---- explain ----------------------------------------------------------------------------------------

    public function testExplainListsEveryEnabledPolicyAndTheRuntimeCapabilitiesVerbatim(): void
    {
        $h = new Harness(true, true, true, true, true);
        $explanation = $h->definition(Harness::retry(3), true)->compile($h->environment())->explain();

        self::assertSame(['attempt', 'retry(maxAttempts=3)', 'rate_limit', 'circuit', 'concurrency', 'retry_budget', 'cooldown'], $explanation->enabledPolicies);
        self::assertSame([
            'invocation=synchronous',
            'settlement=inline_return_or_throw',
            'attempt_timeout=unsupported',
            'cancellation=checkpoint_only',
            'scheduling=bounded_blocking',
            'queue_ownership=none',
            'detached_completion=unsupported',
            'nonblocking=not_claimed',
            'physical_stop=not_guaranteed',
            'require_finite_backend_io=true',
        ], $explanation->capabilityNotes);
    }

    public function testExplainReportsAnOptionalFiniteIoAndAnOptInFallbackScope(): void
    {
        $h = new Harness(true);
        $h->fallbackScope = FallbackScope::IncludeAdmissionRefusals;
        $notes = $h->definition(null, false)->compile($h->environment())->explain()->capabilityNotes;

        self::assertContains('require_finite_backend_io=false', $notes);
        self::assertSame('fallback_scope=' . FallbackScope::IncludeAdmissionRefusals->value, end($notes));
        self::assertSame(10 + 1, count($notes));
    }

    // ---- which call shapes may retry ----------------------------------------------------------------------

    public function testDetailedExecutionOfAGenericCallableAlsoNeedsACallableAssertionToRetry(): void
    {
        $h = new Harness(true, true, true, true);
        $rewind = OperationSafety::replayableRead(new ReplaySafetyContract(
            'contract-1',
            'identity-1',
            'scope-1',
            'safe-repeat',
            new \DateTimeImmutable('2026-10-07T00:00:00+00:00'),
            new \DateTimeImmutable('2026-10-09T00:00:00+00:00'),
            Duration::seconds(3600),
            ReplayPreparation::VerifiedRewind,
            ['transient'],
        ));
        $pipeline = $h->pipeline(Harness::retry(3));
        $spy = DispatchSpy::returning('v', $h->log);

        try {
            $pipeline->executeDetailed($spy->callable(), $h->context(Duration::seconds(5), null, $rewind));
            self::fail('A rewind proof says nothing about a plain callable.');
        } catch (InvalidConfigurationException) {
            self::assertSame(0, $spy->dispatches);
        }

        $completion = $pipeline->executeOperationDetailed(new SpyOperation($h->log), $h->context(Duration::seconds(5), null, $rewind));
        self::assertSame(1, $completion->summary->dispatched);
    }

    // ---- builder messages ----------------------------------------------------------------------------------

    public function testTheBuilderNamesWhatIsMissing(): void
    {
        try {
            PipelineBuilder::profile('a', 'v1')->attemptTimeout(Duration::seconds(1), AttemptTimeoutRequirement::Cooperative)->definition();
            self::fail('partition() is required.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame(
                "partition() is required: policies need the application, environment and subject they protect, for example ->partition('shop', 'prod', 'payments-api').",
                $e->getMessage(),
            );
        }

        try {
            PipelineBuilder::profile('a', 'v1')->partition('app', 'env', 'subject')->definition();
            self::fail('attemptTimeout() is required.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame(
                'attemptTimeout() is required: every attempt needs an explicit maximum duration, for example ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::Cooperative).',
                $e->getMessage(),
            );
        }
    }

    public function testTheBuilderExplainsRetryWithoutABudgetAndAnInertFallbackScope(): void
    {
        $base = PipelineBuilder::profile('a', 'v1')->partition('app', 'env', 'subject')->attemptTimeout(Duration::seconds(1), AttemptTimeoutRequirement::Cooperative);

        try {
            $base->retry(3, \Enaxon\Reqtide\Policy\BackoffMode::Constant, Duration::milliseconds(10), Duration::milliseconds(10), \Enaxon\Reqtide\Policy\JitterMode::None)->definition();
            self::fail('A retry without a budget must be rejected.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame(
                'retry(maxAttempts=3) requires retryBudget(): retries must be paid for so a failing dependency is not hammered. '
                . 'Every call also needs an explicit replay-safety assertion, for example ReplaySafetyContract::forReadOnlyCallable(); '
                . 'Reqtide never infers that repeating a callable is safe.',
                $e->getMessage(),
            );
        }

        try {
            $base->fallbackScope(FallbackScope::IncludeAdmissionRefusals)->definition();
            self::fail('An opt-in fallback scope without any admission policy has no effect.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame(
                'fallbackScope(IncludeAdmissionRefusals) has no effect without rateLimit(), circuitBreaker(), bulkhead() or cooldown(): there is no admission refusal to replace.',
                $e->getMessage(),
            );
        }
    }

    public function testTheBuilderReportsLeasesInMillisecondsOrNanosecondsWithTheMinimumToUse(): void
    {
        $milliseconds = PipelineBuilder::profile('a', 'v1')->partition('app', 'env', 'subject')
            ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::Cooperative)
            ->bulkhead(1, Duration::seconds(3));
        try {
            $milliseconds->definition();
            self::fail('A lease equal to attempt + margin is too short.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame(
                'bulkhead() lease (3000 ms) must exceed attemptTimeout() (2000 ms) plus a 1 second cleanup margin; use more than 3000 ms.',
                $e->getMessage(),
            );
        }

        $nanoseconds = PipelineBuilder::profile('a', 'v1')->partition('app', 'env', 'subject')
            ->attemptTimeout(Duration::nanoseconds(1_500_000_001), AttemptTimeoutRequirement::Cooperative)
            ->bulkhead(1, Duration::nanoseconds(2_500_000_001));
        try {
            $nanoseconds->definition();
            self::fail('A lease equal to attempt + margin is too short.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame(
                'bulkhead() lease (2500000001 ns) must exceed attemptTimeout() (1500000001 ns) plus a 1 second cleanup margin; use more than 2500000001 ns.',
                $e->getMessage(),
            );
        }
    }

    public function testTheBuilderPrefixesNestedValidationMessagesWithTheOptionAndKeepsTheCause(): void
    {
        try {
            PipelineBuilder::profile('a', 'v1')->rateLimit(PipelineBuilder::tokenBucket(5, 5, Duration::seconds(1)), 0);
            self::fail('A zero cost must be rejected.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame('rateLimit() cost must be at least 1, got 0.', $e->getMessage());
        }

        try {
            PipelineBuilder::tokenBucket(5, 5, Duration::zero());
            self::fail('A zero refill interval must be rejected.');
        } catch (InvalidConfigurationException $e) {
            self::assertStringStartsWith('tokenBucket(): ', $e->getMessage());
            self::assertSame(0, $e->getCode());
            self::assertInstanceOf(InvalidConfigurationException::class, $e->getPrevious());
        }
    }
}
