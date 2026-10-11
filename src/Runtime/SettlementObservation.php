<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Runtime;

use Enaxon\Reqtide\Completion\Diagnostic;
use Enaxon\Reqtide\Completion\SettlementOverride;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Outcome\Outcome;
use Enaxon\Reqtide\Value\Duration;

/** @template T */
final readonly class SettlementObservation
{
    /**
     * @param Outcome<T> $outcome
     * @param list<Diagnostic> $diagnostics
     */
    public function __construct(
        public Outcome $outcome,
        public SettlementOverride $logicalOverride,
        public ?int $observedMonotonicNanoseconds,
        public ?Duration $elapsed,
        public bool $accountingComplete,
        public array $diagnostics,
    ) {
        if (!array_is_list($diagnostics) || count($diagnostics) > 32) {
            throw new InvalidConfigurationException('Diagnostics must be a list of at most 32 entries.');
        }
        if ($observedMonotonicNanoseconds !== null && $observedMonotonicNanoseconds < 0) {
            throw new InvalidConfigurationException('Observed monotonic time must not be negative.');
        }
        if (($observedMonotonicNanoseconds === null || $elapsed === null) && ($accountingComplete || $diagnostics === [])) {
            throw new InvalidConfigurationException('Unavailable timing requires a diagnostic and incomplete accounting.');
        }
    }
}
