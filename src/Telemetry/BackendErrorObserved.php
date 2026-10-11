<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Telemetry;

use Enaxon\Reqtide\Error\ReasonCode;
use Enaxon\Reqtide\Error\Severity;
use Enaxon\Reqtide\Internal\Codes;
use Enaxon\Reqtide\Value\OperationId;
use Enaxon\Reqtide\Value\ProfileVersion;

/** `failureCode` carries the wire value of a `ReasonCode` (for example `unavailable` or `clock_failure`). */
final readonly class BackendErrorObserved implements Event
{
    public function __construct(
        private OperationId $operation,
        private ProfileVersion $profile,
        public string $backendKind,
        public string $failureCode,
    ) {
        Codes::code($backendKind, 'backendKind');
        Codes::code($failureCode, 'failureCode');
    }

    public function operationId(): OperationId
    {
        return $this->operation;
    }

    public function profileVersion(): ProfileVersion
    {
        return $this->profile;
    }

    /** The registry code behind `failureCode`, or null when a foreign component supplied its own code. */
    public function reason(): ?ReasonCode
    {
        return ReasonCode::tryFrom($this->failureCode);
    }

    /** Advisory severity of the code; Error when the code is not a registry member. */
    public function severity(): Severity
    {
        return $this->reason()?->severity() ?? Severity::Error;
    }
}
