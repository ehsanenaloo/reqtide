<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Tests\Unit;

use Enaxon\Reqtide\Exception\CapabilityMismatchException;
use Enaxon\Reqtide\Exception\DeadlineExceededException;
use Enaxon\Reqtide\Exception\OperationCancelledException;
use Enaxon\Reqtide\Guzzle\HttpOperation;
use Enaxon\Reqtide\Guzzle\HttpOutcomeClassifier;
use Enaxon\Reqtide\Guzzle\Runtime\GuzzlePromiseRuntimeAdapter;
use Enaxon\Reqtide\Guzzle\Tests\Support\CancelAfterSeconds;
use Enaxon\Reqtide\Guzzle\Tests\Support\FixtureServer;
use Enaxon\Reqtide\Guzzle\Transport\CurlMultiTransfer;
use Enaxon\Reqtide\Pipeline\CompiledPipeline;
use Enaxon\Reqtide\Pipeline\PipelineBuilder;
use Enaxon\Reqtide\Policy\AttemptTimeoutRequirement;
use Enaxon\Reqtide\Policy\BackoffMode;
use Enaxon\Reqtide\Policy\JitterMode;
use Enaxon\Reqtide\Runtime\AttemptTimeoutCapability;
use Enaxon\Reqtide\Runtime\CancellationCapability;
use Enaxon\Reqtide\Runtime\InvocationForm;
use Enaxon\Reqtide\Runtime\QueueOwnership;
use Enaxon\Reqtide\Runtime\SchedulingCapability;
use Enaxon\Reqtide\Runtime\SettlementCapability;
use Enaxon\Reqtide\Time\Cancellation;
use Enaxon\Reqtide\Value\Duration;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Fulfilled and rejected promises, cancellation requests, bounded driving and deadlines
 * through a real Core pipeline on the promise runtime, a real cURL multi handler and the fixture server.
 */
final class GuzzlePromiseRuntimeAdapterTest extends TestCase
{
    private static FixtureServer $server;

    public static function setUpBeforeClass(): void
    {
        self::$server = FixtureServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    protected function setUp(): void
    {
        self::$server->reset();
    }

    private function builder(Duration $attemptTimeout): PipelineBuilder
    {
        return PipelineBuilder::profile('http-promise', 'v1')
            ->partition('test', 'local', 'fixture')
            ->attemptTimeout($attemptTimeout, AttemptTimeoutRequirement::TransportEnforced)
            ->retry(3, BackoffMode::Constant, Duration::milliseconds(5), Duration::seconds(2), JitterMode::None)
            ->retryBudget(10, 10, Duration::seconds(60), $attemptTimeout->add(Duration::seconds(10)));
    }

    private function pipeline(CurlMultiTransfer $transfer, Duration $attemptTimeout = new Duration(2_000_000_000)): CompiledPipeline
    {
        $builder = $this->builder($attemptTimeout);

        return $builder->compile(GuzzlePromiseRuntimeAdapter::inMemoryEnvironment($builder->definition(), HttpOutcomeClassifier::starter(), $transfer));
    }

    private function read(string $path): HttpOperation
    {
        return HttpOperation::read(new Request('GET', self::$server->baseUri . $path), 'fixture.read', Duration::seconds(60), HttpOutcomeClassifier::starter()->retryableOutcomeCodes());
    }

    private function execute(CompiledPipeline $pipeline, CurlMultiTransfer $transfer, HttpOperation $operation, Duration $budget, ?Cancellation $cancellation = null): mixed
    {
        $metadata = $operation->metadata();
        $context = $pipeline->newContext($budget, $metadata->safety, null, $cancellation, $metadata->attributes);

        return $pipeline->executeOperation($operation->asyncAttemptOperation($transfer->starter()), $context);
    }

    public function testItDeclaresAnObservedPromiseOnAnAdapterOwnedQueue(): void
    {
        $capabilities = (new GuzzlePromiseRuntimeAdapter(new CurlMultiTransfer()))->capabilities();
        self::assertSame(InvocationForm::ObservedPromise, $capabilities->invocation);
        self::assertSame(SettlementCapability::TerminalPromiseSettlement, $capabilities->settlement);
        self::assertSame(QueueOwnership::AdapterOwned, $capabilities->queueOwnership);
        self::assertSame(SchedulingCapability::AdapterDrivenQueue, $capabilities->scheduling);
        self::assertSame(AttemptTimeoutCapability::TransportEnforced, $capabilities->attemptTimeout);
        self::assertSame(CancellationCapability::TransportRequest, $capabilities->cancellation);
    }

    public function testItCannotBeCombinedWithTheBlockingDelaySchedulerOfTheInMemoryEnvironment(): void
    {
        $builder = $this->builder(Duration::seconds(2));
        $definition = $builder->definition();
        $base = \Enaxon\Reqtide\Pipeline\InMemoryEnvironment::for($definition, HttpOutcomeClassifier::starter());
        $mismatched = new \Enaxon\Reqtide\Pipeline\RuntimeEnvironment(
            $base->clock,
            $base->scheduler,
            $base->random,
            $base->classifier,
            new GuzzlePromiseRuntimeAdapter(new CurlMultiTransfer()),
            $base->events,
            $base->rateLimit,
            $base->circuit,
            $base->concurrency,
            $base->retryBudget,
            $base->cooldown,
        );
        $this->expectException(CapabilityMismatchException::class);
        $definition->compile($mismatched);
    }

    public function testAFulfilledPromiseReturnsTheResponse(): void
    {
        $transfer = new CurlMultiTransfer();
        $response = $this->execute($this->pipeline($transfer), $transfer, $this->read('/status/200'), Duration::seconds(10));

        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, self::$server->count('/status/200'));
    }

    public function testARejectedPromiseIsRethrownAsTheOriginalException(): void
    {
        $closed = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($closed);
        $name = (string) stream_socket_get_name($closed, false);
        fclose($closed);
        $operation = HttpOperation::read(new Request('GET', 'http://' . $name . '/x'), 'fixture.read', Duration::seconds(60), HttpOutcomeClassifier::starter()->retryableOutcomeCodes());
        $transfer = new CurlMultiTransfer();

        $this->expectException(ConnectException::class);
        $this->execute($this->pipeline($transfer), $transfer, $operation, Duration::seconds(10));
    }

    public function testRetriesRunThroughTheQueueDrivenSchedulerAndEveryDispatchIsSeenAtTheServer(): void
    {
        $transfer = new CurlMultiTransfer();
        $this->execute($this->pipeline($transfer), $transfer, $this->read('/status/503'), Duration::seconds(10));

        self::assertSame(3, self::$server->count('/status/503'));
    }

    public function testRetryAfterDelaysEveryRetryOnThePromiseRuntimeToo(): void
    {
        $transfer = new CurlMultiTransfer();
        $this->execute($this->pipeline($transfer), $transfer, $this->read('/status/429?ra=1'), Duration::seconds(10));

        $log = self::$server->log();
        self::assertCount(3, $log);
        foreach ([1, 2] as $i) {
            self::assertIsInt($log[$i]['atMs']);
            self::assertIsInt($log[$i - 1]['atMs']);
            self::assertGreaterThanOrEqual(950, $log[$i]['atMs'] - $log[$i - 1]['atMs']);
        }
    }

    /**
     * One finished operation on the transfer before a timing assertion: the first operation of a process loads the
     * classes of the pipeline and the transfer (slow on a bind-mounted source tree) and is not what the bound measures.
     */
    private function warmUp(CurlMultiTransfer $transfer): void
    {
        $this->execute($this->pipeline($transfer), $transfer, $this->read('/status/200'), Duration::seconds(10));
    }

    public function testACancellationRequestDuringTheTransferEndsItPromptlyAndNothingIsRetried(): void
    {
        $transfer = new CurlMultiTransfer();
        $this->warmUp($transfer);
        $started = hrtime(true);
        try {
            $this->execute($this->pipeline($transfer), $transfer, $this->read('/slow?ms=3000'), Duration::seconds(20), new CancelAfterSeconds(0.2));
            self::fail('expected the operation to be cancelled');
        } catch (OperationCancelledException) {
            $elapsed = (hrtime(true) - $started) / 1e9;
            self::assertLessThan(1.0, $elapsed, 'cancelled at about 0.2 s, long before the server would have answered');
            self::assertGreaterThanOrEqual(0.15, $elapsed);
        }
        self::assertSame(1, self::$server->count('/slow'), 'the request was sent once; a cancelled operation is not retried');
    }

    public function testTheDeadlineEndsTheTransferWithinTheDrivingGranularity(): void
    {
        $transfer = new CurlMultiTransfer();
        $this->warmUp($transfer);
        $started = hrtime(true);
        try {
            $this->execute($this->pipeline($transfer, Duration::seconds(20)), $transfer, $this->read('/slow?ms=3000'), Duration::milliseconds(400));
            self::fail('expected a failure at the deadline');
        } catch (ConnectException|DeadlineExceededException) {
            // The transfer failure or the policy exception: either way the operation is over at its deadline.
        }
        $elapsed = (hrtime(true) - $started) / 1e9;
        self::assertGreaterThanOrEqual(0.35, $elapsed);
        self::assertLessThan(1.0, $elapsed, 'a 20 s attempt timeout cannot outlive a 400 ms deadline');
        self::assertLessThanOrEqual(2, self::$server->count('/slow'));
    }

    public function testAPromiseThatIsNeverDrivenStartsNoTransfer(): void
    {
        $transfer = new CurlMultiTransfer();
        $context = new \Enaxon\Reqtide\Execution\AttemptContext(
            new \Enaxon\Reqtide\Value\OperationId('op-1'),
            \Enaxon\Reqtide\Value\AttemptId::fromLowerHex(str_repeat('c', 32)),
            1,
            \Enaxon\Reqtide\Time\Deadline::after(Duration::seconds(30), new \Enaxon\Reqtide\Time\SystemClock()),
            new \Enaxon\Reqtide\Time\CancellationToken(),
            Duration::seconds(5),
        );
        $promise = $transfer->start(new Request('GET', self::$server->baseUri . '/status/200'), $context);
        usleep(200_000);
        self::assertSame(0, self::$server->count('/status/200'), 'without driving, nothing is sent');

        $deadline = hrtime(true) + 5_000_000_000;
        while ($promise->getState() === 'pending' && hrtime(true) < $deadline) {
            $transfer->drive();
        }
        self::assertSame('fulfilled', $promise->getState());
        self::assertSame(1, self::$server->count('/status/200'));
    }

    public function testOneDriveStepBlocksForAtMostTheSmallSelectTimeout(): void
    {
        $transfer = new CurlMultiTransfer();
        $context = new \Enaxon\Reqtide\Execution\AttemptContext(
            new \Enaxon\Reqtide\Value\OperationId('op-1'),
            \Enaxon\Reqtide\Value\AttemptId::fromLowerHex(str_repeat('c', 32)),
            1,
            \Enaxon\Reqtide\Time\Deadline::after(Duration::seconds(30), new \Enaxon\Reqtide\Time\SystemClock()),
            new \Enaxon\Reqtide\Time\CancellationToken(),
            Duration::seconds(5),
        );
        $promise = $transfer->start(new Request('GET', self::$server->baseUri . '/slow?ms=500'), $context);
        $longest = 0.0;
        $steps = 0;
        $deadline = hrtime(true) + 5_000_000_000;
        while ($promise->getState() === 'pending' && hrtime(true) < $deadline) {
            $before = hrtime(true);
            $transfer->drive();
            $longest = max($longest, (hrtime(true) - $before) / 1e9);
            ++$steps;
        }
        self::assertSame('fulfilled', $promise->getState());
        self::assertGreaterThan(5, $steps, 'a 500 ms transfer takes many small steps');
        self::assertLessThan(0.1, $longest, 'with the default select timeout one tick blocks for up to a second');
    }
}
