<?php

declare(strict_types=1);

/**
 * 11 - Rate limit charges: every dispatch costs units, retries included, and a charge is never refunded.
 * This is the code shown in https://enaloo.com/apps/reqtide/docs/rate-limiting/. Numbers are examples for the demonstration,
 * not recommendations.
 *
 * Process-local, in-memory state only.
 *
 * Run:  php examples/11-rate-limit-charges.php
 */

namespace Reqtide\Examples\RateLimitCharges;

use Enaxon\Reqtide\Exception\AdmissionDeferredException;
use Enaxon\Reqtide\Exception\AdmissionRejectedException;
use Enaxon\Reqtide\Exception\ImpossibleAdmissionException;
use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Execution\OperationSafety;
use Enaxon\Reqtide\Execution\ReplaySafetyContract;
use Enaxon\Reqtide\Outcome\ExceptionClassifier;
use Enaxon\Reqtide\Pipeline\PipelineBuilder;
use Enaxon\Reqtide\Policy\AttemptTimeoutRequirement;
use Enaxon\Reqtide\Policy\BackoffMode;
use Enaxon\Reqtide\Policy\JitterMode;
use Enaxon\Reqtide\Value\Duration;

require __DIR__ . '/autoload.php';

final class Hiccup extends \RuntimeException {}

echo "-- 1. A retry is charged like a first attempt\n";
// A bucket of 3 units that does not refill within this script. The call may make up to 3 attempts.
$pipeline = PipelineBuilder::profile('rate-charges', 'v1')
    ->partition('shop', 'prod', 'catalog-api')
    ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::Cooperative)
    ->retry(3, BackoffMode::Constant, Duration::milliseconds(5), Duration::milliseconds(5), JitterMode::None)
    ->retryBudget(10, 10, Duration::seconds(60), Duration::seconds(10))
    ->rateLimit(PipelineBuilder::tokenBucket(3, 1, Duration::seconds(3600)))
    ->compileInMemory(ExceptionClassifier::create()->retryOn(Hiccup::class));
$safety = static fn(): OperationSafety => OperationSafety::replayableRead(
    ReplaySafetyContract::forReadOnlyCallable('list-products', Duration::seconds(3600)),
);

$flaky = static function (AttemptContext $attempt): string {
    if ($attempt->dispatchedAttemptNumber < 3) {
        throw new Hiccup('try again');
    }

    return 'ok';
};
echo 'first operation: ', $pipeline->execute($flaky, $pipeline->newContext(Duration::seconds(30), $safety())), " (3 dispatches)\n";

$completion = $pipeline->executeDetailed($flaky, $pipeline->newContext(Duration::seconds(30), $safety()));
echo 'second operation: ', $completion->disposition->value, ', dispatched ', $completion->summary->dispatched, ', reason ', $completion->problem()?->code->value, "\n";

echo "-- 2. A cost above the capacity can never be admitted\n";
$tooCostly = PipelineBuilder::profile('rate-charges', 'v1')
    ->partition('shop', 'prod', 'catalog-api')
    ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::Cooperative)
    ->rateLimit(PipelineBuilder::tokenBucket(2, 2, Duration::seconds(1)), cost: 3)
    ->compileInMemory(ExceptionClassifier::create());
try {
    $tooCostly->execute(static fn(): string => 'never runs', $tooCostly->newContext(Duration::seconds(30)));
} catch (ImpossibleAdmissionException $e) {
    echo get_class($e), ': ', $e->reason()->value, "\n";
} catch (AdmissionRejectedException | AdmissionDeferredException $e) {
    echo 'unexpected: ', get_class($e), "\n";
    exit(1);
}
