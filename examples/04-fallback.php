<?php

declare(strict_types=1);

/**
 * 04 - Fallbacks, and FallbackScope.
 *
 * A fallback is a FallbackOperation you attach to ONE operation (ExecutionContext::$fallback). It receives the
 * original Outcome and returns a replacement value.
 *
 *  - FallbackScope::BusinessOutcomesOnly (the default): the fallback may only replace what your callable did,
 *    i.e. an exception or an unacceptable result that the classifier marked fallback-eligible. If the pipeline
 *    refuses the call (open circuit, exhausted rate limit) you still get the typed refusal.
 *  - FallbackScope::IncludeAdmissionRefusals: the fallback may ALSO replace a circuit, concurrency, rate or
 *    cooldown refusal. The refusal is not lost: the detailed completion keeps it as `policyCause`.
 *
 * Choose the second scope deliberately. A fallback that hides refusals also hides that you are being throttled.
 * A fallback that throws is reported as FallbackFailedException (simple mode) or FallbackFailed (detailed mode).
 *
 * Process-local, in-memory state only.
 *
 * Run:  php examples/04-fallback.php
 */

namespace Reqtide\Examples;

use Enaxon\Reqtide\Completion\CompletionDisposition;
use Enaxon\Reqtide\Exception\AdmissionRejectedException;
use Enaxon\Reqtide\Exception\FallbackFailedException;
use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Execution\ExecutionContext;
use Enaxon\Reqtide\Fallback\FallbackOperation;
use Enaxon\Reqtide\Fallback\FallbackScope;
use Enaxon\Reqtide\Outcome\ExceptionClassifier;
use Enaxon\Reqtide\Outcome\Outcome;
use Enaxon\Reqtide\Pipeline\CompiledPipeline;
use Enaxon\Reqtide\Pipeline\PipelineBuilder;
use Enaxon\Reqtide\Policy\AttemptTimeoutRequirement;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\Ratio;

require __DIR__ . '/support.php';

/**
 * A fallback returning a fixed value, recording how often it ran and what it was handed.
 *
 * @implements FallbackOperation<string>
 */
final class StaleCacheFallback implements FallbackOperation
{
    public int $runs = 0;
    public ?string $receivedKind = null;

    public function __construct(private readonly bool $fails = false) {}

    public function invoke(Outcome $original, ExecutionContext $context): string
    {
        ++$this->runs;
        $this->receivedKind = $original->kind->value;
        if ($this->fails) {
            throw new \RuntimeException('the cache is cold too');
        }

        return 'stale-cache-value';
    }
}

/**
 * One fresh pipeline (fresh circuit state) per scenario, so scenarios cannot affect each other. The classifier
 * says any exception may be replaced by a fallback (`fallbackOn(\Throwable::class)`); the default is none.
 */
$build = static fn(FallbackScope $scope): CompiledPipeline => PipelineBuilder::profile('fallback-demo', 'v1')
    ->partition('example', 'local', 'payments-api')
    ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::Cooperative)
    // Judge the last 2 calls, open at >= 1/2 failures, stay open 30 s, one probe.
    ->circuitBreaker(PipelineBuilder::lastCalls(2), 2, Ratio::of(1, 2), Duration::seconds(30), 1, 1, Duration::seconds(10))
    ->fallbackScope($scope)
    ->compileInMemory(ExceptionClassifier::create()->fallbackOn(\Throwable::class));

/**
 * A fresh context per operation, typed to the fallback's result type (string).
 *
 * @param FallbackOperation<string>|null $fallback
 * @return ExecutionContext<string>
 */
function operationContext(CompiledPipeline $pipeline, ?FallbackOperation $fallback): ExecutionContext
{
    static $operation = 0;

    return $pipeline->newContext(Duration::seconds(30), fallback: $fallback, operationId: 'fallback-' . ++$operation);
}

$broken = static fn(AttemptContext $attempt): string => upstreamIsDown();

/** Fails the callable twice so the circuit (2 samples, half failing) is open afterwards. */
$openCircuit = static function (CompiledPipeline $pipeline) use ($broken): void {
    for ($i = 0; $i < 2; ++$i) {
        try {
            $pipeline->execute($broken, operationContext($pipeline, null));
        } catch (PermanentError) {
            // expected: no fallback attached
        }
    }
};

// ---------------------------------------------------------------- A: a business failure is replaced

say('-- A: callable fails, default scope');
$pipeline = $build(FallbackScope::BusinessOutcomesOnly);
$fallback = new StaleCacheFallback();
$value = $pipeline->execute($broken, operationContext($pipeline, $fallback));
say("value: {$value}");
say("fallback runs: {$fallback->runs}, received outcome kind: {$fallback->receivedKind}");
check($value === 'stale-cache-value', 'the fallback value replaces the failure');
check($fallback->runs === 1 && $fallback->receivedKind === 'exception', 'the fallback saw the original exception outcome');

// ---------------------------------------------------------------- B: a refusal is NOT replaced by default

say();
say('-- B: circuit open, default scope (BusinessOutcomesOnly)');
$pipeline = $build(FallbackScope::BusinessOutcomesOnly);
$openCircuit($pipeline);
$fallback = new StaleCacheFallback();
try {
    $pipeline->execute($broken, operationContext($pipeline, $fallback));
    check(false, 'the open circuit must refuse the call');
} catch (AdmissionRejectedException $e) {
    say('refused: ' . $e->problem()->code->value);
}
say("fallback runs: {$fallback->runs}");
check($fallback->runs === 0, 'the default scope does not hide a refusal behind a fallback');

// ---------------------------------------------------------------- C: IncludeAdmissionRefusals

say();
say('-- C: circuit open, FallbackScope::IncludeAdmissionRefusals');
$pipeline = $build(FallbackScope::IncludeAdmissionRefusals);
check(in_array('fallback_scope=include_admission_refusals', $pipeline->explain()->capabilityNotes, true), 'explain() reports a non-default scope');
$openCircuit($pipeline);
$fallback = new StaleCacheFallback();
$value = $pipeline->execute($broken, operationContext($pipeline, $fallback));
say("value: {$value}");
check($value === 'stale-cache-value', 'the fallback replaced the refusal');
check($fallback->runs === 1, 'the fallback ran once');
// The fallback is handed the refusal as its "original" outcome, so it can tell a refusal from a failure.
check($fallback->receivedKind === 'exception', 'the fallback received the refusal as an exception outcome');

$fallback = new StaleCacheFallback();
$completion = $pipeline->executeDetailed($broken, operationContext($pipeline, $fallback));
say('disposition: ' . $completion->disposition->value);
check($completion->disposition === CompletionDisposition::FallbackSucceeded, 'detailed mode reports FallbackSucceeded');
$refusal = $completion->policyCause;
if (!$refusal instanceof AdmissionRejectedException) {
    check(false, 'the refusal is retained as the policy cause');
    exit(1);
}
check($completion->summary->dispatched === 0, 'the callable was never dispatched');
check($completion->summary->fallbackExecutions === 1, 'one fallback execution is counted');
say('retained refusal: ' . $refusal->problem()->code->value);
say('dispatched: ' . $completion->summary->dispatched . ', fallback executions: ' . $completion->summary->fallbackExecutions);

// ---------------------------------------------------------------- D: a failing fallback

say();
say('-- D: the fallback itself fails');
$pipeline = $build(FallbackScope::BusinessOutcomesOnly);
try {
    $pipeline->execute($broken, operationContext($pipeline, new StaleCacheFallback(fails: true)));
    check(false, 'a failing fallback must not succeed');
} catch (FallbackFailedException $e) {
    say('thrown: ' . $e::class);
    say('problem.code: ' . $e->problem()->code->value);
}

say('ok');
