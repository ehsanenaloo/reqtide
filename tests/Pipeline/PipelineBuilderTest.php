<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Pipeline;

use Enaxon\Reqtide\Error\ReasonCode;
use Enaxon\Reqtide\Exception\CapabilityMismatchException;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Fallback\FallbackScope;
use Enaxon\Reqtide\Outcome\ExceptionClassifier;
use Enaxon\Reqtide\Pipeline\InMemoryEnvironment;
use Enaxon\Reqtide\Pipeline\PipelineBuilder;
use Enaxon\Reqtide\Pipeline\PipelineDefinition;
use Enaxon\Reqtide\Pipeline\RuntimeEnvironment;
use Enaxon\Reqtide\Policy\AttemptOptions;
use Enaxon\Reqtide\Policy\AttemptTimeoutRequirement;
use Enaxon\Reqtide\Policy\BackoffMode;
use Enaxon\Reqtide\Policy\CircuitPolicy;
use Enaxon\Reqtide\Policy\ConcurrencyPolicy;
use Enaxon\Reqtide\Policy\CooldownPolicy;
use Enaxon\Reqtide\Policy\CountCircuitWindow;
use Enaxon\Reqtide\Policy\FixedWindowAnchor;
use Enaxon\Reqtide\Policy\FixedWindowOptions;
use Enaxon\Reqtide\Policy\JitterMode;
use Enaxon\Reqtide\Policy\RateLimitPolicy;
use Enaxon\Reqtide\Policy\RetryBudgetPolicy;
use Enaxon\Reqtide\Policy\RetryOptions;
use Enaxon\Reqtide\Policy\SlidingCounterOptions;
use Enaxon\Reqtide\Policy\TimeCircuitWindow;
use Enaxon\Reqtide\Policy\TokenBucketOptions;
use Enaxon\Reqtide\Runtime\SchedulingCapability;
use Enaxon\Reqtide\Runtime\SynchronousRuntimeAdapter;
use Enaxon\Reqtide\Telemetry\NullEventSink;
use Enaxon\Reqtide\Time\BoundedBlockingScheduler;
use Enaxon\Reqtide\Time\SecureRandomSource;
use Enaxon\Reqtide\Time\SystemClock;
use Enaxon\Reqtide\Value\ApplicationId;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\EnvironmentId;
use Enaxon\Reqtide\Value\PartitionIdentity;
use Enaxon\Reqtide\Value\PolicyId;
use Enaxon\Reqtide\Value\PolicyIdentity;
use Enaxon\Reqtide\Value\PositiveInt;
use Enaxon\Reqtide\Value\ProfileId;
use Enaxon\Reqtide\Value\ProfileVersion;
use Enaxon\Reqtide\Value\Ratio;
use Enaxon\Reqtide\Value\SubjectId;
use Enaxon\Reqtide\Value\TenantId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The builder's validation matrix and its equivalence with the explicit PipelineDefinition constructor. */
final class PipelineBuilderTest extends TestCase
{
    /** The smallest valid builder: no retry, no optional policy. */
    private static function minimal(): PipelineBuilder
    {
        return PipelineBuilder::profile('orders', 'v1')
            ->partition('shop', 'prod', 'orders-api')
            ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::Cooperative);
    }

    /** Every optional policy configured. */
    private static function full(): PipelineBuilder
    {
        return self::minimal()
            ->retry(3, BackoffMode::Exponential, Duration::milliseconds(100), Duration::seconds(1), JitterMode::Full, Duration::milliseconds(50))
            ->retryBudget(10, 5, Duration::seconds(60), Duration::seconds(10))
            ->rateLimit(PipelineBuilder::tokenBucket(20, 10, Duration::seconds(1)), 2)
            ->circuitBreaker(PipelineBuilder::lastCalls(10), 4, Ratio::of(1, 2), Duration::seconds(30), 2, 1, Duration::seconds(10))
            ->bulkhead(8, Duration::seconds(10))
            ->cooldown(Duration::seconds(5))
            ->fallbackScope(FallbackScope::IncludeAdmissionRefusals);
    }

    private static function identity(string $policy, ?string $tenant = null): PolicyIdentity
    {
        return new PolicyIdentity(
            new PartitionIdentity(new ApplicationId('shop'), new EnvironmentId('prod'), $tenant === null ? null : new TenantId($tenant), new SubjectId('orders-api')),
            new ProfileVersion('v1'),
            new PolicyId($policy),
        );
    }

    public function testTheMinimalBuilderEqualsTheExplicitConstructorWithNoRetryAndNoPolicies(): void
    {
        $expected = new PipelineDefinition(
            new ProfileId('orders'),
            new ProfileVersion('v1'),
            RetryOptions::noRetry(),
            new AttemptOptions(Duration::seconds(2), AttemptTimeoutRequirement::Cooperative),
            null,
            null,
            null,
            null,
            null,
            null,
            false,
            [],
            FallbackScope::BusinessOutcomesOnly,
            self::identity('rate-limit')->partition,
        );

        self::assertEquals($expected, self::minimal()->definition());
    }

    public function testAPipelineWithNoStatePolicyKeepsTheBuilderPartition(): void
    {
        $definition = self::minimal()->definition();

        self::assertNotNull($definition->partition);
        self::assertTrue($definition->partition->equals(self::identity('rate-limit')->partition));
    }

    public function testNewContextUsesTheBuilderPartitionWithoutAnyStatePolicy(): void
    {
        $pipeline = self::minimal()->compileInMemory(ExceptionClassifier::create());

        $context = $pipeline->newContext(Duration::seconds(1));

        self::assertTrue($context->partition->equals(self::identity('rate-limit')->partition));
    }

    public function testNewContextKeepsATenantPartitionWithoutAnyStatePolicy(): void
    {
        $pipeline = PipelineBuilder::profile('orders', 'v1')
            ->partition('shop', 'prod', 'orders-api', 'acme')
            ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::Cooperative)
            ->compileInMemory(ExceptionClassifier::create());

        self::assertTrue($pipeline->newContext(Duration::seconds(1))->partition->equals(self::identity('x', 'acme')->partition));
    }

    public function testAnExplicitPartitionStillOverridesTheDefinitionPartition(): void
    {
        $pipeline = self::minimal()->compileInMemory(ExceptionClassifier::create());
        $other = self::identity('x', 'other')->partition;

        self::assertTrue($pipeline->newContext(Duration::seconds(1), partition: $other)->partition->equals($other));
    }

    public function testAHandBuiltDefinitionWithoutPartitionOrPolicyStillNeedsAnExplicitPartition(): void
    {
        $definition = new PipelineDefinition(
            new ProfileId('orders'),
            new ProfileVersion('v1'),
            RetryOptions::noRetry(),
            new AttemptOptions(Duration::seconds(2), AttemptTimeoutRequirement::Cooperative),
            null,
            null,
            null,
            null,
            null,
            null,
            false,
        );
        self::assertNull($definition->partition);
        $pipeline = $definition->compile(InMemoryEnvironment::for($definition, ExceptionClassifier::create()));

        try {
            $pipeline->newContext(Duration::seconds(1));
            self::fail('Expected InvalidConfigurationException.');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString('Pass partition: explicitly', $e->getMessage());
        }
        $partition = self::identity('x')->partition;
        self::assertTrue($pipeline->newContext(Duration::seconds(1), partition: $partition)->partition->equals($partition));
    }

    public function testAHandBuiltDefinitionDerivesItsPartitionFromThePolicies(): void
    {
        $definition = new PipelineDefinition(
            new ProfileId('orders'),
            new ProfileVersion('v1'),
            RetryOptions::noRetry(),
            new AttemptOptions(Duration::seconds(2), AttemptTimeoutRequirement::Cooperative),
            null,
            null,
            null,
            new ConcurrencyPolicy(self::identity('bulkhead', 'acme'), new PositiveInt(1), Duration::seconds(10)),
            null,
            null,
            false,
        );

        self::assertNotNull($definition->partition);
        self::assertTrue($definition->partition->equals(self::identity('bulkhead', 'acme')->partition));
    }

    public function testAnExplicitDefinitionPartitionMustMatchEveryPolicyPartition(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('share one partition identity');
        new PipelineDefinition(
            new ProfileId('orders'),
            new ProfileVersion('v1'),
            RetryOptions::noRetry(),
            new AttemptOptions(Duration::seconds(2), AttemptTimeoutRequirement::Cooperative),
            null,
            null,
            null,
            new ConcurrencyPolicy(self::identity('bulkhead', 'acme'), new PositiveInt(1), Duration::seconds(10)),
            null,
            null,
            false,
            [],
            FallbackScope::BusinessOutcomesOnly,
            self::identity('x')->partition,
        );
    }

    public function testTheFullBuilderEqualsTheExplicitConstructorPolicyForPolicy(): void
    {
        $expected = new PipelineDefinition(
            new ProfileId('orders'),
            new ProfileVersion('v1'),
            new RetryOptions(new PositiveInt(3), BackoffMode::Exponential, Duration::milliseconds(100), Duration::seconds(1), JitterMode::Full, Duration::milliseconds(50)),
            new AttemptOptions(Duration::seconds(2), AttemptTimeoutRequirement::Cooperative),
            new RateLimitPolicy(self::identity('rate-limit'), new TokenBucketOptions(new PositiveInt(20), new PositiveInt(10), Duration::seconds(1))),
            new PositiveInt(2),
            new CircuitPolicy(self::identity('circuit-breaker'), new CountCircuitWindow(new PositiveInt(10)), new PositiveInt(4), Ratio::of(1, 2), Duration::seconds(30), new PositiveInt(2), new PositiveInt(1), Duration::seconds(10)),
            new ConcurrencyPolicy(self::identity('bulkhead'), new PositiveInt(8), Duration::seconds(10)),
            new RetryBudgetPolicy(self::identity('retry-budget'), new PositiveInt(10), new PositiveInt(5), Duration::seconds(60), Duration::seconds(10)),
            new CooldownPolicy(self::identity('cooldown'), Duration::seconds(5)),
            false,
            [],
            FallbackScope::IncludeAdmissionRefusals,
        );

        self::assertEquals($expected, self::full()->definition());
    }

    public function testATenantEntersEveryPolicyIdentity(): void
    {
        $definition = PipelineBuilder::profile('orders', 'v1')
            ->partition('shop', 'prod', 'orders-api', 'acme')
            ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::Cooperative)
            ->bulkhead(2, Duration::seconds(10))
            ->definition();

        self::assertNotNull($definition->concurrency);
        self::assertTrue($definition->concurrency->identity->equals(self::identity('bulkhead', 'acme')));
    }

    public function testCallOrderDoesNotMatter(): void
    {
        $forward = self::minimal()
            ->retry(2, BackoffMode::Constant, Duration::milliseconds(5), Duration::milliseconds(5), JitterMode::None)
            ->retryBudget(3, 3, Duration::seconds(10), Duration::seconds(10))
            ->bulkhead(2, Duration::seconds(10));
        $reverse = PipelineBuilder::profile('orders', 'v1')
            ->bulkhead(2, Duration::seconds(10))
            ->retryBudget(3, 3, Duration::seconds(10), Duration::seconds(10))
            ->retry(2, BackoffMode::Constant, Duration::milliseconds(5), Duration::milliseconds(5), JitterMode::None)
            ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::Cooperative)
            ->partition('shop', 'prod', 'orders-api');

        self::assertEquals($forward->definition(), $reverse->definition());
    }

    public function testEveryCallReturnsANewBuilderAndLeavesTheBaseUntouched(): void
    {
        $base = self::minimal();
        $derived = $base->bulkhead(2, Duration::seconds(10))->fallbackScope(FallbackScope::IncludeAdmissionRefusals);

        self::assertNotSame($base, $derived);
        self::assertNull($base->definition()->concurrency);
        self::assertSame(FallbackScope::BusinessOutcomesOnly, $base->definition()->fallbackScope);
        self::assertNotNull($derived->definition()->concurrency);
        // A base can be forked into two independent variants.
        // (the partition lives in the policy identities, so the forks need a policy to differ)
        $a = $base->partition('shop', 'prod', 'a')->bulkhead(1, Duration::seconds(10));
        $b = $base->partition('shop', 'prod', 'b')->bulkhead(1, Duration::seconds(10));
        self::assertNotEquals($a->definition(), $b->definition());
    }

    public function testRateAlgorithmHelpersBuildTheMatchingOptions(): void
    {
        self::assertEquals(new TokenBucketOptions(new PositiveInt(5), new PositiveInt(2), Duration::seconds(1)), PipelineBuilder::tokenBucket(5, 2, Duration::seconds(1)));
        self::assertEquals(new FixedWindowOptions(new PositiveInt(5), Duration::seconds(10), FixedWindowAnchor::Aligned), PipelineBuilder::fixedWindow(5, Duration::seconds(10), FixedWindowAnchor::Aligned));
        self::assertEquals(new SlidingCounterOptions(new PositiveInt(5), Duration::seconds(10)), PipelineBuilder::slidingWindow(5, Duration::seconds(10)));
        self::assertEquals(new CountCircuitWindow(new PositiveInt(7)), PipelineBuilder::lastCalls(7));
        self::assertEquals(new TimeCircuitWindow(Duration::seconds(60), Duration::seconds(5)), PipelineBuilder::timeWindow(Duration::seconds(60), Duration::seconds(5)));
    }

    public function testEveryRateAlgorithmCanBeUsedWithTheBuilder(): void
    {
        foreach ([
            PipelineBuilder::tokenBucket(5, 5, Duration::seconds(1)),
            PipelineBuilder::fixedWindow(5, Duration::seconds(1), FixedWindowAnchor::FirstHit),
            PipelineBuilder::slidingWindow(5, Duration::seconds(1)),
        ] as $algorithm) {
            $definition = self::minimal()->rateLimit($algorithm)->definition();
            self::assertNotNull($definition->rateLimit);
            self::assertSame($algorithm, $definition->rateLimit->algorithm);
            self::assertNotNull($definition->rateCost);
            self::assertSame(1, $definition->rateCost->value, 'cost 1 means one operation is one unit');
        }
    }

    /**
     * Each case builds a configuration and names the fragment its error message must contain. The error is
     * always an InvalidConfigurationException, raised either by the offending call or by definition().
     *
     * @return iterable<string, array{\Closure(): mixed, string}>
     */
    public static function invalidConfigurations(): iterable
    {
        $retry = static fn(PipelineBuilder $b, int $attempts = 3): PipelineBuilder => $b->retry($attempts, BackoffMode::Constant, Duration::milliseconds(5), Duration::milliseconds(5), JitterMode::None);
        $budget = static fn(PipelineBuilder $b): PipelineBuilder => $b->retryBudget(3, 3, Duration::seconds(10), Duration::seconds(10));

        // Missing required options.
        yield 'missing partition' => [static fn(): mixed => PipelineBuilder::profile('a', 'v1')->attemptTimeout(Duration::seconds(1), AttemptTimeoutRequirement::Cooperative)->definition(), 'partition() is required'];
        yield 'missing attemptTimeout' => [static fn(): mixed => PipelineBuilder::profile('a', 'v1')->partition('a', 'e', 's')->definition(), 'attemptTimeout() is required'];

        // Retry composition.
        yield 'retry without budget' => [static fn(): mixed => $retry(self::minimal())->definition(), 'retry(maxAttempts=3) requires retryBudget()'];
        yield 'retry message mentions replay safety' => [static fn(): mixed => $retry(self::minimal())->definition(), 'replay-safety assertion'];
        yield 'budget without retry' => [static fn(): mixed => $budget(self::minimal())->definition(), 'retryBudget() has no effect without retry()'];
        yield 'budget with a single attempt' => [static fn(): mixed => $budget($retry(self::minimal(), 1))->definition(), 'retryBudget() has no effect'];
        yield 'zero attempts' => [static fn(): mixed => $retry(self::minimal(), 0), 'retry(): maxAttempts must be at least 1, got 0'];
        yield 'negative attempts' => [static fn(): mixed => $retry(self::minimal(), -2), 'retry(): maxAttempts must be at least 1, got -2'];
        yield 'too many attempts' => [static fn(): mixed => $retry(self::minimal(), 65), 'retry(): maxAttempts must be in [1, 64]'];
        yield 'maximum delay below base' => [static fn(): mixed => self::minimal()->retry(2, BackoffMode::Exponential, Duration::seconds(2), Duration::seconds(1), JitterMode::None), 'retry(): maximumDelay must not be shorter than baseDelay'];
        yield 'zero attempt timeout' => [static fn(): mixed => self::minimal()->attemptTimeout(Duration::zero(), AttemptTimeoutRequirement::Cooperative), 'attemptTimeout(): Attempt maximumDuration must be strictly positive'];

        // Budget and rate numbers.
        yield 'zero budget capacity' => [static fn(): mixed => self::minimal()->retryBudget(0, 1, Duration::seconds(1), Duration::seconds(10)), 'retryBudget() capacityTokens must be at least 1'];
        yield 'zero budget refill' => [static fn(): mixed => self::minimal()->retryBudget(1, 0, Duration::seconds(1), Duration::seconds(10)), 'retryBudget() refillTokens must be at least 1'];
        yield 'zero budget interval' => [static fn(): mixed => self::minimal()->retryBudget(1, 1, Duration::zero(), Duration::seconds(10)), 'retryBudget(): refillInterval and reservationLease must be positive'];
        yield 'zero rate cost' => [static fn(): mixed => self::minimal()->rateLimit(PipelineBuilder::tokenBucket(5, 5, Duration::seconds(1)), 0), 'rateLimit() cost must be at least 1'];
        yield 'rate cost above the portable maximum' => [static fn(): mixed => self::minimal()->rateLimit(PipelineBuilder::tokenBucket(5, 5, Duration::seconds(1)), PHP_INT_MAX)->definition(), 'rateCost must not exceed'];
        yield 'zero token capacity' => [static fn(): mixed => PipelineBuilder::tokenBucket(0, 1, Duration::seconds(1)), 'tokenBucket(): capacity must be at least 1'];
        yield 'zero token refill interval' => [static fn(): mixed => PipelineBuilder::tokenBucket(1, 1, Duration::zero()), 'tokenBucket(): Refill interval must be positive'];
        yield 'zero fixed window' => [static fn(): mixed => PipelineBuilder::fixedWindow(1, Duration::zero(), FixedWindowAnchor::Aligned), 'fixedWindow(): Window must be positive'];
        yield 'zero sliding capacity' => [static fn(): mixed => PipelineBuilder::slidingWindow(0, Duration::seconds(1)), 'slidingWindow(): capacity must be at least 1'];

        // Circuit breaker.
        yield 'zero count window' => [static fn(): mixed => PipelineBuilder::lastCalls(0), 'lastCalls(): completedSamples must be at least 1'];
        yield 'count window above the limit' => [static fn(): mixed => PipelineBuilder::lastCalls(10_001), 'lastCalls(): Count window must hold at most 10000 samples'];
        yield 'time window with too many buckets' => [static fn(): mixed => PipelineBuilder::timeWindow(Duration::seconds(3600), Duration::seconds(1)), 'timeWindow(): A time window may intersect at most 1024 buckets'];
        yield 'minimum samples above the window' => [static fn(): mixed => self::minimal()->circuitBreaker(PipelineBuilder::lastCalls(5), 6, Ratio::of(1, 2), Duration::seconds(30), 1, 1, Duration::seconds(10)), 'circuitBreaker(): minimumSamples must not exceed the count window'];
        yield 'probe successes above probe capacity' => [static fn(): mixed => self::minimal()->circuitBreaker(PipelineBuilder::lastCalls(5), 2, Ratio::of(1, 2), Duration::seconds(30), 1, 2, Duration::seconds(10)), 'circuitBreaker(): probeSuccessThreshold must not exceed probeCapacity'];
        yield 'zero minimum samples' => [static fn(): mixed => self::minimal()->circuitBreaker(PipelineBuilder::lastCalls(5), 0, Ratio::of(1, 2), Duration::seconds(30), 1, 1, Duration::seconds(10)), 'circuitBreaker() minimumSamples must be at least 1'];
        yield 'zero open duration' => [static fn(): mixed => self::minimal()->circuitBreaker(PipelineBuilder::lastCalls(5), 2, Ratio::of(1, 2), Duration::zero(), 1, 1, Duration::seconds(10)), 'circuitBreaker(): openDuration and permitLease must be positive'];

        // Bulkhead and cooldown.
        yield 'zero bulkhead capacity' => [static fn(): mixed => self::minimal()->bulkhead(0, Duration::seconds(10)), 'bulkhead() capacity must be at least 1'];
        yield 'zero bulkhead lease' => [static fn(): mixed => self::minimal()->bulkhead(1, Duration::zero()), 'bulkhead(): Concurrency lease must be positive'];
        yield 'cooldown beyond the horizon' => [static fn(): mixed => self::minimal()->cooldown(Duration::nanoseconds(PHP_INT_MAX)), 'cooldown(): cleanupGrace must not exceed'];

        // Leases must outlast the attempt budget plus the 1 s cleanup margin; the message says by how much.
        yield 'circuit lease equals the attempt timeout' => [static fn(): mixed => self::minimal()->circuitBreaker(PipelineBuilder::lastCalls(5), 2, Ratio::of(1, 2), Duration::seconds(30), 1, 1, Duration::seconds(2))->definition(), 'circuitBreaker() permitLease (2000 ms) must exceed attemptTimeout() (2000 ms) plus a 1 second cleanup margin; use more than 3000 ms'];
        yield 'bulkhead lease inside the margin' => [static fn(): mixed => self::minimal()->bulkhead(2, Duration::milliseconds(2500))->definition(), 'bulkhead() lease (2500 ms) must exceed attemptTimeout()'];
        yield 'budget lease inside the margin' => [static fn(): mixed => $budget($retry(self::minimal()))->retryBudget(3, 3, Duration::seconds(10), Duration::milliseconds(3000))->definition(), 'retryBudget() reservationLease (3000 ms) must exceed attemptTimeout()'];
        yield 'a longer attempt timeout invalidates an earlier lease (order independent)' => [static fn(): mixed => self::minimal()->bulkhead(2, Duration::seconds(10))->attemptTimeout(Duration::seconds(10), AttemptTimeoutRequirement::Cooperative)->definition(), 'bulkhead() lease (10000 ms)'];

        // Fallback scope.
        yield 'admission fallback without an admission policy' => [static fn(): mixed => self::minimal()->fallbackScope(FallbackScope::IncludeAdmissionRefusals)->definition(), 'fallbackScope(IncludeAdmissionRefusals) has no effect without'];

        // Identifiers.
        yield 'profile id with a space' => [static fn(): mixed => PipelineBuilder::profile('bad id', 'v1'), 'profile() id'];
        yield 'empty profile version' => [static fn(): mixed => PipelineBuilder::profile('ok', ''), 'profile() version'];
        yield 'partition with a slash' => [static fn(): mixed => PipelineBuilder::profile('ok', 'v1')->partition('app/x', 'env', 'subject'), 'partition()'];
        yield 'partition tenant too long' => [static fn(): mixed => PipelineBuilder::profile('ok', 'v1')->partition('app', 'env', 'subject', str_repeat('t', 129)), 'partition()'];
    }

    /** @param \Closure(): mixed $build */
    #[DataProvider('invalidConfigurations')]
    public function testEveryInvalidCompositionRaisesAnInvalidConfigurationExceptionThatNamesTheOption(\Closure $build, string $fragment): void
    {
        try {
            $build();
            self::fail('InvalidConfigurationException expected.');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString($fragment, $e->getMessage());
            self::assertSame(ReasonCode::InvalidConfiguration, $e->reason());
        }
    }

    public function testTheLeaseMessageStatesTheSmallestAcceptableValueAndThatValueIsAccepted(): void
    {
        try {
            self::minimal()->bulkhead(1, Duration::milliseconds(2500))->definition();
            self::fail('InvalidConfigurationException expected.');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString('use more than 3000 ms', $e->getMessage());
        }

        $accepted = self::minimal()->bulkhead(1, Duration::milliseconds(3001))->definition();
        self::assertNotNull($accepted->concurrency);
    }

    public function testCompileInMemoryProducesAWorkingPipelineWhoseExplanationListsOnlyTheConfiguredPolicies(): void
    {
        $pipeline = self::minimal()
            ->bulkhead(2, Duration::seconds(10))
            ->rateLimit(PipelineBuilder::tokenBucket(5, 5, Duration::seconds(1)))
            ->compileInMemory(ExceptionClassifier::create());

        $explanation = $pipeline->explain();

        self::assertSame(['attempt', 'retry(maxAttempts=1)', 'rate_limit', 'concurrency'], $explanation->enabledPolicies);
    }

    public function testCompileAgainstAnEnvironmentMissingABackendRaisesTheCapabilityError(): void
    {
        $clock = new SystemClock();
        $bare = new RuntimeEnvironment(
            $clock,
            new BoundedBlockingScheduler($clock),
            new SecureRandomSource(),
            ExceptionClassifier::create(),
            new SynchronousRuntimeAdapter(SchedulingCapability::BoundedBlocking),
            new NullEventSink(),
            null,
            null,
            null,
            null,
            null,
        );

        $this->expectException(CapabilityMismatchException::class);
        $this->expectExceptionMessage('The concurrency policy is configured but its backend is not installed.');
        self::minimal()->bulkhead(2, Duration::seconds(10))->compile($bare);
    }

    public function testATransportEnforcedTimeoutIsRejectedByTheSynchronousRuntimeAtCompile(): void
    {
        $this->expectException(CapabilityMismatchException::class);
        PipelineBuilder::profile('orders', 'v1')
            ->partition('shop', 'prod', 'orders-api')
            ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::TransportEnforced)
            ->compileInMemory(ExceptionClassifier::create());
    }
}
