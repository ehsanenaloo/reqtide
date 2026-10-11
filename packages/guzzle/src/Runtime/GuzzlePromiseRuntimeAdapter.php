<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Runtime;

use Enaxon\Reqtide\Exception\ClockFailureException;
use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Guzzle\Operation\HttpPreparedAttempt;
use Enaxon\Reqtide\Guzzle\Transport\CurlMultiTransfer;
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
use Enaxon\Reqtide\Telemetry\EventSink;
use GuzzleHttp\Promise\Create;
use Psr\Http\Message\ResponseInterface;

/**
 * The runtime of a Guzzle transfer on an adapter-owned cURL multi handler. It declares an observed promise
 * with a queue the adapter owns and drives, a transfer-enforced attempt timeout, and cancellation as a request to the
 * transport. It claims neither detached completion nor non-blocking behavior nor a guaranteed physical stop: a caller
 * waits while the queue is driven, one logical operation at a time.
 *
 * Use it with a pipeline that requires `AttemptTimeoutRequirement::TransportEnforced`, an environment whose delay
 * scheduler is {@see QueueDrivenDelayScheduler} (see {@see inMemoryEnvironment()}), and operations built with
 * `HttpOperation::asyncAttemptOperation()`.
 */
final readonly class GuzzlePromiseRuntimeAdapter implements RuntimeAdapter
{
    private RuntimeCapabilities $capabilities;

    public function __construct(private CurlMultiTransfer $transfer)
    {
        $this->capabilities = new RuntimeCapabilities(
            InvocationForm::ObservedPromise,
            AttemptTimeoutCapability::TransportEnforced,
            SchedulingCapability::AdapterDrivenQueue,
            QueueOwnership::AdapterOwned,
            CancellationCapability::TransportRequest,
            SettlementCapability::TerminalPromiseSettlement,
            DetachedCompletionCapability::Unsupported,
            NonblockingCapability::NotClaimed,
            PhysicalStopCapability::NotGuaranteed,
        );
    }

    /**
     * Core's in-memory environment (system clock, process-local state) with this runtime and a delay scheduler that
     * keeps the queue moving while a retry waits.
     */
    public static function inMemoryEnvironment(PipelineDefinition $definition, OutcomeClassifier $classifier, CurlMultiTransfer $transfer, ?EventSink $events = null): RuntimeEnvironment
    {
        $base = InMemoryEnvironment::for($definition, $classifier, $events);

        return new RuntimeEnvironment(
            $base->clock,
            new QueueDrivenDelayScheduler($base->clock, $transfer->drive(...)),
            $base->random,
            $base->classifier,
            new self($transfer),
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
     * @param PreparedAttempt<ResponseInterface> $operation
     * @return RuntimeExecution<ResponseInterface>
     * @throws ClockFailureException when the start time cannot be read; nothing was started
     */
    public function invoke(PreparedAttempt $operation, AttemptContext $context, AttemptControls $controls): RuntimeExecution
    {
        $start = $controls->clock->monotonicNow();
        if ($start < 0) {
            throw new ClockFailureException('The monotonic clock returned a negative reading.');
        }

        if ($operation instanceof HttpPreparedAttempt) {
            $operation->bindControls($controls);
            try {
                $promise = $operation->start($context);
            } catch (\Throwable $failure) {
                $promise = Create::rejectionFor($failure);
            }
        } else {
            $promise = Create::rejectionFor(new \LogicException('The Guzzle promise runtime runs only operations built by HttpOperation::asyncAttemptOperation().'));
        }

        return new PromiseExecution($promise, $this->transfer->drive(...), $start);
    }
}
