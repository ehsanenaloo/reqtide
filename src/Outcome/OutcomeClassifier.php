<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Outcome;

use Enaxon\Reqtide\Execution\OperationMetadata;

interface OutcomeClassifier
{
    /**
     * @template T
     * @param Outcome<T> $outcome
     */
    public function classify(Outcome $outcome, OperationMetadata $metadata): OutcomeClassification;
}
