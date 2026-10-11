<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Clocked;

use Enaxon\Reqtide\Redis\Tests\Support\RedisBackendHarness;
use Enaxon\Reqtide\Tests\Contract\CooldownBackendContract;
use Enaxon\Reqtide\Tests\Contract\CooldownHarness;

/** The shared cooldown contract against real Redis servers with a controllable clock. */
final class RedisCooldownContractTest extends CooldownBackendContract
{
    private ?RedisBackendHarness $created = null;

    protected function createHarness(): CooldownHarness
    {
        return $this->created = RedisBackendHarness::create();
    }

    protected function tearDown(): void
    {
        $this->created?->dispose();
        parent::tearDown();
    }
}
