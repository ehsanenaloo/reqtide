<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\State\Memory;

/**
 * Algorithm state projected to an effective tick without touching the stored record. The three fields have
 * the meaning documented on RateRecord.
 *
 * @internal
 */
final readonly class RateView
{
    public function __construct(public int $primary, public int $secondary, public int $windowStart) {}
}
