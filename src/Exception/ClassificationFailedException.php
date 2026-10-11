<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Exception;

use Enaxon\Reqtide\Error\ProblemCarrier;
use Enaxon\Reqtide\Error\ProblemReporting;
use Enaxon\Reqtide\Error\ReasonCode;
use Enaxon\Reqtide\Outcome\Outcome;

/** @template T */
final class ClassificationFailedException extends \RuntimeException implements ProblemCarrier
{
    use ProblemReporting;

    /** @param Outcome<T> $actualOutcome */
    public function __construct(public readonly Outcome $actualOutcome, public readonly \Throwable $classifierCause)
    {
        parent::__construct('Outcome classification failed', 0, $classifierCause);
    }

    public function reason(): ReasonCode
    {
        return ReasonCode::ClassifierFailed;
    }
}
