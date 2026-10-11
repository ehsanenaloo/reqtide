<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Outcome;

enum OutcomeKind: string
{
    case Value = 'value';
    case Exception = 'exception';
}
