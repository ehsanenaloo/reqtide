<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Time;

use Enaxon\Reqtide\Exception\ClockFailureException;

final class SystemClock implements Clock
{
    public function monotonicNow(): int
    {
        $now = hrtime(true);
        if (!is_int($now)) {
            throw new ClockFailureException('The monotonic clock is unavailable.');
        }

        return $now;
    }

    public function wallNow(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
