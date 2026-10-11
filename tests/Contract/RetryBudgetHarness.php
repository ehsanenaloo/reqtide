<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Contract;

use Enaxon\Reqtide\Policy\RetryBudgetPolicy;
use Enaxon\Reqtide\State\RetryBudgetBackend;

interface RetryBudgetHarness extends BackendHarness
{
    public function retryBudget(int $maxPartitions = 10_000, int $maxRecords = 20_000): RetryBudgetBackend;

    /**
     * Test-side observation of the stored budget, so a suite can assert exact scaled arithmetic that the public
     * inspection rounds away. A harness reads the backend's own storage (process memory, or a read-only test
     * connection for a shared backend); it is an observation port for tests, not a backend capability.
     *
     * @return array{balance: int, pending: int}|null balance and pending cost in scaled units (1 token = 1,000,000), or
     *                                                null when no state exists for the policy identity
     */
    public function observeBudget(RetryBudgetBackend $backend, RetryBudgetPolicy $policy): ?array;
}
