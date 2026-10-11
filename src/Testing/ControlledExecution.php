<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Testing;

use Enaxon\Reqtide\Completion\Diagnostic;
use Enaxon\Reqtide\Completion\SettlementOverride;
use Enaxon\Reqtide\Exception\ClockFailureException;
use Enaxon\Reqtide\Outcome\Outcome;
use Enaxon\Reqtide\Runtime\AttemptControls;
use Enaxon\Reqtide\Runtime\RuntimeExecution;
use Enaxon\Reqtide\Runtime\SettlementObservation;
use Enaxon\Reqtide\Value\Duration;

/**
 * Already-settled execution produced by ControlledRuntimeAdapter.
 *
 * Observation: the settlement is timed on the controls' monotonic clock;
 * observed >= deadline is late (DeadlineExceeded, evaluated before cancellation); otherwise a
 * requested cancellation yields Cancelled. An unreadable clock yields null timing, incomplete
 * accounting and a diagnostic, and never fabricates an on-time observation.
 *
 * @api-for-tests
 * @template T
 * @implements RuntimeExecution<T>
 */
final class ControlledExecution implements RuntimeExecution
{
    /** @param Outcome<T> $outcome */
    public function __construct(
        private readonly Outcome $outcome,
        private readonly ?int $startMonotonic,
    ) {}

    public function awaitObservedSettlement(AttemptControls $controls): SettlementObservation
    {
        try {
            $observed = $controls->clock->monotonicNow();
        } catch (ClockFailureException) {
            $observed = null;
        }

        if ($observed === null) {
            return new SettlementObservation(
                $this->outcome,
                $controls->cancellation->isRequested() ? SettlementOverride::Cancelled : SettlementOverride::None,
                null,
                null,
                false,
                [new Diagnostic('settlement', 'clock_failure')],
            );
        }

        if ($observed >= $controls->deadline->monotonicNanoseconds) {
            $override = SettlementOverride::DeadlineExceeded;
        } elseif ($controls->cancellation->isRequested()) {
            $override = SettlementOverride::Cancelled;
        } else {
            $override = SettlementOverride::None;
        }

        if ($this->startMonotonic === null) {
            return new SettlementObservation(
                $this->outcome,
                $override,
                $observed,
                null,
                false,
                [new Diagnostic('settlement', 'start_unavailable')],
            );
        }

        return new SettlementObservation(
            $this->outcome,
            $override,
            $observed,
            new Duration(max(0, $observed - $this->startMonotonic)),
            true,
            [],
        );
    }
}
