<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Tests\Unit;

use Enaxon\Reqtide\Guzzle\Tests\Support\FixtureServer;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use PHPUnit\Framework\TestCase;

/**
 * The TLS mode of the fixture server with a per-run test CA, and what Guzzle 7.15 with the cURL easy handler reports for
 * each verification failure. A handshake that fails never reaches the request log, so the server-side count stays 0.
 */
final class FixtureTlsTest extends TestCase
{
    private static string $certificates;

    private static FixtureServer $server;

    public static function setUpBeforeClass(): void
    {
        self::$certificates = FixtureServer::makeCertificates();
        self::$server = FixtureServer::startTls(self::$certificates);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
        foreach (['ca.crt', 'server.crt', 'server.key', 'other-ca.crt'] as $file) {
            @unlink(self::$certificates . '/' . $file);
        }
        @rmdir(self::$certificates);
    }

    protected function setUp(): void
    {
        self::$server->reset();
    }

    private static function errno(GuzzleException $e): int
    {
        self::assertTrue($e instanceof RequestException || $e instanceof ConnectException);
        $context = $e->getHandlerContext();

        return is_int($context['errno'] ?? null) ? $context['errno'] : -1;
    }

    /** @param array<string, mixed> $options */
    private static function client(array $options): Client
    {
        return new Client($options + ['handler' => HandlerStack::create(new CurlHandler()), 'http_errors' => false, 'timeout' => 5.0, 'connect_timeout' => 2.0]);
    }

    private static function port(): string
    {
        return substr(self::$server->baseUri, (int) strrpos(self::$server->baseUri, ':') + 1);
    }

    public function testARequestVerifiedAgainstTheTestCaIsAnsweredAndRecorded(): void
    {
        $response = self::$server->client()->get('/status/204');

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(1, self::$server->count('/status/204'));
    }

    public function testACertificateFromAnotherCaFailsVerificationBeforeAnyRequestIsDispatched(): void
    {
        try {
            self::client(['verify' => self::$certificates . '/other-ca.crt'])->get(self::$server->baseUri . '/status/204');
            self::fail('verification against an unrelated CA must fail');
        } catch (GuzzleException $e) {
            self::assertSame(60, self::errno($e));
        }

        self::assertSame(0, self::$server->count('/status/204'));
    }

    public function testAHostNameThatIsNotInTheCertificateFailsVerification(): void
    {
        try {
            self::client(['verify' => self::$certificates . '/ca.crt'])->get('https://127.0.0.1:' . self::port() . '/status/204');
            self::fail('a name mismatch must fail');
        } catch (GuzzleException $e) {
            self::assertSame(60, self::errno($e));
        }

        self::assertSame(0, self::$server->count('/status/204'));
    }

    public function testPlainHttpToTheTlsPortEndsInAReceiveErrorAndIsNotRecorded(): void
    {
        try {
            $response = self::client([])->get('http://localhost:' . self::port() . '/status/204');
            self::fail('plain HTTP to a TLS port must not succeed, got ' . $response->getStatusCode());
        } catch (GuzzleException $e) {
            self::assertSame(56, self::errno($e));
        }

        self::assertSame(0, self::$server->count('/status/204'));
    }
}
