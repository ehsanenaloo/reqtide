<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Contract;

use Enaxon\Reqtide\State\ConcurrencyBackend;

interface ConcurrencyHarness extends BackendHarness
{
    public function concurrency(int $maxPartitions = 10_000, int $maxRecords = 20_000): ConcurrencyBackend;
}
