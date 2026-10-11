<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Pipeline;

use Enaxon\Reqtide\Exception\AdmissionRejectedException;
use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Execution\OperationSafety;
use Enaxon\Reqtide\Execution\ReplaySafetyContract;
use Enaxon\Reqtide\Outcome\ExceptionClassifier;
use Enaxon\Reqtide\Pipeline\InMemoryEnvironment;
use Enaxon\Reqtide\Pipeline\PipelineBuilder;
use Enaxon\Reqtide\Pipeline\PipelineDefinition;
use Enaxon\Reqtide\Policy\AttemptTimeoutRequirement;
use Enaxon\Reqtide\Policy\BackoffMode;
use Enaxon\Reqtide\Policy\JitterMode;
use Enaxon\Reqtide\Runtime\SchedulingCapability;
use Enaxon\Reqtide\Runtime\SynchronousRuntimeAdapter;
use Enaxon\Reqtide\State\Memory\MemoryCircuitBackend;
use Enaxon\Reqtide\State\Memory\MemoryConcurrencyBackend;
use Enaxon\Reqtide\State\Memory\MemoryCooldownBackend;
use Enaxon\Reqtide\State\Memory\MemoryRateLimitBackend;
use Enaxon\Reqtide\State\Memory\MemoryRetryBudgetBackend;
use Enaxon\Reqtide\State\StateScope;
use Enaxon\Reqtide\Telemetry\NullEventSink;
use Enaxon\Reqtide\Tests\Pipeline\Support\RecordingSink;
use Enaxon\Reqtide\Time\BoundedBlockingScheduler;
use Enaxon\Reqtide\Time\SecureRandomSource;
use Enaxon\Reqtide\Time\SystemClock;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\Ratio;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** InMemoryEnvironment wires real time, a bounded-blocking scheduler and memory backends only where configured. */
final class InMemoryEnvironmentTest extends TestCase
{
    private static function base(): PipelineBuilder
    {
        return PipelineBuilder::profile('env', 'v1')
            ->partition('app', 'test', 'subject')
            ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::Cooperative);
    }

    public function testTheFixedPartsAreTheRealTimeAndTheSynchronousRuntime(): void
    {
        $classifier = ExceptionClassifier::create();

        $environment = InMemoryEnvironment::for(self::base()->definition(), $classifier);

        self::assertInstanceOf(SystemClock::class, $environment->clock);
        self::assertInstanceOf(BoundedBlockingScheduler::class, $environment->scheduler);
        self::assertSame(SchedulingCapability::BoundedBlocking, $environment->scheduler->capability());
        self::assertInstanceOf(SecureRandomSource::class, $environment->random);
        self::assertInstanceOf(SynchronousRuntimeAdapter::class, $environment->runtime);
        self::assertSame(SchedulingCapability::BoundedBlocking, $environment->runtime->capabilities()->scheduling);
        self::assertSame($classifier, $environment->classifier);
        self::assertInstanceOf(NullEventSink::class, $environment->events);
    }

    public function testAProvidedSinkIsUsed(): void
    {
        $sink = new RecordingSink();

        $environment = InMemoryEnvironment::for(self::base()->definition(), ExceptionClassifier::create(), $sink);

        self::assertSame($sink, $environment->events);
    }

    public function testAnUnconfiguredDefinitionCreatesNoBackendAtAll(): void
    {
        $environment = InMemoryEnvironment::for(self::base()->definition(), ExceptionClassifier::create());

        self::assertNull($environment->rateLimit);
        self::assertNull($environment->circuit);
        self::assertNull($environment->concurrency);
        self::assertNull($environment->retryBudget);
        self::assertNull($environment->cooldown);
    }

    /** @return iterable<string, array{PipelineDefinition, string}> */
    public static function singlePolicyDefinitions(): iterable
    {
        yield 'rate limit' => [self::base()->rateLimit(PipelineBuilder::tokenBucket(5, 5, Duration::seconds(1)))->definition(), 'rateLimit'];
        yield 'circuit breaker' => [self::base()->circuitBreaker(PipelineBuilder::lastCalls(5), 2, Ratio::of(1, 2), Duration::seconds(30), 1, 1, Duration::seconds(10))->definition(), 'circuit'];
        yield 'bulkhead' => [self::base()->bulkhead(2, Duration::seconds(10))->definition(), 'concurrency'];
        yield 'retry budget' => [self::base()
            ->retry(2, BackoffMode::Constant, Duration::milliseconds(1), Duration::milliseconds(1), JitterMode::None)
            ->retryBudget(2, 2, Duration::seconds(10), Duration::seconds(10))->definition(), 'retryBudget'];
        yield 'cooldown' => [self::base()->cooldown(Duration::seconds(5))->definition(), 'cooldown'];
    }

    #[DataProvider('singlePolicyDefinitions')]
    public function testExactlyTheBackendOfTheConfiguredPolicyIsCreated(PipelineDefinition $definition, string $installed): void
    {
        $environment = InMemoryEnvironment::for($definition, ExceptionClassifier::create());

        $backends = [
            'rateLimit' => [$environment->rateLimit, MemoryRateLimitBackend::class],
            'circuit' => [$environment->circuit, MemoryCircuitBackend::class],
            'concurrency' => [$environment->concurrency, MemoryConcurrencyBackend::class],
            'retryBudget' => [$environment->retryBudget, MemoryRetryBudgetBackend::class],
            'cooldown' => [$environment->cooldown, MemoryCooldownBackend::class],
        ];
        foreach ($backends as $name => [$backend, $class]) {
            if ($name === $installed) {
                self::assertInstanceOf($class, $backend, $name);
                self::assertSame(StateScope::ProcessLocal, $backend->capabilities()->scope, 'memory backends declare themselves process-local');
            } else {
                self::assertNull($backend, $name . ' must not be created for a definition that does not configure it');
            }
        }
    }

    public function testEveryEnvironmentOwnsItsOwnState(): void
    {
        $definition = self::base()->rateLimit(PipelineBuilder::tokenBucket(1, 1, Duration::seconds(3600)))->definition();
        $first = $definition->compile(InMemoryEnvironment::for($definition, ExceptionClassifier::create()));
        $second = $definition->compile(InMemoryEnvironment::for($definition, ExceptionClassifier::create()));
        $work = static fn(AttemptContext $attempt): string => 'done';

        self::assertSame('done', $first->execute($work, $first->newContext(Duration::seconds(30))));
        try {
            $first->execute($work, $first->newContext(Duration::seconds(30)));
            self::fail('The single token of the first environment is spent.');
        } catch (AdmissionRejectedException) {
            // expected: the first environment is exhausted ...
        }
        self::assertSame('done', $second->execute($work, $second->newContext(Duration::seconds(30))), '... while the second one still has its own token');
    }

    public function testRetriesRunOverRealSleepingDelays(): void
    {
        $definition = self::base()
            ->retry(2, BackoffMode::Constant, Duration::milliseconds(1), Duration::milliseconds(1), JitterMode::None)
            ->retryBudget(2, 2, Duration::seconds(10), Duration::seconds(10))
            ->definition();
        $pipeline = $definition->compile(InMemoryEnvironment::for($definition, ExceptionClassifier::create()->retryOn(\RuntimeException::class)));
        $calls = 0;

        $result = $pipeline->execute(
            static function (AttemptContext $attempt) use (&$calls): string {
                if (++$calls === 1) {
                    throw new \RuntimeException('first attempt fails');
                }

                return 'second attempt';
            },
            $pipeline->newContext(
                Duration::seconds(30),
                OperationSafety::replayableRead(ReplaySafetyContract::forReadOnlyCallable('flaky-read', Duration::seconds(3600))),
            ),
        );

        self::assertSame('second attempt', $result);
        self::assertSame(2, $calls);
    }
}
