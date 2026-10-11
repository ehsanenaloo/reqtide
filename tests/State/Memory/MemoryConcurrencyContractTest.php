<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\State\Memory;

use Enaxon\Reqtide\Tests\Contract\ConcurrencyBackendContract;
use Enaxon\Reqtide\Tests\Contract\ConcurrencyHarness;

/** The shared ConcurrencyBackend contract, run against the process-local memory backend. */
final class MemoryConcurrencyContractTest extends ConcurrencyBackendContract
{
    protected function createHarness(): ConcurrencyHarness
    {
        return new MemoryBackendHarness();
    }
}
