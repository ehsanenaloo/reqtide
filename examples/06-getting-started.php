<?php

declare(strict_types=1);

/**
 * 06 - Getting started: the smallest useful pipeline, self-contained. This is the code shown in the README and in
 * docs/guides/getting-started.html; example 01 is the same idea with longer comments and self-checks.
 *
 * Process-local, in-memory state only. The retry delays really sleep (a few milliseconds).
 *
 * Run:  php examples/06-getting-started.php
 */

namespace Reqtide\Examples\GettingStarted;

use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Execution\OperationSafety;
use Enaxon\Reqtide\Execution\ReplaySafetyContract;
use Enaxon\Reqtide\Outcome\ExceptionClassifier;
use Enaxon\Reqtide\Pipeline\PipelineBuilder;
use Enaxon\Reqtide\Policy\AttemptTimeoutRequirement;
use Enaxon\Reqtide\Policy\BackoffMode;
use Enaxon\Reqtide\Policy\JitterMode;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\Ratio;

require __DIR__ . '/autoload.php';

/** The failure we treat as transient. Anything else is a plain failure and is never retried. */
final class UpstreamTimeout extends \RuntimeException {}

// Build the pipeline once and reuse it. Every number is yours: Reqtide has no defaults.
$pipeline = PipelineBuilder::profile('payments', 'v1')
    ->partition('shop', 'prod', 'payments-api')
    // One attempt may take at most 2 s (checked by you, not enforced inside your code: "Cooperative").
    ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::Cooperative)
    // At most 3 attempts in total (the first call counts), exponential backoff from 10 ms up to 100 ms.
    ->retry(3, BackoffMode::Exponential, Duration::milliseconds(10), Duration::milliseconds(100), JitterMode::Full)
    // Retries draw from a budget so a failing dependency is not hammered: 10 tokens, 10 more per minute.
    ->retryBudget(10, 10, Duration::seconds(60), Duration::seconds(10))
    // Token bucket: 5 tokens, refilled 5 per second. Every dispatch, retries included, costs 1 token.
    ->rateLimit(PipelineBuilder::tokenBucket(5, 5, Duration::seconds(1)))
    // Judge the last 10 completed calls; from 4 samples on, open at >= 1/2 failures, for 30 s, then allow 1 probe.
    ->circuitBreaker(PipelineBuilder::lastCalls(10), 4, Ratio::of(1, 2), Duration::seconds(30), 1, 1, Duration::seconds(10))
    // Only UpstreamTimeout may be retried.
    ->compileInMemory(ExceptionClassifier::create()->retryOn(UpstreamTimeout::class));

// The protected call. Here it fails twice with a timeout and then succeeds.
$calls = 0;
$callApi = static function (AttemptContext $attempt) use (&$calls): string {
    ++$calls;
    if ($calls < 3) {
        throw new UpstreamTimeout('upstream timed out');
    }

    return "ok on attempt {$attempt->dispatchedAttemptNumber}";
};

// One context per operation. Its deadline covers all attempts and waits together. forReadOnlyCallable() is YOUR
// statement that running this callable again is safe; Reqtide cannot check it, and refuses to retry without it.
$context = $pipeline->newContext(
    Duration::seconds(30),
    OperationSafety::replayableRead(ReplaySafetyContract::forReadOnlyCallable('get-payment', Duration::seconds(3600))),
);

echo $pipeline->execute($callApi, $context), "\n";
echo "invocations: {$calls}\n";
