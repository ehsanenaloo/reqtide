<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Pipeline\Internal;

use Enaxon\Reqtide\Completion\AttemptRecord;
use Enaxon\Reqtide\Exception\ReqtideException;

/**
 * Result of one candidate attempt: a dispatched and finalized attempt, a retry declined before dispatch, or
 * a policy/infrastructure termination (optionally carrying the record of an attempt that had already begun
 * its dispatch). The generic payload type is restored by the owning OperationRun.
 *
 * @internal
 */
final readonly class AttemptResult
{
    /**
     * @param Settled<mixed>|null $settled
     * @param AttemptRecord<mixed>|null $dispatchedRecord
     */
    private function __construct(
        public ?Settled $settled,
        public bool $declined,
        public ?ReqtideException $termination,
        public ?AttemptRecord $dispatchedRecord = null,
    ) {}

    /** @param Settled<mixed> $settled */
    public static function settled(Settled $settled): self
    {
        return new self($settled, false, null);
    }

    public static function declined(): self
    {
        return new self(null, true, null);
    }

    public static function terminated(ReqtideException $cause): self
    {
        return new self(null, false, $cause);
    }

    /**
     * The runtime adapter failed after invocation had begun: the attempt is a dispatch and keeps its record.
     *
     * @param AttemptRecord<mixed> $record
     */
    public static function failedAfterDispatch(AttemptRecord $record, ReqtideException $cause): self
    {
        return new self(null, false, $cause, $record);
    }
}
