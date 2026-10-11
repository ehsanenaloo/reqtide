<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Time;

/**
 * Settable, idempotent cancellation request. A request never implies physical stop of running work.
 */
final class CancellationToken implements Cancellation
{
    private bool $requested = false;

    public function cancel(): void
    {
        $this->requested = true;
    }

    public function isRequested(): bool
    {
        return $this->requested;
    }
}
