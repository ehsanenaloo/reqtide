<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Pipeline;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Fallback\FallbackScope;
use Enaxon\Reqtide\Outcome\OutcomeClassifier;
use Enaxon\Reqtide\Policy\AttemptOptions;
use Enaxon\Reqtide\Policy\AttemptTimeoutRequirement;
use Enaxon\Reqtide\Policy\BackoffMode;
use Enaxon\Reqtide\Policy\CircuitPolicy;
use Enaxon\Reqtide\Policy\CircuitWindowOptions;
use Enaxon\Reqtide\Policy\ConcurrencyPolicy;
use Enaxon\Reqtide\Policy\CooldownPolicy;
use Enaxon\Reqtide\Policy\CountCircuitWindow;
use Enaxon\Reqtide\Policy\FixedWindowAnchor;
use Enaxon\Reqtide\Policy\FixedWindowOptions;
use Enaxon\Reqtide\Policy\JitterMode;
use Enaxon\Reqtide\Policy\RateAlgorithmOptions;
use Enaxon\Reqtide\Policy\RateLimitPolicy;
use Enaxon\Reqtide\Policy\RetryBudgetPolicy;
use Enaxon\Reqtide\Policy\RetryOptions;
use Enaxon\Reqtide\Policy\SlidingCounterOptions;
use Enaxon\Reqtide\Policy\TimeCircuitWindow;
use Enaxon\Reqtide\Policy\TokenBucketOptions;
use Enaxon\Reqtide\Telemetry\EventSink;
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

/**
 * Fluent builder for {@see PipelineDefinition} (the developer-experience layer).
 *
 * It adds no behaviour: it assembles the same typed options you could pass to the `PipelineDefinition`
 * constructor, derives the policy identities from one partition and one profile version, and validates the
 * composition up front with messages that name the missing or conflicting option.
 *
 *     $pipeline = PipelineBuilder::profile('payments', 'v1')
 *         ->partition('shop', 'prod', 'payments-api')
 *         ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::Cooperative)
 *         ->retry(3, BackoffMode::Exponential, Duration::milliseconds(100), Duration::seconds(1), JitterMode::Full)
 *         ->retryBudget(10, 10, Duration::seconds(60), Duration::seconds(10))
 *         ->compileInMemory(ExceptionClassifier::create()->retryOn(TimeoutException::class));
 *
 * Rules
 * -----
 * - **No numeric defaults.** Every limit, window, size, lease and delay must be given, with its unit in the type
 *   (`Duration::seconds(2)`, token counts as plain integers). The few optional arguments are neutral: a rate
 *   `cost` of 1 (one operation is one unit) and a zero minimum remaining attempt allowance (no reserve).
 * - **Order does not matter** and each call returns a new builder; a base builder can be reused and extended.
 * - **Required:** `profile()`, `partition()` and `attemptTimeout()`. Everything else is optional; a policy exists
 *   only if you call its method.
 * - **Errors** are `InvalidConfigurationException` naming the option, raised by the option's own call when the
 *   value is invalid and by `definition()` (hence `compile*()`) for a missing or conflicting combination.
 *   `compile()` against a hand-built environment can additionally raise `CapabilityMismatchException`.
 * - Process-local only. `compileInMemory()` uses {@see InMemoryEnvironment}; see its limits.
 *
 * Policies share the profile version and the partition given here; policy ids are fixed (`rate-limit`,
 * `circuit-breaker`, `bulkhead`, `retry-budget`, `cooldown`) because a profile can hold one of each.
 */
final class PipelineBuilder
{
    /** Cleanup margin required by PipelineDefinition between an attempt and every lease (1 s). */
    private const LEASE_MARGIN_NANOSECONDS = 1_000_000_000;

    private ?PartitionIdentity $partition = null;

    private ?AttemptOptions $attempt = null;

    private ?RetryOptions $retry = null;

    /** @var array{capacity: PositiveInt, refill: PositiveInt, interval: Duration, lease: Duration}|null */
    private ?array $retryBudget = null;

    /** @var array{algorithm: RateAlgorithmOptions, cost: PositiveInt}|null */
    private ?array $rateLimit = null;

    /**
     * @var array{
     *     window: CircuitWindowOptions, minimumSamples: PositiveInt, threshold: Ratio, open: Duration,
     *     probeCapacity: PositiveInt, probeSuccess: PositiveInt, lease: Duration
     * }|null
     */
    private ?array $circuit = null;

    /** @var array{capacity: PositiveInt, lease: Duration}|null */
    private ?array $bulkhead = null;

    private ?Duration $cooldownGrace = null;
    private ?Duration $cooldownMaximum = null;

    private FallbackScope $fallbackScope = FallbackScope::BusinessOutcomesOnly;

    private function __construct(private readonly ProfileId $profileId, private readonly ProfileVersion $profileVersion) {}

    /**
     * Starts a builder for one named, versioned profile. Both are bounded identifiers
     * (`[A-Za-z0-9][A-Za-z0-9._-]{0,127}`). Change the version when the policy settings change: shared state
     * is keyed by it.
     *
     * @throws InvalidConfigurationException
     */
    public static function profile(string $id, string $version): self
    {
        return new self(
            self::guard('profile() id', static fn(): ProfileId => new ProfileId($id)),
            self::guard('profile() version', static fn(): ProfileVersion => new ProfileVersion($version)),
        );
    }

    /**
     * The identity every policy's state is partitioned by, and the one every per-call context must use. One
     * pipeline serves exactly one partition: build one pipeline per tenant or subject when limits are per tenant.
     * `$subject` names what is protected (for example `payments-api`), never a credential.
     *
     * @throws InvalidConfigurationException
     */
    public function partition(string $application, string $environment, string $subject, ?string $tenant = null): self
    {
        $partition = self::guard('partition()', static fn(): PartitionIdentity => new PartitionIdentity(
            new ApplicationId($application),
            new EnvironmentId($environment),
            $tenant === null ? null : new TenantId($tenant),
            new SubjectId($subject),
        ));

        return $this->change(static function (self $b) use ($partition): void {
            $b->partition = $partition;
        });
    }

    /**
     * Upper bound for one attempt. With the synchronous runtime use `Cooperative`: the budget is checked around
     * the callable, not inside it (the callable itself must carry a transport timeout). `TransportEnforced`
     * needs a runtime that declares it; `compile()` rejects the mismatch.
     */
    public function attemptTimeout(Duration $maximumDuration, AttemptTimeoutRequirement $requirement): self
    {
        $attempt = self::guard('attemptTimeout()', static fn(): AttemptOptions => new AttemptOptions($maximumDuration, $requirement));

        return $this->change(static function (self $b) use ($attempt): void {
            $b->attempt = $attempt;
        });
    }

    /**
     * Retries. `$maxAttempts` counts the first attempt (3 means one call plus at most two retries), 1 to 64.
     * More than one attempt requires {@see retryBudget()} and, on every call, an explicit replay-safety
     * assertion (`ReplaySafetyContract::forReadOnlyCallable()`): Reqtide never infers that repeating a callable
     * is safe. Delays grow from `$baseDelay` (constant or exponential) and never exceed `$maximumDelay`;
     * `JitterMode::Full` draws each delay uniformly from zero to that value.
     * `$minimumRemainingAttemptAllowance` is the time that must still remain after a delay for another attempt
     * to be worthwhile (default: none).
     *
     * @throws InvalidConfigurationException
     */
    public function retry(
        int $maxAttempts,
        BackoffMode $backoff,
        Duration $baseDelay,
        Duration $maximumDelay,
        JitterMode $jitter,
        ?Duration $minimumRemainingAttemptAllowance = null,
    ): self {
        $options = self::guard('retry()', static fn(): RetryOptions => new RetryOptions(
            self::positive($maxAttempts, 'maxAttempts'),
            $backoff,
            $baseDelay,
            $maximumDelay,
            $jitter,
            $minimumRemainingAttemptAllowance ?? Duration::zero(),
        ));

        return $this->change(static function (self $b) use ($options): void {
            $b->retry = $options;
        });
    }

    /**
     * Token bucket that pays for retries so a failing dependency is not hammered: `$capacityTokens` available at
     * most, `$refillTokens` added every `$refillInterval`, and a reservation held for at most `$reservationLease`
     * while a retry is being dispatched (it must exceed the attempt timeout by more than 1 s). Required by
     * {@see retry()} with more than one attempt, and only valid with it.
     *
     * @throws InvalidConfigurationException
     */
    public function retryBudget(int $capacityTokens, int $refillTokens, Duration $refillInterval, Duration $reservationLease): self
    {
        $capacity = self::positive($capacityTokens, 'retryBudget() capacityTokens');
        $refill = self::positive($refillTokens, 'retryBudget() refillTokens');
        self::guard('retryBudget()', static fn(): RetryBudgetPolicy => new RetryBudgetPolicy(self::scratchIdentity(), $capacity, $refill, $refillInterval, $reservationLease));

        return $this->change(static function (self $b) use ($capacity, $refill, $refillInterval, $reservationLease): void {
            $b->retryBudget = ['capacity' => $capacity, 'refill' => $refill, 'interval' => $refillInterval, 'lease' => $reservationLease];
        });
    }

    /**
     * Rate limit charged `$cost` units for every dispatch opportunity: the first attempt and each retry are each
     * charged, so an operation that makes three attempts consumes `3 * $cost` units. A
     * charge is never refunded. Size quotas for the expected traffic times `retry()` attempts. Pass the algorithm
     * from {@see tokenBucket()}, {@see fixedWindow()} or {@see slidingWindow()}. A cost of 1 means one dispatch
     * consumes one unit of the algorithm's capacity.
     *
     * @throws InvalidConfigurationException
     */
    public function rateLimit(RateAlgorithmOptions $algorithm, int $cost = 1): self
    {
        $cost = self::positive($cost, 'rateLimit() cost');

        return $this->change(static function (self $b) use ($algorithm, $cost): void {
            $b->rateLimit = ['algorithm' => $algorithm, 'cost' => $cost];
        });
    }

    /**
     * Circuit breaker. It judges the `$window` of completed calls once `$minimumSamples` exist, opens when the
     * share of failures reaches `$failureThreshold` (for example `Ratio::of(1, 2)`), stays open for
     * `$openDuration`, then admits up to `$probeCapacity` probe calls and closes after `$probeSuccessThreshold`
     * successful probes. `$permitLease` is how long a call's permit stays valid; it must exceed the attempt
     * timeout by more than 1 s. What counts as a failure is decided by the classifier.
     *
     * @throws InvalidConfigurationException
     */
    public function circuitBreaker(
        CircuitWindowOptions $window,
        int $minimumSamples,
        Ratio $failureThreshold,
        Duration $openDuration,
        int $probeCapacity,
        int $probeSuccessThreshold,
        Duration $permitLease,
    ): self {
        $samples = self::positive($minimumSamples, 'circuitBreaker() minimumSamples');
        $capacity = self::positive($probeCapacity, 'circuitBreaker() probeCapacity');
        $success = self::positive($probeSuccessThreshold, 'circuitBreaker() probeSuccessThreshold');
        self::guard('circuitBreaker()', static fn(): CircuitPolicy => new CircuitPolicy(
            self::scratchIdentity(),
            $window,
            $samples,
            $failureThreshold,
            $openDuration,
            $capacity,
            $success,
            $permitLease,
        ));

        return $this->change(static function (self $b) use ($window, $samples, $failureThreshold, $openDuration, $capacity, $success, $permitLease): void {
            $b->circuit = [
                'window' => $window,
                'minimumSamples' => $samples,
                'threshold' => $failureThreshold,
                'open' => $openDuration,
                'probeCapacity' => $capacity,
                'probeSuccess' => $success,
                'lease' => $permitLease,
            ];
        });
    }

    /**
     * Bulkhead: at most `$capacity` operations in flight at once in this partition. `$lease` bounds how long a slot
     * stays reserved if its owner vanishes; it must exceed the attempt timeout by more than 1 s.
     *
     * @throws InvalidConfigurationException
     */
    public function bulkhead(int $capacity, Duration $lease): self
    {
        $slots = self::positive($capacity, 'bulkhead() capacity');
        self::guard('bulkhead()', static fn(): ConcurrencyPolicy => new ConcurrencyPolicy(self::scratchIdentity(), $slots, $lease));

        return $this->change(static function (self $b) use ($slots, $lease): void {
            $b->bulkhead = ['capacity' => $slots, 'lease' => $lease];
        });
    }

    /**
     * Shared pause: when the classifier reports a Retry-After (see `ExceptionClassifier::retryAfterFrom()`), new
     * operations in this partition are deferred until it elapses. `$cleanupGrace` is how long the state is kept
     * after the pause ended. Without a classifier that reports waits this policy never activates.
     * `$maximumExtension` is the longest shared pause one reported wait may start: a longer Retry-After still governs
     * this operation's own retry, but the pause other operations see is capped to it. Null selects one hour.
     *
     * @throws InvalidConfigurationException
     */
    public function cooldown(Duration $cleanupGrace, ?Duration $maximumExtension = null): self
    {
        self::guard('cooldown()', static fn(): CooldownPolicy => new CooldownPolicy(self::scratchIdentity(), $cleanupGrace, $maximumExtension));

        return $this->change(static function (self $b) use ($cleanupGrace, $maximumExtension): void {
            $b->cooldownGrace = $cleanupGrace;
            $b->cooldownMaximum = $maximumExtension;
        });
    }

    /**
     * Whether the context's fallback may also replace a refusal by the rate limit, circuit breaker, bulkhead or
     * cooldown. Default `BusinessOutcomesOnly`. `IncludeAdmissionRefusals` needs at least one of those policies.
     */
    public function fallbackScope(FallbackScope $scope): self
    {
        return $this->change(static function (self $b) use ($scope): void {
            $b->fallbackScope = $scope;
        });
    }

    /** Token-bucket algorithm for {@see rateLimit()}: `$capacity` tokens, `$refillTokens` added every `$refillInterval`. */
    public static function tokenBucket(int $capacity, int $refillTokens, Duration $refillInterval): TokenBucketOptions
    {
        return self::guard('tokenBucket()', static fn(): TokenBucketOptions => new TokenBucketOptions(
            self::positive($capacity, 'capacity'),
            self::positive($refillTokens, 'refillTokens'),
            $refillInterval,
        ));
    }

    /** Fixed-window algorithm for {@see rateLimit()}: at most `$capacity` units per `$window`. */
    public static function fixedWindow(int $capacity, Duration $window, FixedWindowAnchor $anchor): FixedWindowOptions
    {
        return self::guard('fixedWindow()', static fn(): FixedWindowOptions => new FixedWindowOptions(self::positive($capacity, 'capacity'), $window, $anchor));
    }

    /** Sliding-counter algorithm for {@see rateLimit()}: at most `$capacity` units in any `$window`. */
    public static function slidingWindow(int $capacity, Duration $window): SlidingCounterOptions
    {
        return self::guard('slidingWindow()', static fn(): SlidingCounterOptions => new SlidingCounterOptions(self::positive($capacity, 'capacity'), $window));
    }

    /** Count window for {@see circuitBreaker()}: judge the last `$completedSamples` completed calls (1 to 10000). */
    public static function lastCalls(int $completedSamples): CountCircuitWindow
    {
        return self::guard('lastCalls()', static fn(): CountCircuitWindow => new CountCircuitWindow(self::positive($completedSamples, 'completedSamples')));
    }

    /** Time window for {@see circuitBreaker()}: judge calls completed within `$window`, counted in buckets of `$bucketGranularity`. */
    public static function timeWindow(Duration $window, Duration $bucketGranularity): TimeCircuitWindow
    {
        return self::guard('timeWindow()', static fn(): TimeCircuitWindow => new TimeCircuitWindow($window, $bucketGranularity));
    }

    /**
     * Validates the composition and returns the immutable definition.
     *
     * @throws InvalidConfigurationException naming the missing or conflicting option
     */
    public function definition(): PipelineDefinition
    {
        $partition = $this->partition ?? throw new InvalidConfigurationException(
            'partition() is required: policies need the application, environment and subject they protect, '
            . 'for example ->partition(\'shop\', \'prod\', \'payments-api\').',
        );
        $attempt = $this->attempt ?? throw new InvalidConfigurationException(
            'attemptTimeout() is required: every attempt needs an explicit maximum duration, for example '
            . '->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::Cooperative).',
        );
        $retry = $this->retry ?? RetryOptions::noRetry();

        if ($retry->maxAttempts->value > 1 && $this->retryBudget === null) {
            throw new InvalidConfigurationException(
                'retry(maxAttempts=' . $retry->maxAttempts->value . ') requires retryBudget(): retries must be paid for so a failing '
                . 'dependency is not hammered. Every call also needs an explicit replay-safety assertion, for example '
                . 'ReplaySafetyContract::forReadOnlyCallable(); Reqtide never infers that repeating a callable is safe.',
            );
        }
        if ($retry->maxAttempts->value === 1 && $this->retryBudget !== null) {
            throw new InvalidConfigurationException(
                'retryBudget() has no effect without retry(): call retry() with maxAttempts greater than 1, or remove retryBudget().',
            );
        }
        if (
            $this->fallbackScope === FallbackScope::IncludeAdmissionRefusals
            && $this->rateLimit === null && $this->circuit === null && $this->bulkhead === null && $this->cooldownGrace === null
        ) {
            throw new InvalidConfigurationException(
                'fallbackScope(IncludeAdmissionRefusals) has no effect without rateLimit(), circuitBreaker(), bulkhead() or cooldown(): '
                . 'there is no admission refusal to replace.',
            );
        }

        $this->assertLeaseOutlastsAttempt('circuitBreaker() permitLease', $this->circuit['lease'] ?? null, $attempt);
        $this->assertLeaseOutlastsAttempt('bulkhead() lease', $this->bulkhead['lease'] ?? null, $attempt);
        $this->assertLeaseOutlastsAttempt('retryBudget() reservationLease', $this->retryBudget['lease'] ?? null, $attempt);

        $identity = fn(string $policy): PolicyIdentity => new PolicyIdentity($partition, $this->profileVersion, new PolicyId($policy));
        $rate = $this->rateLimit;
        $circuit = $this->circuit;
        $bulkhead = $this->bulkhead;
        $budget = $this->retryBudget;
        $grace = $this->cooldownGrace;

        $rateLimit = $rate === null ? null : new RateLimitPolicy($identity('rate-limit'), $rate['algorithm']);
        $circuitPolicy = $circuit === null ? null : new CircuitPolicy(
            $identity('circuit-breaker'),
            $circuit['window'],
            $circuit['minimumSamples'],
            $circuit['threshold'],
            $circuit['open'],
            $circuit['probeCapacity'],
            $circuit['probeSuccess'],
            $circuit['lease'],
        );
        $concurrency = $bulkhead === null ? null : new ConcurrencyPolicy($identity('bulkhead'), $bulkhead['capacity'], $bulkhead['lease']);
        $retryBudget = $budget === null ? null : new RetryBudgetPolicy(
            $identity('retry-budget'),
            $budget['capacity'],
            $budget['refill'],
            $budget['interval'],
            $budget['lease'],
        );
        $cooldown = $grace === null ? null : new CooldownPolicy($identity('cooldown'), $grace, $this->cooldownMaximum);

        return self::guard('pipeline definition', fn(): PipelineDefinition => new PipelineDefinition(
            $this->profileId,
            $this->profileVersion,
            $retry,
            $attempt,
            $rateLimit,
            $rate['cost'] ?? null,
            $circuitPolicy,
            $concurrency,
            $retryBudget,
            $cooldown,
            false,
            [],
            $this->fallbackScope,
            $partition,
        ));
    }

    /**
     * Compiles against an environment you built (for example one with shared backends, or the testing package's
     * virtual clock).
     *
     * @throws InvalidConfigurationException
     * @throws \Enaxon\Reqtide\Exception\CapabilityMismatchException when the environment lacks a needed capability or backend
     */
    public function compile(RuntimeEnvironment $environment): CompiledPipeline
    {
        return $this->definition()->compile($environment);
    }

    /**
     * Compiles against {@see InMemoryEnvironment}: system clock, real sleeping delays, process-local state.
     * The classifier is required because what counts as retryable or as a circuit failure is your decision.
     *
     * @throws InvalidConfigurationException
     */
    public function compileInMemory(OutcomeClassifier $classifier, ?EventSink $events = null): CompiledPipeline
    {
        $definition = $this->definition();

        return $definition->compile(InMemoryEnvironment::for($definition, $classifier, $events));
    }

    private function assertLeaseOutlastsAttempt(string $label, ?Duration $lease, AttemptOptions $attempt): void
    {
        if ($lease === null) {
            return;
        }
        $maximum = $attempt->maximumDuration->nanoseconds;
        if ($lease->nanoseconds <= $maximum || $lease->nanoseconds - $maximum <= self::LEASE_MARGIN_NANOSECONDS) {
            throw new InvalidConfigurationException(
                $label . ' (' . self::describe($lease) . ') must exceed attemptTimeout() (' . self::describe($attempt->maximumDuration)
                . ') plus a 1 second cleanup margin; use more than ' . self::describe(new Duration(min($maximum, PHP_INT_MAX - self::LEASE_MARGIN_NANOSECONDS) + self::LEASE_MARGIN_NANOSECONDS)) . '.',
            );
        }
    }

    private static function describe(Duration $duration): string
    {
        return $duration->nanoseconds % 1_000_000 === 0
            ? intdiv($duration->nanoseconds, 1_000_000) . ' ms'
            : $duration->nanoseconds . ' ns';
    }

    /** Placeholder identity for validating a policy's own invariants before the partition is known. */
    private static function scratchIdentity(): PolicyIdentity
    {
        return new PolicyIdentity(
            new PartitionIdentity(new ApplicationId('validation'), new EnvironmentId('validation'), null, new SubjectId('validation')),
            new ProfileVersion('validation'),
            new PolicyId('validation'),
        );
    }

    private static function positive(int $value, string $what): PositiveInt
    {
        if ($value < 1) {
            throw new InvalidConfigurationException($what . ' must be at least 1, got ' . $value . '.');
        }

        return new PositiveInt($value);
    }

    /**
     * Runs a constructor and prefixes its validation message with the option it belongs to.
     *
     * @template R
     * @param \Closure(): R $build
     * @return R
     */
    private static function guard(string $option, \Closure $build): mixed
    {
        try {
            return $build();
        } catch (InvalidConfigurationException $invalid) {
            throw new InvalidConfigurationException($option . ': ' . $invalid->getMessage(), 0, $invalid);
        }
    }

    /** @param \Closure(self): void $change */
    private function change(\Closure $change): self
    {
        $copy = clone $this;
        $change($copy);

        return $copy;
    }
}
