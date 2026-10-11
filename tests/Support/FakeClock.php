<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Support;

use Enaxon\Reqtide\Time\Clock;

final class FakeClock implements Clock
{
    public function __construct(public int $now = 1_000) {}

    public function monotonicNow(): int
    {
        return $this->now;
    }

    public function wallNow(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
    }
}
