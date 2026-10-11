<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Outcome;

enum FallbackEligibility: string
{
    case Eligible = 'eligible';
    case Ineligible = 'ineligible';
}
