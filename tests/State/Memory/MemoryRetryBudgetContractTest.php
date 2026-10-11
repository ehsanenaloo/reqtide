<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\State\Memory;

use Enaxon\Reqtide\Tests\Contract\RetryBudgetBackendContract;
use Enaxon\Reqtide\Tests\Contract\RetryBudgetHarness;

/** The shared RetryBudgetBackend contract, run against the process-local memory backend. */
final class MemoryRetryBudgetContractTest extends RetryBudgetBackendContract
{
    protected function createHarness(): RetryBudgetHarness
    {
        return new MemoryBackendHarness();
    }
}
