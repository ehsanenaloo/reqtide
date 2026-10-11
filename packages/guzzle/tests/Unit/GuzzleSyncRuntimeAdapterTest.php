<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Tests\Unit;

use Enaxon\Reqtide\Exception\CapabilityMismatchException;
use Enaxon\Reqtide\Exception\DeadlineExceededException;
use Enaxon\Reqtide\Guzzle\HttpOperation;
use Enaxon\Reqtide\Guzzle\HttpOutcomeClassifier;
use Enaxon\Reqtide\Guzzle\Runtime\GuzzleSyncRuntimeAdapter;
use Enaxon\Reqtide\Guzzle\Tests\Support\FixtureServer;
use Enaxon\Reqtide\Guzzle\Transport\CurlTransfer;
use Enaxon\Reqtide\Pipeline\CompiledPipeline;
use Enaxon\Reqtide\Pipeline\PipelineBuilder;
use Enaxon\Reqtide\Policy\AttemptTimeoutRequirement;
use Enaxon\Reqtide\Policy\BackoffMode;
use Enaxon\Reqtide\Policy\JitterMode;
use Enaxon\Reqtide\Runtime\AttemptTimeoutCapability;
use Enaxon\Reqtide\Runtime\CancellationCapability;
use Enaxon\Reqtide\Runtime\InvocationForm;
use Enaxon\Reqtide\Runtime\QueueOwnership;
use Enaxon\Reqtide\Runtime\SettlementCapability;
use Enaxon\Reqtide\Value\Duration;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Synchronous throw and return, a timeout that honors the remaining budget, rejection of an unsupported
 * capability and deadlines through a real Core pipeline on the Guzzle runtime and the fixture server.
 */
final class GuzzleSyncRuntimeAdapterTest extends TestCase
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

    private function builder(AttemptTimeoutRequirement $requirement, Duration $attemptTimeout): PipelineBuilder
    {
        return PipelineBuilder::profile('http-sync', 'v1')
            ->partition('test', 'local', 'fixture')
            ->attemptTimeout($attemptTimeout, $requirement)
            ->retry(3, BackoffMode::Constant, Duration::milliseconds(5), Duration::seconds(2), JitterMode::None)
            ->retryBudget(10, 10, Duration::seconds(60), $attemptTimeout->add(Duration::seconds(10)));
    }

    private function pipeline(Duration $attemptTimeout): CompiledPipeline
    {
        $builder = $this->builder(AttemptTimeoutRequirement::TransportEnforced, $attemptTimeout);
        $classifier = HttpOutcomeClassifier::starter();

        return $builder->compile(GuzzleSyncRuntimeAdapter::inMemoryEnvironment($builder->definition(), $classifier));
    }

    /** @return mixed the value, or the exception the pipeline throws */
    private function execute(CompiledPipeline $pipeline, HttpOperation $operation, Duration $budget, CurlTransfer $transfer): mixed
    {
        $metadata = $operation->metadata();
        $context = $pipeline->newContext($budget, $metadata->safety, null, null, $metadata->attributes);

        return $pipeline->executeOperation($operation->attemptOperation($transfer->sender()), $context);
    }

    private function read(string $path): HttpOperation
    {
        return HttpOperation::read(new Request('GET', self::$server->baseUri . $path), 'fixture.read', Duration::seconds(60), HttpOutcomeClassifier::starter()->retryableOutcomeCodes());
    }

    public function testItDeclaresASynchronousInlineRuntimeWithATransportEnforcedTimeout(): void
    {
        $capabilities = (new GuzzleSyncRuntimeAdapter())->capabilities();
        self::assertSame(InvocationForm::Synchronous, $capabilities->invocation);
        self::assertSame(SettlementCapability::InlineReturnOrThrow, $capabilities->settlement);
        self::assertSame(AttemptTimeoutCapability::TransportEnforced, $capabilities->attemptTimeout);
        self::assertSame(CancellationCapability::CheckpointOnly, $capabilities->cancellation);
        self::assertSame(QueueOwnership::None, $capabilities->queueOwnership);
    }

    public function testAPipelineThatRequiresATransportTimeoutIsRejectedWithoutThisRuntime(): void
    {
        $builder = $this->builder(AttemptTimeoutRequirement::TransportEnforced, Duration::seconds(2));
        $this->expectException(CapabilityMismatchException::class);
        $builder->compileInMemory(HttpOutcomeClassifier::starter());
    }

    public function testAResponseIsReturnedAsTheValue(): void
    {
        $response = $this->execute($this->pipeline(Duration::seconds(2)), $this->read('/status/200'), Duration::seconds(10), CurlTransfer::curl());
        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, self::$server->count('/status/200'));
    }

    public function testAnErrorStatusIsAValueNotAnException(): void
    {
        $response = $this->execute($this->pipeline(Duration::seconds(2)), $this->read('/status/404'), Duration::seconds(10), CurlTransfer::curl());
        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(404, $response->getStatusCode());
        self::assertSame(1, self::$server->count('/status/404'), '404 is not retried');
    }

    public function testATransportFailureIsRethrownAsTheOriginalException(): void
    {
        $closed = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($closed);
        $name = (string) stream_socket_get_name($closed, false);
        fclose($closed);
        $operation = HttpOperation::read(new Request('GET', 'http://' . $name . '/x'), 'fixture.read', Duration::seconds(60), HttpOutcomeClassifier::starter()->retryableOutcomeCodes());

        $this->expectException(ConnectException::class);
        $this->execute($this->pipeline(Duration::seconds(2)), $operation, Duration::seconds(10), CurlTransfer::curl());
    }

    public function testTheAttemptTimeoutEndsAHangingAttemptAndTheLastFailureIsTheResult(): void
    {
        $started = hrtime(true);
        try {
            $this->execute($this->pipeline(Duration::milliseconds(300)), $this->read('/never-answer'), Duration::seconds(10), CurlTransfer::curl());
            self::fail('expected the timeout to be the result');
        } catch (ConnectException $e) {
            self::assertSame(28, $e->getHandlerContext()['errno'] ?? null);
        }
        $elapsed = (hrtime(true) - $started) / 1e9;
        self::assertSame(3, self::$server->count('/never-answer'), 'a timeout (errno 28) is retried up to the attempt limit');
        self::assertGreaterThanOrEqual(0.9, $elapsed);
        self::assertLessThan(2.5, $elapsed);
    }

    public function testTheRemainingBudgetBoundsTheWholeOperation(): void
    {
        $started = hrtime(true);
        try {
            $this->execute($this->pipeline(Duration::seconds(20)), $this->read('/never-answer'), Duration::milliseconds(600), CurlTransfer::curl());
            self::fail('expected a failure');
        } catch (ConnectException|DeadlineExceededException) {
            // The transfer failure or the policy exception: either way the operation must be over at its deadline.
        }
        $elapsed = (hrtime(true) - $started) / 1e9;
        self::assertGreaterThanOrEqual(0.5, $elapsed, 'the failure came from the deadline, not from an early error');
        self::assertLessThan(1.5, $elapsed, 'a 20 s attempt timeout cannot outlive a 600 ms deadline');
        self::assertLessThanOrEqual(2, self::$server->count('/never-answer'));
    }
}
