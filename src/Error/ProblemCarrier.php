<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Error;

use Enaxon\Reqtide\Exception\ReqtideException;

/**
 * Implemented by every concrete Reqtide exception. It adds a stable machine identity and a redaction-safe
 * report to the unchanged `ReqtideException` marker (extending the marker itself would break any
 * third-party implementer).
 *
 * Catch `ReqtideException` to separate library terminations from business exceptions, then read
 * `reason()` for control flow and `problem()` for logging or an HTTP error body:
 *
 *     try {
 *         $value = $pipeline->execute(...);
 *     } catch (ReqtideException $e) {
 *         if ($e instanceof ProblemCarrier) {
 *             $report = $e->problem();           // safe to log or serialize as-is
 *             if ($e->reason()->retryability() === Retryability::AfterWait) { ... }
 *         }
 *     }
 *
 * Business exceptions thrown by the protected operation are never wrapped and never carry a Problem.
 */
interface ProblemCarrier extends ReqtideException
{
    /** The registry code identifying why Reqtide raised this exception. Never derived from the message. */
    public function reason(): ReasonCode;

    /**
     * A bounded, redaction-safe report built from the code and the cause chain only, plus the operation id,
     * profile version and attempt id when the exception was thrown by `CompiledPipeline::execute()`.
     * No message, URL, header or credential ever enters it.
     */
    public function problem(): Problem;
}
