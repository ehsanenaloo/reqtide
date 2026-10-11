<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Contract;

use Enaxon\Reqtide\State\CircuitBackend;

interface CircuitHarness extends BackendHarness
{
    public function circuit(int $maxPartitions = 10_000, int $maxRecords = 20_000): CircuitBackend;
}
