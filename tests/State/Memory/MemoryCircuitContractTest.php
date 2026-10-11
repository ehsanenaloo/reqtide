<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\State\Memory;

use Enaxon\Reqtide\Tests\Contract\CircuitBackendContract;
use Enaxon\Reqtide\Tests\Contract\CircuitHarness;

/** The shared CircuitBackend contract, run against the process-local memory backend. */
final class MemoryCircuitContractTest extends CircuitBackendContract
{
    protected function createHarness(): CircuitHarness
    {
        return new MemoryBackendHarness();
    }
}
