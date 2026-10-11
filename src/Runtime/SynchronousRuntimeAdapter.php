<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Runtime;

use Enaxon\Reqtide\Completion\Diagnostic;
use Enaxon\Reqtide\Completion\SettlementOverride;
use Enaxon\Reqtide\Error\ReasonCode;
use Enaxon\Reqtide\Error\Stage;
use Enaxon\Reqtide\Exception\ClockFailureException;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Execution\BoundedMetadata;
use Enaxon\Reqtide\Outcome\Outcome;
use Enaxon\Reqtide\Time\Clock;
use Enaxon\Reqtide\Value\Duration;

/**
 * Runtime for in-process callables. Invocation is inline: the original return or throw is captured as an
 * Outcome and the execution is already settled when it is returned.
 *
 * It enforces no attempt timeout and checks cancellation only after the callable returns (a blocked callable
 * cannot sample it). Settlement is observed on the clock of the attempt controls; observation at or after the
 * absolute deadline is logically late even when no earlier checkpoint saw expiry.
 */
final readonly class SynchronousRuntimeAdapter implements RuntimeAdapter
{
    private RuntimeCapabilities $capabilities;

    public function __construct(SchedulingCapability $scheduling = SchedulingCapability::None)
    {
        if ($scheduling === SchedulingCapability::AdapterDrivenQueue) {
            throw new InvalidConfigurationException('A synchronous runtime cannot drive an adapter-owned queue.');
        }
        $this->capabilities = new RuntimeCapabilities(
            InvocationForm::Synchronous,
            AttemptTimeoutCapability::Unsupported,
            $scheduling,
            QueueOwnership::None,
            CancellationCapability::CheckpointOnly,
            SettlementCapability::InlineReturnOrThrow,
            DetachedCompletionCapability::Unsupported,
            NonblockingCapability::NotClaimed,
            PhysicalStopCapability::NotGuaranteed,
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
     * @throws ClockFailureException when the start time cannot be read; nothing was invoked
     */
    public function invoke(PreparedAttempt $operation, AttemptContext $context, AttemptControls $controls): RuntimeExecution
    {
        $clock = $controls->clock;
        $start = $clock->monotonicNow();
        if ($start < 0) {
            throw new ClockFailureException('The monotonic clock returned a negative reading.');
        }

        try {
            $outcome = Outcome::fromValue($operation->invoke($context), BoundedMetadata::empty());
        } catch (\Throwable $throwable) {
            $outcome = Outcome::fromException($throwable, BoundedMetadata::empty());
        }

        return new SettledRuntimeExecution($this->observe($outcome, $start, $controls, $clock));
    }

    /**
     * @template T
     * @param Outcome<T> $outcome
     * @return SettlementObservation<T>
     */
    private function observe(Outcome $outcome, int $start, AttemptControls $controls, Clock $clock): SettlementObservation
    {
        $diagnostics = [];
        $complete = true;

        try {
            $now = $clock->monotonicNow();
            if ($now < 0) {
                throw new ClockFailureException('The monotonic clock returned a negative reading.');
            }
        } catch (ClockFailureException) {
            $now = null;
        }

        $cancelled = false;
        try {
            $cancelled = $controls->cancellation->isRequested();
        } catch (\Throwable) {
            $diagnostics[] = Diagnostic::of(Stage::Settlement, ReasonCode::CancellationSampleFailed);
            $complete = false;
        }

        if ($now === null) {
            // Never declare an unobserved settlement on time: keep any established cancellation, add nothing else.
            $diagnostics[] = Diagnostic::of(Stage::Settlement, ReasonCode::ClockUnavailable);

            return new SettlementObservation(
                $outcome,
                $cancelled ? SettlementOverride::Cancelled : SettlementOverride::None,
                null,
                null,
                false,
                $diagnostics,
            );
        }

        // Deadline equality is expiry and is sampled before cancellation.
        $override = match (true) {
            $now >= $controls->deadline->monotonicNanoseconds => SettlementOverride::DeadlineExceeded,
            $cancelled => SettlementOverride::Cancelled,
            default => SettlementOverride::None,
        };

        $elapsed = null;
        if ($now >= $start) {
            $elapsed = new Duration($now - $start);
        } else {
            $diagnostics[] = Diagnostic::of(Stage::Settlement, ReasonCode::ElapsedUnavailable);
            $complete = false;
        }

        return new SettlementObservation($outcome, $override, $now, $elapsed, $complete, $diagnostics);
    }
}
