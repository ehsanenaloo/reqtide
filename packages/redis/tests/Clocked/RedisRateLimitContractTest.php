<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Clocked;

use Enaxon\Reqtide\Redis\Tests\Support\RedisBackendHarness;
use Enaxon\Reqtide\Tests\Contract\RateLimitBackendContract;
use Enaxon\Reqtide\Tests\Contract\RateLimitHarness;

/**
 * The shared rate-limit contract against real Redis servers with a
 * controllable clock. The wait-tightness test builds many harnesses of its own; every one is disposed.
 */
final class RedisRateLimitContractTest extends RateLimitBackendContract
{
    /** @var list<RedisBackendHarness> */
    private array $created = [];

    protected function createHarness(): RateLimitHarness
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
