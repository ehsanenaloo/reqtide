<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Pipeline\Internal;

use Enaxon\Reqtide\State\CircuitPermit;
use Enaxon\Reqtide\State\ConcurrencyPermit;
use Enaxon\Reqtide\State\RetryBudgetReservation;

/**
 * Ownership held by exactly one physical attempt. Created per attempt, never shared and never stored on the
 * reusable pipeline.
 *
 * @internal
 */
final class HeldOwnership
{
    public ?RetryBudgetReservation $pendingReservation = null;

    public ?CircuitPermit $circuit = null;

    public ?ConcurrencyPermit $concurrency = null;

    public bool $rateDebited = false;
}
