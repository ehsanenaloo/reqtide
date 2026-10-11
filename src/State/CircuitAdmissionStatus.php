<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\State;

enum CircuitAdmissionStatus: string
{
    case Accepted = 'accepted';
    case Open = 'open';
    case ProbeCapacityExhausted = 'probe_capacity_exhausted';
}
