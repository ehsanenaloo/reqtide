<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Error;

/**
 * What the caller ABOVE Reqtide may do after this failure. It is distinct from the library's internal retry
 * eligibility. Callers must tolerate that a later release adds cases and keep a default branch.
 */
enum Retryability: string
{
    /** Fix configuration or code; repeating the same call cannot help. */
    case Never = 'never';

    /** A policy refused the call and a wait hint exists or can be obtained; retry after waiting. */
    case AfterWait = 'after_wait';

    /** Retrying later may succeed and no side-effect uncertainty exists. */
    case Transient = 'transient';

    /** A mutation may have been applied; reconcile instead of retrying blindly. */
    case Unknown = 'unknown';
}
