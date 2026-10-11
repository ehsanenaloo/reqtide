<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Exception;

use Enaxon\Reqtide\Admission\AdmissionDecision;
use Enaxon\Reqtide\Error\ReasonCode;

final class AdmissionDeferredException extends PolicyTerminationException
{
    /** @param \Throwable|null $previous the last business exception when a later attempt was refused by another protection */
    public function __construct(public readonly AdmissionDecision $decision, ?\Throwable $previous = null)
    {
        parent::__construct($decision->reasonCode, 0, $previous);
    }

    /** The registry code of the decision's reason, or the generic code when a backend used its own reason. */
    public function reason(): ReasonCode
    {
        return ReasonCode::tryFrom($this->decision->reasonCode) ?? ReasonCode::AdmissionDeferred;
    }
}
