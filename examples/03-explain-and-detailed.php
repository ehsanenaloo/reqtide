<?php

declare(strict_types=1);

/**
 * 03 - explain() and executeDetailed().
 *
 *  - explain() describes a compiled pipeline (profile, enabled policies, runtime capabilities) without any
 *    I/O, state change or call. Use it for startup logs and for asserting "this pipeline is what I think it is".
 *  - execute() returns your value or throws. executeDetailed() does not throw for business or policy outcomes:
 *    it returns an ExecutionCompletion with the disposition, every attempt, diagnostics and a summary.
 *
 * The printed capability notes are the honest part: a synchronous runtime cannot enforce an attempt timeout,
 * cannot stop a running callback and does not run anything detached. The notes say so.
 *
 * This is also the one example written with the explicit low-level API: PipelineDefinition, RuntimeEnvironment,
 * a hand-written OutcomeClassifier and a hand-built ExecutionContext. Everything the builder, InMemoryEnvironment,
 * ExceptionClassifier and newContext() do in the other examples is spelled out here.
 *
 * Process-local, in-memory state only.
 *
 * Run:  php examples/03-explain-and-detailed.php
 */

namespace Reqtide\Examples;

use Enaxon\Reqtide\Completion\CompletionDisposition;
use Enaxon\Reqtide\Completion\ExecutionCompletion;
use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Execution\BoundedMetadata;
use Enaxon\Reqtide\Execution\ExecutionContext;
use Enaxon\Reqtide\Execution\OperationMetadata;
use Enaxon\Reqtide\Pipeline\PipelineDefinition;
use Enaxon\Reqtide\Pipeline\RuntimeEnvironment;
use Enaxon\Reqtide\Policy\AttemptOptions;
use Enaxon\Reqtide\Policy\AttemptTimeoutRequirement;
use Enaxon\Reqtide\Policy\BackoffMode;
use Enaxon\Reqtide\Policy\CircuitPolicy;
use Enaxon\Reqtide\Policy\CountCircuitWindow;
use Enaxon\Reqtide\Policy\JitterMode;
use Enaxon\Reqtide\Policy\RateLimitPolicy;
use Enaxon\Reqtide\Policy\RetryBudgetPolicy;
use Enaxon\Reqtide\Policy\RetryOptions;
use Enaxon\Reqtide\Policy\TokenBucketOptions;
use Enaxon\Reqtide\Runtime\SchedulingCapability;
use Enaxon\Reqtide\Runtime\SynchronousRuntimeAdapter;
use Enaxon\Reqtide\State\Memory\MemoryCircuitBackend;
use Enaxon\Reqtide\State\Memory\MemoryRateLimitBackend;
use Enaxon\Reqtide\State\Memory\MemoryRetryBudgetBackend;
use Enaxon\Reqtide\Telemetry\NullEventSink;
use Enaxon\Reqtide\Time\BoundedBlockingScheduler;
use Enaxon\Reqtide\Time\Deadline;
use Enaxon\Reqtide\Time\NeverCancelled;
use Enaxon\Reqtide\Time\SecureRandomSource;
use Enaxon\Reqtide\Time\SystemClock;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\OperationId;
use Enaxon\Reqtide\Value\PositiveInt;
use Enaxon\Reqtide\Value\ProfileId;
use Enaxon\Reqtide\Value\ProfileVersion;
use Enaxon\Reqtide\Value\Ratio;

require __DIR__ . '/support.php';

$clock = new SystemClock();

$definition = new PipelineDefinition(
    new ProfileId('explain-demo'),
    new ProfileVersion('v1'),
    new RetryOptions(new PositiveInt(3), BackoffMode::Constant, Duration::milliseconds(2), Duration::milliseconds(2), JitterMode::None, Duration::zero()),
    new AttemptOptions(Duration::seconds(2), AttemptTimeoutRequirement::Cooperative),
    new RateLimitPolicy(policyIdentity('rate'), new TokenBucketOptions(new PositiveInt(100), new PositiveInt(100), Duration::seconds(1))),
    new PositiveInt(1),
    new CircuitPolicy(
        policyIdentity('circuit'),
        new CountCircuitWindow(new PositiveInt(10)),
        new PositiveInt(10),
        Ratio::of(1, 2),
        Duration::seconds(30),
        new PositiveInt(1),
        new PositiveInt(1),
        Duration::seconds(10),
    ),
    null,
    new RetryBudgetPolicy(policyIdentity('retry-budget'), new PositiveInt(10), new PositiveInt(10), Duration::seconds(60), Duration::seconds(10)),
    null,
    false,
);

$pipeline = $definition->compile(new RuntimeEnvironment(
    $clock,
    new BoundedBlockingScheduler($clock),
    new SecureRandomSource(),
    new ExampleClassifier(),
    new SynchronousRuntimeAdapter(SchedulingCapability::BoundedBlocking),
    new NullEventSink(),
    new MemoryRateLimitBackend($clock),
    new MemoryCircuitBackend($clock),
    null,
    new MemoryRetryBudgetBackend($clock),
    null,
));

// ---------------------------------------------------------------- explain()

$explanation = $pipeline->explain();
say('-- explain()');
say("profile: {$explanation->profileId->value} {$explanation->profileVersion->value}");
say('enabled policies: ' . implode(', ', $explanation->enabledPolicies));
foreach ($explanation->capabilityNotes as $note) {
    say("  {$note}");
}
check($explanation->enabledPolicies === ['attempt', 'retry(maxAttempts=3)', 'rate_limit', 'circuit', 'retry_budget'], 'explain lists exactly the configured policies');
check(in_array('physical_stop=not_guaranteed', $explanation->capabilityNotes, true), 'a synchronous runtime does not claim to stop callbacks');

// ---------------------------------------------------------------- executeDetailed()

$operation = 0;
$context = static function () use ($clock, &$operation): ExecutionContext {
    return new ExecutionContext(
        new OperationId('detailed-' . ++$operation),
        partition(),
        Deadline::after(Duration::seconds(30), $clock),
        new NeverCancelled(),
        new OperationMetadata(replayableRead('transient'), BoundedMetadata::empty()),
        null,
    );
};

/** Prints the parts of a completion that are stable from run to run (no ids, no timings). */
$describe = static function (ExecutionCompletion $completion): void {
    say('disposition: ' . $completion->disposition->value);
    foreach ($completion->attempts as $attempt) {
        $kind = $attempt->outcome->kind->value;
        $reason = $attempt->classification->reasonCode ?? 'unclassified';
        say("  attempt {$attempt->dispatchedAttemptNumber}: {$kind}, classified '{$reason}', circuit verdict {$attempt->circuitVerdict->value}");
    }
    foreach ($completion->diagnostics as $diagnostic) {
        say("  diagnostic: {$diagnostic->stageCode} / {$diagnostic->reasonCode}");
    }
    $s = $completion->summary;
    say("summary: dispatched={$s->dispatched} retries={$s->retries} succeeded={$s->successfulAttempts} failed={$s->failedAttempts} "
        . "neutral={$s->neutralAttempts} fallbacks={$s->fallbackExecutions} accountingComplete=" . ($s->accountingComplete ? 'yes' : 'no')
        . ' elapsedKnown=' . ($s->elapsed !== null ? 'yes' : 'no'));
};

say();
say('-- succeeds on the third attempt');
$calls = 0;
$completion = $pipeline->executeDetailed(static function (AttemptContext $attempt) use (&$calls): string {
    if (++$calls < 3) {
        throw new TransientError('try again');
    }

    return 'done';
}, $context());
$describe($completion);
check($completion->disposition === CompletionDisposition::Succeeded, 'third attempt succeeds');
check($completion->terminalOutcome !== null && $completion->terminalOutcome->value() === 'done', 'terminal outcome carries the value');
check($completion->summary->dispatched === 3 && $completion->summary->retries === 2, 'three dispatches, two retries');
check($completion->summary->failedAttempts === 2 && $completion->summary->successfulAttempts === 1, 'two failed attempts, one successful');
check($completion->problem() === null, 'success has no Problem');

say();
say('-- every attempt fails');
$calls = 0;
$completion = $pipeline->executeDetailed(static function (AttemptContext $attempt) use (&$calls): string {
    ++$calls;
    throw new TransientError('still failing');
}, $context());
$describe($completion);
check($completion->disposition === CompletionDisposition::Failed, 'exhausted attempts fail');
check($calls === 3, 'maxAttempts=3 means three invocations in total');
check($completion->terminalOutcome !== null && $completion->terminalOutcome->exception() instanceof TransientError, 'the last business exception is preserved');
$problem = $completion->problem();
if ($problem === null || $problem->code->value !== 'attempts_exhausted') {
    check(false, 'a Problem explains why retrying stopped');
    exit(1);
}
say('problem.code: ' . $problem->code->value);

say('ok');
