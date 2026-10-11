<?php

declare(strict_types=1);

/**
 * 05 - Deterministic tests with the Enaxon\Reqtide\Testing helpers: virtual time, scripted attempts, recorded events.
 *
 * The other examples use SystemClock and really sleep. Tests should not. The testing helpers (part of enaxon/reqtide, src/Testing) provide:
 *
 *   VirtualClock             a Clock that only moves when you (or the scheduler) advance it
 *   VirtualDelayScheduler    a DelayScheduler that advances the VirtualClock instead of sleeping
 *   ControlledRuntimeAdapter a runtime that returns/throws exactly what you script, and counts dispatches
 *   RecordingEventSink       an EventSink that keeps every event for assertions
 *   SeededRandomSource       jitter that is random-looking but identical for the same seed
 *
 * They ship inside enaxon/reqtide itself; no separate install.
 * Never use it in production code: nothing here ever waits, and the "runtime" is a script.
 *
 * Rules of thumb: use ONE VirtualClock for the Deadline, the scheduler, the adapter and the memory backends
 * (a pipeline's newContext() builds the Deadline on the pipeline's own clock, so that part is automatic).
 * Anything anchored to wall time, such as a replay-safety contract, must come from the same clock:
 * `ReplaySafetyContract::forReadOnlyCallable(..., clock: $clock)`. A VirtualClock starts at a fixed, realistic
 * date (VirtualClock::DEFAULT_WALL_START) rather than at the real time, so a test gives the same answer
 * tomorrow; `startingAt()` picks another start, as below. A passing virtual-time test shows the policy logic; it
 * is not evidence about real timing or real transports.
 *
 * Run:  php examples/05-testing-with-virtual-clock.php
 */

namespace Reqtide\Examples;

use Enaxon\Reqtide\Completion\CompletionDisposition;
use Enaxon\Reqtide\Exception\AdmissionRejectedException;
use Enaxon\Reqtide\Execution\ExecutionContext;
use Enaxon\Reqtide\Execution\OperationSafety;
use Enaxon\Reqtide\Execution\ReplaySafetyContract;
use Enaxon\Reqtide\Outcome\ExceptionClassifier;
use Enaxon\Reqtide\Pipeline\CompiledPipeline;
use Enaxon\Reqtide\Pipeline\PipelineBuilder;
use Enaxon\Reqtide\Pipeline\RuntimeEnvironment;
use Enaxon\Reqtide\Policy\AttemptTimeoutRequirement;
use Enaxon\Reqtide\Policy\BackoffMode;
use Enaxon\Reqtide\Policy\JitterMode;
use Enaxon\Reqtide\State\Memory\MemoryCircuitBackend;
use Enaxon\Reqtide\State\Memory\MemoryRetryBudgetBackend;
use Enaxon\Reqtide\Telemetry\RetryScheduled;
use Enaxon\Reqtide\Testing\ControlledRuntimeAdapter;
use Enaxon\Reqtide\Testing\DispatchScript;
use Enaxon\Reqtide\Testing\RecordingEventSink;
use Enaxon\Reqtide\Testing\SeededRandomSource;
use Enaxon\Reqtide\Testing\VirtualClock;
use Enaxon\Reqtide\Testing\VirtualDelayScheduler;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\Ratio;

require __DIR__ . '/support.php';

/**
 * Everything a test needs, wired around one VirtualClock.
 */
final class Rig
{
    public readonly VirtualClock $clock;
    public readonly VirtualDelayScheduler $scheduler;
    public readonly ControlledRuntimeAdapter $runtime;
    public readonly RecordingEventSink $events;
    public readonly CompiledPipeline $pipeline;
    private int $operations = 0;

    public function __construct(int $maxAttempts, bool $withCircuit, JitterMode $jitter = JitterMode::None, int $seed = 1)
    {
        // The clock starts at a date of our choosing; the default would do as well.
        $this->clock = (new VirtualClock())->startingAt(new \DateTimeImmutable('2026-03-01T09:00:00+00:00'));
        $this->scheduler = new VirtualDelayScheduler($this->clock);
        $this->runtime = new ControlledRuntimeAdapter($this->clock);
        $this->events = new RecordingEventSink();

        $builder = PipelineBuilder::profile('virtual-time-demo', 'v1')
            ->partition('example', 'local', 'payments-api')
            ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::Cooperative);
        if ($maxAttempts > 1) {
            $builder = $builder
                ->retry($maxAttempts, BackoffMode::Exponential, Duration::milliseconds(100), Duration::seconds(1), $jitter)
                ->retryBudget(10, 10, Duration::seconds(60), Duration::seconds(10));
        }
        if ($withCircuit) {
            $builder = $builder->circuitBreaker(PipelineBuilder::lastCalls(2), 2, Ratio::of(1, 2), Duration::seconds(30), 1, 1, Duration::seconds(10));
        }

        // The virtual scheduler, runtime and random source replace the real ones, so the environment is built by hand.
        $definition = $builder->definition();
        $this->pipeline = $builder->compile(new RuntimeEnvironment(
            $this->clock,
            $this->scheduler,
            new SeededRandomSource($seed),
            ExceptionClassifier::create()->retryOn(TransientError::class),
            $this->runtime,
            $this->events,
            null,
            $definition->circuit === null ? null : new MemoryCircuitBackend($this->clock),
            null,
            $definition->retryBudget === null ? null : new MemoryRetryBudgetBackend($this->clock),
            null,
        ));
    }

    /**
     * A context whose deadline and replay-safety contract are both measured on the virtual clock.
     *
     * @return ExecutionContext<never>
     */
    public function context(Duration $budget): ExecutionContext
    {
        return $this->pipeline->newContext(
            $budget,
            OperationSafety::replayableRead(ReplaySafetyContract::forReadOnlyCallable('virtual-read', Duration::seconds(3600), $this->clock)),
            operationId: 'virtual-' . ++$this->operations,
        );
    }
}

/** The scripted runtime decides every outcome, so this body is never reached. */
function neverCalled(): string
{
    throw new \LogicException('the scripted runtime replaces the callable');
}

$unused = static fn(): string => neverCalled();

// ---------------------------------------------------------------- 1: exponential backoff in virtual time

say('-- 1: backoff without sleeping');
$rig = new Rig(4, false);
$rig->runtime->enqueue(
    DispatchScript::throwing(new TransientError('503')),
    DispatchScript::throwing(new TransientError('503')),
    DispatchScript::returning('ok'),
);
$start = $rig->clock->peekMonotonic();
$result = $rig->pipeline->execute($unused, $rig->context(Duration::seconds(10)));
$virtualElapsed = new Duration($rig->clock->peekMonotonic() - $start);

$delays = array_map(static fn(Duration $d): int => intdiv($d->nanoseconds, 1_000_000), $rig->scheduler->requestedDelays());
say("result: {$result}");
say('dispatches: ' . $rig->runtime->dispatchCount());
say('delays (ms): ' . implode(', ', $delays));
say('virtual time elapsed (ms): ' . intdiv($virtualElapsed->nanoseconds, 1_000_000));
check($result === 'ok', 'third dispatch succeeds');
check($rig->runtime->dispatchCount() === 3, 'exactly three dispatches');
check($delays === [100, 200], 'exponential backoff: 100 ms then 200 ms');
check($virtualElapsed->nanoseconds === 300_000_000, 'the virtual clock moved by exactly the two delays');

$retryEvents = $rig->events->ofType(RetryScheduled::class);
check(count($retryEvents) === 2, 'two RetryScheduled events were recorded');
say('events: ' . implode(', ', array_map(static fn(RetryScheduled $e): string => "retry->attempt {$e->nextDispatchedAttemptNumber} ({$e->reasonCode})", $retryEvents)));

// ---------------------------------------------------------------- 2: the deadline wins

say();
say('-- 2: a deadline that is too short for the next backoff');
$rig = new Rig(4, false);
$rig->runtime->enqueue(DispatchScript::throwing(new TransientError('503')), DispatchScript::throwing(new TransientError('503')));
$completion = $rig->pipeline->executeDetailed($unused, $rig->context(Duration::milliseconds(250)));
$reasons = array_map(static fn($d): string => $d->reasonCode, $completion->diagnostics);
say('disposition: ' . $completion->disposition->value);
say('dispatches: ' . $rig->runtime->dispatchCount());
say('retry stop reason: ' . implode(', ', $reasons));
check($completion->disposition === CompletionDisposition::Failed, 'the last business failure is kept');
check($rig->runtime->dispatchCount() === 2, 'after 100 ms a 200 ms backoff no longer fits in the remaining 150 ms');
check($reasons === ['retry_delay_exceeds_deadline'], 'the stop reason is recorded');

// ---------------------------------------------------------------- 3: circuit open time, advanced by hand

say();
say('-- 3: circuit recovery by advancing the clock');
$rig = new Rig(1, true);
$rig->runtime->enqueue(
    DispatchScript::throwing(new PermanentError('down')),
    DispatchScript::throwing(new PermanentError('down')),
    DispatchScript::returning('recovered'),
);
for ($i = 0; $i < 2; ++$i) {
    try {
        $rig->pipeline->execute($unused, $rig->context(Duration::seconds(10)));
    } catch (PermanentError) {
        // the two failures that open the circuit
    }
}
try {
    $rig->pipeline->execute($unused, $rig->context(Duration::seconds(10)));
    check(false, 'the circuit must be open');
} catch (AdmissionRejectedException $e) {
    say('while open: ' . $e->problem()->code->value);
}
check($rig->runtime->dispatchCount() === 2, 'the refused call was not dispatched');

$rig->clock->advance(Duration::seconds(31)); // no sleeping: time simply moves
$value = $rig->pipeline->execute($unused, $rig->context(Duration::seconds(10)));
say("after 31 virtual seconds: {$value}");
check($value === 'recovered', 'a probe call is admitted once the open duration has passed');
check($rig->runtime->dispatchCount() === 3, 'the probe was dispatched');

// ---------------------------------------------------------------- 4: seeded jitter is reproducible

say();
say('-- 4: seeded jitter');
$runs = [];
foreach ([7, 7, 8] as $seed) {
    $rig = new Rig(3, false, JitterMode::Full, $seed);
    $rig->runtime->enqueue(DispatchScript::throwing(new TransientError('503')), DispatchScript::throwing(new TransientError('503')), DispatchScript::returning('ok'));
    $rig->pipeline->execute($unused, $rig->context(Duration::seconds(10)));
    $runs[] = array_map(static fn(Duration $d): int => $d->nanoseconds, $rig->scheduler->requestedDelays());
}
say('same seed, same delays: ' . ($runs[0] === $runs[1] ? 'yes' : 'no'));
check($runs[0] === $runs[1], 'the same seed gives identical jittered delays');
check(count($runs[0]) === 2, 'two retries, two delays');
check($runs[0][0] <= 100_000_000 && $runs[0][1] <= 200_000_000, 'full jitter stays within the exponential ceiling');

say('ok');
