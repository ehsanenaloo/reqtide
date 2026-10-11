<?php

declare(strict_types=1);

/**
 * 08 - Testing without sleeping: virtual time, scripted attempts and recorded events from the Enaxon\Reqtide\Testing helpers.
 * This is the code shown in docs/guides/testing.html. It uses plain checks so it runs without a test framework; in a real
 * suite replace `expect()` with your framework's assertions.
 *
 * The helpers ship inside enaxon/reqtide itself (src/Testing); no separate install.
 *
 * Run:  php examples/08-testing.php
 */

namespace Reqtide\Examples\Testing;

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

require __DIR__ . '/autoload.php';

final class UpstreamTimeout extends \RuntimeException {}

/** Replace with your framework's assertion. */
function expect(bool $condition, string $what): void
{
    if (!$condition) {
        throw new \LogicException("expectation failed: {$what}");
    }
    echo "ok: {$what}\n";
}

/** One VirtualClock drives the scheduler, the runtime and the memory backends, so time only moves when you say so. */
function rig(bool $withCircuit): array
{
    $clock = new VirtualClock();
    $scheduler = new VirtualDelayScheduler($clock);
    $runtime = new ControlledRuntimeAdapter($clock);
    $events = new RecordingEventSink();

    $builder = PipelineBuilder::profile('virtual-time', 'v1')
        ->partition('shop', 'test', 'orders-api')
        ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::Cooperative)
        ->retry(4, BackoffMode::Exponential, Duration::milliseconds(100), Duration::seconds(1), JitterMode::None)
        ->retryBudget(10, 10, Duration::seconds(60), Duration::seconds(10));
    if ($withCircuit) {
        $builder = $builder->circuitBreaker(PipelineBuilder::lastCalls(2), 2, Ratio::of(1, 2), Duration::seconds(30), 1, 1, Duration::seconds(10));
    }

    $pipeline = $builder->compile(new RuntimeEnvironment(
        $clock,
        $scheduler,
        new SeededRandomSource(1),
        ExceptionClassifier::create()->retryOn(UpstreamTimeout::class),
        $runtime,
        $events,
        null,
        $withCircuit ? new MemoryCircuitBackend($clock) : null,
        null,
        new MemoryRetryBudgetBackend($clock),
        null,
    ));

    return [$pipeline, $clock, $scheduler, $runtime, $events];
}

/** A context whose deadline and replay-safety statement are both measured on the pipeline's (virtual) clock. */
function context(CompiledPipeline $pipeline, VirtualClock $clock): ExecutionContext
{
    $contract = ReplaySafetyContract::forReadOnlyCallable('get-order', Duration::seconds(3600), $clock);

    return $pipeline->newContext(Duration::seconds(10), OperationSafety::replayableRead($contract));
}

// The scripted runtime decides every outcome, so the callable you pass is never invoked here.
$unused = static fn(): string => throw new \LogicException('the scripted runtime replaces the callable');

echo "-- backoff without sleeping\n";
[$pipeline, $clock, $scheduler, $runtime, $events] = rig(false);
$runtime->enqueue(
    DispatchScript::throwing(new UpstreamTimeout('503')),
    DispatchScript::throwing(new UpstreamTimeout('503')),
    DispatchScript::returning('ok'),
);
$start = $clock->peekMonotonic();
$result = $pipeline->execute($unused, context($pipeline, $clock));
$delays = array_map(static fn(Duration $d): int => intdiv($d->nanoseconds, 1_000_000), $scheduler->requestedDelays());

expect($result === 'ok', 'the third dispatch succeeds');
expect($runtime->dispatchCount() === 3, 'exactly three dispatches');
expect($delays === [100, 200], 'exponential backoff waited 100 ms, then 200 ms');
expect($clock->peekMonotonic() - $start === 300_000_000, 'the virtual clock moved by exactly those two delays');
expect(count($events->ofType(RetryScheduled::class)) === 2, 'two RetryScheduled events were recorded');

echo "-- circuit recovery by advancing the clock\n";
[$pipeline, $clock, , $runtime] = rig(true);
$runtime->enqueue(
    DispatchScript::throwing(new \DomainException('down')),
    DispatchScript::throwing(new \DomainException('down')),
    DispatchScript::returning('recovered'),
);
for ($i = 0; $i < 2; ++$i) {
    try {
        $pipeline->execute($unused, context($pipeline, $clock));
    } catch (\DomainException) {
        // two failures open the circuit
    }
}
try {
    $pipeline->execute($unused, context($pipeline, $clock));
} catch (AdmissionRejectedException $e) {
    expect($e->reason()->value === 'circuit_open', 'the third call is refused while the circuit is open');
}
expect($runtime->dispatchCount() === 2, 'the refused call was never dispatched');

$clock->advance(Duration::seconds(31)); // no sleeping: time just moves
expect($pipeline->execute($unused, context($pipeline, $clock)) === 'recovered', 'a probe is admitted after the open duration');
expect($runtime->dispatchCount() === 3, 'the probe was dispatched');
