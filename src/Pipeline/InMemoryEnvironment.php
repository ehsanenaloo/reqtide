<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Pipeline;

use Enaxon\Reqtide\Outcome\OutcomeClassifier;
use Enaxon\Reqtide\Runtime\SchedulingCapability;
use Enaxon\Reqtide\Runtime\SynchronousRuntimeAdapter;
use Enaxon\Reqtide\State\Memory\MemoryCircuitBackend;
use Enaxon\Reqtide\State\Memory\MemoryConcurrencyBackend;
use Enaxon\Reqtide\State\Memory\MemoryCooldownBackend;
use Enaxon\Reqtide\State\Memory\MemoryRateLimitBackend;
use Enaxon\Reqtide\State\Memory\MemoryRetryBudgetBackend;
use Enaxon\Reqtide\Telemetry\EventSink;
use Enaxon\Reqtide\Telemetry\NullEventSink;
use Enaxon\Reqtide\Time\BoundedBlockingScheduler;
use Enaxon\Reqtide\Time\SecureRandomSource;
use Enaxon\Reqtide\Time\SystemClock;

/**
 * Builds the {@see RuntimeEnvironment} for a single-process, in-memory setup.
 *
 * It wires `SystemClock`, `BoundedBlockingScheduler` (retry delays really sleep, in slices of at most 5 ms),
 * `SecureRandomSource`, the in-process `SynchronousRuntimeAdapter`, and a process-local memory backend for
 * every policy the definition configures (and none for the others, so nothing unused is allocated).
 *
 * Honest scope
 * ------------
 * - All state lives in this PHP process. Nothing is shared with other processes, workers or servers. Under
 *   PHP-FPM a pipeline built inside a request starts every request with a full quota and a closed circuit.
 *   Build the environment once per long-lived worker (CLI worker, Swoole/RoadRunner/FrankenPHP worker) to keep
 *   state across operations.
 * - The synchronous runtime cannot interrupt a running callable or enforce an attempt timeout: configure the
 *   attempt timeout as `AttemptTimeoutRequirement::Cooperative`.
 * - For deterministic tests use the `Enaxon\Reqtide\Testing` helpers (`VirtualClock` and friends) with a hand-built
 *   {@see RuntimeEnvironment}; a virtual clock must never be paired with `BoundedBlockingScheduler`.
 */
final class InMemoryEnvironment
{
    private function __construct() {}

    /**
     * @param PipelineDefinition $definition decides which memory backends are created
     * @param OutcomeClassifier $classifier there is deliberately no default: what counts as retryable or as a
     *                                      circuit failure is the application's decision (see ExceptionClassifier)
     * @param EventSink|null $events defaults to a sink that discards every event
     */
    public static function for(PipelineDefinition $definition, OutcomeClassifier $classifier, ?EventSink $events = null): RuntimeEnvironment
    {
        $clock = new SystemClock();

        return new RuntimeEnvironment(
            $clock,
            new BoundedBlockingScheduler($clock),
            new SecureRandomSource(),
            $classifier,
            new SynchronousRuntimeAdapter(SchedulingCapability::BoundedBlocking),
            $events ?? new NullEventSink(),
            $definition->rateLimit === null ? null : new MemoryRateLimitBackend($clock),
            $definition->circuit === null ? null : new MemoryCircuitBackend($clock),
            $definition->concurrency === null ? null : new MemoryConcurrencyBackend($clock),
            $definition->retryBudget === null ? null : new MemoryRetryBudgetBackend($clock),
            $definition->cooldown === null ? null : new MemoryCooldownBackend($clock),
        );
    }
}
