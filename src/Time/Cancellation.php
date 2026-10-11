<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Time;

interface Cancellation
{
    public function isRequested(): bool;
}
