<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Tests\Unit;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Exception\ReqtideException;
use Enaxon\Reqtide\Guzzle\GuzzleConfiguration;
use Enaxon\Reqtide\Guzzle\HttpOperation;
use Enaxon\Reqtide\Guzzle\HttpOutcomeClassifier;
use Enaxon\Reqtide\Guzzle\ReqtideClient;
use Enaxon\Reqtide\Guzzle\Tests\Support\FixtureServer;
use Enaxon\Reqtide\Pipeline\PipelineBuilder;
use Enaxon\Reqtide\Policy\AttemptTimeoutRequirement;
use Enaxon\Reqtide\Policy\BackoffMode;
use Enaxon\Reqtide\Policy\JitterMode;
use Enaxon\Reqtide\Testing\RecordingEventSink;
use Enaxon\Reqtide\Value\Duration;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The public client end to end against the fixture server: the server's own record judges what was dispatched
 * (locally), `explain` sends nothing and no event or summary carries a URL query, a
 * header, a body or a credential.
 */
final class ReqtideClientTest extends TestCase
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

    private function builder(): PipelineBuilder
    {
        return PipelineBuilder::profile('http-client', 'v1')
            ->partition('test', 'local', 'fixture')
            ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::TransportEnforced)
            ->retry(3, BackoffMode::Constant, Duration::milliseconds(5), Duration::seconds(2), JitterMode::None)
            ->retryBudget(10, 10, Duration::seconds(60), Duration::seconds(12));
    }

    /** A pipeline for operations that may be sent once: Core refuses an unsafe operation under a retry of more than one attempt. */
    private function singleAttempt(): PipelineBuilder
    {
        return PipelineBuilder::profile('http-once', 'v1')
            ->partition('test', 'local', 'fixture')
            ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::TransportEnforced)
            ->retry(1, BackoffMode::Constant, Duration::milliseconds(5), Duration::seconds(1), JitterMode::None);
    }

    private function client(?PipelineBuilder $builder = null, ?RecordingEventSink $events = null, ?GuzzleConfiguration $configuration = null): ReqtideClient
    {
        return ReqtideClient::inMemory($builder ?? $this->builder(), $configuration, $events);
    }

    private function read(string $path, ?string $credentialKey = null): HttpOperation
    {
        return HttpOperation::read(
            new Request('GET', self::$server->baseUri . $path),
            'fixture.read',
            Duration::seconds(60),
            HttpOutcomeClassifier::starter()->retryableOutcomeCodes(),
            credentialKey: $credentialKey,
        );
    }

    public function testSendReturnsTheResponseAndTheServerSawOneRequest(): void
    {
        $response = $this->client()->send($this->read('/status/200'), Duration::seconds(10));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, self::$server->count('/status/200'));
    }

    public function testARetriedReadReturnsTheLastResponseAndEveryDispatchIsSeenAtTheServer(): void
    {
        $response = $this->client()->send($this->read('/status/503'), Duration::seconds(10));

        self::assertSame(503, $response->getStatusCode(), 'a 5xx status is a response, not an exception');
        self::assertSame(3, self::$server->count('/status/503'));
    }

    public function testAMutationThatIsNotDeclaredRepeatableIsSentOnce(): void
    {
        $operation = HttpOperation::single(new Request('POST', self::$server->baseUri . '/status/503', [], 'order=42'), 'fixture.create');
        $response = $this->client($this->singleAttempt())->send($operation, Duration::seconds(10));

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(1, self::$server->count('/status/503'));
    }

    public function testSendDetailedReturnsTheCompletionWithTheAttempts(): void
    {
        $completion = $this->client()->sendDetailed($this->read('/status/503'), Duration::seconds(10));

        self::assertCount(3, $completion->attempts);
        self::assertSame(3, self::$server->count('/status/503'));
    }

    public function testATransportFailureIsTheOriginalGuzzleException(): void
    {
        $closed = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($closed);
        $name = (string) stream_socket_get_name($closed, false);
        fclose($closed);
        $operation = HttpOperation::single(new Request('GET', 'http://' . $name . '/x'), 'fixture.read');

        $this->expectException(ConnectException::class);
        $this->client($this->singleAttempt())->send($operation, Duration::seconds(10));
    }

    public function testSendAsyncIsLazyAndSettlesOnlyAfterTheWholeOperation(): void
    {
        $promise = $this->client()->sendAsync($this->read('/status/503'), Duration::seconds(10));

        self::assertInstanceOf(PromiseInterface::class, $promise);
        self::assertSame(PromiseInterface::PENDING, $promise->getState());
        self::assertSame(0, self::$server->count('/status/503'), 'nothing is sent before the promise is waited for');

        $response = $promise->wait();
        self::assertIsObject($response);
        self::assertSame(PromiseInterface::FULFILLED, $promise->getState());
        self::assertSame(3, self::$server->count('/status/503'), 'all attempts ran before the promise settled');
    }

    public function testARejectedPromiseCarriesTheOriginalFailure(): void
    {
        $closed = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($closed);
        $name = (string) stream_socket_get_name($closed, false);
        fclose($closed);
        $promise = $this->client($this->singleAttempt())->sendAsync(HttpOperation::single(new Request('GET', 'http://' . $name . '/x'), 'fixture.read'), Duration::seconds(10));

        $this->expectException(ConnectException::class);
        $promise->wait();
    }

    public function testThePolicyStateIsSharedByTheSynchronousAndThePromiseRuntime(): void
    {
        $builder = $this->builder()->rateLimit(PipelineBuilder::tokenBucket(1, 1, Duration::seconds(3600)));
        $client = $this->client($builder);

        $first = $client->send($this->read('/status/200'), Duration::seconds(5));
        self::assertSame(200, $first->getStatusCode());

        try {
            $client->sendAsync($this->read('/status/200'), Duration::seconds(1))->wait();
            self::fail('The single token was used by the synchronous call.');
        } catch (ReqtideException) {
        }
        self::assertSame(1, self::$server->count('/status/200'), 'the refused call dispatched nothing');
    }

    public function testExplainDescribesBothRuntimesAndSendsNothing(): void
    {
        $client = $this->client($this->builder()->rateLimit(PipelineBuilder::tokenBucket(5, 5, Duration::seconds(1))));

        $sync = $client->explain();
        $async = $client->explain(true);

        self::assertContains('rate_limit', $sync->enabledPolicies);
        self::assertContains('retry_budget', $sync->enabledPolicies);
        self::assertContains('invocation=synchronous', $sync->capabilityNotes);
        self::assertContains('invocation=observed_promise', $async->capabilityNotes);
        self::assertSame([], self::$server->log(), 'explain performs no transfer');
    }

    public function testTheTransportEnforcesTheAttemptTimeoutWhateverTheRequirementSays(): void
    {
        $builder = PipelineBuilder::profile('http-client', 'v1')
            ->partition('test', 'local', 'fixture')
            ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::Cooperative)
            ->retry(1, BackoffMode::Constant, Duration::milliseconds(5), Duration::seconds(1), JitterMode::None);

        $client = $this->client($builder);
        self::assertContains('attempt_timeout=transport_enforced', $client->explain()->capabilityNotes);
        self::assertContains('attempt_timeout=transport_enforced', $client->explain(true)->capabilityNotes);
    }

    public function testReferencesAreResolvedAgainstTheBaseUri(): void
    {
        $client = $this->client(null, null, new GuzzleConfiguration(baseUri: 'http://api.example.test:8080/v2/'));

        self::assertSame('http://api.example.test:8080/v2/items?page=2', (string) $client->uri('items?page=2'));
        self::assertSame('http://api.example.test:8080/health', (string) $client->uri('/health'));
        self::assertSame('https://other.example.test/x', (string) $client->uri('https://other.example.test/x'));
    }

    public function testARelativeReferenceWithoutABaseUriIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->client()->uri('/items');
    }

    public function testTheConfigurationRefusesAHandlerAndAnUnusableBaseUri(): void
    {
        foreach ([
            static fn(): GuzzleConfiguration => new GuzzleConfiguration(clientDefaults: ['handler' => static fn() => null]),
            static fn(): GuzzleConfiguration => new GuzzleConfiguration(baseUri: '/relative'),
            static fn(): GuzzleConfiguration => new GuzzleConfiguration(baseUri: 'ftp://files.example.test/'),
        ] as $build) {
            try {
                $build();
                self::fail('The configuration must be refused.');
            } catch (InvalidConfigurationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testNoEventSummaryOrPolicyFailureCarriesAQueryAHeaderABodyOrACredential(): void
    {
        $events = new RecordingEventSink();
        $client = $this->client(null, $events);
        $onceClient = $this->client($this->singleAttempt(), $events);
        $secrets = ['CANARY_QUERY_91', 'CANARY_HEADER_92', 'CANARY_BODY_93', 'CANARY_KEY_94'];

        $read = HttpOperation::read(
            new Request('GET', self::$server->baseUri . '/status/503?token=CANARY_QUERY_91', ['Authorization' => 'Bearer CANARY_HEADER_92']),
            'fixture.read',
            Duration::seconds(60),
            HttpOutcomeClassifier::starter()->retryableOutcomeCodes(),
            credentialKey: 'CANARY_KEY_94',
        );
        $post = HttpOperation::single(
            new Request('POST', self::$server->baseUri . '/status/500?k=CANARY_QUERY_91', ['Authorization' => 'Bearer CANARY_HEADER_92'], 'secret=CANARY_BODY_93'),
            'fixture.create',
            'CANARY_KEY_94',
        );
        $closed = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($closed);
        $name = (string) stream_socket_get_name($closed, false);
        fclose($closed);
        $refused = HttpOperation::single(new Request('GET', 'http://' . $name . '/x?token=CANARY_QUERY_91', ['Authorization' => 'Bearer CANARY_HEADER_92']), 'fixture.read', 'CANARY_KEY_94');

        // What Reqtide itself produced: the summary, the diagnostics, the problem, the policy failure and the events. The
        // business outcome (the caller's own response or Guzzle exception, whose message holds the URL) is the caller's.
        $dump = '';
        foreach ([[$client, $read], [$onceClient, $post], [$onceClient, $refused]] as [$sender, $operation]) {
            $completion = $sender->sendDetailed($operation, Duration::seconds(10));
            $dump .= print_r([$completion->summary, $completion->diagnostics, $completion->problem(), $completion->policyCause], true);
        }
        $dump .= print_r($events->events(), true);
        $dump .= print_r($client->explain(), true);

        self::assertGreaterThan(5, count($events->events()), 'the events were produced and are part of the dump');
        self::assertNotSame('', $dump);
        foreach ($secrets as $secret) {
            self::assertStringNotContainsString($secret, $dump, 'the canary ' . $secret . ' must not appear in any event or summary');
        }
    }
    /** @return iterable<string, array{string}> */
    public static function refusedDefaults(): iterable
    {
        foreach (['delay', 'query', 'body', 'json', 'form_params', 'multipart', 'sink', 'curl', 'on_headers', 'on_stats', 'progress', 'cookies', 'timeout', 'connect_timeout', 'read_timeout', 'http_errors', 'allow_redirects', 'base_uri', 'stream', 'expect', 'debug'] as $option) {
            yield $option => [$option];
        }
    }

    #[DataProvider('refusedDefaults')]
    public function testAClientDefaultOutsideTheAllowedSetIsRefusedByName(string $option): void
    {
        try {
            new GuzzleConfiguration(clientDefaults: [$option => 1]);
            self::fail('The client default must be refused.');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString('"' . $option . '"', $e->getMessage());
        }
    }

    public function testEveryAllowedClientDefaultIsAccepted(): void
    {
        $configuration = new GuzzleConfiguration(clientDefaults: array_fill_keys(GuzzleConfiguration::ALLOWED_CLIENT_DEFAULTS, null));
        self::assertSame(GuzzleConfiguration::ALLOWED_CLIENT_DEFAULTS, array_keys($configuration->clientDefaults));
    }

    public function testAnAbsoluteOrProtocolRelativeReferenceReplacesTheBaseHostAndRfc3986ResolutionApplies(): void
    {
        $client = $this->client(null, null, new GuzzleConfiguration(baseUri: 'https://h.example.test/v1'));

        self::assertSame('https://h.example.test/orders', (string) $client->uri('orders'), 'the last segment of the base path is replaced');
        self::assertSame('https://other.example.test/x', (string) $client->uri('//other.example.test/x'));
        self::assertSame('http://other.example.test/x', (string) $client->uri('http://other.example.test/x'));
    }

    public function testAMalformedReferenceOrBaseUriIsAConfigurationError(): void
    {
        $client = $this->client(null, null, new GuzzleConfiguration(baseUri: 'https://h.example.test/v1'));
        foreach (['http://', 'http://:80', '//:99999999/x'] as $bad) {
            try {
                $client->uri($bad);
                self::fail('Expected a refusal for ' . $bad);
            } catch (InvalidConfigurationException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidConfigurationException::class);
        new GuzzleConfiguration(baseUri: 'http://:99999999');
    }
}
