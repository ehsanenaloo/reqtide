<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Runtime;

/**
 * Execution whose settlement was already observed when it was created, as for inline return-or-throw runtimes.
 *
 * @template T
 * @implements RuntimeExecution<T>
 */
final readonly class SettledRuntimeExecution implements RuntimeExecution
{
    /** @param SettlementObservation<T> $observation */
    public function __construct(private SettlementObservation $observation) {}

    public function awaitObservedSettlement(AttemptControls $controls): SettlementObservation
    {
        return $this->observation;
    }
}
