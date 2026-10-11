<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Testing;

use Enaxon\Reqtide\Exception\PreparationFailedException;
use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Outcome\Outcome;
use Enaxon\Reqtide\Runtime\AttemptOperation;
use Enaxon\Reqtide\Runtime\PreparedAttempt;

/**
 * AttemptOperation that counts preparations, replay verifications, invocations and discards.
 * A "rewind" is a preparation after the first: the replayable input was recreated for a new attempt.
 *
 * @api-for-tests
 * @template T
 * @implements AttemptOperation<T>
 */
final class RecordingAttemptOperation implements AttemptOperation
{
    private int $prepareCount = 0;
    private int $verifyReplayCount = 0;
    private int $invokeCount = 0;
    /** @var list<Outcome<T>> */
    private array $discarded = [];
    private ?\Throwable $prepareFailure = null;
    private ?\Throwable $verifyFailure = null;

    /** @param \Closure(AttemptContext): T $work */
    public function __construct(private readonly \Closure $work) {}

    /** The next prepare() throws PreparationFailedException wrapping $cause. */
    public function failNextPrepare(\Throwable $cause): void
    {
        $this->prepareFailure = $cause;
    }

    /** The next verifyReplay() throws PreparationFailedException wrapping $cause. */
    public function failNextVerifyReplay(\Throwable $cause): void
    {
        $this->verifyFailure = $cause;
    }

    public function prepare(AttemptContext $context): PreparedAttempt
    {
        ++$this->prepareCount;
        if ($this->prepareFailure !== null) {
            $cause = $this->prepareFailure;
            $this->prepareFailure = null;

            throw new PreparationFailedException($cause);
        }

        return new RecordingPreparedAttempt($this);
    }

    public function prepareCount(): int
    {
        return $this->prepareCount;
    }

    public function rewinds(): int
    {
        return max(0, $this->prepareCount - 1);
    }

    public function verifyReplayCount(): int
    {
        return $this->verifyReplayCount;
    }

    /** Times the user work actually ran. */
    public function invokeCount(): int
    {
        return $this->invokeCount;
    }

    /** @return list<Outcome<T>> */
    public function discardedOutcomes(): array
    {
        return $this->discarded;
    }

    /** @internal Called by RecordingPreparedAttempt. */
    public function recordVerifyReplay(): void
    {
        ++$this->verifyReplayCount;
        if ($this->verifyFailure !== null) {
            $cause = $this->verifyFailure;
            $this->verifyFailure = null;

            throw new PreparationFailedException($cause);
        }
    }

    /**
     * @internal Called by RecordingPreparedAttempt.
     * @return T
     */
    public function recordInvoke(AttemptContext $context): mixed
    {
        ++$this->invokeCount;

        return ($this->work)($context);
    }

    /**
     * @internal Called by RecordingPreparedAttempt.
     * @param Outcome<T> $outcome
     */
    public function recordDiscard(Outcome $outcome): void
    {
        $this->discarded[] = $outcome;
    }
}
