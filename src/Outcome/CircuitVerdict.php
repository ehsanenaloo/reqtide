<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Outcome;

enum CircuitVerdict: string
{
    case Success = 'success';
    case Failure = 'failure';
    case Neutral = 'neutral';
}
