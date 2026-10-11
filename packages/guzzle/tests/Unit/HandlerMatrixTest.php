<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Tests\Unit;

use Composer\InstalledVersions;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Guzzle\GuzzleConfiguration;
use Enaxon\Reqtide\Guzzle\HttpOperation;
use Enaxon\Reqtide\Guzzle\HttpOutcomeClassifier;
use Enaxon\Reqtide\Guzzle\ReqtideClient;
use Enaxon\Reqtide\Guzzle\Tests\Support\FixtureServer;
use Enaxon\Reqtide\Guzzle\Transport\CurlTransfer;
use Enaxon\Reqtide\Pipeline\PipelineBuilder;
use Enaxon\Reqtide\Policy\AttemptTimeoutRequirement;
use Enaxon\Reqtide\Policy\BackoffMode;
use Enaxon\Reqtide\Policy\JitterMode;
use Enaxon\Reqtide\Runtime\AttemptControls;
use Enaxon\Reqtide\Time\CancellationToken;
use Enaxon\Reqtide\Time\Deadline;
use Enaxon\Reqtide\Time\SystemClock;
use Enaxon\Reqtide\Value\AttemptId;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\OperationId;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Which handlers and client defaults the client supports, on the PHP lane the suite runs on. The
 * facade owns its cURL handlers, so an unsupported handler has no way in; the transfer layer still rejects one it is given.
 */
final class HandlerMatrixTest extends TestCase
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

    /** @param array<string, mixed> $defaults */
    private function client(array $defaults = []): ReqtideClient
    {
        $builder = PipelineBuilder::profile('http-matrix', 'v1')
            ->partition('test', 'local', 'fixture')
            ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::TransportEnforced)
            ->retry(2, BackoffMode::Constant, Duration::milliseconds(5), Duration::seconds(1), JitterMode::None)
            ->retryBudget(10, 10, Duration::seconds(60), Duration::seconds(12));

        return ReqtideClient::inMemory($builder, new GuzzleConfiguration(clientDefaults: $defaults));
    }

    private function read(string $path): HttpOperation
    {
        return HttpOperation::read(new Request('GET', self::$server->baseUri . $path), 'fixture.read', Duration::seconds(60), HttpOutcomeClassifier::starter()->retryableOutcomeCodes());
    }

    private static function response(mixed $value): ResponseInterface
    {
        self::assertInstanceOf(ResponseInterface::class, $value);

        return $value;
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function clientDefaults(): iterable
    {
        yield 'none' => [[]];
        yield 'default headers' => [['headers' => ['X-Client' => 'matrix']]];
        yield 'http 1.1 and no decoding' => [['version' => '1.1', 'decode_content' => false]];
    }

    /** @param array<string, mixed> $defaults */
    #[DataProvider('clientDefaults')]
    public function testAStatusIsAlwaysAResponseWhateverTheClientDefaultsSay(array $defaults): void
    {
        $client = $this->client($defaults);

        self::assertSame(404, $client->send($this->read('/status/404'), Duration::seconds(5))->getStatusCode());
        self::assertSame(404, self::response($client->sendAsync($this->read('/status/404'), Duration::seconds(5))->wait())->getStatusCode());
        self::assertSame(2, self::$server->count('/status/404'), 'one dispatch for each runtime, no retry of a 404');
    }

    /** @param array<string, mixed> $defaults */
    #[DataProvider('clientDefaults')]
    public function testARedirectIsAResponseAndIsNeverFollowedWhateverTheClientDefaultsSay(array $defaults): void
    {
        $client = $this->client($defaults);
        $path = '/redirect?code=302&to=/status/200';

        self::assertSame(302, $client->send($this->read($path), Duration::seconds(5))->getStatusCode());
        self::assertSame(302, self::response($client->sendAsync($this->read($path), Duration::seconds(5))->wait())->getStatusCode());
        self::assertSame(0, self::$server->count('/status/200'), 'the target of the redirect was never requested');
    }

    public function testAHandlerCannotBeGivenToTheClient(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->client(['handler' => static fn() => Create::promiseFor(new Response(200))]);
    }

    public function testACustomHandlerThatReportsNoTransferStatisticsIsRejectedAtTheTransferLayer(): void
    {
        $calls = 0;
        $client = new Client(['handler' => static function () use (&$calls) {
            ++$calls;

            return Create::promiseFor(new Response(200));
        }]);
        $transfer = CurlTransfer::forClient($client);
        $clock = new SystemClock();
        $deadline = Deadline::after(Duration::seconds(10), $clock);
        $cancellation = new CancellationToken();
        $context = new AttemptContext(new OperationId('op-1'), AttemptId::fromLowerHex(str_repeat('b', 32)), 1, $deadline, $cancellation, Duration::seconds(2));
        $controls = new AttemptControls(Duration::seconds(2), $deadline, $cancellation, $clock);

        try {
            $transfer->send(new Request('GET', self::$server->baseUri . '/status/200'), $context, $controls);
            self::fail('A handler without transfer statistics must be rejected.');
        } catch (InvalidConfigurationException) {
        }
        try {
            $transfer->send(new Request('GET', self::$server->baseUri . '/status/200'), $context, $controls);
            self::fail('A rejected handler must stay rejected.');
        } catch (InvalidConfigurationException) {
        }
        self::assertSame(1, $calls, 'the second transfer was refused before it reached the handler');
    }

    public function testTheLaneFactsOfTheMatrix(): void
    {
        self::assertTrue(extension_loaded('curl'));
        $curl = curl_version();
        self::assertIsArray($curl);
        $version = $curl['version'] ?? '';
        self::assertIsString($version);
        self::assertNotSame('', $version);
        $ssl = $curl['ssl_version'] ?? 'none';
        self::assertIsString($ssl);
        self::assertSame('7', explode('.', (string) InstalledVersions::getVersion('guzzlehttp/guzzle'))[0]);
        fwrite(STDERR, sprintf(
            "\nmatrix lane: php %s, libcurl %s, ssl %s, guzzle %s, psr7 %s, promises %s\n",
            PHP_VERSION,
            $version,
            $ssl,
            InstalledVersions::getPrettyVersion('guzzlehttp/guzzle'),
            InstalledVersions::getPrettyVersion('guzzlehttp/psr7'),
            InstalledVersions::getPrettyVersion('guzzlehttp/promises'),
        ));
    }
}
