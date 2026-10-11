<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Value;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;

/** Backend-authoritative instant as a normalised (seconds, microseconds) pair; never combined into one number. */
final readonly class BackendInstant
{
    public const MAX_SECONDS = 9_007_199_254_740_991;

    public function __construct(public int $seconds, public int $microseconds)
    {
        if ($seconds < 0 || $seconds > self::MAX_SECONDS || $microseconds < 0 || $microseconds > 999_999) {
            throw new InvalidConfigurationException('BackendInstant components are out of range.');
        }
    }
}
