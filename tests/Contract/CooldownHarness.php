<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Contract;

use Enaxon\Reqtide\State\CooldownBackend;

interface CooldownHarness extends BackendHarness
{
    /** @param int $maxPartitions partition ceiling; @param int $maxRecords record ceiling (both enforced by the backend) */
    public function cooldown(int $maxPartitions = 10_000, int $maxRecords = 20_000): CooldownBackend;
}
