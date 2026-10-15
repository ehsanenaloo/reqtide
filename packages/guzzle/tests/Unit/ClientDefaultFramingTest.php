<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Tests\Unit;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Guzzle\GuzzleConfiguration;
use Enaxon\Reqtide\Guzzle\HttpOperation;
use Enaxon\Reqtide\Guzzle\ReqtideClient;
use Enaxon\Reqtide\Guzzle\Tests\Support\FixtureServer;
use Enaxon\Reqtide\Pipeline\PipelineBuilder;
use Enaxon\Reqtide\Policy\AttemptTimeoutRequirement;
use Enaxon\Reqtide\Policy\BackoffMode;
use Enaxon\Reqtide\Policy\JitterMode;
use Enaxon\Reqtide\Value\Duration;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A client default must not decide how the body is framed: the transport derives that from the body that is sent.
 */
final class ClientDefaultFramingTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function framingHeaders(): iterable
    {
        yield 'Content-Length' => ['Content-Length'];
        yield 'lower case' => ['content-length'];
        yield 'upper case' => ['CONTENT-LENGTH'];
        yield 'Transfer-Encoding' => ['Transfer-Encoding'];
        yield 'mixed case' => ['tRaNsFeR-eNcOdInG'];
    }

    #[DataProvider('framingHeaders')]
    public function testAFramingHeaderAsAClientDefaultIsRefusedByName(string $name): void
    {
        try {
            new GuzzleConfiguration(clientDefaults: ['headers' => ['Accept' => 'application/json', $name => '1000000']]);
            self::fail('expected an exception');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString('framing', $e->getMessage());
            self::assertStringNotContainsString('1000000', $e->getMessage());
        }
    }

    public function testOtherDefaultHeadersAndNonArrayHeaderValuesAreStillAccepted(): void
    {
        $configuration = new GuzzleConfiguration(clientDefaults: ['headers' => ['Accept' => 'application/json', 'X-Api-Key' => 'k']]);
        $headers = $configuration->clientDefaults['headers'];
        self::assertIsArray($headers);
        self::assertSame(['Accept', 'X-Api-Key'], array_keys($headers));
        self::assertNull((new GuzzleConfiguration(clientDefaults: ['headers' => null]))->clientDefaults['headers']);
    }

    public function testALargeBodyArrivesWholeAtTheServerWithTheFramingOfTheBodyItself(): void
    {
        $server = FixtureServer::start();
        try {
            $builder = PipelineBuilder::profile('framing', 'v1')
                ->partition('test', 'local', 'fixture')
                ->attemptTimeout(Duration::seconds(5), AttemptTimeoutRequirement::TransportEnforced)
                ->retry(1, BackoffMode::Constant, Duration::milliseconds(5), Duration::seconds(1), JitterMode::None);
            $client = ReqtideClient::inMemory($builder, new GuzzleConfiguration(clientDefaults: ['headers' => ['Accept' => 'application/json']]));
            $body = str_repeat('a', 2_000_000);
            $operation = HttpOperation::single(new Request('POST', $server->baseUri . '/echo', [], Utils::streamFor($body)), 'framing.upload');

            $client->send($operation, Duration::seconds(10));

            $log = $server->log();
            self::assertCount(1, $log);
            self::assertSame(2_000_000, $log[0]['bodyLength']);
            self::assertSame(sha1($body), $log[0]['bodySha1']);
        } finally {
            $server->stop();
        }
    }
}
