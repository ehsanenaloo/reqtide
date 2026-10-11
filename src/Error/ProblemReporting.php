<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Error;

use Enaxon\Reqtide\Value\AttemptId;
use Enaxon\Reqtide\Value\OperationId;
use Enaxon\Reqtide\Value\ProfileVersion;

/**
 * Shared `problem()` implementation for ProblemCarrier exceptions. The using class provides `reason()` and may
 * override `problemCauses()` when its causal links are not just the `previous` chain.
 *
 * An exception thrown by `CompiledPipeline::execute()` has the operation context (operation id, profile
 * version, attempt id when the problem is about one attempt) attached by the pipeline just before it is
 * thrown, so `problem()` then names the operation like `ExecutionCompletion::problem()` does. An exception
 * created elsewhere has no context and reports null for those members.
 *
 * @internal
 * @phpstan-require-implements ProblemCarrier
 */
trait ProblemReporting
{
    private ?OperationId $problemOperationId = null;

    private ?ProfileVersion $problemProfileVersion = null;

    private ?AttemptId $problemAttemptId = null;

    public function problem(): Problem
    {
        $problem = new Problem($this->reason(), null, null, null, null, null, $this->problemCauses());

        return $this->problemOperationId !== null && $this->problemProfileVersion !== null
            ? $problem->withContext($this->problemOperationId, $this->problemProfileVersion, $this->problemAttemptId)
            : $problem;
    }

    /**
     * Records which operation raised this exception. Called by the pipeline only; the first context wins, so an
     * exception that is rethrown through an outer pipeline keeps the operation that really raised it.
     *
     * @internal
     */
    public function attachProblemContext(OperationId $operationId, ProfileVersion $profileVersion, ?AttemptId $attemptId = null): void
    {
        if ($this->problemOperationId !== null) {
            return;
        }
        $this->problemOperationId = $operationId;
        $this->problemProfileVersion = $profileVersion;
        $this->problemAttemptId = $attemptId;
    }

    /** @return list<ProblemCause> */
    protected function problemCauses(): array
    {
        return ProblemCause::chainFrom($this->getPrevious());
    }
}
