<?php

declare(strict_types=1);

/**
 * 02 - Failure demo: the circuit opens, and later calls are refused with a typed exception and Problem JSON.
 *
 * What to take from this:
 *  - A refusal is not your callable failing. The callable is NOT invoked when the circuit is open or the rate
 *    limit is exhausted; you get a typed ReqtideException instead.
 *  - Your own exceptions (here PermanentError) pass through unchanged while the circuit is still closed.
 *  - Every Reqtide exception that describes a policy decision implements ProblemCarrier, so it can be turned
 *    into a stable, machine-readable Problem (a JSON document with a tag-URI `type` and a `code`). When it is
 *    thrown by execute() the problem also names the operation (operationId, profileVersion, correlationId), so
 *    the JSON can go straight into a log line or an error response.
 *
 * Process-local, in-memory state only. A circuit opened here is invisible to any other PHP process.
 *
 * Run:  php examples/02-failure-demo.php
 */

namespace Reqtide\Examples;

use Enaxon\Reqtide\Completion\CompletionDisposition;
use Enaxon\Reqtide\Error\ProblemCarrier;
use Enaxon\Reqtide\Exception\AdmissionDeferredException;
use Enaxon\Reqtide\Exception\AdmissionRejectedException;
use Enaxon\Reqtide\Exception\ReqtideException;
use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Execution\ExecutionContext;
use Enaxon\Reqtide\Outcome\ExceptionClassifier;
use Enaxon\Reqtide\Pipeline\CompiledPipeline;
use Enaxon\Reqtide\Pipeline\PipelineBuilder;
use Enaxon\Reqtide\Policy\AttemptTimeoutRequirement;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\Ratio;

require __DIR__ . '/support.php';

/** A fresh pipeline per scenario. No retry is configured, so no replay-safety statement is needed. */
$base = static fn(): PipelineBuilder => PipelineBuilder::profile('failure-demo', 'v1')
    ->partition('example', 'local', 'payments-api')
    ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::Cooperative);

$operation = 0;
/** A fresh context per logical operation: stable id for the demo, 30 s deadline (generous: cold class loading can be slow). */
$context = static function (CompiledPipeline $pipeline) use (&$operation): ExecutionContext {
    return $pipeline->newContext(Duration::seconds(30), operationId: 'failure-demo-' . ++$operation);
};

// ---------------------------------------------------------------- Part 1: the circuit opens

// Judge the last 4 completed calls, but only once 4 samples exist; open when at least half of them failed, stay
// open for 30 s. The permit lease (10 s) must exceed the attempt budget plus 1 s. The default classifier counts
// every exception as a circuit failure and retries none.
$pipeline = $base()
    ->circuitBreaker(PipelineBuilder::lastCalls(4), 4, Ratio::of(1, 2), Duration::seconds(30), 1, 1, Duration::seconds(10))
    ->compileInMemory(ExceptionClassifier::create());

$invocations = 0;
$broken = static function (AttemptContext $attempt) use (&$invocations): string {
    ++$invocations;

    return upstreamIsDown();
};

say('-- circuit breaker');
for ($i = 1; $i <= 4; ++$i) {
    try {
        $pipeline->execute($broken, $context($pipeline));
        check(false, 'a broken callable must not succeed');
    } catch (PermanentError $e) {
        // Closed circuit: your exception comes through untouched.
        say("call {$i}: PermanentError ({$e->getMessage()})");
    }
}
check($invocations === 4, 'the callable ran for each of the four closed-circuit calls');

try {
    $pipeline->execute($broken, $context($pipeline));
    check(false, 'the fifth call must be refused');
} catch (ReqtideException $e) {
    say('call 5: ' . $e::class);
    check($e instanceof AdmissionRejectedException, 'an open circuit rejects');
    // ReqtideException is a marker; the Problem lives on ProblemCarrier, which every policy exception implements.
    if (!$e instanceof ProblemCarrier) {
        check(false, 'policy exceptions carry a Problem');
        throw $e;
    }
    say(json_encode($e->problem(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
}
check($invocations === 4, 'the open circuit refused the call without invoking the callable');
say("callable invocations: {$invocations}");

// Detailed mode returns the refusal as data instead of throwing; its Problem names the operation the same way.
$completion = $pipeline->executeDetailed($broken, $context($pipeline));
check($completion->disposition === CompletionDisposition::Rejected, 'detailed mode reports Rejected');
$problem = $completion->problem();
check($problem !== null, 'a rejected completion has a Problem');
say('detailed disposition: ' . $completion->disposition->value);
say(json_encode($problem, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
check($invocations === 4, 'still no invocation');

// ---------------------------------------------------------------- Part 2: the rate limit refuses

say();
say('-- rate limit');
// 2 tokens, refilling 1 token per hour: effectively "2 calls" for the lifetime of this script.
$limited = $base()
    ->rateLimit(PipelineBuilder::tokenBucket(2, 1, Duration::seconds(3600)))
    ->compileInMemory(ExceptionClassifier::create());
$served = 0;
$ok = static function (AttemptContext $attempt) use (&$served): string {
    ++$served;

    return 'served';
};
check($limited->execute($ok, $context($limited)) === 'served', 'first call is admitted');
check($limited->execute($ok, $context($limited)) === 'served', 'second call is admitted');
say("served: {$served}");
try {
    $limited->execute($ok, $context($limited));
    check(false, 'the third call must be refused');
} catch (ReqtideException $e) {
    // Depending on the algorithm and cost a refusal is "Rejected", "Deferred" (with a wait) or "Impossible".
    check($e instanceof AdmissionDeferredException || $e instanceof AdmissionRejectedException, 'rate refusal is Deferred or Rejected');
    if (!$e instanceof ProblemCarrier) {
        check(false, 'policy exceptions carry a Problem');
        throw $e;
    }
    say('third call: ' . $e::class);
    // Print the stable fields only; a wait duration would differ from run to run.
    $problem = $e->problem();
    say('problem.code: ' . $problem->code->value);
    say('problem.retryability: ' . $problem->retryability()->value);
}
check($served === 2, 'the refused call never reached the callable');

say('ok');
