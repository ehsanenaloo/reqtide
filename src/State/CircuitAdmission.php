<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\State;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Value\Duration;

final readonly class CircuitAdmission
{
    /**
     * Permit iff Accepted; a wait is carried only by Open, when computable. `$transition` is the state change this
     * call caused (for example Open to HalfOpen when a probe is admitted), or null.
     */
    public function __construct(
        public CircuitAdmissionStatus $status,
        public ?CircuitPermit $permit,
        public ?Duration $retryAfter,
        public ?CircuitTransition $transition = null,
    ) {
        if (($status === CircuitAdmissionStatus::Accepted) !== ($permit !== null)) {
            throw new InvalidConfigurationException('A circuit permit is present if and only if the admission is Accepted.');
        }
        if ($retryAfter !== null && $status !== CircuitAdmissionStatus::Open) {
            throw new InvalidConfigurationException('Only an Open circuit admission may carry a wait.');
        }
    }
}
