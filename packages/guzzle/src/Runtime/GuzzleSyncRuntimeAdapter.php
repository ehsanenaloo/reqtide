<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Runtime;

use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Guzzle\Operation\HttpPreparedAttempt;
use Enaxon\Reqtide\Outcome\OutcomeClassifier;
use Enaxon\Reqtide\Pipeline\InMemoryEnvironment;
use Enaxon\Reqtide\Pipeline\PipelineDefinition;
use Enaxon\Reqtide\Pipeline\RuntimeEnvironment;
use Enaxon\Reqtide\Runtime\AttemptControls;
use Enaxon\Reqtide\Runtime\AttemptTimeoutCapability;
use Enaxon\Reqtide\Runtime\CancellationCapability;
use Enaxon\Reqtide\Runtime\DetachedCompletionCapability;
use Enaxon\Reqtide\Runtime\InvocationForm;
use Enaxon\Reqtide\Runtime\NonblockingCapability;
use Enaxon\Reqtide\Runtime\PhysicalStopCapability;
use Enaxon\Reqtide\Runtime\PreparedAttempt;
use Enaxon\Reqtide\Runtime\QueueOwnership;
use Enaxon\Reqtide\Runtime\RuntimeAdapter;
use Enaxon\Reqtide\Runtime\RuntimeCapabilities;
use Enaxon\Reqtide\Runtime\RuntimeExecution;
use Enaxon\Reqtide\Runtime\SchedulingCapability;
use Enaxon\Reqtide\Runtime\SettlementCapability;
use Enaxon\Reqtide\Runtime\SynchronousRuntimeAdapter;
use Enaxon\Reqtide\Telemetry\EventSink;

/**
 * The runtime of a synchronous Guzzle transfer (cURL easy handler). It settles inline like Core's synchronous runtime,
 * whose observation it reuses, and it declares the one thing Core's cannot: the attempt timeout is enforced by the
 * transport, because {@see \Enaxon\Reqtide\Guzzle\Transport\CurlTransfer} gives every transfer a `timeout` that is the
 * smaller of the attempt timeout and the time left before the deadline. Cancellation is still checked only at
 * checkpoints (a running transfer cannot be interrupted), and no physical stop is guaranteed beyond the timeout.
 *
 * Use it with a pipeline that requires `AttemptTimeoutRequirement::TransportEnforced`.
 */
final readonly class GuzzleSyncRuntimeAdapter implements RuntimeAdapter
{
    private SynchronousRuntimeAdapter $inner;

    private RuntimeCapabilities $capabilities;

    public function __construct(SchedulingCapability $scheduling = SchedulingCapability::BoundedBlocking)
    {
        $this->inner = new SynchronousRuntimeAdapter($scheduling);
        $this->capabilities = new RuntimeCapabilities(
            InvocationForm::Synchronous,
            AttemptTimeoutCapability::TransportEnforced,
            $scheduling,
            QueueOwnership::None,
            CancellationCapability::CheckpointOnly,
            SettlementCapability::InlineReturnOrThrow,
            DetachedCompletionCapability::Unsupported,
            NonblockingCapability::NotClaimed,
            PhysicalStopCapability::NotGuaranteed,
        );
    }

    /**
     * Core's in-memory environment (system clock, real sleeping delays, process-local state) with this runtime
     * instead of the callable runtime.
     */
    public static function inMemoryEnvironment(PipelineDefinition $definition, OutcomeClassifier $classifier, ?EventSink $events = null): RuntimeEnvironment
    {
        $base = InMemoryEnvironment::for($definition, $classifier, $events);

        return new RuntimeEnvironment(
            $base->clock,
            $base->scheduler,
            $base->random,
            $base->classifier,
            new self(),
            $base->events,
            $base->rateLimit,
            $base->circuit,
            $base->concurrency,
            $base->retryBudget,
            $base->cooldown,
        );
    }

    public function capabilities(): RuntimeCapabilities
    {
        return $this->capabilities;
    }

    /**
     * @template T
     * @param PreparedAttempt<T> $operation
     * @return RuntimeExecution<T>
     */
    public function invoke(PreparedAttempt $operation, AttemptContext $context, AttemptControls $controls): RuntimeExecution
    {
        if ($operation instanceof HttpPreparedAttempt) {
            $operation->bindControls($controls);
        }

        return $this->inner->invoke($operation, $context, $controls);
    }
}
