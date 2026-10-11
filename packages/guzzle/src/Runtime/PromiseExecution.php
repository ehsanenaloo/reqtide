<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Runtime;

use Enaxon\Reqtide\Completion\Diagnostic;
use Enaxon\Reqtide\Completion\SettlementOverride;
use Enaxon\Reqtide\Error\ReasonCode;
use Enaxon\Reqtide\Error\Stage;
use Enaxon\Reqtide\Exception\ClockFailureException;
use Enaxon\Reqtide\Execution\BoundedMetadata;
use Enaxon\Reqtide\Outcome\Outcome;
use Enaxon\Reqtide\Runtime\AttemptControls;
use Enaxon\Reqtide\Runtime\RuntimeExecution;
use Enaxon\Reqtide\Runtime\SettlementObservation;
use Enaxon\Reqtide\Value\Duration;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * One transfer in flight on the adapter-owned queue. {@see awaitObservedSettlement()} drives the queue until the promise
 * has actually fulfilled or rejected; there is no event loop, so nothing else makes it progress.
 *
 * Between two drive steps (each blocks for at most the multi handler's small select timeout) it samples the deadline
 * and then cancellation, deadline first as everywhere in Core. The first sample that finds either one asks the
 * transport to cancel, exactly once. That is a request and not a claim: the promise then rejects, the rejection is the
 * outcome, and the logical override (`DeadlineExceeded` or `Cancelled`) is decided from the observation time, not from
 * the cancel request. A response that arrives after the cancel request cannot change anything, because a Guzzle promise
 * settles once. The observation is computed once and returned again on every later await.
 *
 * @implements RuntimeExecution<ResponseInterface>
 */
final class PromiseExecution implements RuntimeExecution
{
    /** @var SettlementObservation<ResponseInterface>|null */
    private ?SettlementObservation $observation = null;

    private bool $cancelRequested = false;

    /** @var list<Diagnostic> */
    private array $diagnostics = [];

    private bool $complete = true;

    /**
     * @param \Closure(): void $drive one step of progress of the owned queue
     * @param ?int $startMonotonic monotonic time at dispatch, or null when it could not be read
     */
    public function __construct(
        private readonly PromiseInterface $promise,
        private readonly \Closure $drive,
        private readonly ?int $startMonotonic,
    ) {}

    public function awaitObservedSettlement(AttemptControls $controls): SettlementObservation
    {
        if ($this->observation !== null) {
            return $this->observation;
        }

        $this->settle($controls);

        return $this->observation = $this->observe($controls);
    }

    private function settle(AttemptControls $controls): void
    {
        while ($this->promise->getState() === PromiseInterface::PENDING) {
            if (!$this->cancelRequested && $this->mustCancel($controls)) {
                $this->requestCancel();
                continue;
            }
            try {
                ($this->drive)();
            } catch (\Throwable $failure) {
                // The queue could not make progress: fail this attempt instead of spinning, and say the accounting is not whole.
                $this->diagnostics[] = Diagnostic::of(Stage::Settlement, ReasonCode::RuntimeAdapterFailure);
                $this->complete = false;
                $this->requestCancel();
                if ($this->promise->getState() === PromiseInterface::PENDING) {
                    return;
                }
            }
        }
    }

    /** True when the deadline has been reached or cancellation was requested (deadline sampled first). */
    private function mustCancel(AttemptControls $controls): bool
    {
        try {
            $now = $controls->clock->monotonicNow();
            if ($now >= 0 && $now >= $controls->deadline->monotonicNanoseconds) {
                return true;
            }
        } catch (ClockFailureException) {
            // An unreadable clock is reported when the settlement is observed; keep driving.
        }
        try {
            return $controls->cancellation->isRequested();
        } catch (\Throwable) {
            if (!$this->hasDiagnostic(ReasonCode::CancellationSampleFailed)) {
                $this->diagnostics[] = Diagnostic::of(Stage::Settlement, ReasonCode::CancellationSampleFailed);
                $this->complete = false;
            }

            return false;
        }
    }

    private function requestCancel(): void
    {
        $this->cancelRequested = true;
        try {
            $this->promise->cancel();
        } catch (\Throwable) {
            // A promise that cannot be cancelled keeps running; the caller of settle() returns when it cannot progress.
        }
    }

    private function hasDiagnostic(ReasonCode $reason): bool
    {
        foreach ($this->diagnostics as $diagnostic) {
            if ($diagnostic->reason() === $reason) {
                return true;
            }
        }

        return false;
    }

    /** @return SettlementObservation<ResponseInterface> */
    private function observe(AttemptControls $controls): SettlementObservation
    {
        $outcome = $this->outcome();
        try {
            $now = $controls->clock->monotonicNow();
            if ($now < 0) {
                throw new ClockFailureException('The monotonic clock returned a negative reading.');
            }
        } catch (ClockFailureException) {
            $now = null;
        }

        $cancelled = false;
        try {
            $cancelled = $controls->cancellation->isRequested();
        } catch (\Throwable) {
            if (!$this->hasDiagnostic(ReasonCode::CancellationSampleFailed)) {
                $this->diagnostics[] = Diagnostic::of(Stage::Settlement, ReasonCode::CancellationSampleFailed);
            }
            $this->complete = false;
        }

        if ($now === null) {
            // Never declare an unobserved settlement on time: keep any established cancellation, add nothing else.
            $this->diagnostics[] = Diagnostic::of(Stage::Settlement, ReasonCode::ClockUnavailable);

            return new SettlementObservation(
                $outcome,
                $cancelled ? SettlementOverride::Cancelled : SettlementOverride::None,
                null,
                null,
                false,
                $this->diagnostics,
            );
        }

        // Deadline equality is expiry and is sampled before cancellation.
        $override = match (true) {
            $now >= $controls->deadline->monotonicNanoseconds => SettlementOverride::DeadlineExceeded,
            $cancelled => SettlementOverride::Cancelled,
            default => SettlementOverride::None,
        };

        $elapsed = null;
        if ($this->startMonotonic !== null && $now >= $this->startMonotonic) {
            $elapsed = new Duration($now - $this->startMonotonic);
        } else {
            $this->diagnostics[] = Diagnostic::of(Stage::Settlement, ReasonCode::ElapsedUnavailable);
            $this->complete = false;
        }

        return new SettlementObservation($outcome, $override, $now, $elapsed, $this->complete && $elapsed !== null, $this->diagnostics);
    }

    /** @return Outcome<ResponseInterface> */
    private function outcome(): Outcome
    {
        if ($this->promise->getState() === PromiseInterface::PENDING) {
            return Outcome::fromException(new \RuntimeException('The transfer could not be driven to a settlement.'), BoundedMetadata::empty());
        }
        try {
            $value = $this->promise->wait();
        } catch (\Throwable $exception) {
            return Outcome::fromException($exception, BoundedMetadata::empty());
        }

        /** @var ResponseInterface $value */
        return Outcome::fromValue($value, BoundedMetadata::empty());
    }
}
