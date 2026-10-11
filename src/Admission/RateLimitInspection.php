<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Admission;

use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\NonNegativeInt;

/** Mutation-free view of a rate limit. Absent or expired state is interpreted as fresh without a write. */
final readonly class RateLimitInspection
{
    public function __construct(
        public NonNegativeInt $available,
        public bool $requestedCostWouldBeAccepted,
        public ?Duration $retryAfter,
    ) {}
}
