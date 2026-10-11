<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Outcome;

enum RetryEligibility: string
{
    case Eligible = 'eligible';
    case Ineligible = 'ineligible';
}
