<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Integration;

use Enaxon\Reqtide\Completion\CompletionDisposition;
use Enaxon\Reqtide\Completion\ExecutionCompletion;
use Enaxon\Reqtide\Exception\CorruptStateException;
use Enaxon\Reqtide\Exception\IndeterminateStateException;
use Enaxon\Reqtide\Exception\StateUnavailableException;
use Enaxon\Reqtide\Execution\OperationSafety;
use Enaxon\Reqtide\Execution\ReplaySafetyContract;
use Enaxon\Reqtide\Outcome\ExceptionClassifier;
use Enaxon\Reqtide\Pipeline\CompiledPipeline;
use Enaxon\Reqtide\Pipeline\PipelineBuilder;
use Enaxon\Reqtide\Pipeline\RuntimeEnvironment;
use Enaxon\Reqtide\Policy\AttemptTimeoutRequirement;
use Enaxon\Reqtide\Policy\BackoffMode;
use Enaxon\Reqtide\Policy\JitterMode;
use Enaxon\Reqtide\Redis\RedisConcurrencyBackend;
use Enaxon\Reqtide\Redis\RedisConnectionOptions;
use Enaxon\Reqtide\Redis\RedisRateLimitBackend;
use Enaxon\Reqtide\Redis\RedisRegistry;
use Enaxon\Reqtide\Redis\RedisRegistryAdmin;
use Enaxon\Reqtide\Redis\RedisRetryBudgetBackend;
use Enaxon\Reqtide\Redis\Tests\Support\FaultProxy;
use Enaxon\Reqtide\Redis\Tests\Support\RedisEndpoint;
use Enaxon\Reqtide\Redis\Tests\Support\ScriptWarmer;
use Enaxon\Reqtide\Runtime\SchedulingCapability;
use Enaxon\Reqtide\Runtime\SynchronousRuntimeAdapter;
use Enaxon\Reqtide\Telemetry\NullEventSink;
use Enaxon\Reqtide\Time\BoundedBlockingScheduler;
use Enaxon\Reqtide\Time\SecureRandomSource;
use Enaxon\Reqtide\Time\SystemClock;
use Enaxon\Reqtide\Value\Duration;
use PHPUnit\Framework\TestCase;
use Predis\Client;

/**
 * Fault checks with a real server: a real pipeline over Redis backends behind
 * the fault proxy. After an uncertain admission nothing is dispatched, after an uncertain finalization the business result
 * is kept, an uncertain retry commit sends nothing more, and lost or damaged state is never replaced by fresh state.
 */
final class PipelineFaultTest extends TestCase
{
    private const SECRET = 'a-host-managed-secret-of-32-bytes!!';

    private ?FaultProxy $proxy = null;

    private ?RedisRegistry $registry = null;

    protected function tearDown(): void
    {
        $this->proxy?->stop();
        if ($this->registry !== null) {
            $client = $this->client();
            $cursor = '0';
            do {
                /** @var array{0: string, 1: list<string>} $page */
                $page = $client->scan($cursor, ['MATCH' => $this->registry->keyPrefix . ':*', 'COUNT' => 500]);
                $cursor = (string) $page[0];
                if ($page[1] !== []) {
                    $client->del($page[1]);
                }
            } while ($cursor !== '0');
        }
    }

    private function client(): Client
    {
        return new Client(RedisEndpoint::forTest()->parameters(10.0));
    }

    private function direct(): RedisConnectionOptions
    {
        $endpoint = RedisEndpoint::forTest();

        return new RedisConnectionOptions($endpoint->host, 2.0, 5.0, $endpoint->port);
    }

    private function proxy(): FaultProxy
    {
        return $this->proxy ??= FaultProxy::start(RedisEndpoint::forTest());
    }

    private function registry(): RedisRegistry
    {
        ScriptWarmer::warm($this->client());
        $registry = new RedisRegistry('rt_pf_' . bin2hex(random_bytes(5)), 'pipeline-fault', self::SECRET);
        (new RedisRegistryAdmin($this->direct(), $registry))->initialize();
        $this->registry = $registry;

        return $registry;
    }

    private function builder(): PipelineBuilder
    {
        return PipelineBuilder::profile('fault', 'v1')
            ->partition('shop', 'prod', 'fault-api')
            ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::Cooperative);
    }

    private function compile(PipelineBuilder $builder, RedisRegistry $registry, bool $rate, bool $bulkhead, bool $budget): CompiledPipeline
    {
        $options = $this->proxy()->options(1.0);
        $clock = new SystemClock();

        return $builder->compile(new RuntimeEnvironment(
            $clock,
            new BoundedBlockingScheduler($clock),
            new SecureRandomSource(),
            ExceptionClassifier::create()->retryOn(\RuntimeException::class),
            new SynchronousRuntimeAdapter(SchedulingCapability::BoundedBlocking),
            new NullEventSink(),
            $rate ? new RedisRateLimitBackend($options, $registry) : null,
            null,
            $bulkhead ? new RedisConcurrencyBackend($options, $registry) : null,
            $budget ? new RedisRetryBudgetBackend($options, $registry) : null,
            null,
        ));
    }

    /**
     * @param \Closure(): mixed $operation
     * @return ExecutionCompletion<mixed>
     */
    private function operate(CompiledPipeline $pipeline, \Closure $operation, bool $retryable = false): ExecutionCompletion
    {
        $safety = $retryable ? OperationSafety::replayableRead(ReplaySafetyContract::forReadOnlyCallable('fault-read', Duration::seconds(120))) : null;

        return $pipeline->executeDetailed($operation, $pipeline->newContext(Duration::seconds(20), $safety));
    }

    public function testAnUncertainRateDebitDispatchesNothing(): void
    {
        $registry = $this->registry();
        $pipeline = $this->compile($this->builder()->rateLimit(PipelineBuilder::tokenBucket(5, 5, Duration::seconds(3600))), $registry, true, false, false);
        $calls = 0;

        $this->proxy()->mode('drop-reply@1');
        $completion = $this->operate($pipeline, static function () use (&$calls): string {
            ++$calls;

            return 'dispatched';
        });

        self::assertSame(0, $calls, 'the callable never ran after an uncertain debit');
        self::assertSame(0, $completion->summary->dispatched);
        self::assertSame(1, $completion->summary->admissionUncertain, 'the uncertain debit is counted');
        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertInstanceOf(IndeterminateStateException::class, $completion->policyCause ?? $completion->terminalOutcome?->exception());
        self::assertFalse($completion->summary->accountingComplete);

        // The debit was applied once on the server; the next operation is admitted and sees four tokens left.
        $this->proxy()->mode('pass');
        $second = $this->operate($pipeline, static function () use (&$calls): string {
            ++$calls;

            return 'dispatched';
        });
        self::assertSame(CompletionDisposition::Succeeded, $second->disposition);
        self::assertSame(1, $calls);
        $partition = $this->client()->keys($registry->keyPrefix . ':p:rl:*');
        self::assertCount(1, $partition);
    }

    public function testALostReleaseReplyKeepsTheBusinessResultAndTheServerStillReleases(): void
    {
        $registry = $this->registry();
        $pipeline = $this->compile($this->builder()->bulkhead(1, Duration::seconds(30)), $registry, false, true, false);

        $completion = $this->operate($pipeline, function (): string {
            $this->proxy()->mode('drop-reply@1');

            return 'the business value';
        });

        self::assertSame(CompletionDisposition::Succeeded, $completion->disposition);
        self::assertSame('the business value', $completion->terminalOutcome?->value());
        self::assertFalse($completion->summary->accountingComplete, 'the lost release is reported as incomplete accounting');
        self::assertSame(1, $completion->summary->dispatched);

        // The release ran on the server although its reply was lost: the single permit is free again.
        $again = $this->operate($pipeline, static fn(): string => 'second');
        self::assertSame(CompletionDisposition::Succeeded, $again->disposition);
    }

    public function testAStalledReleaseEndsInBoundedTimeWithTheBusinessResult(): void
    {
        $registry = $this->registry();
        $pipeline = $this->compile($this->builder()->bulkhead(1, Duration::seconds(30)), $registry, false, true, false);

        $started = hrtime(true);
        $completion = $this->operate($pipeline, function (): string {
            $this->proxy()->mode('stall@1');

            return 'value';
        });

        self::assertLessThan(4.0, (hrtime(true) - $started) / 1e9);
        self::assertSame('value', $completion->terminalOutcome?->value());
        self::assertFalse($completion->summary->accountingComplete);
    }

    public function testAnUncertainRetryCommitSendsNothingMore(): void
    {
        $registry = $this->registry();
        $builder = $this->builder()
            ->retry(2, BackoffMode::Constant, Duration::milliseconds(5), Duration::seconds(1), JitterMode::None)
            ->retryBudget(5, 5, Duration::seconds(3600), Duration::seconds(30));
        $pipeline = $this->compile($builder, $registry, false, false, true);
        $calls = 0;

        // The reserve of the retry is the first script call, its commit the second.
        $this->proxy()->mode('drop-reply@2');
        $completion = $this->operate($pipeline, static function () use (&$calls): never {
            ++$calls;

            throw new \RuntimeException('transient');
        }, true);

        self::assertSame(1, $calls, 'the retry was not dispatched after an uncertain commit');
        self::assertSame(1, $completion->summary->dispatched);
        self::assertSame(0, $completion->summary->retries);
    }

    public function testLostStateIsNeverReplacedByFreshState(): void
    {
        $registry = $this->registry();
        $pipeline = $this->compile($this->builder()->rateLimit(PipelineBuilder::tokenBucket(1, 1, Duration::seconds(3600))), $registry, true, false, false);
        $calls = 0;
        $operation = static function () use (&$calls): string {
            ++$calls;

            return 'dispatched';
        };

        self::assertSame(CompletionDisposition::Succeeded, $this->operate($pipeline, $operation)->disposition);
        // The server loses the registry (a restart without persistence, an eviction, a flush).
        $this->client()->del([$registry->keys()->metadata()]);

        $completion = $this->operate($pipeline, $operation);
        self::assertSame(1, $calls, 'with the state gone nothing is dispatched: no fresh bucket');
        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertInstanceOf(StateUnavailableException::class, $completion->policyCause ?? $completion->terminalOutcome?->exception());
        self::assertSame(0, $this->client()->exists($registry->keys()->metadata()), 'and nothing re-created the registry');
    }

    public function testDamagedStateIsCorruptStateAndNothingIsDispatched(): void
    {
        $registry = $this->registry();
        $pipeline = $this->compile($this->builder()->rateLimit(PipelineBuilder::tokenBucket(5, 5, Duration::seconds(3600))), $registry, true, false, false);
        $calls = 0;
        $operation = static function () use (&$calls): string {
            ++$calls;

            return 'dispatched';
        };
        self::assertSame(CompletionDisposition::Succeeded, $this->operate($pipeline, $operation)->disposition);
        /** @var list<string> $partitions */
        $partitions = $this->client()->keys($registry->keyPrefix . ':p:rl:*');
        self::assertCount(1, $partitions);
        $this->client()->hset($partitions[0], 'v1', 'not a number');

        $completion = $this->operate($pipeline, $operation);
        self::assertSame(1, $calls);
        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertInstanceOf(CorruptStateException::class, $completion->policyCause ?? $completion->terminalOutcome?->exception());
    }
}
