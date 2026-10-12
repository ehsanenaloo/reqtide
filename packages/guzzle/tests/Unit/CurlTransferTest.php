<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Tests\Unit;

use Enaxon\Reqtide\Exception\DeadlineExceededException;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Guzzle\HttpOperation;
use Enaxon\Reqtide\Guzzle\Tests\Support\FixtureServer;
use Enaxon\Reqtide\Guzzle\Tests\Support\RecordingClient;
use Enaxon\Reqtide\Guzzle\Transport\CurlTransfer;
use Enaxon\Reqtide\Runtime\AttemptControls;
use Enaxon\Reqtide\Testing\VirtualClock;
use Enaxon\Reqtide\Time\CancellationToken;
use Enaxon\Reqtide\Time\Clock;
use Enaxon\Reqtide\Time\Deadline;
use Enaxon\Reqtide\Time\SystemClock;
use Enaxon\Reqtide\Value\AttemptId;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\OperationId;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * How one synchronous transfer is set up, checked on the options a double records and then
 * on the wire against the fixture server.
 */
final class CurlTransferTest extends TestCase
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

    /** @phpstan-impure */
    private static function firstSeconds(CurlTransfer $transfer): ?float
    {
        return $transfer->firstTransferSeconds();
    }

    /** @return array{AttemptContext, AttemptControls} */
    private function attempt(Clock $clock, Duration $attemptTimeout, Duration $budget): array
    {
        $deadline = Deadline::after($budget, $clock);
        $cancellation = new CancellationToken();

        return [
            new AttemptContext(new OperationId('op-1'), AttemptId::fromLowerHex(str_repeat('b', 32)), 1, $deadline, $cancellation, $attemptTimeout),
            new AttemptControls($attemptTimeout, $deadline, $cancellation, $clock),
        ];
    }

    /** @return array<string, mixed> */
    private function optionsFor(Duration $attemptTimeout, Duration $budget, ?Duration $elapsed = null, ?Request $request = null): array
    {
        $clock = new VirtualClock();
        $client = new RecordingClient();
        [$context, $controls] = $this->attempt($clock, $attemptTimeout, $budget);
        if ($elapsed !== null) {
            $clock->advance($elapsed);
        }
        CurlTransfer::forClient($client)->send($request ?? new Request('GET', 'https://api.example.com/x'), $context, $controls);

        return $client->sends[0]['options'];
    }

    public function testTheTimeoutIsTheSmallerOfTheAttemptTimeoutAndTheTimeLeft(): void
    {
        $options = $this->optionsFor(Duration::seconds(2), Duration::seconds(10));
        self::assertSame(2.0, $options['timeout']);
        self::assertSame(2.0, $options['connect_timeout']);

        $left = $this->optionsFor(Duration::seconds(2), Duration::seconds(10), Duration::milliseconds(9_700));
        self::assertEqualsWithDelta(0.3, $left['timeout'], 1e-9, 'only 300 ms of the budget remain');
        self::assertEqualsWithDelta(0.3, $left['connect_timeout'], 1e-9);
    }

    public function testTheTimeoutIsNeverZeroAndReadTimeoutIsNeverSet(): void
    {
        $almost = $this->optionsFor(Duration::seconds(2), Duration::seconds(1), Duration::nanoseconds(999_999_999));
        self::assertSame(0.001, $almost['timeout'], 'one nanosecond left is sent as the 1 ms granularity of cURL; zero would mean "wait forever"');
        self::assertArrayNotHasKey('read_timeout', $almost);
        self::assertArrayNotHasKey('read_timeout', $this->optionsFor(Duration::seconds(2), Duration::seconds(10)));
        self::assertSame(0.001, $this->optionsFor(Duration::seconds(2), Duration::seconds(1), Duration::nanoseconds(999_000_001))['timeout']);
        self::assertSame(0.001, $this->optionsFor(Duration::seconds(2), Duration::seconds(1), Duration::nanoseconds(999_000_000))['timeout']);
    }

    /** @return iterable<string, array{int}> */
    public static function exhausted(): iterable
    {
        yield 'deadline reached' => [1_000_000_000];
        yield 'deadline passed' => [5_000_000_000];
    }

    #[DataProvider('exhausted')]
    public function testNoTransferStartsWhenTheDeadlineHasBeenReached(int $elapsedNanoseconds): void
    {
        $clock = new VirtualClock();
        $client = new RecordingClient();
        [$context, $controls] = $this->attempt($clock, Duration::seconds(2), Duration::seconds(1));
        $clock->advance(Duration::nanoseconds($elapsedNanoseconds));

        try {
            CurlTransfer::forClient($client)->send(new Request('GET', 'https://api.example.com/x'), $context, $controls);
            self::fail('expected the transfer to be refused');
        } catch (DeadlineExceededException) {
            self::assertSame([], $client->sends, 'nothing was handed to Guzzle');
        }
    }

    public function testResponsesAreValuesAndRedirectsAreNotFollowed(): void
    {
        $options = $this->optionsFor(Duration::seconds(2), Duration::seconds(10));
        self::assertFalse($options['http_errors'], 'the option cannot change what an outcome means');
        self::assertFalse($options['allow_redirects'], 'redirects are not followed');
        self::assertIsCallable($options['on_stats']);
    }

    public function testASizedBodyGetsAnEmptyExpectHeaderAndAnUnsizedBodyDoesNot(): void
    {
        $clock = new VirtualClock();
        $client = new RecordingClient();
        $transfer = CurlTransfer::forClient($client);
        [$context, $controls] = $this->attempt($clock, Duration::seconds(2), Duration::seconds(10));

        $transfer->send(new Request('POST', 'https://api.example.com/x', [], 'abc'), $context, $controls);
        $transfer->send(new Request('POST', 'https://api.example.com/x', [], new PumpStream(static fn(): false => false)), $context, $controls);
        $transfer->send(new Request('POST', 'https://api.example.com/x', ['Expect' => '100-continue'], 'abc'), $context, $controls);

        self::assertSame([''], $client->sends[0]['request']->getHeader('Expect'));
        self::assertFalse($client->sends[1]['request']->hasHeader('Expect'));
        self::assertSame(['100-continue'], $client->sends[2]['request']->getHeader('Expect'), 'an explicit Expect header is respected');
    }

    public function testTheClientDefaultsCannotReplaceTheHandler(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        CurlTransfer::curl(['handler' => HandlerStack::create()]);
    }

    public function testTheFirstTransferIsTimedOnceAndTheSenderIsTheSameCallable(): void
    {
        $transfer = CurlTransfer::curl();
        self::assertNull(self::firstSeconds($transfer));
        [$context, $controls] = $this->attempt(new VirtualClock(), Duration::seconds(2), Duration::seconds(10));
        $sender = $transfer->sender();

        $sender(new Request('GET', self::$server->baseUri . '/status/200'), $context, $controls);
        $first = self::firstSeconds($transfer);
        self::assertNotNull($first);
        self::assertGreaterThan(0.0, $first);
        $sender(new Request('GET', self::$server->baseUri . '/status/200'), $context, $controls);
        self::assertSame($first, self::firstSeconds($transfer), 'only the first transfer is recorded');
    }

    public function testAnAttemptTimeoutEndsTheTransferAtTheTimeoutOnTheWire(): void
    {
        $transfer = CurlTransfer::curl();
        [$context, $controls] = $this->attempt(new SystemClock(), Duration::milliseconds(300), Duration::seconds(30));
        $started = hrtime(true);
        try {
            $transfer->send(new Request('GET', self::$server->baseUri . '/slow?ms=3000'), $context, $controls);
            self::fail('expected a timeout');
        } catch (ConnectException $e) {
            self::assertSame(28, $e->getHandlerContext()['errno'] ?? null);
            self::assertLessThan(1.0, (hrtime(true) - $started) / 1e9);
            self::assertGreaterThanOrEqual(0.25, (hrtime(true) - $started) / 1e9);
        }
    }

    public function testTheRemainingBudgetBoundsTheTransferWhenItIsShorterThanTheAttemptTimeout(): void
    {
        [$context, $controls] = $this->attempt(new SystemClock(), Duration::seconds(20), Duration::milliseconds(400));
        $started = hrtime(true);
        try {
            CurlTransfer::curl()->send(new Request('GET', self::$server->baseUri . '/slow?ms=5000'), $context, $controls);
            self::fail('expected a timeout');
        } catch (ConnectException) {
            $elapsed = (hrtime(true) - $started) / 1e9;
            self::assertLessThan(1.2, $elapsed, 'the 20 s attempt timeout did not apply: the 400 ms budget did');
            self::assertGreaterThanOrEqual(0.3, $elapsed);
        }
    }

    public function testALargeSizedBodyIsSentWithoutAskingForContinue(): void
    {
        $body = str_repeat('x', 2 * 1024 * 1024);
        [$context, $controls] = $this->attempt(new SystemClock(), Duration::seconds(5), Duration::seconds(10));
        $started = hrtime(true);
        CurlTransfer::curl()->send(new Request('POST', self::$server->baseUri . '/echo', [], Utils::streamFor($body)), $context, $controls);

        $log = self::$server->log();
        self::assertSame(2 * 1024 * 1024, $log[0]['bodyLength']);
        self::assertIsArray($log[0]['headers']);
        self::assertSame([''], $log[0]['headers']['expect'], 'Guzzle sends the empty Expect header as given, which stops cURL from asking for 100-continue');
        self::assertFalse($log[0]['expectContinueSent'], 'no 100-continue handshake took place');
        self::assertLessThan(1.0, (hrtime(true) - $started) / 1e9);
    }

    public function testAnUnsupportedHandlerIsRejectedAfterTheFirstTransferAndBeforeEveryLaterOne(): void
    {
        $client = new Client(['handler' => HandlerStack::create(new StreamHandler())]);
        $transfer = CurlTransfer::forClient($client);
        [$context, $controls] = $this->attempt(new SystemClock(), Duration::seconds(2), Duration::seconds(10));

        try {
            $transfer->send(new Request('GET', self::$server->baseUri . '/status/200'), $context, $controls);
            self::fail('expected the stream handler to be rejected');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString('cURL', $e->getMessage());
        }
        self::assertSame(1, self::$server->count('/status/200'), 'the first transfer was already sent: a client you build can only be verified afterwards');

        try {
            $transfer->send(new Request('GET', self::$server->baseUri . '/status/200'), $context, $controls);
            self::fail('expected a refusal');
        } catch (InvalidConfigurationException) {
            self::assertSame(1, self::$server->count('/status/200'), 'later transfers are refused before they are sent');
        }
    }

    public function testACurlHandlerClientIsAccepted(): void
    {
        $client = new Client(['handler' => HandlerStack::create(new \GuzzleHttp\Handler\CurlHandler())]);
        [$context, $controls] = $this->attempt(new SystemClock(), Duration::seconds(2), Duration::seconds(10));
        $response = CurlTransfer::forClient($client)->send(new Request('GET', self::$server->baseUri . '/status/204'), $context, $controls);
        self::assertSame(204, $response->getStatusCode());
    }
    public function testNonReadsAskForAFreshConnectionAndReadsDoNot(): void
    {
        $client = new RecordingClient();
        $transfer = CurlTransfer::forClient($client);
        [$context, $controls] = $this->attempt(new VirtualClock(), Duration::seconds(2), Duration::seconds(10));

        $transfer->send(new Request('POST', 'https://api.example.com/x', [], 'abc'), $context, $controls);
        $transfer->send(new Request('GET', 'https://api.example.com/x'), $context, $controls, true);

        self::assertSame([CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true], $client->sends[0]['options']['curl']);
        self::assertArrayNotHasKey('curl', $client->sends[1]['options']);
    }

    public function testTheOperationKindDecidesWhetherAConnectionMayBeReused(): void
    {
        $seen = [];
        $send = static function (RequestInterface $request, AttemptContext $context, ?AttemptControls $controls, bool $mayReuse) use (&$seen): Response {
            $seen[] = $mayReuse;

            return new Response(200);
        };
        [$context] = $this->attempt(new VirtualClock(), Duration::seconds(2), Duration::seconds(10));
        $post = new Request('POST', 'https://api.example.com/x', [], 'abc');
        $get = new Request('GET', 'https://api.example.com/x');

        HttpOperation::single($post, 'g')->attemptOperation($send)->prepare($context)->invoke($context);
        HttpOperation::idempotentMutation($post->withHeader('Idempotency-Key', 'k1'), 'g', 'k1', 'scope', 'dedup', Duration::seconds(60))->attemptOperation($send)->prepare($context)->invoke($context);
        HttpOperation::read($get, 'g', Duration::seconds(60))->attemptOperation($send)->prepare($context)->invoke($context);

        self::assertSame([false, true, true], $seen, 'only an operation with no safety statement forbids reuse');
    }

    public function testAPostOnAStaleReusedConnectionReachesTheServerOnceOnlyWithAFreshConnection(): void
    {
        [$context, $controls] = $this->attempt(new SystemClock(), Duration::seconds(2), Duration::seconds(10));

        // Control: with reuse allowed, the server sees the request twice. The first one arrives on the kept-alive connection and is
        // dropped without a reply; libcurl then resends it on a new connection by itself (Curl_retry_request).
        $reusing = CurlTransfer::curl();
        $reusing->send(new Request('GET', self::$server->baseUri . '/keepalive'), $context, $controls, true);
        try {
            $reusing->send(new Request('POST', self::$server->baseUri . '/close-no-reply', [], 'abc'), $context, $controls, true);
            self::fail('expected an empty reply');
        } catch (ConnectException|RequestException) {
        }
        self::assertSame(2, self::$server->count('/close-no-reply'), 'libcurl resent the POST silently');

        self::$server->reset();
        $fresh = CurlTransfer::curl();
        $fresh->send(new Request('GET', self::$server->baseUri . '/keepalive'), $context, $controls, true);
        try {
            $fresh->send(new Request('POST', self::$server->baseUri . '/close-no-reply', [], 'abc'), $context, $controls);
            self::fail('expected an empty reply');
        } catch (ConnectException|RequestException) {
        }
        self::assertSame(1, self::$server->count('/close-no-reply'), 'a fresh connection leaves nothing to retry');
        $connections = array_unique(array_map(static fn(array $entry): int => is_int($entry['connection']) ? $entry['connection'] : -1, self::$server->log()));
        self::assertCount(2, $connections, 'the POST did not use the connection of the GET');
    }
}
