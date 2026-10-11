<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Clocked;

use Enaxon\Reqtide\Redis\Tests\Support\RedisBackendHarness;
use Enaxon\Reqtide\Tests\Contract\RetryBudgetBackendContract;
use Enaxon\Reqtide\Tests\Contract\RetryBudgetHarness;

/** The shared retry-budget contract against real Redis servers with a controllable clock. */
final class RedisRetryBudgetContractTest extends RetryBudgetBackendContract
{
    /** @var list<RedisBackendHarness> */
    private array $created = [];

    protected function createHarness(): RetryBudgetHarness
    {
        return $this->created[] = RedisBackendHarness::create();
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $harness) {
            $harness->dispose();
        }
        $this->created = [];
        parent::tearDown();
    }
}
