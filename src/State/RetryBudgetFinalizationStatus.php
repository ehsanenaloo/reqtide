<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\State;

enum RetryBudgetFinalizationStatus: string
{
    case Committed = 'committed';
    case Released = 'released';
    case AlreadyCommitted = 'already_committed';
    case AlreadyReleased = 'already_released';
    case Expired = 'expired';
    case StaleOwnership = 'stale_ownership';
}
