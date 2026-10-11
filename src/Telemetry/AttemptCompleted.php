<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Telemetry;

use Enaxon\Reqtide\Outcome\CircuitVerdict;
use Enaxon\Reqtide\Value\AttemptId;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\OperationId;
use Enaxon\Reqtide\Value\ProfileVersion;

final readonly class AttemptCompleted implements Event
{
    public function __construct(
        private OperationId $operation,
        private ProfileVersion $profile,
        public AttemptId $attemptId,
        public CircuitVerdict $verdict,
        public ?Duration $elapsed,
    ) {}

    public function operationId(): OperationId
    {
        return $this->operation;
    }

    public function profileVersion(): ProfileVersion
    {
        return $this->profile;
    }
}
