<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Exception;

use Enaxon\Reqtide\Error\ReasonCode;

final class DeadlineExceededException extends PolicyTerminationException
{
    public function reason(): ReasonCode
    {
        return ReasonCode::DeadlineExceeded;
    }
}
