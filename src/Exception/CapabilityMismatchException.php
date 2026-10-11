<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Exception;

use Enaxon\Reqtide\Error\ProblemCarrier;
use Enaxon\Reqtide\Error\ProblemReporting;
use Enaxon\Reqtide\Error\ReasonCode;

/** A configured behaviour requires a capability the selected runtime, scheduler or backend does not declare. */
final class CapabilityMismatchException extends \LogicException implements ProblemCarrier
{
    use ProblemReporting;

    public function reason(): ReasonCode
    {
        return ReasonCode::CapabilityMismatch;
    }
}
