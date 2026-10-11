<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Testing;

use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Outcome\Outcome;
use Enaxon\Reqtide\Runtime\PreparedAttempt;

/**
 * PreparedAttempt created by RecordingAttemptOperation; reports every call back to it.
 *
 * @api-for-tests
 * @template T
 * @implements PreparedAttempt<T>
 */
final class RecordingPreparedAttempt implements PreparedAttempt
{
    /** @param RecordingAttemptOperation<T> $owner */
    public function __construct(private readonly RecordingAttemptOperation $owner) {}

    public function verifyReplay(AttemptContext $context): void
    {
        $this->owner->recordVerifyReplay();
    }

    public function invoke(AttemptContext $context): mixed
    {
        return $this->owner->recordInvoke($context);
    }

    public function discardOutcome(Outcome $outcome): void
    {
        $this->owner->recordDiscard($outcome);
    }
}
