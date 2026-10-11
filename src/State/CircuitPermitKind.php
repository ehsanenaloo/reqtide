<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\State;

enum CircuitPermitKind: string
{
    case ClosedSample = 'closed_sample';
    case HalfOpenProbe = 'half_open_probe';
}
