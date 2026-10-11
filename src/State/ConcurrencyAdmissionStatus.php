<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\State;

enum ConcurrencyAdmissionStatus: string
{
    case Accepted = 'accepted';
    case CapacityExhausted = 'capacity_exhausted';
}
