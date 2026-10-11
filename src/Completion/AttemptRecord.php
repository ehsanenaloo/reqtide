<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Completion;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Outcome\CircuitVerdict;
use Enaxon\Reqtide\Outcome\Outcome;
use Enaxon\Reqtide\Outcome\OutcomeClassification;
use Enaxon\Reqtide\Value\AttemptId;
use Enaxon\Reqtide\Value\Duration;

/**
 * One record per actual dispatch. A null `elapsed` is legal only with a clock/arithmetic diagnostic
 * and incomplete accounting, which the owning ExecutionSummary enforces.
 *
 * @template T
 */
final readonly class AttemptRecord
{
    /** @param Outcome<T> $outcome */
    public function __construct(
        public AttemptId $attemptId,
        public int $dispatchedAttemptNumber,
        public ActualAttemptDisposition $disposition,
        public Outcome $outcome,
        public ?Duration $elapsed,
        public SettlementOverride $logicalOverride,
        public ?OutcomeClassification $classification,
        public CircuitVerdict $circuitVerdict,
    ) {
        if ($dispatchedAttemptNumber < 1 || $dispatchedAttemptNumber > 64) {
            throw new InvalidConfigurationException('dispatchedAttemptNumber must be in [1, 64].');
        }

        // The actual disposition uses the unoverridden circuit classification; unclassified is Neutral.
        $expected = match ($classification?->circuit) {
            CircuitVerdict::Success => ActualAttemptDisposition::Succeeded,
            CircuitVerdict::Failure => ActualAttemptDisposition::Failed,
            CircuitVerdict::Neutral, null => ActualAttemptDisposition::Neutral,
        };
        if ($disposition !== $expected) {
            throw new InvalidConfigurationException('Attempt disposition must follow the unoverridden circuit classification.');
        }
        // A cancellation/deadline override forces the applied circuit verdict Neutral.
        if ($logicalOverride !== SettlementOverride::None && $circuitVerdict !== CircuitVerdict::Neutral) {
            throw new InvalidConfigurationException('A settlement override forces a Neutral applied circuit verdict.');
        }
    }
}
