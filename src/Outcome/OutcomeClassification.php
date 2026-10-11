<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Outcome;

use Enaxon\Reqtide\Internal\Codes;
use Enaxon\Reqtide\Value\Duration;

/**
 * Independent classification fields. `retryAfterLowerBound === null` means no valid lower bound was
 * supplied; a valid zero is Duration::zero().
 */
final readonly class OutcomeClassification
{
    public function __construct(
        public RetryEligibility $retry,
        public CircuitVerdict $circuit,
        public FallbackEligibility $fallback,
        public Acceptability $acceptability,
        public string $reasonCode,
        public ?Duration $retryAfterLowerBound,
    ) {
        Codes::code($reasonCode, 'reasonCode');
    }
}
