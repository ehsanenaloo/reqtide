<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Tests\Support;

use Enaxon\Reqtide\Time\Cancellation;

/** Becomes cancelled once the given number of real seconds has passed since it was created. */
final class CancelAfterSeconds implements Cancellation
{
    private readonly int $startedAt;

    public function __construct(private readonly float $seconds)
    {
        $this->startedAt = hrtime(true);
    }

    public function isRequested(): bool
    {
        return (hrtime(true) - $this->startedAt) / 1e9 >= $this->seconds;
    }
}
