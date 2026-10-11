<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Exception;

use Enaxon\Reqtide\Error\ProblemCarrier;
use Enaxon\Reqtide\Error\ProblemReporting;
use Enaxon\Reqtide\Error\ReasonCode;

/** A value, option or definition violates its documented domain. Raised before any mutation. */
final class InvalidConfigurationException extends \InvalidArgumentException implements ProblemCarrier
{
    use ProblemReporting;

    public function reason(): ReasonCode
    {
        return ReasonCode::InvalidConfiguration;
    }
}
