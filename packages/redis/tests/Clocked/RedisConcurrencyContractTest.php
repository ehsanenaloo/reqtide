<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Clocked;

use Enaxon\Reqtide\Redis\Tests\Support\RedisBackendHarness;
use Enaxon\Reqtide\Tests\Contract\ConcurrencyBackendContract;
use Enaxon\Reqtide\Tests\Contract\ConcurrencyHarness;

/** The shared concurrency contract against real Redis servers with a controllable clock. */
final class RedisConcurrencyContractTest extends ConcurrencyBackendContract
{
    private ?RedisBackendHarness $created = null;

    protected function createHarness(): ConcurrencyHarness
    {
        return $this->created = RedisBackendHarness::create();
    }

    protected function tearDown(): void
    {
        $this->created?->dispose();
        parent::tearDown();
    }
}
