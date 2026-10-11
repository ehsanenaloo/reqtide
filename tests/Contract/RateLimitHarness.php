<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Contract;

use Enaxon\Reqtide\State\RateLimitBackend;

interface RateLimitHarness extends BackendHarness
{
    /** Rate-limit state is one record per partition, so there is a single ceiling. */
    public function rateLimit(int $maxPartitions = 10_000): RateLimitBackend;
}
