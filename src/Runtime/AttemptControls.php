<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Runtime;

use Enaxon\Reqtide\Time\Cancellation;
use Enaxon\Reqtide\Time\Clock;
use Enaxon\Reqtide\Time\Deadline;
use Enaxon\Reqtide\Value\Duration;

final readonly class AttemptControls
{
    public function __construct(
        public Duration $attemptTimeout,
        public Deadline $deadline,
        public Cancellation $cancellation,
        public Clock $clock,
    ) {}
}
