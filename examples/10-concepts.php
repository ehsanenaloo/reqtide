<?php

declare(strict_types=1);

/**
 * 10 - Concepts: the pipeline you build once, the operation you run, and the partition both belong to.
 * This is the code shown in docs/guides/concepts.html. Numbers are examples for the demonstration, not
 * recommendations: Reqtide has no defaults and no universal safe values.
 *
 * Process-local, in-memory state only.
 *
 * Run:  php examples/10-concepts.php
 */

namespace Reqtide\Examples\Concepts;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
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

final class Hiccup extends \RuntimeException {}

echo "-- 1. A pipeline is built once, and explain() says what it contains\n";
$catalog = PipelineBuilder::profile('concepts', 'v1')
    ->partition('shop', 'prod', 'catalog-api')
    ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::Cooperative)
    ->rateLimit(PipelineBuilder::tokenBucket(5, 5, Duration::seconds(1)))
    ->circuitBreaker(PipelineBuilder::lastCalls(10), 4, Ratio::of(1, 2), Duration::seconds(30), 1, 1, Duration::seconds(10))
    ->compileInMemory(ExceptionClassifier::create());

$explanation = $catalog->explain();
echo "profile: {$explanation->profileId->value} {$explanation->profileVersion->value}\n";
echo 'enabled policies: ', implode(', ', $explanation->enabledPolicies), "\n";
foreach ($explanation->capabilityNotes as $note) {
    echo "  {$note}\n";
}

echo "-- 2. One operation, several attempts\n";
$retrying = PipelineBuilder::profile('concepts', 'v1')
    ->partition('shop', 'prod', 'catalog-api')
    ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::Cooperative)
    ->retry(3, BackoffMode::Constant, Duration::milliseconds(5), Duration::milliseconds(5), JitterMode::None)
    ->retryBudget(10, 10, Duration::seconds(60), Duration::seconds(10))
    ->compileInMemory(ExceptionClassifier::create()->retryOn(Hiccup::class));

$operationIds = [];
$attemptIds = [];
$flaky = static function (AttemptContext $attempt) use (&$operationIds, &$attemptIds): string {
    $operationIds[] = $attempt->operationId->value;
    $attemptIds[] = $attempt->attemptId->lowerHex;
    if ($attempt->dispatchedAttemptNumber < 3) {
        throw new Hiccup('try again');
    }

    return "succeeded on attempt {$attempt->dispatchedAttemptNumber}";
};
$safety = OperationSafety::replayableRead(ReplaySafetyContract::forReadOnlyCallable('list-products', Duration::seconds(3600)));
echo $retrying->execute($flaky, $retrying->newContext(Duration::seconds(30), $safety, operationId: 'concepts-1')), "\n";
echo 'attempts: ', count($attemptIds), ', distinct attempt ids: ', count(array_unique($attemptIds)), "\n";
echo 'distinct operation ids: ', count(array_unique($operationIds)), ' (', $operationIds[0], ")\n";

echo "-- 3. A context belongs to one partition\n";
$orders = PipelineBuilder::profile('concepts', 'v1')
    ->partition('shop', 'prod', 'orders-api')
    ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::Cooperative)
    ->rateLimit(PipelineBuilder::tokenBucket(5, 5, Duration::seconds(1)))
    ->compileInMemory(ExceptionClassifier::create());
try {
    $catalog->execute(static fn(): string => 'never runs', $orders->newContext(Duration::seconds(30)));
} catch (InvalidConfigurationException $e) {
    echo $e->getMessage(), "\n";
}

// Fail loudly on anything unexpected, like the other examples.
if (count($attemptIds) !== 3 || count(array_unique($attemptIds)) !== 3 || count(array_unique($operationIds)) !== 1) {
    fwrite(STDERR, "unexpected attempt or operation ids\n");
    exit(1);
}
