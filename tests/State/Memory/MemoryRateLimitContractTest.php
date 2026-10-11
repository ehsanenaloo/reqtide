<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\State\Memory;

use Enaxon\Reqtide\Tests\Contract\RateLimitBackendContract;
use Enaxon\Reqtide\Tests\Contract\RateLimitHarness;

/** The shared RateLimitBackend contract, run against the process-local memory backend. */
final class MemoryRateLimitContractTest extends RateLimitBackendContract
{
    protected function createHarness(): RateLimitHarness
    {
        return new MemoryBackendHarness();
    }
}
