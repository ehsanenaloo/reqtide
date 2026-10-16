<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Tests\Wire;

use Enaxon\Reqtide\Guzzle\BodyIntegrity;
use Enaxon\Reqtide\Guzzle\GuzzleConfiguration;
use Enaxon\Reqtide\Guzzle\HttpOperation;
use Enaxon\Reqtide\Guzzle\HttpOutcomeClassifier;
use Enaxon\Reqtide\Guzzle\ReqtideClient;
use Enaxon\Reqtide\Pipeline\PipelineBuilder;
use Enaxon\Reqtide\Policy\AttemptTimeoutRequirement;
use Enaxon\Reqtide\Policy\BackoffMode;
use Enaxon\Reqtide\Policy\JitterMode;
use Enaxon\Reqtide\Value\Duration;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;

/**
 * The same Go server as WireTest, but behind nginx (tools/docker/wire/nginx.conf). RT_WIRE_PROXY is the address of nginx
 * and RT_WIRE_ORIGIN the address of the server itself, which answers how many requests reached it. Both are set by
 * tools/check-wire.sh. Without them the tests are skipped, and with RT_REQUIRE_WIRE=1 a missing proxy is a failure.
 */
final class WireProxyTest extends TestCase
{
    private static string $proxy = '';

    private static string $origin = '';

    public static function setUpBeforeClass(): void
    {
        $proxy = getenv('RT_WIRE_PROXY');
        $origin = getenv('RT_WIRE_ORIGIN');
        if (!is_string($proxy) || $proxy === '' || !is_string($origin) || $origin === '') {
            if (getenv('RT_REQUIRE_WIRE') === '1') {
                self::fail('RT_WIRE_PROXY and RT_WIRE_ORIGIN must name the nginx and the server of the proxy lane.');
            }
            self::markTestSkipped('RT_WIRE_PROXY is not set: run tools/check-wire.sh.');
        }
        self::$proxy = rtrim($proxy, '/');
        self::$origin = rtrim($origin, '/');
    }

    private function client(int $attempts = 1, int $attemptSeconds = 10): ReqtideClient
    {
        $builder = PipelineBuilder::profile('wire-proxy', 'v1')
            ->partition('test', 'local', 'wire' . bin2hex(random_bytes(4)))
            ->attemptTimeout(Duration::seconds($attemptSeconds), AttemptTimeoutRequirement::TransportEnforced)
            ->retry($attempts, BackoffMode::Constant, Duration::milliseconds(5), Duration::seconds(1), JitterMode::None);
        if ($attempts > 1) {
            $builder = $builder->retryBudget(10, 10, Duration::seconds(60), Duration::seconds(12));
        }

        return ReqtideClient::inMemory($builder, new GuzzleConfiguration(HttpOutcomeClassifier::starter()));
    }

    private function seen(string $path): int
    {
        return (int) (new Client())->get(self::$origin . '/__count', ['query' => ['path' => $path]])->getBody()->getContents();
    }

    /** @return iterable<string, array{string, int}> */
    public static function uploads(): iterable
    {
        foreach (['buffered', 'direct'] as $mode) {
            foreach ([1, 4_096, 1_000_001, 5_000_000] as $size) {
                yield $mode . ' ' . $size . ' bytes' => [$mode, $size];
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('uploads')]
    public function testABodyArrivesWholeThroughNginx(string $mode, int $size): void
    {
        $body = random_bytes(max(1, $size));
        $operation = HttpOperation::single(new Request('POST', self::$proxy . '/' . $mode . '/echo', [], Utils::streamFor($body)), 'wire.proxy.upload');

        $answer = json_decode((string) $this->client()->send($operation, Duration::seconds(20))->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($answer);
        self::assertSame($size, $answer['length']);
        self::assertSame(hash('sha256', $body), $answer['sha256']);
    }

    /** @return iterable<string, array{string}> */
    public static function responseModes(): iterable
    {
        yield 'passed on as it arrives' => ['streaming'];
        yield 'collected by nginx first' => ['collecting'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('responseModes')]
    public function testASlowChunkedResponseIsReadWhole(string $mode): void
    {
        $operation = HttpOperation::single(new Request('GET', self::$proxy . '/' . $mode . '/stream?chunks=6&delay=100'), 'wire.proxy.stream');

        $body = (string) $this->client()->send($operation, Duration::seconds(20))->getBody();

        self::assertSame(6 * 1024, strlen($body));
        self::assertStringStartsWith('chunk-0x', $body);
        self::assertStringContainsString("\nchunk-5x", $body);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('responseModes')]
    public function testAResponseThatNeverEndsIsStoppedByTheAttemptTimeout(string $mode): void
    {
        $operation = HttpOperation::single(new Request('GET', self::$proxy . '/' . $mode . '/trickle'), 'wire.proxy.trickle');
        $started = microtime(true);

        $returned = false;
        try {
            $this->client(1, 1)->send($operation, Duration::seconds(10));
            $returned = true;
        } catch (\Throwable) {
        }
        self::assertFalse($returned, 'a response without an end must not be returned');

        $elapsed = microtime(true) - $started;
        self::assertLessThan(4.0, $elapsed, 'the attempt timeout of one second ended the call');
        self::assertGreaterThanOrEqual(1, $this->seen('/trickle'));
    }

    public function testAnUploadThatTheOriginCutIsAFailureOfNginxAndNotARetryOfAPartialBody(): void
    {
        $before = $this->seen('/cut');
        $request = new Request('POST', self::$proxy . '/buffered/cut?after=100000', ['Idempotency-Key' => 'wire-proxy-key'], Utils::streamFor(str_repeat('b', 2_000_000)));
        $operation = HttpOperation::idempotentMutation(
            $request,
            'wire.proxy.interrupted',
            'wire-proxy-key',
            'wire',
            'The test server never keeps a request.',
            Duration::seconds(60),
            retryableOutcomeCodes: HttpOutcomeClassifier::starter()->retryableOutcomeCodes(),
            bodyIntegrity: BodyIntegrity::Verified,
        );

        $this->client(3)->sendDetailed($operation, Duration::seconds(20));

        // nginx has read the whole body before it talks to the origin, so the client delivered every byte. The origin
        // closes the connection, nginx answers 502, and the 502 is retried as the classifier says.
        self::assertSame(3, $this->seen('/cut') - $before);
    }
}
