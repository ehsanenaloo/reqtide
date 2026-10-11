<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Time;

enum DelayDisposition: string
{
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case DeadlineExceeded = 'deadline_exceeded';
}
