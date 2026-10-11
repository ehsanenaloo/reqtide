<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Outcome;

enum Acceptability: string
{
    case Acceptable = 'acceptable';
    case Unacceptable = 'unacceptable';
}
