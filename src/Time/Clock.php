<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Time;

use Enaxon\Reqtide\Exception\ClockFailureException;

interface Clock
{
    /**
     * Monotonic nanoseconds; non-negative and never decreasing for one instance.
     *
     * @throws ClockFailureException
     */
    public function monotonicNow(): int;

    /** @throws ClockFailureException */
    public function wallNow(): \DateTimeImmutable;
}
