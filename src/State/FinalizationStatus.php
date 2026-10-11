<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\State;

enum FinalizationStatus: string
{
    case Applied = 'applied';
    case AlreadyFinalized = 'already_finalized';
    case StaleOwnership = 'stale_ownership';
}
