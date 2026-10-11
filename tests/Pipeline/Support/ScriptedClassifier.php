<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Pipeline\Support;

use Enaxon\Reqtide\Execution\OperationMetadata;
use Enaxon\Reqtide\Outcome\Acceptability;
use Enaxon\Reqtide\Outcome\CircuitVerdict;
use Enaxon\Reqtide\Outcome\FallbackEligibility;
use Enaxon\Reqtide\Outcome\Outcome;
use Enaxon\Reqtide\Outcome\OutcomeClassification;
use Enaxon\Reqtide\Outcome\OutcomeClassifier;
use Enaxon\Reqtide\Outcome\OutcomeKind;
use Enaxon\Reqtide\Outcome\RetryEligibility;
use Enaxon\Reqtide\Value\Duration;

/**
 * Default policy: a value is a successful acceptable result; an exception is a transient dependency failure
 * that is retry- and fallback-eligible. Every field can be overridden per test.
 */
final class ScriptedClassifier implements OutcomeClassifier
{
    public int $calls = 0;

    public ?\Throwable $throw = null;

    /** @var (\Closure(Outcome<mixed>): ?OutcomeClassification)|null */
    public ?\Closure $custom = null;

    /** @var (\Closure(): void)|null runs inside classify() */
    public ?\Closure $hook = null;

    public RetryEligibility $exceptionRetry = RetryEligibility::Eligible;

    public FallbackEligibility $exceptionFallback = FallbackEligibility::Eligible;

    public CircuitVerdict $exceptionVerdict = CircuitVerdict::Failure;

    public ?Duration $retryAfter = null;

    public function classify(Outcome $outcome, OperationMetadata $metadata): OutcomeClassification
    {
        ++$this->calls;
        if ($this->hook !== null) {
            ($this->hook)();
        }
        if ($this->throw !== null) {
            throw $this->throw;
        }
        if ($this->custom !== null) {
            $custom = ($this->custom)($outcome);
            if ($custom !== null) {
                return $custom;
            }
        }
        if ($outcome->kind === OutcomeKind::Value) {
            return new OutcomeClassification(
                RetryEligibility::Ineligible,
                CircuitVerdict::Success,
                FallbackEligibility::Ineligible,
                Acceptability::Acceptable,
                'ok',
                null,
            );
        }

        return new OutcomeClassification(
            $this->exceptionRetry,
            $this->exceptionVerdict,
            $this->exceptionFallback,
            Acceptability::Unacceptable,
            'transient',
            $this->retryAfter,
        );
    }
}
