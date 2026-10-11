<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\State;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\NonNegativeInt;

/**
 * Mutation-free, advisory view of a retry budget, used to decide before a retry delay whether the retry could be granted.
 *
 * `wouldReserve` says whether a reservation issued at the moment of inspection would have been granted. It is a
 * time-of-check answer only: the authoritative reservation after the retry delay may still be refused, and a
 * refusal by the pre-delay check never reserves, commits or releases anything. Absent or lease-expired state is
 * interpreted as a fresh or refunded budget without a write.
 */
final readonly class RetryBudgetInspection
{
    /**
     * @param NonNegativeInt $availableTokens whole tokens a reservation could take now (held reservations excluded)
     * @param bool $wouldReserve whether one reservation would be granted now
     * @param Duration|null $retryAfter earliest wait until a token is expected; only when `wouldReserve` is false,
     *                                  and null when held reservations leave no headroom for a wait to help
     */
    public function __construct(
        public NonNegativeInt $availableTokens,
        public bool $wouldReserve,
        public ?Duration $retryAfter,
    ) {
        if ($wouldReserve && $availableTokens->value < 1) {
            throw new InvalidConfigurationException('A budget that would reserve must have at least one available token.');
        }
        if ($wouldReserve && $retryAfter !== null) {
            throw new InvalidConfigurationException('Only a refusing inspection may carry a wait.');
        }
    }
}
