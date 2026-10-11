<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Time;

interface RandomSource
{
    /** Uniform integer in [$minimumInclusive, $maximumInclusive]. */
    public function integer(int $minimumInclusive, int $maximumInclusive): int;
}
