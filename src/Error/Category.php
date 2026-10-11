<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Error;

/**
 * Who or what a reason code is about. There is deliberately no Business case: the protected operation's own
 * value or exception is returned as data or rethrown unchanged and is never described by Reqtide.
 */
enum Category: string
{
    /** Reqtide ended the operation by a configured rule (cancellation, deadline, circuit transitions). */
    case Policy = 'policy';

    /** Reqtide's own state, clock or runtime failed. */
    case Infrastructure = 'infrastructure';

    /** A caller defect in configuration or capabilities, raised before admission. */
    case Configuration = 'configuration';

    /** The caller's classifier failed or misbehaved. */
    case Classification = 'classification';

    /** The caller's fallback (or its cleanup) failed. */
    case Fallback = 'fallback';

    /** A rate, circuit, concurrency or cooldown protection refused or admitted a call. */
    case Admission = 'admission';

    /** The retry policy itself ended or suppressed a retry. */
    case Retry = 'retry';

    /** Ownership or protective accounting became uncertain or degraded. */
    case Accounting = 'accounting';

    /** Attempt preparation or lifecycle failed before anything was dispatched. */
    case Preparation = 'preparation';
}
