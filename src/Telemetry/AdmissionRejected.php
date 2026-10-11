<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Telemetry;

use Enaxon\Reqtide\Error\ReasonCode;
use Enaxon\Reqtide\Error\Severity;
use Enaxon\Reqtide\Internal\Codes;
use Enaxon\Reqtide\Value\OperationId;
use Enaxon\Reqtide\Value\ProfileVersion;

final readonly class AdmissionRejected implements Event
{
    public function __construct(
        private OperationId $operation,
        private ProfileVersion $profile,
        public string $policyKind,
        public string $reasonCode,
    ) {
        Codes::code($policyKind, 'policyKind');
        Codes::code($reasonCode, 'reasonCode');
    }

    public function operationId(): OperationId
    {
        return $this->operation;
    }

    public function profileVersion(): ProfileVersion
    {
        return $this->profile;
    }

    /** The registry code behind `reasonCode`, or null when a backend supplied its own reason. */
    public function reason(): ?ReasonCode
    {
        return ReasonCode::tryFrom($this->reasonCode);
    }

    /** Advisory severity of the code; Info (expected control flow) when the code is not a registry member. */
    public function severity(): Severity
    {
        return $this->reason()?->severity() ?? Severity::Info;
    }
}
