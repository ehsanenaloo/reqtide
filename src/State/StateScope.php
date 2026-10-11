<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\State;

enum StateScope: string
{
    case ProcessLocal = 'process_local';
    case SharedAuthority = 'shared_authority';
}
