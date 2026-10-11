<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Policy;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Internal\NumericLimits;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\PositiveInt;

final readonly class RetryOptions
{
    public function __construct(
        public PositiveInt $maxAttempts,
        public BackoffMode $backoff,
        public Duration $baseDelay,
        public Duration $maximumDelay,
        public JitterMode $jitter,
        public Duration $minimumRemainingAttemptAllowance,
    ) {
        if ($maxAttempts->value > NumericLimits::MAX_ATTEMPTS) {
            throw new InvalidConfigurationException('maxAttempts must be in [1, 64].');
        }
        if ($maximumDelay->compare($baseDelay) < 0) {
            throw new InvalidConfigurationException('maximumDelay must not be shorter than baseDelay.');
        }
    }

    public static function noRetry(): self
    {
        return new self(
            new PositiveInt(1),
            BackoffMode::Constant,
            Duration::zero(),
            Duration::zero(),
            JitterMode::None,
            Duration::zero(),
        );
    }
}
