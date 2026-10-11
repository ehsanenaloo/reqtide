<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Error;

/**
 * Closed vocabulary of lifecycle stages a diagnostic or problem is reported at. Append-only: a value never
 * changes meaning. Stage plus reason code is the pair carried by `Completion\Diagnostic`.
 */
enum Stage: string
{
    case Preflight = 'preflight';
    case Preparation = 'preparation';
    case RetryBudget = 'retry_budget';
    case Admission = 'admission';
    case Barrier = 'barrier';
    case Dispatch = 'dispatch';
    case Settlement = 'settlement';
    case Classification = 'classification';
    case Finalize = 'finalize';
    case Cleanup = 'cleanup';
    case Discard = 'discard';
    case Retry = 'retry';
    case Fallback = 'fallback';
    case Observation = 'observation';
    case Summary = 'summary';
}
