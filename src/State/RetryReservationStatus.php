<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\State;

enum RetryReservationStatus: string
{
    case Reserved = 'reserved';
    case Exhausted = 'exhausted';
}
