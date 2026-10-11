<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Internal\Arithmetic;

/**
 * Result of a capped exact division. When $capped is true the exact quotient is at least the cap, $quotient
 * equals the cap and $remainder is zero and carries no meaning (callers that clamp discard it).
 *
 * @internal
 */
final readonly class CappedQuotient
{
    public function __construct(public int $quotient, public int $remainder, public bool $capped) {}
}
