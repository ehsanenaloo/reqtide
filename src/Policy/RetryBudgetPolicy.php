<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Policy;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Internal\Horizon;
use Enaxon\Reqtide\Internal\NumericLimits;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\PolicyIdentity;
use Enaxon\Reqtide\Value\PositiveInt;

final readonly class RetryBudgetPolicy
{
    public function __construct(
        public PolicyIdentity $identity,
        public PositiveInt $capacityTokens,
        public PositiveInt $refillTokens,
        public Duration $refillInterval,
        public Duration $reservationLease,
    ) {
        if ($capacityTokens->value > NumericLimits::MAX_PORTABLE_TOKENS || $refillTokens->value > NumericLimits::MAX_PORTABLE_TOKENS) {
            throw new InvalidConfigurationException('Budget capacity and refill must not exceed 9007199254 tokens.');
        }
        if (!$refillInterval->isPositive() || !$reservationLease->isPositive()) {
            throw new InvalidConfigurationException('refillInterval and reservationLease must be positive.');
        }
        Horizon::assert($reservationLease, 'reservationLease');
    }
}
