<?php

declare(strict_types=1);

/**
 * Shared plumbing for the examples. Not part of the Reqtide API.
 *
 * It does four things: load the Composer autoloader, provide a tiny `check()` assertion, define the two exception
 * types the examples throw, and keep the low-level helpers (partition and policy identities, a hand-written
 * OutcomeClassifier, a hand-built replay-safety contract) that only example 03 uses. Examples 01, 02, 04 and 05
 * use the builder, `ExceptionClassifier` and `ReplaySafetyContract::forReadOnlyCallable()` instead; 03 shows the
 * same machinery written out with the low-level constructors so you can see what the builder assembles.
 */

namespace Reqtide\Examples;

use Enaxon\Reqtide\Execution\OperationMetadata;
use Enaxon\Reqtide\Execution\ReplayPreparation;
use Enaxon\Reqtide\Execution\ReplaySafetyContract;
use Enaxon\Reqtide\Outcome\Acceptability;
use Enaxon\Reqtide\Outcome\CircuitVerdict;
use Enaxon\Reqtide\Outcome\FallbackEligibility;
use Enaxon\Reqtide\Outcome\Outcome;
use Enaxon\Reqtide\Outcome\OutcomeClassification;
use Enaxon\Reqtide\Outcome\OutcomeClassifier;
use Enaxon\Reqtide\Outcome\OutcomeKind;
use Enaxon\Reqtide\Outcome\RetryEligibility;
use Enaxon\Reqtide\Execution\OperationSafety;
use Enaxon\Reqtide\Value\ApplicationId;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\EnvironmentId;
use Enaxon\Reqtide\Value\PartitionIdentity;
use Enaxon\Reqtide\Value\PolicyId;
use Enaxon\Reqtide\Value\PolicyIdentity;
use Enaxon\Reqtide\Value\ProfileVersion;
use Enaxon\Reqtide\Value\SubjectId;

require __DIR__ . '/autoload.php';

/** A failure the example classifier treats as transient: eligible for retry. */
final class TransientError extends \RuntimeException {}

/** A failure the example classifier treats as permanent: never retried, still counted against the circuit. */
final class PermanentError extends \RuntimeException {}

/**
 * The hand-written classifier of example 03 (the other examples use `ExceptionClassifier`, which covers this case
 * with lists of exception classes):
 * a value is a success; TransientError is retryable (reason code `transient`); any other exception is not
 * retryable (reason code `permanent`). Both kinds of exception count as circuit failures and may be replaced
 * by a fallback.
 */
final class ExampleClassifier implements OutcomeClassifier
{
    public function classify(Outcome $outcome, OperationMetadata $metadata): OutcomeClassification
    {
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
        $transient = $outcome->exception() instanceof TransientError;

        return new OutcomeClassification(
            $transient ? RetryEligibility::Eligible : RetryEligibility::Ineligible,
            CircuitVerdict::Failure,
            FallbackEligibility::Eligible,
            Acceptability::Unacceptable,
            $transient ? 'transient' : 'permanent',
            null,
        );
    }
}

/**
 * Always throws PermanentError. It is declared to return a string so that static analysis sees an operation that
 * normally returns one (a closure whose body only throws would be typed `never`, and everything after its call
 * would be flagged as unreachable).
 */
function upstreamIsDown(): string
{
    throw new PermanentError('upstream is down');
}

/** Fails the example loudly (exit code 1) when an expectation does not hold. */
function check(bool $condition, string $what): void
{
    if (!$condition) {
        fwrite(STDERR, "CHECK FAILED: {$what}\n");
        exit(1);
    }
}

/** One deterministic output line. */
function say(string $line = ''): void
{
    echo $line, "\n";
}

/** All policies of one definition share a single partition: here one application, one environment, one subject. */
function partition(string $subject = 'payments-api'): PartitionIdentity
{
    return new PartitionIdentity(new ApplicationId('example'), new EnvironmentId('local'), null, new SubjectId($subject));
}

function policyIdentity(string $policy, string $subject = 'payments-api', string $profileVersion = 'v1'): PolicyIdentity
{
    return new PolicyIdentity(partition($subject), new ProfileVersion($profileVersion), new PolicyId($policy));
}

/**
 * Retrying a generic callable needs an explicit statement from you that repeating it is safe. Reqtide
 * cannot infer idempotency. Only outcomes whose classifier reason code is listed here are retried.
 *
 * The contract's validity window is checked against the pipeline's own Clock (wall time), so `$now` must come
 * from that clock: real time for SystemClock, the virtual wall time for a VirtualClock.
 */
function replayableReadAt(\DateTimeImmutable $now, string ...$retryableReasonCodes): OperationSafety
{
    $now = $now->setTimezone(new \DateTimeZone('UTC'));

    return OperationSafety::replayableRead(new ReplaySafetyContract(
        'example-contract',
        'example-identity',
        'example-scope',
        'safe-to-repeat-read',
        $now->modify('-1 hour'),
        $now->modify('+1 hour'),
        Duration::seconds(3600),
        ReplayPreparation::CallableAssertion,
        array_values($retryableReasonCodes),
    ));
}

/** Same, anchored on the real clock (use with SystemClock). */
function replayableRead(string ...$retryableReasonCodes): OperationSafety
{
    return replayableReadAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')), ...$retryableReasonCodes);
}
