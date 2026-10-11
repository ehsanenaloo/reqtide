<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Exception;

use Enaxon\Reqtide\Error\ProblemCarrier;
use Enaxon\Reqtide\Error\ProblemReporting;

/** A policy (admission, deadline or cancellation) ended the operation. */
abstract class PolicyTerminationException extends \RuntimeException implements ProblemCarrier
{
    use ProblemReporting;
}
