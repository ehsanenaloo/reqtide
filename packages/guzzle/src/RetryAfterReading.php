<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle;

use Enaxon\Reqtide\Value\Duration;

/**
 * What the `Retry-After` header lines of one response say. `$delay` is the greatest valid delay, or null when no line
 * was valid: null means "no lower bound", never zero. A line that is malformed or out of range is counted and ignored;
 * it never becomes a truncated or invented delay.
 */
final readonly class RetryAfterReading
{
    public function __construct(
        public ?Duration $delay,
        public int $validLines,
        public int $rejectedLines,
        public bool $outOfRange,
    ) {}
}
