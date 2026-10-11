<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Exception;

use Enaxon\Reqtide\Error\ProblemCarrier;
use Enaxon\Reqtide\Error\ProblemReporting;
use Enaxon\Reqtide\Error\ReasonCode;

/** Attempt preparation or final replay verification failed; nothing was dispatched. */
final class PreparationFailedException extends \RuntimeException implements ProblemCarrier
{
    use ProblemReporting;

    public function __construct(public readonly \Throwable $preparationCause)
    {
        parent::__construct('Attempt preparation failed', 0, $preparationCause);
    }

    public function reason(): ReasonCode
    {
        return ReasonCode::PreparationFailed;
    }
}
