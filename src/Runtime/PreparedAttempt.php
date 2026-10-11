<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Runtime;

use Enaxon\Reqtide\Exception\PreparationFailedException;
use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Outcome\Outcome;

/** @template T */
interface PreparedAttempt
{
    /**
     * Final replay/identity check; no dispatch.
     *
     * @throws PreparationFailedException
     */
    public function verifyReplay(AttemptContext $context): void;

    /** @return T */
    public function invoke(AttemptContext $context): mixed;

    /**
     * Dispose only a selected discarded outcome; the callable wrapper is a no-op.
     *
     * @param Outcome<T> $outcome
     */
    public function discardOutcome(Outcome $outcome): void;
}
