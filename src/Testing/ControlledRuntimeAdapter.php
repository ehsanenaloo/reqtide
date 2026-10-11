<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Testing;

use Enaxon\Reqtide\Exception\ClockFailureException;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Execution\BoundedMetadata;
use Enaxon\Reqtide\Outcome\Outcome;
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

/**
 * Synchronous RuntimeAdapter whose every dispatch is scripted by the test. It records each real
 * dispatch (context and controls) so tests assert observed dispatch counts, never assumed ones.
 *
 * Each invoke() consumes the next DispatchScript from the queue (or the default script). With
 * neither available the dispatch is still recorded and then fails with a LogicException, so an
 * unexpected extra dispatch is both visible in the count and loud.
 *
 * @api-for-tests
 */
final class ControlledRuntimeAdapter implements RuntimeAdapter
{
    /** @var list<DispatchScript> */
    private array $queue = [];
    /** @var list<DispatchRecord> */
    private array $dispatches = [];

    public function __construct(
        private readonly VirtualClock $clock,
        private readonly ?DispatchScript $default = null,
        private readonly ?RuntimeCapabilities $capabilities = null,
    ) {
        if ($capabilities !== null && $capabilities->invocation !== InvocationForm::Synchronous) {
            throw new InvalidConfigurationException('ControlledRuntimeAdapter is synchronous only.');
        }
    }

    public static function defaultCapabilities(): RuntimeCapabilities
    {
        return new RuntimeCapabilities(
            InvocationForm::Synchronous,
            AttemptTimeoutCapability::Unsupported,
            SchedulingCapability::BoundedBlocking,
            QueueOwnership::None,
            CancellationCapability::CheckpointOnly,
            SettlementCapability::InlineReturnOrThrow,
            DetachedCompletionCapability::Unsupported,
            NonblockingCapability::NotClaimed,
            PhysicalStopCapability::NotGuaranteed,
        );
    }

    public function enqueue(DispatchScript ...$scripts): void
    {
        foreach ($scripts as $script) {
            $this->queue[] = $script;
        }
    }

    public function capabilities(): RuntimeCapabilities
    {
        return $this->capabilities ?? self::defaultCapabilities();
    }

    /**
     * @template T
     * @param PreparedAttempt<T> $operation
     * @return RuntimeExecution<T>
     */
    public function invoke(PreparedAttempt $operation, AttemptContext $context, AttemptControls $controls): RuntimeExecution
    {
        $this->dispatches[] = new DispatchRecord($context, $controls);
        $script = array_shift($this->queue) ?? $this->default;
        if ($script === null) {
            throw new \LogicException('ControlledRuntimeAdapter has no script for dispatch #' . count($this->dispatches) . '.');
        }

        try {
            $start = $controls->clock->monotonicNow();
        } catch (ClockFailureException) {
            $start = null;
        }

        if ($script->cancelToken !== null) {
            $script->cancelToken->cancel();
        }
        if ($script->advance->isPositive()) {
            $this->clock->advance($script->advance);
        }

        try {
            if ($script->exception !== null) {
                throw $script->exception;
            }
            $value = $script->callsThrough ? $operation->invoke($context) : $script->value;
            /** @var Outcome<T> $outcome */
            $outcome = Outcome::fromValue($value, BoundedMetadata::empty());
        } catch (\Throwable $exception) {
            $outcome = Outcome::fromException($exception, BoundedMetadata::empty());
        }

        /** @var ControlledExecution<T> $execution */
        $execution = new ControlledExecution($outcome, $start);

        return $execution;
    }

    /** Number of real dispatches, including ones that had no script. */
    public function dispatchCount(): int
    {
        return count($this->dispatches);
    }

    /** @return list<DispatchRecord> */
    public function dispatches(): array
    {
        return $this->dispatches;
    }

    /** @return list<AttemptContext> */
    public function attemptContexts(): array
    {
        return array_map(static fn(DispatchRecord $record): AttemptContext => $record->context, $this->dispatches);
    }

    /** Scripts not yet consumed. */
    public function pendingScripts(): int
    {
        return count($this->queue);
    }
}
