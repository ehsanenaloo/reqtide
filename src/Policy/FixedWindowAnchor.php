<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Policy;

enum FixedWindowAnchor: string
{
    case Aligned = 'aligned';
    case FirstHit = 'first_hit';
}
