<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Value;

enum BackendClockKind: string
{
    case LocalMonotonic = 'local_monotonic';
    case SharedAuthority = 'shared_authority';
}
