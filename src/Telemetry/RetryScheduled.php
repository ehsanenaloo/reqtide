<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Telemetry;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Internal\Codes;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\OperationId;
use Enaxon\Reqtide\Value\ProfileVersion;

final readonly class RetryScheduled implements Event
{
    public function __construct(
        private OperationId $operation,
        private ProfileVersion $profile,
        public int $nextDispatchedAttemptNumber,
        public Duration $delay,
        public string $reasonCode,
    ) {
        if ($nextDispatchedAttemptNumber < 2 || $nextDispatchedAttemptNumber > 64) {
            throw new InvalidConfigurationException('nextDispatchedAttemptNumber must be in [2, 64].');
        }
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
}
