<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Completion;

enum SettlementOverride: string
{
    case None = 'none';
    case Cancelled = 'cancelled';
    case DeadlineExceeded = 'deadline_exceeded';
}
