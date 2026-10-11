<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Runtime;

use Enaxon\Reqtide\Exception\PreparationFailedException;
use Enaxon\Reqtide\Execution\AttemptContext;

/** @template T */
interface AttemptOperation
{
    /**
     * Recreates or verifies replayable input before admissions or rate debit. Must return promptly and
     * run outside state locks.
     *
     * @return PreparedAttempt<T>
     * @throws PreparationFailedException
     */
    public function prepare(AttemptContext $context): PreparedAttempt;
}
