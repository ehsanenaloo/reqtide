<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Runtime;

/** @template T */
interface RuntimeExecution
{
    /**
     * Drives the declared runtime through one actual settlement, retaining the latched policy observation.
     *
     * @return SettlementObservation<T>
     */
    public function awaitObservedSettlement(AttemptControls $controls): SettlementObservation;
}
