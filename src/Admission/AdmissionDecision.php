<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Admission;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Internal\Codes;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\NonNegativeInt;

/**
 * Matrix: Accepted has no wait; Deferred requires a positive wait; Impossible has neither remaining
 * capacity nor a wait; Rejected has a (positive) wait only when the algorithm can compute one.
 */
final readonly class AdmissionDecision
{
    public function __construct(
        public AdmissionStatus $status,
        public string $reasonCode,
        public ?NonNegativeInt $remaining,
        public ?Duration $retryAfter,
    ) {
        Codes::code($reasonCode, 'reasonCode');

        $valid = match ($status) {
            AdmissionStatus::Accepted => $retryAfter === null,
            AdmissionStatus::Deferred => $retryAfter !== null && $retryAfter->isPositive(),
            AdmissionStatus::Impossible => $remaining === null && $retryAfter === null,
            AdmissionStatus::Rejected => $retryAfter === null || $retryAfter->isPositive(),
        };
        if (!$valid) {
            throw new InvalidConfigurationException('AdmissionDecision fields violate the ' . $status->value . ' matrix.');
        }
    }
}
