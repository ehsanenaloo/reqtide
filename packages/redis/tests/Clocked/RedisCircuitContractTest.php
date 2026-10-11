<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Clocked;

use Enaxon\Reqtide\Redis\Tests\Support\RedisBackendHarness;
use Enaxon\Reqtide\Tests\Contract\CircuitBackendContract;
use Enaxon\Reqtide\Tests\Contract\CircuitHarness;

/** The shared circuit contract against real Redis servers with a controllable clock. */
final class RedisCircuitContractTest extends CircuitBackendContract
{
    /** @var list<RedisBackendHarness> */
    private array $created = [];

    protected function createHarness(): CircuitHarness
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
