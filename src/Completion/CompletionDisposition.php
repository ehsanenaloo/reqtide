<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Completion;

enum CompletionDisposition: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Rejected = 'rejected';
    case Deferred = 'deferred';
    case Cancelled = 'cancelled';
    case DeadlineExceeded = 'deadline_exceeded';
    case FallbackSucceeded = 'fallback_succeeded';
    case FallbackFailed = 'fallback_failed';
}
