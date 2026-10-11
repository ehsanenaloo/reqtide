<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Time;

final class NeverCancelled implements Cancellation
{
    public function isRequested(): bool
    {
        return false;
    }
}
