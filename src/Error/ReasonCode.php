<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Error;

/**
 * Single registry of every stable reason code Reqtide emits: diagnostics, admission decisions, circuit
 * transitions, accounting degradation, backend failures and exception reasons.
 *
 * Using the registry
 * ------------------
 * Branch on `$exception->reason()`, `$problem->code`, `Diagnostic::reason()` or an event's `reason()`; never
 * parse `getMessage()`, `Problem::detail()` or class names for control flow. Ask the code for its
 * `category()`, `severity()`, `retryability()`, `title()` and `stage()`. Consumers must keep a `default`
 * branch: new cases are added in minor releases, so an exhaustive `match` over this enum is unsupported.
 * Codes written by foreign components (a host classifier's outcome code, a third-party backend's admission
 * reason) are not registry members; `tryFrom()` returns null for them.
 *
 * Append-only policy
 * ------------------
 * 1. A published value (the string behind a case) never changes meaning and is never reused after retirement.
 * 2. Adding a case, or emitting an existing case at a new site, is a minor change.
 * 3. Changing a case's category or retryability is breaking, because callers branch on them. Severity,
 *    title and detail wording are advisory and may change in a minor release.
 * 4. A retired case is marked `@deprecated` here, keeps being emitted for at least one minor release and is
 *    removed in a major release. Its value stays reserved.
 * 5. Values are globally unique, 1 to 64 characters and match `[A-Za-z0-9._-]{1,64}` (the `Diagnostic`
 *    contract). Dotted values (`rate.*`, `circuit.*`) are the historical namespaced wire values.
 * 6. Core code must obtain a code string from this enum instead of writing the literal; a scan test fails
 *    the build otherwise.
 *
 * Several backend failure values are historical single words (`unavailable`, `corrupt`, `indeterminate`,
 * `capacity_exceeded`, `unexpected`); they are only meaningful together with the failing component or stage.
 */
enum ReasonCode: string
{
    // ---- Admission: refusals and decisions of rate, circuit, concurrency and cooldown protections.
    case CircuitOpen = 'circuit_open';
    case CircuitProbeCapacityExhausted = 'circuit_probe_capacity_exhausted';
    case ConcurrencyCapacityExhausted = 'concurrency_capacity_exhausted';
    case CooldownActive = 'cooldown_active';
    case CooldownExceedsDeadline = 'cooldown_exceeds_deadline';
    case RateAccepted = 'rate.accepted';
    case RateCostExceedsCapacity = 'rate.cost_exceeds_capacity';
    case RateExhausted = 'rate.exhausted';
    case RateWaitUnrepresentable = 'rate.wait_unrepresentable';
    /** Generic reason for a rejection whose backend reason is not a registry member. */
    case AdmissionRejected = 'admission_rejected';
    /** Generic reason for a deferral whose backend reason is not a registry member. */
    case AdmissionDeferred = 'admission_deferred';
    /** Generic reason for an impossible admission whose backend reason is not a registry member. */
    case AdmissionImpossible = 'admission_impossible';

    // ---- Circuit state transitions (facts reported by circuit backends).
    case CircuitThresholdExceeded = 'circuit.threshold_exceeded';
    case CircuitProbeFailed = 'circuit.probe_failed';
    case CircuitProbesSucceeded = 'circuit.probes_succeeded';
    case CircuitOpenElapsed = 'circuit.open_elapsed';

    // ---- Retry: the retry policy ended or suppressed a retry; the business result is kept.
    case RetryBudgetExhausted = 'retry_budget_exhausted';
    case RetryBudgetCommitRefused = 'retry_budget_commit_refused';
    case AttemptsExhausted = 'attempts_exhausted';
    case OutcomeNotRetryable = 'outcome_not_retryable';
    case RetryDelayExceedsDeadline = 'retry_delay_exceeds_deadline';
    case AttemptAllowanceUnavailable = 'attempt_allowance_unavailable';
    case ReplayContractNotValid = 'replay_contract_not_valid';
    case ReplayHorizonInadequate = 'replay_horizon_inadequate';
    case ReplayHorizonSaturated = 'replay_horizon_saturated';
    case RetryDeadlineReached = 'retry_deadline_reached';
    case RetryCancelled = 'retry_cancelled';
    case RetryAfterExceedsMaximumDelay = 'retry_after_exceeds_maximum_delay';
    case RetryAfterExceedsDeadline = 'retry_after_exceeds_deadline';
    case DelayFailed = 'delay_failed';

    // ---- Policy terminations.
    case OperationCancelled = 'operation_cancelled';
    case DeadlineExceeded = 'deadline_exceeded';

    // ---- Infrastructure: Reqtide's own state, clock or runtime failed.
    case StateUnavailable = 'unavailable';
    case StateCorrupt = 'corrupt';
    case StateIndeterminate = 'indeterminate';
    case StateCapacityExceeded = 'capacity_exceeded';
    case ClockFailure = 'clock_failure';
    case UnexpectedBackendFailure = 'unexpected';
    case RuntimeAdapterFailure = 'runtime_adapter_failure';
    case RateConsumeIndeterminate = 'rate_consume_indeterminate';
    case CommitNotConfirmed = 'commit_not_confirmed';
    case ClockUnavailable = 'clock_unavailable';
    case RetryClockUnavailable = 'retry_clock_unavailable';
    case FallbackClockUnavailable = 'fallback_clock_unavailable';
    case TimingUnavailable = 'timing_unavailable';
    case FallbackTimingUnavailable = 'fallback_timing_unavailable';
    case ElapsedUnavailable = 'elapsed_unavailable';
    case CancellationSampleFailed = 'cancellation_sample_failed';
    case RandomOutOfRange = 'random_out_of_range';

    // ---- Accounting: ownership or protective accounting became uncertain or degraded.
    case ConcurrencyReleaseFailed = 'concurrency_release_failed';
    case ConcurrencyReleaseStale = 'concurrency_release_stale';
    case CircuitAbandonFailed = 'circuit_abandon_failed';
    case CircuitAbandonStale = 'circuit_abandon_stale';
    case CircuitRecordFailed = 'circuit_record_failed';
    case CircuitRecordStale = 'circuit_record_stale';
    case RetryReservationReleaseFailed = 'retry_reservation_release_failed';
    case CooldownExtendFailed = 'cooldown_extend_failed';
    case CooldownExtensionCapped = 'cooldown_extension_capped';
    case SettlementAccounting = 'settlement_accounting';
    case CompletionSinkFailed = 'completion_sink_failed';
    case EventSinkFailed = 'event_sink_failed';

    // ---- Classification and fallback: caller code failed inside Reqtide's lifecycle.
    case ClassifierFailed = 'classifier_failed';
    case LateSignalExtractionFailed = 'late_signal_extraction_failed';
    case FallbackFailed = 'fallback_failed';
    case DiscardFailed = 'discard_failed';

    // ---- Classification results: the stable codes `Outcome\ExceptionClassifier` reports. They name a
    //      classification, not a failure of Reqtide, and are the codes a ReplaySafetyContract allowlists.
    /** The operation returned a value and no predicate marked it unacceptable. */
    case ValueAccepted = 'value_accepted';
    /** A value predicate of the classifier marked the returned value as a failure. */
    case ValueUnacceptable = 'value_unacceptable';
    /** The operation threw an exception the classifier was told is retryable. */
    case ExceptionRetryable = 'exception_retryable';
    /** The operation threw an exception that counts as a failure but is not retryable (including unlisted ones). */
    case ExceptionFailure = 'exception_failure';
    /** The operation threw an exception the classifier ignores for circuit-health purposes. */
    case ExceptionIgnored = 'exception_ignored';

    // ---- Preparation and configuration: caller defects.
    case PreparationFailed = 'preparation_failed';
    case LifecycleFailure = 'lifecycle_failure';
    case UnexpectedFailure = 'unexpected_failure';
    case InvalidConfiguration = 'invalid_configuration';
    case CapabilityMismatch = 'capability_mismatch';

    public function category(): Category
    {
        return $this->profile()[0];
    }

    public function severity(): Severity
    {
        return $this->profile()[1];
    }

    public function retryability(): Retryability
    {
        return $this->profile()[2];
    }

    /** The lifecycle stage this code is typically reported at, or null when it is stage-agnostic. */
    public function stage(): ?Stage
    {
        return $this->profile()[3];
    }

    /** Constant English title per code (RFC 9457 `title` rule). Wording is not API. */
    public function title(): string
    {
        return $this->profile()[4];
    }

    /**
     * @return array{Category, Severity, Retryability, Stage|null, string}
     */
    private function profile(): array
    {
        return match ($this) {
            self::CircuitOpen => [Category::Admission, Severity::Info, Retryability::AfterWait, Stage::Admission, 'Circuit is open'],
            self::CircuitProbeCapacityExhausted => [Category::Admission, Severity::Info, Retryability::AfterWait, Stage::Admission, 'Circuit probe capacity exhausted'],
            self::ConcurrencyCapacityExhausted => [Category::Admission, Severity::Info, Retryability::Transient, Stage::Admission, 'Concurrency capacity exhausted'],
            self::CooldownActive => [Category::Admission, Severity::Info, Retryability::AfterWait, Stage::Admission, 'Cooldown is active'],
            self::CooldownExceedsDeadline => [Category::Admission, Severity::Info, Retryability::AfterWait, Stage::Admission, 'Cooldown outlasts the operation deadline'],
            self::RateAccepted => [Category::Admission, Severity::Debug, Retryability::Never, Stage::Admission, 'Rate limit admitted the call'],
            self::RateCostExceedsCapacity => [Category::Admission, Severity::Info, Retryability::Never, Stage::Admission, 'Rate cost exceeds total capacity'],
            self::RateExhausted => [Category::Admission, Severity::Info, Retryability::AfterWait, Stage::Admission, 'Rate limit exhausted'],
            self::RateWaitUnrepresentable => [Category::Admission, Severity::Info, Retryability::Never, Stage::Admission, 'Rate limit wait is not representable'],
            self::AdmissionRejected => [Category::Admission, Severity::Info, Retryability::Transient, Stage::Admission, 'Admission rejected'],
            self::AdmissionDeferred => [Category::Admission, Severity::Info, Retryability::AfterWait, Stage::Admission, 'Admission deferred'],
            self::AdmissionImpossible => [Category::Admission, Severity::Info, Retryability::Never, Stage::Admission, 'Admission impossible'],

            self::CircuitThresholdExceeded => [Category::Policy, Severity::Warning, Retryability::Never, Stage::Finalize, 'Circuit opened: failure threshold exceeded'],
            self::CircuitProbeFailed => [Category::Policy, Severity::Warning, Retryability::Never, Stage::Finalize, 'Circuit reopened: probe failed'],
            self::CircuitProbesSucceeded => [Category::Policy, Severity::Info, Retryability::Never, Stage::Finalize, 'Circuit closed: probes succeeded'],
            self::CircuitOpenElapsed => [Category::Policy, Severity::Info, Retryability::Never, Stage::Admission, 'Circuit half-open: open period elapsed'],

            self::RetryBudgetExhausted => [Category::Retry, Severity::Info, Retryability::Never, Stage::Retry, 'Retry budget exhausted'],
            self::RetryBudgetCommitRefused => [Category::Retry, Severity::Info, Retryability::Never, Stage::Retry, 'Retry budget commit refused'],
            self::AttemptsExhausted => [Category::Retry, Severity::Info, Retryability::Never, Stage::Retry, 'Maximum attempts reached'],
            self::OutcomeNotRetryable => [Category::Retry, Severity::Debug, Retryability::Never, Stage::Retry, 'Outcome is not retryable under the replay contract'],
            self::RetryDelayExceedsDeadline => [Category::Retry, Severity::Info, Retryability::Never, Stage::Retry, 'Retry delay exceeds the deadline'],
            self::AttemptAllowanceUnavailable => [Category::Retry, Severity::Info, Retryability::Never, Stage::Retry, 'Minimum remaining attempt allowance unavailable'],
            self::ReplayContractNotValid => [Category::Retry, Severity::Warning, Retryability::Never, Stage::Preflight, 'Replay contract is not valid'],
            self::ReplayHorizonInadequate => [Category::Retry, Severity::Warning, Retryability::Never, Stage::Preflight, 'Replay horizon is inadequate'],
            self::ReplayHorizonSaturated => [Category::Retry, Severity::Info, Retryability::Never, Stage::Preflight, 'Replay horizon saturated'],
            self::RetryDeadlineReached => [Category::Retry, Severity::Info, Retryability::Never, Stage::Retry, 'Deadline reached before the retry'],
            self::RetryCancelled => [Category::Retry, Severity::Info, Retryability::Never, Stage::Retry, 'Cancelled before the retry'],
            self::RetryAfterExceedsMaximumDelay => [Category::Retry, Severity::Info, Retryability::AfterWait, Stage::Retry, 'Retry-After exceeds the maximum delay'],
            self::RetryAfterExceedsDeadline => [Category::Retry, Severity::Info, Retryability::AfterWait, Stage::Retry, 'Retry-After exceeds the deadline'],
            self::DelayFailed => [Category::Retry, Severity::Warning, Retryability::Never, Stage::Retry, 'Retry delay could not be scheduled'],

            self::OperationCancelled => [Category::Policy, Severity::Notice, Retryability::Never, null, 'Operation cancelled'],
            self::DeadlineExceeded => [Category::Policy, Severity::Warning, Retryability::Never, null, 'Operation deadline exceeded'],

            self::StateUnavailable => [Category::Infrastructure, Severity::Error, Retryability::Transient, null, 'State backend unavailable'],
            self::StateCorrupt => [Category::Infrastructure, Severity::Error, Retryability::Never, null, 'State backend data corrupt'],
            self::StateIndeterminate => [Category::Infrastructure, Severity::Error, Retryability::Unknown, null, 'State backend outcome indeterminate'],
            self::StateCapacityExceeded => [Category::Infrastructure, Severity::Error, Retryability::Transient, null, 'State backend capacity exceeded'],
            self::ClockFailure => [Category::Infrastructure, Severity::Error, Retryability::Transient, null, 'Clock failure'],
            self::UnexpectedBackendFailure => [Category::Infrastructure, Severity::Error, Retryability::Unknown, null, 'Unexpected backend failure'],
            self::RuntimeAdapterFailure => [Category::Infrastructure, Severity::Error, Retryability::Unknown, Stage::Dispatch, 'Runtime adapter failed after invocation began'],
            self::RateConsumeIndeterminate => [Category::Infrastructure, Severity::Error, Retryability::Unknown, Stage::Admission, 'Rate limit debit is indeterminate'],
            self::CommitNotConfirmed => [Category::Infrastructure, Severity::Error, Retryability::Unknown, Stage::RetryBudget, 'Retry budget commit not confirmed'],
            self::ClockUnavailable => [Category::Infrastructure, Severity::Warning, Retryability::Transient, Stage::Settlement, 'Clock unavailable'],
            self::RetryClockUnavailable => [Category::Infrastructure, Severity::Warning, Retryability::Transient, Stage::Retry, 'Clock unavailable at the retry decision'],
            self::FallbackClockUnavailable => [Category::Infrastructure, Severity::Warning, Retryability::Transient, Stage::Fallback, 'Clock unavailable before the fallback'],
            self::TimingUnavailable => [Category::Infrastructure, Severity::Warning, Retryability::Never, Stage::Fallback, 'Timing unavailable'],
            self::FallbackTimingUnavailable => [Category::Infrastructure, Severity::Warning, Retryability::Never, Stage::Fallback, 'Fallback timing unavailable'],
            self::ElapsedUnavailable => [Category::Infrastructure, Severity::Warning, Retryability::Never, Stage::Summary, 'Elapsed time unavailable'],
            self::CancellationSampleFailed => [Category::Infrastructure, Severity::Warning, Retryability::Never, Stage::Settlement, 'Cancellation could not be sampled'],
            self::RandomOutOfRange => [Category::Infrastructure, Severity::Warning, Retryability::Never, Stage::Retry, 'Random source failed or returned an out-of-range value'],

            self::ConcurrencyReleaseFailed => [Category::Accounting, Severity::Warning, Retryability::Never, Stage::Finalize, 'Concurrency permit release failed'],
            self::ConcurrencyReleaseStale => [Category::Accounting, Severity::Warning, Retryability::Never, Stage::Finalize, 'Concurrency permit was already stale'],
            self::CircuitAbandonFailed => [Category::Accounting, Severity::Warning, Retryability::Never, Stage::Finalize, 'Circuit permit abandon failed'],
            self::CircuitAbandonStale => [Category::Accounting, Severity::Warning, Retryability::Never, Stage::Finalize, 'Circuit permit was already stale at abandon'],
            self::CircuitRecordFailed => [Category::Accounting, Severity::Warning, Retryability::Never, Stage::Finalize, 'Circuit verdict record failed'],
            self::CircuitRecordStale => [Category::Accounting, Severity::Warning, Retryability::Never, Stage::Finalize, 'Circuit verdict not applied: permit stale'],
            self::RetryReservationReleaseFailed => [Category::Accounting, Severity::Warning, Retryability::Never, Stage::Cleanup, 'Retry reservation release failed'],
            self::CooldownExtendFailed => [Category::Accounting, Severity::Warning, Retryability::Never, Stage::Retry, 'Cooldown extension failed'],
            self::CooldownExtensionCapped => [Category::Accounting, Severity::Info, Retryability::Never, Stage::Retry, 'Cooldown extension capped at the policy maximum'],
            self::SettlementAccounting => [Category::Accounting, Severity::Warning, Retryability::Never, Stage::Settlement, 'Settlement accounting incomplete'],
            self::CompletionSinkFailed => [Category::Accounting, Severity::Warning, Retryability::Never, Stage::Observation, 'Completion event sink failed'],
            self::EventSinkFailed => [Category::Accounting, Severity::Warning, Retryability::Never, Stage::Observation, 'Event sink failed'],

            self::ClassifierFailed => [Category::Classification, Severity::Error, Retryability::Never, Stage::Classification, 'Outcome classifier failed'],
            self::LateSignalExtractionFailed => [Category::Classification, Severity::Warning, Retryability::Never, Stage::Classification, 'Late signal extraction failed'],
            self::FallbackFailed => [Category::Fallback, Severity::Error, Retryability::Never, Stage::Fallback, 'Fallback failed'],
            self::DiscardFailed => [Category::Fallback, Severity::Warning, Retryability::Never, Stage::Discard, 'Outcome discard failed'],

            self::ValueAccepted => [Category::Classification, Severity::Debug, Retryability::Never, Stage::Classification, 'Value accepted by the classifier'],
            self::ValueUnacceptable => [Category::Classification, Severity::Info, Retryability::Never, Stage::Classification, 'Value marked unacceptable by the classifier'],
            self::ExceptionRetryable => [Category::Classification, Severity::Info, Retryability::Transient, Stage::Classification, 'Exception classified as retryable'],
            self::ExceptionFailure => [Category::Classification, Severity::Info, Retryability::Never, Stage::Classification, 'Exception classified as a non-retryable failure'],
            self::ExceptionIgnored => [Category::Classification, Severity::Debug, Retryability::Never, Stage::Classification, 'Exception ignored for circuit health'],

            self::PreparationFailed => [Category::Preparation, Severity::Error, Retryability::Never, Stage::Preparation, 'Attempt preparation failed'],
            self::LifecycleFailure => [Category::Preparation, Severity::Error, Retryability::Never, null, 'Lifecycle failure'],
            self::UnexpectedFailure => [Category::Preparation, Severity::Error, Retryability::Never, null, 'Unexpected failure'],
            self::InvalidConfiguration => [Category::Configuration, Severity::Error, Retryability::Never, null, 'Invalid configuration'],
            self::CapabilityMismatch => [Category::Configuration, Severity::Error, Retryability::Never, null, 'Capability mismatch'],
        };
    }
}
