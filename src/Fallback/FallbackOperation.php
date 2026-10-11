<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Fallback;

use Enaxon\Reqtide\Execution\ExecutionContext;
use Enaxon\Reqtide\Outcome\Outcome;

/** @template T */
interface FallbackOperation
{
    /**
     * @param Outcome<T> $original
     * @param ExecutionContext<T> $context
     * @return T
     */
    public function invoke(Outcome $original, ExecutionContext $context): mixed;
}
