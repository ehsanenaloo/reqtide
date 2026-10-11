<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Internal;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Value\Duration;

/**
 * Validates durations that are added to a monotonic reading to form a deadline (lease, open duration, retention).
 *
 * @internal
 */
final class Horizon
{
    private function __construct() {}

    /** @throws InvalidConfigurationException when the duration exceeds {@see NumericLimits::MAX_DEADLINE_HORIZON_NANOSECONDS} */
    public static function assert(Duration $duration, string $what): void
    {
        if ($duration->nanoseconds > NumericLimits::MAX_DEADLINE_HORIZON_NANOSECONDS) {
            throw new InvalidConfigurationException(
                $what . ' must not exceed ' . NumericLimits::MAX_DEADLINE_HORIZON_NANOSECONDS . ' nanoseconds so deadlines cannot overflow.',
            );
        }
    }
}
