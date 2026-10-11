<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Runtime;

enum AttemptTimeoutCapability: string
{
    case Unsupported = 'unsupported';
    case TransportEnforced = 'transport_enforced';
}
