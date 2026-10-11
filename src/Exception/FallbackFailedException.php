<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Exception;

use Enaxon\Reqtide\Error\ProblemCarrier;
use Enaxon\Reqtide\Error\ProblemCause;
use Enaxon\Reqtide\Error\ProblemReporting;
use Enaxon\Reqtide\Error\ReasonCode;
use Enaxon\Reqtide\Outcome\Outcome;
use Enaxon\Reqtide\Outcome\OutcomeKind;

/**
 * Raised by simple execution when the fallback itself throws.
 *
 * `getPrevious()` is the fallback exception; `$originalOutcome` retains the first terminal result.
 *
 * @template T
 */
final class FallbackFailedException extends \RuntimeException implements ProblemCarrier
{
    use ProblemReporting {
        problemCauses as private defaultProblemCauses;
    }

    /** @param Outcome<T> $originalOutcome */
    public function __construct(
        public readonly Outcome $originalOutcome,
        public readonly \Throwable $fallbackCause,
    ) {
        parent::__construct('Fallback failed', 0, $fallbackCause);
    }

    public function reason(): ReasonCode
    {
        return ReasonCode::FallbackFailed;
    }

    /**
     * Both causes are retained: the fallback's exception first, then the original terminal exception.
     *
     * @return list<ProblemCause>
     */
    protected function problemCauses(): array
    {
        $causes = $this->defaultProblemCauses();
        if ($this->originalOutcome->kind === OutcomeKind::Exception && count($causes) < ProblemCause::MAX_DEPTH) {
            $causes[] = ProblemCause::of($this->originalOutcome->exception());
        }

        return $causes;
    }
}
