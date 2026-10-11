<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Fallback;

/**
 * Which terminal results may run the context's {@see FallbackOperation}.
 *
 * `BusinessOutcomesOnly` is the default: a fallback runs only for an
 * actual settled attempt outcome that the classifier marks fallback-eligible.
 *
 * `IncludeAdmissionRefusals` additionally runs the fallback ONCE, at the terminal stage, when a circuit,
 * concurrency, rate or cooldown refusal (Rejected, Deferred or Impossible) ends the operation. The fallback
 * receives an `Outcome` holding the refusal exception. It never runs for cancellation, deadline, infrastructure
 * or indeterminate termination, preparation, configuration or classifier failures, or a retry-policy stop.
 */
enum FallbackScope: string
{
    case BusinessOutcomesOnly = 'business_outcomes_only';
    case IncludeAdmissionRefusals = 'include_admission_refusals';
}
