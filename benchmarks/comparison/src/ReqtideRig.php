<?php

declare(strict_types=1);

namespace Comparison;

use Enaxon\Reqtide\Exception\AdmissionDeferredException;
use Enaxon\Reqtide\Exception\AdmissionRejectedException;
use Enaxon\Reqtide\Exception\DeadlineExceededException;
use Enaxon\Reqtide\Exception\ImpossibleAdmissionException;
use Enaxon\Reqtide\Exception\OperationCancelledException;
use Enaxon\Reqtide\Exception\ReqtideException;
use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Execution\ExecutionContext;
use Enaxon\Reqtide\Execution\OperationSafety;
use Enaxon\Reqtide\Execution\ReplaySafetyContract;
use Enaxon\Reqtide\Outcome\ExceptionClassifier;
use Enaxon\Reqtide\Outcome\OutcomeClassifier;
use Enaxon\Reqtide\Pipeline\CompiledPipeline;
use Enaxon\Reqtide\Pipeline\PipelineBuilder;
use Enaxon\Reqtide\Pipeline\PipelineDefinition;
use Enaxon\Reqtide\Pipeline\RuntimeEnvironment;
use Enaxon\Reqtide\Policy\AttemptTimeoutRequirement;
use Enaxon\Reqtide\Runtime\SchedulingCapability;
use Enaxon\Reqtide\Runtime\SynchronousRuntimeAdapter;
use Enaxon\Reqtide\State\Memory\MemoryCircuitBackend;
use Enaxon\Reqtide\State\Memory\MemoryConcurrencyBackend;
use Enaxon\Reqtide\State\Memory\MemoryCooldownBackend;
use Enaxon\Reqtide\State\Memory\MemoryRateLimitBackend;
use Enaxon\Reqtide\State\Memory\MemoryRetryBudgetBackend;
use Enaxon\Reqtide\Telemetry\EventSink;
use Enaxon\Reqtide\Telemetry\NullEventSink;
use Enaxon\Reqtide\Testing\RecordingEventSink;
use Enaxon\Reqtide\Testing\SeededRandomSource;
use Enaxon\Reqtide\Testing\VirtualClock;
use Enaxon\Reqtide\Testing\VirtualDelayScheduler;
use Enaxon\Reqtide\Time\Cancellation;
use Enaxon\Reqtide\Value\ApplicationId;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\EnvironmentId;
use Enaxon\Reqtide\Value\PartitionIdentity;
use Enaxon\Reqtide\Value\SubjectId;

/** A failure raised by the (scripted) upstream. Business exception: Reqtide never wraps it. */
class UpstreamFailure extends \RuntimeException
{
}

/** HTTP-style upstream failure with an optional Retry-After (seconds). */
final class WireStatus extends UpstreamFailure
{
    public function __construct(public readonly int $status, public readonly ?int $retryAfterSeconds = null)
    {
        parent::__construct('HTTP ' . $status);
    }
}

/** Transport-level failure (no response). */
final class WireTransportFailure extends UpstreamFailure
{
}

/**
 * Scripted recording transport for the Reqtide adapter (the equivalent of GuzzleWire): records the virtual
 * time and method of every request that reaches it and answers from a script.
 */
final class ReqtideWire
{
    /** @var list<float> */
    public array $sent = [];

    /** @var list<string> */
    public array $methods = [];

    /** @param \Closure(int): (\Throwable|null) $script request number (1-based) => exception to raise, or null for HTTP 200 */
    public function __construct(private readonly ReqtideRig $rig, private readonly \Closure $script, private readonly string $method = 'GET')
    {
    }

    public function __invoke(AttemptContext $attempt): string
    {
        $this->sent[] = $this->rig->elapsed();
        $this->methods[] = $this->method;
        $failure = ($this->script)(\count($this->sent));
        if ($failure !== null) {
            throw $failure;
        }

        return '200';
    }
}

/**
 * Wires a Reqtide pipeline over the src/Testing virtual clock and scheduler and the process-local memory
 * backends, i.e. what InMemoryEnvironment does except that the clock and scheduler are virtual (InMemoryEnvironment
 * hard-wires SystemClock and BoundedBlockingScheduler and its docblock says a virtual clock must be paired with a
 * hand-built RuntimeEnvironment). Only public API is used; no Reqtide source is modified.
 */
final class ReqtideRig
{
    private static bool $booted = false;

    public readonly int $t0;

    private function __construct(
        public readonly VirtualClock $clock,
        public readonly VirtualDelayScheduler $scheduler,
        public readonly EventSink $events,
        public readonly PipelineDefinition $definition,
        public readonly CompiledPipeline $pipeline,
        public readonly RuntimeEnvironment $env,
    ) {
        $this->t0 = $clock->peekMonotonic();
    }

    /** The harness composer.json does not know the repository package; map it (dev-only, no dependency added). */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        $root = dirname(__DIR__, 3);
        spl_autoload_register(static function (string $class) use ($root): void {
            // One package: Enaxon\Reqtide\ => src/ (the Testing namespace lives in src/Testing).
            foreach (['Enaxon\\Reqtide\\' => $root . '/src/'] as $prefix => $dir) {
                if (str_starts_with($class, $prefix)) {
                    $file = $dir . str_replace('\\', '/', substr($class, \strlen($prefix))) . '.php';
                    if (is_file($file)) {
                        require $file;
                    }

                    return;
                }
            }
        });
    }

    /** Required options only: profile, partition and an attempt timeout (Reqtide has no numeric defaults). */
    public static function base(Duration $attemptTimeout): PipelineBuilder
    {
        return PipelineBuilder::profile('cmp', 'v1')
            ->partition('cmp', 'bench', 'dependency')
            ->attemptTimeout($attemptTimeout, AttemptTimeoutRequirement::Cooperative);
    }

    public static function make(PipelineBuilder $builder, ?OutcomeClassifier $classifier = null, ?EventSink $events = null): self
    {
        self::boot();
        $definition = $builder->definition();
        $clock = new VirtualClock();
        $scheduler = new VirtualDelayScheduler($clock);
        $events ??= new NullEventSink();
        $env = new RuntimeEnvironment(
            $clock,
            $scheduler,
            new SeededRandomSource(1),
            $classifier ?? ExceptionClassifier::create(),
            new SynchronousRuntimeAdapter(SchedulingCapability::BoundedBlocking),
            $events,
            $definition->rateLimit === null ? null : new MemoryRateLimitBackend($clock),
            $definition->circuit === null ? null : new MemoryCircuitBackend($clock),
            $definition->concurrency === null ? null : new MemoryConcurrencyBackend($clock),
            $definition->retryBudget === null ? null : new MemoryRetryBudgetBackend($clock),
            $definition->cooldown === null ? null : new MemoryCooldownBackend($clock),
        );

        return new self($clock, $scheduler, $events, $definition, $definition->compile($env), $env);
    }

    /** Throwing sink for S18. */
    public function recordingSink(): ?RecordingEventSink
    {
        return $this->events instanceof RecordingEventSink ? $this->events : null;
    }

    public static function secs(float $seconds): Duration
    {
        return Duration::nanoseconds((int) round($seconds * 1_000_000_000));
    }

    /** Virtual seconds since the scenario start. */
    public function elapsed(): float
    {
        return ($this->clock->peekMonotonic() - $this->t0) / 1e9;
    }

    /** Move virtual time to an absolute scenario instant (never backwards). */
    public function at(float $seconds): void
    {
        $target = $this->t0 + (int) round($seconds * 1e9);
        $delta = $target - $this->clock->peekMonotonic();
        if ($delta < 0) {
            throw new \LogicException('Rig time cannot go backwards.');
        }
        if ($delta > 0) {
            $this->clock->advance(Duration::nanoseconds($delta));
        }
    }

    /** Explicit, per-operation replay assertion for a repeatable read (the caller asserts it; Reqtide never verifies). */
    public function readOnly(): OperationSafety
    {
        return OperationSafety::replayableRead(ReplaySafetyContract::forReadOnlyCallable('cmp-read', Duration::seconds(3600), $this->clock));
    }

    /**
     * newContext() with the partition passed explicitly: a pipeline without any state policy retains no partition and
     * refuses newContext() otherwise.
     */
    public function context(float $timeout = 60.0, ?OperationSafety $safety = null, ?Cancellation $cancellation = null, ?string $operationId = null): ExecutionContext
    {
        return $this->pipeline->newContext(self::secs($timeout), $safety, null, $cancellation, null, $operationId, self::partition());
    }

    public static function partition(): PartitionIdentity
    {
        return new PartitionIdentity(new ApplicationId('cmp'), new EnvironmentId('bench'), null, new SubjectId('dependency'));
    }

    /**
     * Runs one logical operation through the pipeline (simple mode: value returned, business exception rethrown
     * unchanged, policy exceptions typed).
     *
     * @return array{0:mixed,1:?\Throwable}
     */
    public function run(callable $work, float $timeout = 60.0, ?OperationSafety $safety = null, ?Cancellation $cancellation = null, ?string $operationId = null): array
    {
        $context = $this->context($timeout, $safety, $cancellation, $operationId);
        try {
            return [$this->pipeline->execute($work, $context), null];
        } catch (\Throwable $e) {
            return [null, $e];
        }
    }

    /** One operation reduced to a label: ok | fail | rejected:<code> | deferred:<code> | deadline | cancelled | other:<class>. */
    public function call(callable $work, float $timeout = 60.0, ?OperationSafety $safety = null, ?Cancellation $cancellation = null): string
    {
        [, $e] = $this->run($work, $timeout, $safety, $cancellation);

        return $e === null ? 'ok' : self::label($e);
    }

    public static function label(\Throwable $e): string
    {
        return match (true) {
            $e instanceof AdmissionRejectedException => 'rejected:' . $e->reason()->value,
            $e instanceof AdmissionDeferredException => 'deferred:' . $e->reason()->value,
            $e instanceof ImpossibleAdmissionException => 'impossible:' . $e->reason()->value,
            $e instanceof DeadlineExceededException => 'deadline',
            $e instanceof OperationCancelledException => 'cancelled',
            $e instanceof ReqtideException => 'reqtide:' . $e::class,
            $e instanceof UpstreamFailure => 'fail',
            default => 'other:' . $e::class,
        };
    }

    /** A callable that always fails with an upstream failure. */
    public static function failing(): \Closure
    {
        return static function (AttemptContext $a): never {
            throw new UpstreamFailure('upstream failure');
        };
    }

    public static function succeeding(): \Closure
    {
        return static fn (AttemptContext $a): string => 'ok';
    }

    /** @param list<bool> $failures true = this call fails */
    public function sequence(array $failures): array
    {
        $labels = [];
        foreach ($failures as $fails) {
            $labels[] = $this->call($fails ? self::failing() : self::succeeding());
        }

        return $labels;
    }

    /** Four failures: with minimumSamples 4 and a 50% threshold this opens the circuit at the current instant. */
    public function openCircuit(): void
    {
        $this->sequence([true, true, true, true]);
    }

    /**
     * Starts one caller in a Fiber; its callback parks in Fiber::suspend() (= an in-flight call). Resuming with
     * 'fail' makes the parked call fail with an upstream failure, anything else makes it succeed. The fiber returns
     * the caller's label. $entered counts callbacks that were actually dispatched.
     */
    public function park(int &$entered, float $timeout = 600.0): \Fiber
    {
        $fiber = new \Fiber(function () use (&$entered, $timeout): string {
            return $this->call(static function (AttemptContext $a) use (&$entered): string {
                ++$entered;
                $mode = \Fiber::suspend();
                if ($mode === 'fail') {
                    throw new UpstreamFailure('late failure');
                }

                return 'ok';
            }, $timeout);
        });
        $fiber->start();

        return $fiber;
    }

    /** Classifier for HTTP-style scenarios: every upstream failure is retryable (the replay contract still decides), Retry-After is read from the exception. */
    public static function httpClassifier(): ExceptionClassifier
    {
        return ExceptionClassifier::create()
            ->retryOn(UpstreamFailure::class)
            ->retryAfterFrom(static fn (\Throwable $e): ?Duration => $e instanceof WireStatus && $e->retryAfterSeconds !== null ? Duration::seconds($e->retryAfterSeconds) : null);
    }
}

// The class below implements a Reqtide interface, so the repository autoloader must exist before it is declared.
ReqtideRig::boot();

/**
 * AttemptOperation (public extension seam) whose PreparedAttempt cancels the caller's token from verifyReplay().
 * verifyReplay() runs after every admission (circuit/bulkhead permits held, rate debited) and before dispatch, so
 * this is "cancelled by its own signal after admission and before the transport callback is dispatched".
 * $onVerify runs first, so the scenario can observe that the permits are really held at that instant.
 */
final class ReqtideCancelBeforeDispatch implements \Enaxon\Reqtide\Runtime\AttemptOperation
{
    public int $dispatched = 0;

    public function __construct(private readonly \Enaxon\Reqtide\Time\CancellationToken $token, private readonly ?\Closure $onVerify = null)
    {
    }

    public function prepare(AttemptContext $context): \Enaxon\Reqtide\Runtime\PreparedAttempt
    {
        $self = $this;

        return new class ($self, $this->token, $this->onVerify) implements \Enaxon\Reqtide\Runtime\PreparedAttempt {
            public function __construct(private readonly ReqtideCancelBeforeDispatch $owner, private readonly \Enaxon\Reqtide\Time\CancellationToken $token, private readonly ?\Closure $onVerify)
            {
            }

            public function verifyReplay(AttemptContext $context): void
            {
                if ($this->onVerify !== null) {
                    ($this->onVerify)();
                }
                $this->token->cancel();
            }

            public function invoke(AttemptContext $context): mixed
            {
                ++$this->owner->dispatched;

                return '200';
            }

            public function discardOutcome(\Enaxon\Reqtide\Outcome\Outcome $outcome): void
            {
            }
        };
    }
}
