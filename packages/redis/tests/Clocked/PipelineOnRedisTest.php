<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Clocked;

use Enaxon\Reqtide\Exception\AdmissionDeferredException;
use Enaxon\Reqtide\Exception\AdmissionRejectedException;
use Enaxon\Reqtide\Outcome\ExceptionClassifier;
use Enaxon\Reqtide\Pipeline\CompiledPipeline;
use Enaxon\Reqtide\Pipeline\PipelineBuilder;
use Enaxon\Reqtide\Pipeline\RuntimeEnvironment;
use Enaxon\Reqtide\Policy\AttemptTimeoutRequirement;
use Enaxon\Reqtide\Redis\RedisConcurrencyBackend;
use Enaxon\Reqtide\Redis\RedisCooldownBackend;
use Enaxon\Reqtide\Redis\RedisRegistry;
use Enaxon\Reqtide\Redis\Tests\Support\RedisBackendHarness;
use Enaxon\Reqtide\Runtime\SchedulingCapability;
use Enaxon\Reqtide\Runtime\SynchronousRuntimeAdapter;
use Enaxon\Reqtide\Telemetry\NullEventSink;
use Enaxon\Reqtide\Time\BoundedBlockingScheduler;
use Enaxon\Reqtide\Time\SecureRandomSource;
use Enaxon\Reqtide\Time\SystemClock;
use Enaxon\Reqtide\Value\Duration;
use PHPUnit\Framework\TestCase;

/**
 * The first end-to-end runs on Redis: two pipelines, each with its own backend instances, share
 * one registry the way two workers would. The callable of a refused operation is never invoked.
 */
final class PipelineOnRedisTest extends TestCase
{
    private const SECOND = 1_000_000_000;

    private ?RedisBackendHarness $harness = null;

    protected function setUp(): void
    {
        $this->harness()->time()->moveTo(100 * self::SECOND);
    }

    protected function tearDown(): void
    {
        $this->harness?->dispose();
    }

    private function harness(): RedisBackendHarness
    {
        return $this->harness ??= RedisBackendHarness::create();
    }

    private function builder(): PipelineBuilder
    {
        return PipelineBuilder::profile('orders', 'v1')
            ->partition('shop', 'prod', 'orders-api')
            ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::Cooperative)
            ->bulkhead(1, Duration::seconds(10))
            ->cooldown(Duration::seconds(60));
    }

    /** One "worker": its own backend instances (and connections) over the shared registry. */
    private function worker(RedisRegistry $registry, ExceptionClassifier $classifier): CompiledPipeline
    {
        $clock = new SystemClock();
        $environment = new RuntimeEnvironment(
            $clock,
            new BoundedBlockingScheduler($clock),
            new SecureRandomSource(),
            $classifier,
            new SynchronousRuntimeAdapter(SchedulingCapability::BoundedBlocking),
            new NullEventSink(),
            null,
            null,
            new RedisConcurrencyBackend($this->harness()->options(), $registry),
            null,
            new RedisCooldownBackend($this->harness()->options(), $registry),
        );

        return $this->builder()->compile($environment);
    }

    public function testASharedBulkheadRefusesTheSecondWorkerWhileTheFirstHoldsThePermit(): void
    {
        $registry = $this->harness()->registry();
        $first = $this->worker($registry, ExceptionClassifier::create());
        $second = $this->worker($registry, ExceptionClassifier::create());
        $secondInvocations = 0;
        $refused = null;

        $result = $first->execute(function () use ($second, &$secondInvocations, &$refused): string {
            try {
                $second->execute(static function () use (&$secondInvocations): string {
                    ++$secondInvocations;

                    return 'must not run';
                }, $second->newContext(Duration::seconds(30), operationId: 'second'));
            } catch (AdmissionRejectedException $exception) {
                $refused = $exception;
            }

            return 'first done';
        }, $first->newContext(Duration::seconds(30), operationId: 'first'));

        self::assertSame('first done', $result);
        self::assertInstanceOf(AdmissionRejectedException::class, $refused, 'the shared bulkhead refused the second worker');
        self::assertSame(0, $secondInvocations, 'the refused callable never ran');

        // The first operation released the permit: the second worker is admitted now.
        $after = $second->execute(static fn(): string => 'second done', $second->newContext(Duration::seconds(30), operationId: 'second-again'));
        self::assertSame('second done', $after);
    }

    public function testASharedCooldownStartedByOneWorkerDefersTheOtherUntilItEnds(): void
    {
        $registry = $this->harness()->registry();
        $throttling = ExceptionClassifier::create()->retryAfterFrom(
            static fn(\Throwable $error): ?Duration => $error instanceof \DomainException ? Duration::seconds(5) : null,
        );
        $first = $this->worker($registry, $throttling);
        $second = $this->worker($registry, ExceptionClassifier::create());

        $log = new \ArrayObject();
        try {
            $first->execute(static function () use ($log): string {
                $log->append('ran');

                throw new \DomainException('429 Too Many Requests, retry after 5 s');
            }, $first->newContext(Duration::seconds(30), operationId: 'throttled'));
        } catch (\DomainException $caught) {
            $log->append($caught->getMessage());
        }
        self::assertSame(['ran', '429 Too Many Requests, retry after 5 s'], $log->getArrayCopy(), 'the operation ran once and its own exception passed through unchanged');

        $invocations = 0;
        try {
            $second->execute(static function () use (&$invocations): string {
                ++$invocations;

                return 'must not run';
            }, $second->newContext(Duration::seconds(30), operationId: 'deferred'));
            self::fail('The shared cooldown must defer the second worker.');
        } catch (AdmissionDeferredException $deferred) {
            self::assertSame(5 * self::SECOND, $deferred->decision->retryAfter?->nanoseconds);
        }
        self::assertSame(0, $invocations);

        $this->harness()->time()->advance(5 * self::SECOND);
        self::assertSame('ok', $second->execute(static fn(): string => 'ok', $second->newContext(Duration::seconds(30), operationId: 'after')));
    }
}
