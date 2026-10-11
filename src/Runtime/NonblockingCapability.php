<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Runtime;

enum NonblockingCapability: string
{
    case NotClaimed = 'not_claimed';
}
