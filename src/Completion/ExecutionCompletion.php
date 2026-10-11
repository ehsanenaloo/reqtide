<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Completion;

use Enaxon\Reqtide\Error\Category;
use Enaxon\Reqtide\Error\Problem;
use Enaxon\Reqtide\Error\ProblemCarrier;
use Enaxon\Reqtide\Error\ProblemCause;
use Enaxon\Reqtide\Error\ReasonCode;
use Enaxon\Reqtide\Exception\AdmissionDeferredException;
use Enaxon\Reqtide\Exception\AdmissionRejectedException;
use Enaxon\Reqtide\Exception\ClassificationFailedException;
use Enaxon\Reqtide\Exception\DeadlineExceededException;
use Enaxon\Reqtide\Exception\ImpossibleAdmissionException;
use Enaxon\Reqtide\Exception\InfrastructureException;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Exception\OperationCancelledException;
use Enaxon\Reqtide\Exception\PreparationFailedException;
use Enaxon\Reqtide\Exception\ReqtideException;
use Enaxon\Reqtide\Outcome\Acceptability;
use Enaxon\Reqtide\Outcome\Outcome;
use Enaxon\Reqtide\Outcome\OutcomeKind;
use Enaxon\Reqtide\Value\AttemptId;

/**
 * `terminalOutcome` is the last settled original attempt (never the fallback); it is absent iff no
 * original attempt settled. `fallbackOutcome` is present iff a fallback invocation settled. A fallback
 * disposition has no `policyCause`, except that a fallback which replaced an admission refusal retains that
 * refusal (Rejected, Deferred or Impossible exception) as `policyCause`.
 * Construction validates the disposition/nullability matrix.
 *
 * @template T
 */
final readonly class ExecutionCompletion
{
    /**
     * @param Outcome<T>|null $terminalOutcome
     * @param Outcome<T>|null $fallbackOutcome
     * @param ClassificationFailedException<T>|null $classifierCause
     * @param list<AttemptRecord<T>> $attempts
     * @param list<Diagnostic> $diagnostics
     */
    public function __construct(
        public CompletionDisposition $disposition,
        public ?Outcome $terminalOutcome,
        public ?Outcome $fallbackOutcome,
        public ?ReqtideException $policyCause,
        public ?ClassificationFailedException $classifierCause,
        public array $attempts,
        public array $diagnostics,
        public ExecutionSummary $summary,
    ) {
        if ($policyCause !== null && $classifierCause !== null) {
            throw self::invalid('Policy and classifier causes cannot coexist.');
        }
        if (!array_is_list($attempts) || count($attempts) > 64) {
            throw self::invalid('Attempts must be a list of at most 64 records.');
        }
        if (!array_is_list($diagnostics) || count($diagnostics) > 32) {
            throw self::invalid('Diagnostics must be a list of at most 32 entries.');
        }
        if ($summary->disposition !== $disposition) {
            throw self::invalid('Summary disposition must equal completion disposition.');
        }
        if ($summary->dispatched !== count($attempts)) {
            throw self::invalid('There must be exactly one attempt record per dispatch.');
        }
        self::assertSummaryMatchesAttempts($summary, $attempts);
        if ($fallbackOutcome !== null && $summary->fallbackExecutions < 1) {
            throw self::invalid('A settled fallback outcome requires a counted fallback execution.');
        }
        if ($classifierCause !== null && $terminalOutcome === null) {
            throw self::invalid('A classifier cause preserves the actual terminal outcome.');
        }

        $last = $attempts === [] ? null : $attempts[count($attempts) - 1];
        if ($terminalOutcome !== null && ($last === null || $last->outcome !== $terminalOutcome)) {
            throw self::invalid('The terminal outcome must be the outcome of the last attempt record.');
        }
        if ($terminalOutcome === null && $attempts !== []) {
            // Attempts exist only because some original attempt settled.
            throw self::invalid('A recorded attempt implies a settled terminal outcome.');
        }

        match ($disposition) {
            CompletionDisposition::Succeeded => self::assertSucceeded($terminalOutcome, $fallbackOutcome, $policyCause, $classifierCause, $last),
            CompletionDisposition::Failed => self::assertFailed($terminalOutcome, $fallbackOutcome, $policyCause, $classifierCause, $last),
            CompletionDisposition::Rejected => self::assertPolicy($fallbackOutcome, $classifierCause, $policyCause, true, AdmissionRejectedException::class, ImpossibleAdmissionException::class),
            CompletionDisposition::Deferred => self::assertPolicy($fallbackOutcome, $classifierCause, $policyCause, true, AdmissionDeferredException::class, null),
            CompletionDisposition::Cancelled => self::assertPolicy($fallbackOutcome, $classifierCause, $policyCause, false, OperationCancelledException::class, null),
            CompletionDisposition::DeadlineExceeded => self::assertPolicy($fallbackOutcome, $classifierCause, $policyCause, false, DeadlineExceededException::class, null),
            CompletionDisposition::FallbackSucceeded => self::assertFallback($terminalOutcome, $fallbackOutcome, $policyCause, $classifierCause, OutcomeKind::Value),
            CompletionDisposition::FallbackFailed => self::assertFallback($terminalOutcome, $fallbackOutcome, $policyCause, $classifierCause, OutcomeKind::Exception),
        };
    }

    /**
     * Redaction-safe summary of why this completion was not a plain success, or null when there is nothing for
     * Reqtide to report.
     *
     * - Succeeded and FallbackSucceeded: null (the caller received a value).
     * - Rejected, Deferred, Cancelled, DeadlineExceeded and infrastructure or preparation failures: the policy
     *   cause's problem.
     * - Failed by the classifier: the classifier problem, scoped to the last attempt.
     * - FallbackFailed: the fallback problem, with the fallback exception, the admission refusal the fallback
     *   replaced (when `policyCause` is set, FallbackScope::IncludeAdmissionRefusals) and the original exception
     *   as causes.
     * - FallbackSucceeded replacing an admission refusal: null; the refusal stays available as `policyCause`.
     * - Failed by the business result alone: a retry-stop problem when the retry policy itself ended the retry
     *   (for example attempts_exhausted); otherwise null, because business failures are never described by
     *   Reqtide.
     *
     * Exception messages are never read; causes carry class names and codes only.
     */
    public function problem(): ?Problem
    {
        $problem = match ($this->disposition) {
            CompletionDisposition::Succeeded, CompletionDisposition::FallbackSucceeded => null,
            CompletionDisposition::FallbackFailed => $this->fallbackProblem(),
            CompletionDisposition::Failed => $this->failedProblem(),
            CompletionDisposition::Rejected, CompletionDisposition::Deferred, CompletionDisposition::Cancelled, CompletionDisposition::DeadlineExceeded => $this->causeProblem(),
        };

        return $problem?->withContext($this->summary->operationId, $this->summary->profileVersion, $this->problemAttemptId());
    }

    private function causeProblem(): Problem
    {
        $cause = $this->policyCause;

        return $cause instanceof ProblemCarrier ? $cause->problem() : new Problem(ReasonCode::UnexpectedFailure);
    }

    private function failedProblem(): ?Problem
    {
        if ($this->policyCause !== null) {
            return $this->causeProblem();
        }
        if ($this->classifierCause !== null) {
            return $this->classifierCause->problem();
        }
        foreach ($this->diagnostics as $diagnostic) {
            $reason = $diagnostic->reason();
            if ($reason !== null && $reason->category() === Category::Retry) {
                $terminal = $this->terminalOutcome;

                return new Problem(
                    $reason,
                    $diagnostic->stage(),
                    null,
                    null,
                    null,
                    null,
                    $terminal !== null && $terminal->kind === OutcomeKind::Exception ? [ProblemCause::of($terminal->exception())] : [],
                );
            }
        }

        return null;
    }

    private function fallbackProblem(): Problem
    {
        $causes = [];
        foreach ([$this->fallbackOutcome, $this->terminalOutcome] as $outcome) {
            if ($outcome !== null && $outcome->kind === OutcomeKind::Exception) {
                $causes[] = ProblemCause::of($outcome->exception());
            }
        }
        if ($this->policyCause !== null && count($causes) < ProblemCause::MAX_DEPTH) {
            // The admission refusal the fallback replaced: it sits between the fallback and any earlier attempt.
            array_splice($causes, min(1, count($causes)), 0, [ProblemCause::of($this->policyCause)]);
        }

        return new Problem(ReasonCode::FallbackFailed, null, null, null, null, null, $causes);
    }

    /** The last dispatched attempt, only when the problem is about that attempt. */
    private function problemAttemptId(): ?AttemptId
    {
        $last = $this->attempts === [] ? null : $this->attempts[count($this->attempts) - 1];
        if ($last === null) {
            return null;
        }
        $aboutAttempt = $this->classifierCause !== null;
        foreach ($this->diagnostics as $diagnostic) {
            if ($diagnostic->reason() === ReasonCode::RuntimeAdapterFailure) {
                $aboutAttempt = true;
            }
        }

        return $aboutAttempt ? $last->attemptId : null;
    }

    /**
     * @param Outcome<T>|null $terminal
     * @param Outcome<T>|null $fallback
     * @param ClassificationFailedException<mixed>|null $classifier
     * @param AttemptRecord<T>|null $last
     */
    private static function assertSucceeded(?Outcome $terminal, ?Outcome $fallback, ?ReqtideException $policy, ?ClassificationFailedException $classifier, ?AttemptRecord $last): void
    {
        if ($terminal === null || $terminal->kind !== OutcomeKind::Value) {
            throw self::invalid('Succeeded requires a Value terminal outcome.');
        }
        if ($last === null || $last->classification === null || $last->classification->acceptability !== Acceptability::Acceptable) {
            throw self::invalid('Succeeded requires the terminal attempt to be classified Acceptable.');
        }
        if ($fallback !== null || $policy !== null || $classifier !== null) {
            throw self::invalid('Succeeded has no fallback, policy or classifier cause.');
        }
    }

    /**
     * @param Outcome<T>|null $terminal
     * @param Outcome<T>|null $fallback
     * @param ClassificationFailedException<mixed>|null $classifier
     * @param AttemptRecord<T>|null $last
     */
    private static function assertFailed(?Outcome $terminal, ?Outcome $fallback, ?ReqtideException $policy, ?ClassificationFailedException $classifier, ?AttemptRecord $last): void
    {
        if ($fallback !== null) {
            throw self::invalid('Failed has no fallback outcome.');
        }
        if ($policy !== null && !($policy instanceof InfrastructureException || $policy instanceof PreparationFailedException)) {
            throw self::invalid('Failed admits only an infrastructure or preparation policy cause.');
        }
        $failedTerminal = $terminal !== null && (
            $terminal->kind === OutcomeKind::Exception
            || ($last?->classification !== null && $last->classification->acceptability === Acceptability::Unacceptable)
        );
        if (!$failedTerminal && $classifier === null && $policy === null) {
            throw self::invalid('Failed requires an Exception or Unacceptable terminal outcome, or a classifier or infrastructure cause.');
        }
    }

    /**
     * @param Outcome<mixed>|null $fallback
     * @param ClassificationFailedException<mixed>|null $classifier
     * @param class-string<ReqtideException> $first
     * @param class-string<ReqtideException>|null $second
     */
    private static function assertPolicy(?Outcome $fallback, ?ClassificationFailedException $classifier, ?ReqtideException $policy, bool $noFallback, string $first, ?string $second): void
    {
        if ($classifier !== null) {
            throw self::invalid('This disposition has no classifier cause.');
        }
        if ($noFallback && $fallback !== null) {
            throw self::invalid('This disposition has no fallback outcome.');
        }
        if ($policy === null || !($policy instanceof $first || ($second !== null && $policy instanceof $second))) {
            throw self::invalid('Policy cause does not match the disposition.');
        }
    }

    /**
     * @param Outcome<T>|null $terminal
     * @param Outcome<T>|null $fallback
     * @param ClassificationFailedException<mixed>|null $classifier
     */
    private static function assertFallback(?Outcome $terminal, ?Outcome $fallback, ?ReqtideException $policy, ?ClassificationFailedException $classifier, OutcomeKind $fallbackKind): void
    {
        if ($policy === null && $terminal === null) {
            throw self::invalid('A fallback disposition requires an original terminal outcome.');
        }
        if ($fallback === null || $fallback->kind !== $fallbackKind) {
            throw self::invalid('Fallback outcome kind does not match the disposition.');
        }
        if ($classifier !== null) {
            throw self::invalid('A fallback disposition has no classifier cause.');
        }
        // A fallback that replaced an admission refusal (FallbackScope::IncludeAdmissionRefusals) retains that
        // refusal as its policy cause; the terminal outcome is then absent (first-attempt refusal) or the
        // earlier attempt's. Any other policy cause keeps owning the disposition and admits no fallback result.
        if ($policy !== null && !self::isAdmissionRefusal($policy)) {
            throw self::invalid('A fallback disposition admits only an admission refusal as its policy cause.');
        }
    }

    private static function isAdmissionRefusal(ReqtideException $policy): bool
    {
        return $policy instanceof AdmissionRejectedException
            || $policy instanceof AdmissionDeferredException
            || $policy instanceof ImpossibleAdmissionException;
    }

    /** @param list<AttemptRecord<T>> $attempts */
    private static function assertSummaryMatchesAttempts(ExecutionSummary $summary, array $attempts): void
    {
        $succeeded = $failed = $neutral = $values = $exceptions = 0;
        foreach ($attempts as $attempt) {
            match ($attempt->disposition) {
                ActualAttemptDisposition::Succeeded => ++$succeeded,
                ActualAttemptDisposition::Failed => ++$failed,
                ActualAttemptDisposition::Neutral => ++$neutral,
            };
            if ($attempt->outcome->kind === OutcomeKind::Value) {
                ++$values;
            } else {
                ++$exceptions;
            }
        }
        if (
            $summary->successfulAttempts !== $succeeded || $summary->failedAttempts !== $failed
            || $summary->neutralAttempts !== $neutral || $summary->actualValues !== $values
            || $summary->actualExceptions !== $exceptions
        ) {
            throw self::invalid('Summary counters must match the attempt records.');
        }
    }

    private static function invalid(string $message): InvalidConfigurationException
    {
        return new InvalidConfigurationException('Invalid ExecutionCompletion: ' . $message);
    }
}
