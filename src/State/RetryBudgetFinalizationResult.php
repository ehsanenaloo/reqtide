<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\State;

final readonly class RetryBudgetFinalizationResult
{
    public function __construct(public RetryBudgetFinalizationStatus $status) {}

    /** Only the first Pending to Committed transition authorizes one dispatch. */
    public function authorizesDispatch(): bool
    {
        return $this->status === RetryBudgetFinalizationStatus::Committed;
    }
}
