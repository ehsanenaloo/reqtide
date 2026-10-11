<?php

declare(strict_types=1);

/**
 * 01 - Quick start: rate limit + retry + circuit breaker + deadline around a flaky callable, with the builder.
 *
 * Reading order of the pieces below:
 *   PipelineBuilder      what to protect with (retry, rate limit, circuit breaker) - every number is yours
 *   ExceptionClassifier  which exceptions may be retried (the default: none)
 *   newContext()         one context per operation: deadline, id, and your replay-safety statement
 *
 * Honest scope:
 *  - Everything here is process-local, in-memory state. Nothing is shared with other processes. Under PHP-FPM
 *    each request builds its own backends, so each request starts with fresh quota and a closed circuit;
 *    to share quota across requests you would need a shared backend, which does not exist yet.
 *  - The clock is real and the retry delays really sleep (a few ms).
 *  - Reqtide does not interrupt a running callback. The deadline is checked between steps, not inside your code.
 *  - Retrying means running your callable again. Only do that for a callable that is safe to repeat; the
 *    `forReadOnlyCallable()` call below is YOUR statement that it is. Reqtide cannot check it.
 *
 * The same pipeline written out with the low-level constructors is in 03-explain-and-detailed.php.
 *
 * Run:  php examples/01-quick-start.php
 */

namespace Reqtide\Examples;

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

require __DIR__ . '/support.php';

// 1. Describe the protection. Units are in the types: Duration::seconds(2), token counts are plain integers.
$pipeline = PipelineBuilder::profile('quick-start', 'v1')
    ->partition('example', 'local', 'payments-api')
    // One attempt may take at most 2 s. The synchronous runtime cannot enforce this inside your callable; the
    // requirement says so honestly (Cooperative).
    ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::Cooperative)
    // Up to 3 attempts (the first call counts), 5 ms constant backoff, no jitter.
    ->retry(3, BackoffMode::Constant, Duration::milliseconds(5), Duration::milliseconds(5), JitterMode::None)
    // Retries draw from a budget so a failing dependency is not hammered: 10 tokens, 10 per minute.
    ->retryBudget(10, 10, Duration::seconds(60), Duration::seconds(10))
    // Token bucket: 5 tokens, refilling 5 per second. Every dispatch (the first attempt and each retry) costs 1 token.
    ->rateLimit(PipelineBuilder::tokenBucket(5, 5, Duration::seconds(1)))
    // Circuit: judged over the last 10 completed calls, needs 4 of them, opens at >= 1/2 failures, stays open
    // 30 s, then lets 1 probe through. The permit lease must exceed the attempt timeout plus 1 s.
    ->circuitBreaker(PipelineBuilder::lastCalls(10), 4, Ratio::of(1, 2), Duration::seconds(30), 1, 1, Duration::seconds(10))
    // 2. Say which exceptions are transient. Everything else is a plain failure and is never retried.
    ->compileInMemory(ExceptionClassifier::create()->retryOn(TransientError::class));

// 3. The flaky callable: fails twice with a transient error, then succeeds.
$calls = 0;
$flaky = static function (AttemptContext $attempt) use (&$calls): string {
    ++$calls;
    if ($calls < 3) {
        throw new TransientError("upstream hiccup #{$calls}");
    }

    return "payload after attempt {$attempt->dispatchedAttemptNumber}";
};

// 4. Every operation brings its own context. The deadline is generous on purpose: the first call loads classes,
//    which can be slow on a bind-mounted filesystem. The safety statement is what allows the retries.
$safety = static fn(): OperationSafety => OperationSafety::replayableRead(
    ReplaySafetyContract::forReadOnlyCallable('quick-start-read', Duration::seconds(3600)),
);

// execute() returns exactly what your callable returned, and static analysis knows its type: string here.
$result = $pipeline->execute($flaky, $pipeline->newContext(Duration::seconds(30), $safety(), operationId: 'quick-start-1'));

say("result: {$result}");
say("callable invocations: {$calls}");
check($result === 'payload after attempt 3', 'the third attempt returns the payload');
check($calls === 3, 'two transient failures and one success make three invocations');

// 5. Reuse the same pipeline for a second operation: the rate limiter now remembers the first one.
$second = $pipeline->execute(
    static fn(AttemptContext $attempt): string => 'second call, first try',
    $pipeline->newContext(Duration::seconds(30), $safety(), operationId: 'quick-start-2'),
);
say("second result: {$second}");
check($second === 'second call, first try', 'second operation succeeds');

say('ok');
