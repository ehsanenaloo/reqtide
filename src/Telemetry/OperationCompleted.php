<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Telemetry;

use Enaxon\Reqtide\Completion\CompletionDisposition;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\OperationId;
use Enaxon\Reqtide\Value\ProfileVersion;

final readonly class OperationCompleted implements Event
{
    public function __construct(
        private OperationId $operation,
        private ProfileVersion $profile,
        public CompletionDisposition $disposition,
        public ?Duration $elapsed,
        public bool $accountingComplete,
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
