<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Execution;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Time\Cancellation;
use Enaxon\Reqtide\Time\Deadline;
use Enaxon\Reqtide\Value\AttemptId;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\OperationId;

final readonly class AttemptContext
{
    public function __construct(
        public OperationId $operationId,
        public AttemptId $attemptId,
        public int $dispatchedAttemptNumber,
        public Deadline $deadline,
        public Cancellation $cancellation,
        public Duration $attemptTimeout,
    ) {
        if ($dispatchedAttemptNumber < 1) {
            throw new InvalidConfigurationException('dispatchedAttemptNumber must be at least 1.');
        }
        if (!$attemptTimeout->isPositive()) {
            throw new InvalidConfigurationException('attemptTimeout must be positive.');
        }
    }
}
