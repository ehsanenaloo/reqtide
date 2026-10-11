<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Pipeline\Support;

use Enaxon\Reqtide\Runtime\AttemptControls;
use Enaxon\Reqtide\Runtime\RuntimeExecution;
use Enaxon\Reqtide\Runtime\SettlementObservation;

/**
 * @template T
 * @implements RuntimeExecution<T>
 */
final readonly class ThrowingRuntimeExecution implements RuntimeExecution
{
    public function __construct(private \Throwable $failure) {}

    /** @return SettlementObservation<T> */
    public function awaitObservedSettlement(AttemptControls $controls): SettlementObservation
    {
        throw $this->failure;
    }
}
