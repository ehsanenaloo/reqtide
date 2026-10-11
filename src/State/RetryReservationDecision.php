<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\State;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Value\Duration;

final readonly class RetryReservationDecision
{
    /** Reservation iff Reserved; a wait is carried only by Exhausted. */
    public function __construct(
        public RetryReservationStatus $status,
        public ?RetryBudgetReservation $reservation,
        public ?Duration $retryAfter,
    ) {
        if (($status === RetryReservationStatus::Reserved) !== ($reservation !== null)) {
            throw new InvalidConfigurationException('A reservation is present if and only if the decision is Reserved.');
        }
        if ($retryAfter !== null && $status !== RetryReservationStatus::Exhausted) {
            throw new InvalidConfigurationException('Only an Exhausted decision may carry a wait.');
        }
    }
}
