<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\State;

enum CooldownStatus: string
{
    case Clear = 'clear';
    case Active = 'active';
}
