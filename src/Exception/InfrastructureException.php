<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Exception;

use Enaxon\Reqtide\Error\ProblemCarrier;
use Enaxon\Reqtide\Error\ProblemReporting;

/** Clock, state-backend or preparation infrastructure failed; never a hidden success. */
abstract class InfrastructureException extends \RuntimeException implements ProblemCarrier
{
    use ProblemReporting;
}
