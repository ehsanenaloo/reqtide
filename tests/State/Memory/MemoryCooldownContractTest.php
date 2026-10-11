<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\State\Memory;

use Enaxon\Reqtide\Tests\Contract\CooldownBackendContract;
use Enaxon\Reqtide\Tests\Contract\CooldownHarness;

/** The shared CooldownBackend contract, run against the process-local memory backend. */
final class MemoryCooldownContractTest extends CooldownBackendContract
{
    protected function createHarness(): CooldownHarness
    {
        return new MemoryBackendHarness();
    }
}
