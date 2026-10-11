<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Completion;

enum ActualAttemptDisposition: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Neutral = 'neutral';
}
