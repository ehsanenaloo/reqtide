<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Policy;

enum BackoffMode: string
{
    case Constant = 'constant';
    case Exponential = 'exponential';
}
