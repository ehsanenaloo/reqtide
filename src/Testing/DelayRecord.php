<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Testing;

use Enaxon\Reqtide\Time\DelayDisposition;
use Enaxon\Reqtide\Value\Duration;

/**
 * One recorded VirtualDelayScheduler call: what was requested and how much virtual time passed.
 *
 * @api-for-tests
 */
final readonly class DelayRecord
{
    public function __construct(
        public Duration $requested,
        public Duration $elapsed,
        public DelayDisposition $disposition,
    ) {}
}
