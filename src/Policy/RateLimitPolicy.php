<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Policy;

use Enaxon\Reqtide\Value\PolicyIdentity;

final readonly class RateLimitPolicy
{
    public function __construct(public PolicyIdentity $identity, public RateAlgorithmOptions $algorithm) {}
}
