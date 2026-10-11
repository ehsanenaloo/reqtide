<?php

declare(strict_types=1);

/**
 * 07 - Error handling: your exceptions, Reqtide's refusals, ReasonCode and Problem, and detailed mode.
 * This is the code shown in docs/guides/error-handling.html.
 *
 * Process-local, in-memory state only.
 *
 * Run:  php examples/07-error-handling.php
 */

namespace Reqtide\Examples\ErrorHandling;

use Enaxon\Reqtide\Error\ProblemCarrier;
use Enaxon\Reqtide\Error\Retryability;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Exception\ReqtideException;
use Enaxon\Reqtide\Execution\OperationSafety;
use Enaxon\Reqtide\Execution\ReplaySafetyContract;
use Enaxon\Reqtide\Outcome\ExceptionClassifier;
use Enaxon\Reqtide\Pipeline\PipelineBuilder;
use Enaxon\Reqtide\Policy\AttemptTimeoutRequirement;
use Enaxon\Reqtide\Policy\BackoffMode;
use Enaxon\Reqtide\Policy\JitterMode;
use Enaxon\Reqtide\Value\Duration;

require __DIR__ . '/autoload.php';

/** Stands in for your own transport failure. */
final class UpstreamTimeout extends \RuntimeException {}

$base = static fn(): PipelineBuilder => PipelineBuilder::profile('orders', 'v1')
    ->partition('shop', 'prod', 'orders-api')
    ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::Cooperative);

// A pipeline that admits exactly one call per hour, so the second call is refused.
$pipeline = $base()
    ->rateLimit(PipelineBuilder::tokenBucket(1, 1, Duration::seconds(3600)))
    ->compileInMemory(ExceptionClassifier::create());

$fetchOrder = static fn(): string => 'order 42';

echo $pipeline->execute($fetchOrder, $pipeline->newContext(Duration::seconds(30), operationId: 'orders-1')), "\n";

// 1. A refusal is a typed exception. Every policy exception implements ProblemCarrier.
try {
    $pipeline->execute($fetchOrder, $pipeline->newContext(Duration::seconds(30), operationId: 'orders-2'));
} catch (ReqtideException $e) {
    if (!$e instanceof ProblemCarrier) {
        throw $e;
    }
    echo get_class($e), "\n";
    echo 'reason: ', $e->reason()->value, "\n";
    echo json_encode($e->problem(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";

    // 2. Decide what to do from the machine-readable fields, not from the message.
    echo match ($e->problem()->retryability()) {
        Retryability::AfterWait => "action: ask the caller to try again later\n",
        Retryability::Transient => "action: retry now\n",
        Retryability::Never => "action: fix the configuration or the code\n",
        Retryability::Unknown => "action: reconcile before repeating\n",
    };
}

// 3. Your own exceptions are never wrapped. They come out exactly as your callable threw them.
$failing = $base()->compileInMemory(ExceptionClassifier::create());
try {
    $failing->execute(static fn(): string => throw new UpstreamTimeout('upstream timed out'), $failing->newContext(Duration::seconds(30)));
} catch (UpstreamTimeout $e) {
    echo 'business exception: ', $e::class, ' (', $e->getMessage(), ")\n";
}

// 4. Detailed mode returns the same facts as data instead of throwing.
$completion = $pipeline->executeDetailed($fetchOrder, $pipeline->newContext(Duration::seconds(30), operationId: 'orders-3'));
echo 'disposition: ', $completion->disposition->value, "\n";
echo 'dispatched: ', $completion->summary->dispatched, "\n";
echo 'problem code: ', $completion->problem()?->code->value, "\n";

// 5. When the retry policy gives up, the last business failure is kept, and the reason is recorded.
$retrying = $base()
    ->retry(2, BackoffMode::Constant, Duration::milliseconds(5), Duration::milliseconds(5), JitterMode::None)
    ->retryBudget(10, 10, Duration::seconds(60), Duration::seconds(10))
    ->compileInMemory(ExceptionClassifier::create()->retryOn(UpstreamTimeout::class));
$safety = OperationSafety::replayableRead(ReplaySafetyContract::forReadOnlyCallable('get-order', Duration::seconds(3600)));
$completion = $retrying->executeDetailed(
    static fn(): string => throw new UpstreamTimeout('upstream timed out'),
    $retrying->newContext(Duration::seconds(30), $safety, operationId: 'orders-4'),
);
echo 'disposition: ', $completion->disposition->value, "\n";
echo 'dispatched: ', $completion->summary->dispatched, "\n";
echo 'problem code: ', $completion->problem()?->code->value, "\n";
echo 'last business failure: ', $completion->terminalOutcome?->exception()::class, "\n";

// 6. A configuration mistake is an InvalidConfigurationException that names the option. It is raised before
//    anything is dispatched: while building the pipeline, or when a call lacks what a retry requires.
try {
    $base()->retry(3, BackoffMode::Constant, Duration::milliseconds(5), Duration::milliseconds(5), JitterMode::None)
        ->compileInMemory(ExceptionClassifier::create());
} catch (InvalidConfigurationException $e) {
    echo $e->getMessage(), "\n";
}
try {
    $retrying->execute(static fn(): string => 'order 42', $retrying->newContext(Duration::seconds(30)));
} catch (InvalidConfigurationException $e) {
    echo $e->getMessage(), "\n";
}
