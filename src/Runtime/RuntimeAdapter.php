<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Runtime;

use Enaxon\Reqtide\Execution\AttemptContext;

interface RuntimeAdapter
{
    public function capabilities(): RuntimeCapabilities;

    /**
     * Invokes the prepared operation exactly once per dispatch and captures its original return or throw.
     *
     * @template T
     * @param PreparedAttempt<T> $operation
     * @return RuntimeExecution<T>
     */
    public function invoke(PreparedAttempt $operation, AttemptContext $context, AttemptControls $controls): RuntimeExecution;
}
