<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Telemetry;

use Enaxon\Reqtide\Value\OperationId;
use Enaxon\Reqtide\Value\ProfileVersion;

interface Event
{
    public function operationId(): OperationId;

    public function profileVersion(): ProfileVersion;
}
