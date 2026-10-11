<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Policy;

enum AttemptTimeoutRequirement: string
{
    case Cooperative = 'cooperative';
    case TransportEnforced = 'transport_enforced';
}
