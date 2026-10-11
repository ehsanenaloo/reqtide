<?php

declare(strict_types=1);

/**
 * 09 - Choosing policies: one small pipeline per protection, each run once so you can see what it refuses.
 * This is the code shown in docs/guides/choosing-policies.html. Numbers are examples for the demonstration, not
 * recommendations: Reqtide has no defaults and no universal safe values.
 *
 * Process-local, in-memory state only.
 *
 * Run:  php examples/09-choosing-policies.php
 */

namespace Reqtide\Examples\ChoosingPolicies;

use Enaxon\Reqtide\Exception\AdmissionDeferredException;
use Enaxon\Reqtide\Exception\AdmissionRejectedException;
use Enaxon\Reqtide\Exception\DeadlineExceededException;
use Enaxon\Reqtide\Execution\ExecutionContext;
use Enaxon\Reqtide\Execution\OperationSafety;
use Enaxon\Reqtide\Execution\ReplaySafetyContract;
use Enaxon\Reqtide\Fallback\FallbackOperation;
use Enaxon\Reqtide\Fallback\FallbackScope;
use Enaxon\Reqtide\Outcome\ExceptionClassifier;
use Enaxon\Reqtide\Outcome\Outcome;
use Enaxon\Reqtide\Pipeline\CompiledPipeline;
use Enaxon\Reqtide\Pipeline\PipelineBuilder;
use Enaxon\Reqtide\Policy\AttemptTimeoutRequirement;
use Enaxon\Reqtide\Policy\BackoffMode;
use Enaxon\Reqtide\Policy\FixedWindowAnchor;
use Enaxon\Reqtide\Policy\JitterMode;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\Ratio;

require __DIR__ . '/autoload.php';

/** Stands in for an HTTP 429 with a Retry-After header. */
final class Throttled extends \RuntimeException
{
    public function __construct(public readonly Duration $wait)
    {
        parent::__construct('throttled');
    }
}

/** @implements FallbackOperation<string> */
final class CachedCatalog implements FallbackOperation
{
    public function invoke(Outcome $original, ExecutionContext $context): string
    {
        return 'cached catalog';
    }
}

/** Every pipeline below starts from the same two required options. */
$base = static fn(): PipelineBuilder => PipelineBuilder::profile('choosing', 'v1')
    ->partition('shop', 'prod', 'catalog-api')
    ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::Cooperative);

$ok = static fn(): string => 'ok';
$context = static fn(CompiledPipeline $pipeline): ExecutionContext => $pipeline->newContext(Duration::seconds(30));

echo "-- 1. Rate limit: three algorithms, same builder call\n";
$algorithms = [
    'token bucket' => PipelineBuilder::tokenBucket(1, 1, Duration::seconds(3600)),
    'fixed window' => PipelineBuilder::fixedWindow(1, Duration::seconds(3600), FixedWindowAnchor::FirstHit),
    'sliding window' => PipelineBuilder::slidingWindow(1, Duration::seconds(3600)),
];
foreach ($algorithms as $name => $algorithm) {
    $pipeline = $base()->rateLimit($algorithm)->compileInMemory(ExceptionClassifier::create());
    $pipeline->execute($ok, $context($pipeline));
    try {
        $pipeline->execute($ok, $context($pipeline));
    } catch (AdmissionRejectedException | AdmissionDeferredException $e) {
        echo "{$name}: second call refused with {$e->reason()->value}\n";
    }
}

echo "-- 2. Bulkhead: at most one operation in flight\n";
$pipeline = $base()->bulkhead(1, Duration::seconds(10))->compileInMemory(ExceptionClassifier::create());
$outer = $pipeline->execute(static function () use ($pipeline, $context): string {
    try {
        // Called while the outer operation still holds the only slot.
        return $pipeline->execute(static fn(): string => 'inner', $context($pipeline));
    } catch (AdmissionRejectedException $e) {
        return "inner call refused with {$e->reason()->value}";
    }
}, $context($pipeline));
echo "{$outer}\n";

echo "-- 3. Circuit breaker: count window or time window\n";
$countWindow = PipelineBuilder::lastCalls(4);
$timeWindow = PipelineBuilder::timeWindow(Duration::seconds(60), Duration::seconds(5));
$pipeline = $base()
    ->circuitBreaker($countWindow, 4, Ratio::of(1, 2), Duration::seconds(30), 1, 1, Duration::seconds(10))
    ->compileInMemory(ExceptionClassifier::create());
$invoked = 0;
$broken = static function () use (&$invoked): string {
    ++$invoked;

    throw new \DomainException('down');
};
for ($i = 1; $i <= 5; ++$i) {
    try {
        $pipeline->execute($broken, $context($pipeline));
    } catch (\DomainException) {
        // Closed circuit: your exception comes through.
    } catch (AdmissionRejectedException $e) {
        echo "call {$i} refused with {$e->reason()->value}; the callable ran {$invoked} times\n";
    }
}
// The same breaker over a 60 s window split into 5 s buckets.
$timeBased = $base()
    ->circuitBreaker($timeWindow, 4, Ratio::of(1, 2), Duration::seconds(30), 1, 1, Duration::seconds(10))
    ->compileInMemory(ExceptionClassifier::create());
echo 'time-window circuit compiled: ', $timeBased->explain()->profileId->value, "\n";

echo "-- 4. Retry-After: a wait too long for the policy is returned to you, not slept through\n";
$pipeline = $base()
    ->retry(3, BackoffMode::Constant, Duration::milliseconds(5), Duration::milliseconds(100), JitterMode::None)
    ->retryBudget(10, 10, Duration::seconds(60), Duration::seconds(10))
    ->cooldown(Duration::seconds(10))
    ->compileInMemory(
        ExceptionClassifier::create()
            ->retryOn(Throttled::class)
            ->retryAfterFrom(static fn(\Throwable $e): ?Duration => $e instanceof Throttled ? $e->wait : null),
    );
$safety = static fn(): OperationSafety => OperationSafety::replayableRead(
    ReplaySafetyContract::forReadOnlyCallable('list-products', Duration::seconds(3600)),
);
$calls = 0;
$throttled = static function () use (&$calls): string {
    ++$calls;

    throw new Throttled(Duration::seconds(60)); // "retry after 60 seconds", but the longest retry delay is 100 ms
};
try {
    $pipeline->execute($throttled, $pipeline->newContext(Duration::seconds(30), $safety()));
} catch (AdmissionDeferredException $e) {
    echo "deferred with {$e->reason()->value}; wait hint present: ", $e->decision->retryAfter !== null ? 'yes' : 'no', "\n";
    echo 'the throttling exception is kept as previous: ', $e->getPrevious() instanceof Throttled ? 'yes' : 'no', "\n";
}
try {
    $pipeline->execute($throttled, $pipeline->newContext(Duration::seconds(30), $safety()));
} catch (AdmissionDeferredException $e) {
    echo "next operation deferred with {$e->reason()->value}; the callable ran {$calls} time(s) in total\n";
}

echo "-- 5. Fallback: replace failures, and optionally refusals\n";
$build = static fn(FallbackScope $scope): CompiledPipeline => $base()
    ->rateLimit(PipelineBuilder::tokenBucket(1, 1, Duration::seconds(3600)))
    ->fallbackScope($scope)
    ->compileInMemory(ExceptionClassifier::create()->fallbackOn(\Throwable::class));
foreach ([FallbackScope::BusinessOutcomesOnly, FallbackScope::IncludeAdmissionRefusals] as $scope) {
    $pipeline = $build($scope);
    $pipeline->execute($ok, $pipeline->newContext(Duration::seconds(30))); // uses the only token
    try {
        $value = $pipeline->execute($ok, $pipeline->newContext(Duration::seconds(30), fallback: new CachedCatalog()));
        echo "{$scope->value}: second call returned '{$value}'\n";
    } catch (AdmissionRejectedException | AdmissionDeferredException $e) {
        echo "{$scope->value}: second call refused with {$e->reason()->value}\n";
    }
}

echo "-- 6. Deadline: one budget for the whole operation\n";
$pipeline = $base()->compileInMemory(ExceptionClassifier::create());
$slow = static function (): string {
    usleep(30_000);

    return 'finished late';
};
try {
    $pipeline->execute($slow, $pipeline->newContext(Duration::milliseconds(5)));
} catch (DeadlineExceededException $e) {
    echo "late result replaced by {$e->reason()->value}\n";
}
