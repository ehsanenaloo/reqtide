<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Telemetry;

use Enaxon\Reqtide\Error\ReasonCode;
use Enaxon\Reqtide\Error\Severity;
use Enaxon\Reqtide\Internal\Codes;
use Enaxon\Reqtide\Value\OperationId;
use Enaxon\Reqtide\Value\ProfileVersion;

/** One circuit state change observed by a backend call of the operation (one event per transition). */
final readonly class CircuitTransitioned implements Event
{
    public function __construct(
        private OperationId $operation,
        private ProfileVersion $profile,
        public string $fromState,
        public string $toState,
        public string $reasonCode,
    ) {
        Codes::code($fromState, 'fromState');
        Codes::code($toState, 'toState');
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

    /** Advisory severity of the code; Info when the code is not a registry member. */
    public function severity(): Severity
    {
        return $this->reason()?->severity() ?? Severity::Info;
    }
}
