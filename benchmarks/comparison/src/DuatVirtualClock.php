<?php

declare(strict_types=1);

namespace Comparison;

use Duat\Contract\Clock;

/** Duat's documented Clock seam backed by the harness virtual clock (sleep() advances virtual time). */
final class DuatVirtualClock implements Clock
{
    public function now(): float
    {
        return VirtualTime::now();
    }

    public function sleep(float $seconds): void
    {
        VirtualTime::advance($seconds);
    }
}
