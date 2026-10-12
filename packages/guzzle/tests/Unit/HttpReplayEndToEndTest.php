<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Tests\Unit;

use Enaxon\Reqtide\Completion\ExecutionCompletion;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Exception\PreparationFailedException;
use Enaxon\Reqtide\Guzzle\BodyIntegrity;
use Enaxon\Reqtide\Guzzle\HttpOperation;
use Enaxon\Reqtide\Guzzle\HttpOutcomeClassifier;
use Enaxon\Reqtide\Guzzle\Tests\Support\CloseSpyStream;
use Enaxon\Reqtide\Guzzle\Tests\Support\FixtureServer;
use Enaxon\Reqtide\Guzzle\Tests\Support\MutableBodyStream;
use Enaxon\Reqtide\Pipeline\CompiledPipeline;
use Enaxon\Reqtide\Pipeline\PipelineBuilder;
use Enaxon\Reqtide\Policy\AttemptTimeoutRequirement;
use Enaxon\Reqtide\Policy\BackoffMode;
use Enaxon\Reqtide\Policy\JitterMode;
use Enaxon\Reqtide\Value\Duration;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * End to end: a real Core pipeline with retry, a real Guzzle transfer, and the fixture
 * server's own record as the judge of what was actually dispatched.
 */
final class HttpReplayEndToEndTest extends TestCase
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

    /** @param array<string, mixed> $entry */
    private static function atMs(array $entry): int
    {
        self::assertIsInt($entry['atMs']);

        return $entry['atMs'];
    }

    /** @return list<string> */
    private static function codes(): array
    {
        return HttpOutcomeClassifier::starter()->retryableOutcomeCodes();
    }

    private function pipeline(): CompiledPipeline
    {
        return PipelineBuilder::profile('http-replay', 'v1')
            ->partition('test', 'local', 'fixture')
            ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::Cooperative)
            ->retry(3, BackoffMode::Constant, Duration::milliseconds(5), Duration::seconds(2), JitterMode::None)
            ->retryBudget(10, 10, Duration::seconds(60), Duration::seconds(10))
            ->compileInMemory(HttpOutcomeClassifier::starter());
    }

    /** @return \Closure(RequestInterface): ResponseInterface */
    private function transfer(): \Closure
    {
        $client = self::$server->client(2.0);

        return static fn(RequestInterface $request): ResponseInterface => $client->send($request);
    }

    /** @return ExecutionCompletion<ResponseInterface> */
    private function runDetailed(HttpOperation $operation, ?\Closure $send = null): ExecutionCompletion
    {
        $pipeline = $this->pipeline();
        $metadata = $operation->metadata();
        $context = $pipeline->newContext(Duration::seconds(10), $metadata->safety, null, null, $metadata->attributes);

        return $pipeline->executeOperationDetailed($operation->attemptOperation($send ?? $this->transfer()), $context);
    }

    private function runPlain(HttpOperation $operation): mixed
    {
        $pipeline = $this->pipeline();
        $metadata = $operation->metadata();
        $context = $pipeline->newContext(Duration::seconds(10), $metadata->safety, null, null, $metadata->attributes);

        return $pipeline->executeOperation($operation->attemptOperation($this->transfer()), $context);
    }

    public function testAReadIsRetriedAndEveryDispatchIsSeenAtTheServer(): void
    {
        $operation = HttpOperation::read(new Request('GET', self::$server->baseUri . '/status/503'), 'fixture.read', Duration::seconds(60), self::codes());
        $this->runDetailed($operation);

        self::assertSame(3, self::$server->count('/status/503'), 'three attempts, three real dispatches');
    }

    public function testARetriedMutationSendsTheSameBodyAndTheSameIdentityEveryTime(): void
    {
        $operation = HttpOperation::idempotentMutation(
            new Request('POST', self::$server->baseUri . '/status/503', ['Idempotency-Key' => 'key-7f3a'], 'order=42&qty=3'),
            'fixture.create',
            'key-7f3a',
            'test account and endpoint',
            'returns the stored response',
            Duration::seconds(60),
            retryableOutcomeCodes: self::codes(),
        );
        $this->runDetailed($operation);

        $log = self::$server->log();
        self::assertCount(3, $log);
        self::assertSame([14, 14, 14], array_column($log, 'bodyLength'));
        self::assertSame(array_fill(0, 3, sha1('order=42&qty=3')), array_column($log, 'bodySha1'), 'bodies identical in length and content');
        foreach ($log as $entry) {
            self::assertIsArray($entry['headers']);
            self::assertSame(['key-7f3a'], $entry['headers']['idempotency-key'], 'the same identity on every attempt');
        }
    }

    public function testAFactoryBodyIsRecreatedForEveryAttemptAndArrivesWhole(): void
    {
        // A known-size body from a factory: a fresh in-memory stream each time.
        $operation = HttpOperation::idempotentMutation(
            new Request('POST', self::$server->baseUri . '/status/503', ['Idempotency-Key' => 'key-9'], 'placeholder'),
            'fixture.create',
            'key-9',
            's',
            'd',
            Duration::seconds(60),
            retryableOutcomeCodes: self::codes(),
            bodyFactory: static fn() => Utils::streamFor('factory-made-body'),
        );
        $this->runDetailed($operation);

        self::assertSame([17, 17, 17], array_column(self::$server->log(), 'bodyLength'));
    }

    public function testABodyThatBecomesEmptyAfterTheFirstAttemptIsNeverSentEmptyWithTheSameIdentity(): void
    {
        $body = new MutableBodyStream('amount=100');
        $operation = HttpOperation::idempotentMutation(
            new Request('POST', self::$server->baseUri . '/status/503', ['Idempotency-Key' => 'key-3'], $body),
            'fixture.create',
            'key-3',
            's',
            'd',
            Duration::seconds(60),
            retryableOutcomeCodes: self::codes(),
        );
        $send = $this->transfer();

        $completion = $this->runDetailed($operation, static function (RequestInterface $request) use ($send, $body): ResponseInterface {
            $response = $send($request);
            $body->replace('');

            return $response;
        });

        $log = self::$server->log();
        self::assertCount(1, $log, 'the second attempt was refused before dispatch');
        self::assertSame(10, $log[0]['bodyLength']);
        self::assertInstanceOf(PreparationFailedException::class, $completion->policyCause);
    }

    public function testABodyWithOtherBytesOfTheSameSizeIsNeverSentWithTheSameIdentity(): void
    {
        $body = new MutableBodyStream('amount=100');
        $operation = HttpOperation::idempotentMutation(
            new Request('POST', self::$server->baseUri . '/status/503', ['Idempotency-Key' => 'key-4'], $body),
            'fixture.create',
            'key-4',
            's',
            'd',
            Duration::seconds(60),
            retryableOutcomeCodes: self::codes(),
        );
        $send = $this->transfer();

        $completion = $this->runDetailed($operation, static function (RequestInterface $request) use ($send, $body): ResponseInterface {
            $response = $send($request);
            $body->replace('amount=900');

            return $response;
        });

        $log = self::$server->log();
        self::assertCount(1, $log, 'the second attempt was refused before dispatch');
        self::assertSame(sha1('amount=100'), $log[0]['bodySha1']);
        self::assertInstanceOf(PreparationFailedException::class, $completion->policyCause);
    }

    public function testABodyWithOtherBytesIsSentWhenTheCallerAssertsTheContent(): void
    {
        $body = new MutableBodyStream('amount=100');
        $operation = HttpOperation::idempotentMutation(
            new Request('POST', self::$server->baseUri . '/status/503', ['Idempotency-Key' => 'key-5'], $body),
            'fixture.create',
            'key-5',
            's',
            'd',
            Duration::seconds(60),
            retryableOutcomeCodes: self::codes(),
            bodyIntegrity: BodyIntegrity::CallerAsserted,
        );
        $send = $this->transfer();

        $this->runDetailed($operation, static function (RequestInterface $request) use ($send, $body): ResponseInterface {
            $response = $send($request);
            $body->replace('amount=900');

            return $response;
        });

        self::assertSame([sha1('amount=100'), sha1('amount=900'), sha1('amount=900')], array_column(self::$server->log(), 'bodySha1'));
    }

    public function testANonSeekableMutationBodyWithoutAFactoryIsRefusedAndNothingIsSent(): void
    {
        $pieces = ['abc', 'def'];
        $operation = HttpOperation::idempotentMutation(
            new Request('POST', self::$server->baseUri . '/status/503', ['Idempotency-Key' => 'key-1'], new PumpStream(static function () use (&$pieces): string|false {
                return array_shift($pieces) ?? false;
            })),
            'fixture.create',
            'key-1',
            's',
            'd',
            Duration::seconds(60),
            retryableOutcomeCodes: self::codes(),
        );

        try {
            $this->runPlain($operation);
            self::fail('expected the preparation to be refused');
        } catch (PreparationFailedException) {
            self::assertSame(0, self::$server->count('/status/503'), 'refused before dispatch: the server saw nothing');
        }
    }

    public function testRetryAfterFromTheServerDelaysEveryRetry(): void
    {
        $operation = HttpOperation::read(new Request('GET', self::$server->baseUri . '/status/429?ra=1'), 'fixture.read', Duration::seconds(60), self::codes());
        $this->runDetailed($operation);

        $log = self::$server->log();
        self::assertCount(3, $log);
        foreach ([1, 2] as $i) {
            $gap = self::atMs($log[$i]) - self::atMs($log[$i - 1]);
            self::assertGreaterThanOrEqual(950, $gap, 'never sent before the Retry-After of one second has passed');
        }
    }

    public function testARetryAfterBeyondTheMaximumDelayDefersTheRetryInsteadOfSendingEarly(): void
    {
        $operation = HttpOperation::read(new Request('GET', self::$server->baseUri . '/status/429?ra=5'), 'fixture.read', Duration::seconds(60), self::codes());
        $this->runDetailed($operation);

        self::assertSame(1, self::$server->count('/status/429'), 'a 5 s Retry-After exceeds the 2 s maximum delay: no early second request');
    }

    public function testAMalformedRetryAfterDoesNotDelayTheRetry(): void
    {
        $operation = HttpOperation::read(new Request('GET', self::$server->baseUri . '/status/429?ra=soon'), 'fixture.read', Duration::seconds(60), self::codes());
        $this->runDetailed($operation);

        $log = self::$server->log();
        self::assertCount(3, $log);
        self::assertLessThan(500, self::atMs($log[2]) - self::atMs($log[0]), 'an unreadable value creates no wait');
    }

    public function testASingleOperationIsRefusedByAPipelineThatRetriesAndNothingIsSent(): void
    {
        $operation = HttpOperation::single(new Request('POST', self::$server->baseUri . '/status/503', [], 'once'), 'fixture.once');

        try {
            $this->runPlain($operation);
            self::fail('expected the pipeline to refuse an operation without a replay statement');
        } catch (InvalidConfigurationException) {
            self::assertSame(0, self::$server->count('/status/503'), 'Core refuses it before any admission or dispatch');
        }
    }

    public function testDiscardedResponsesAreClosedAndTheTerminalOneIsNot(): void
    {
        $spies = [];
        $statuses = [503, 503, 200];
        $send = function () use (&$spies, &$statuses): ResponseInterface {
            $spy = new CloseSpyStream(Utils::streamFor('body'));
            $spies[] = $spy;

            return new Response(array_shift($statuses) ?? 200, [], $spy);
        };
        $operation = HttpOperation::read(new Request('GET', 'https://api.example.com/x'), 'fixture.read', Duration::seconds(60), self::codes());
        $completion = $this->runDetailed($operation, $send);

        self::assertCount(3, $spies);
        self::assertSame([1, 1, 0], array_map(static fn(CloseSpyStream $s): int => $s->closed, $spies), 'replaced responses are closed; the response returned to the caller stays open');
        $response = $completion->terminalOutcome?->value();
        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(200, $response->getStatusCode());
    }
}
