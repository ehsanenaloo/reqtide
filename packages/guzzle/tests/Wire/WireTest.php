<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Tests\Wire;

use Enaxon\Reqtide\Exception\PreparationFailedException;
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
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;

/**
 * What an HTTP server that shares no code with this project receives. The server is a program written in Go
 * (`server.go`, standard library only); the tests read its own record of the length, the SHA-256 and the framing of the
 * body. They need the built server: RT_WIRE_SERVER names the binary (see tools/check-wire.sh). Without it the tests are
 * skipped, and with RT_REQUIRE_WIRE=1 a missing server is a failure.
 */
final class WireTest extends TestCase
{
    /** @var resource|null */
    private static $process = null;

    private static string $base = '';

    public static function setUpBeforeClass(): void
    {
        $binary = getenv('RT_WIRE_SERVER');
        if (!is_string($binary) || $binary === '' || !is_file($binary)) {
            if (getenv('RT_REQUIRE_WIRE') === '1') {
                self::fail('RT_WIRE_SERVER does not name a built wire server.');
            }
            self::markTestSkipped('RT_WIRE_SERVER is not set: build the server with tools/check-wire.sh.');
        }
        $process = proc_open([$binary], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process, 'the wire server did not start');
        fclose($pipes[0]);
        $line = fgets($pipes[1]);
        self::assertIsString($line);
        self::assertSame(1, preg_match('~^LISTENING (127\.0\.0\.1:[0-9]+)$~', trim($line), $m), 'unexpected first line: ' . $line);
        self::$process = $process;
        self::$base = 'http://' . (string) ($m[1] ?? '');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$process !== null) {
            proc_terminate(self::$process);
            proc_close(self::$process);
            self::$process = null;
        }
    }

    private function client(int $attempts = 1): ReqtideClient
    {
        $builder = PipelineBuilder::profile('wire', 'v1')
            ->partition('test', 'local', 'wire' . bin2hex(random_bytes(4)))
            ->attemptTimeout(Duration::seconds(10), AttemptTimeoutRequirement::TransportEnforced)
            ->retry($attempts, BackoffMode::Constant, Duration::milliseconds(5), Duration::seconds(1), JitterMode::None);
        if ($attempts > 1) {
            $builder = $builder->retryBudget(10, 10, Duration::seconds(60), Duration::seconds(12));
        }

        return ReqtideClient::inMemory($builder, new GuzzleConfiguration(HttpOutcomeClassifier::starter()));
    }

    private function seen(string $path): int
    {
        return (int) (new Client())->get(self::$base . '/__count', ['query' => ['path' => $path]])->getBody()->getContents();
    }

    /** @return array<string, mixed> */
    private static function answer(string $json): array
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    /** @return iterable<string, array{int}> */
    public static function sizes(): iterable
    {
        foreach ([1, 4_096, 999_999, 1_000_000, 1_000_001, 2_000_000, 5_000_000] as $size) {
            yield $size . ' bytes' => [$size];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sizes')]
    public function testABodyOfAnySizeArrivesWholeWithItsOwnLength(int $size): void
    {
        self::assertGreaterThan(0, $size);
        $body = random_bytes(max(1, $size));
        $operation = HttpOperation::single(new Request('POST', self::$base . '/echo', [], Utils::streamFor($body)), 'wire.upload');

        $answer = self::answer((string) $this->client()->send($operation, Duration::seconds(20))->getBody());

        self::assertSame($size, $answer['length']);
        self::assertSame(hash('sha256', $body), $answer['sha256']);
        self::assertSame($size, $answer['contentLength'], 'the server saw the length of the body that was sent');
        self::assertNull($answer['transferEncoding']);
    }

    public function testAClientDefaultContentLengthIsTheHazardTheConfigurationRefuses(): void
    {
        $body = str_repeat('a', 2_000_000);
        $plain = new Client(['handler' => HandlerStack::create(new CurlHandler()), 'headers' => ['Content-Length' => '1000000']]);

        $answer = self::answer((string) $plain->send(new Request('POST', self::$base . '/echo', [], Utils::streamFor($body)))->getBody());

        self::assertSame(1_000_000, $answer['length'], 'plain Guzzle sends only the declared 1,000,000 bytes of the 2,000,000');
        $this->expectException(\Enaxon\Reqtide\Exception\InvalidConfigurationException::class);
        new GuzzleConfiguration(clientDefaults: ['headers' => ['Content-Length' => '1000000']]);
    }

    private function upload(string $integrity): HttpOperation
    {
        $body = Utils::streamFor(str_repeat('b', 2_000_000));
        $request = new Request('POST', self::$base . '/cut?after=100000', ['Idempotency-Key' => 'wire-key'], $body);

        return HttpOperation::idempotentMutation(
            $request,
            'wire.interrupted',
            'wire-key',
            'wire',
            'The test server never keeps a request.',
            Duration::seconds(60),
            retryableOutcomeCodes: HttpOutcomeClassifier::starter()->retryableOutcomeCodes(),
            bodyIntegrity: $integrity === 'asserted' ? BodyIntegrity::CallerAsserted : BodyIntegrity::Verified,
        );
    }

    public function testAnUploadThatTheServerCutShortIsNotSentAgainUnderVerifiedIntegrity(): void
    {
        $before = $this->seen('/cut');

        $completion = $this->client(3)->sendDetailed($this->upload('verified'), Duration::seconds(20));

        self::assertSame(1, $this->seen('/cut') - $before, 'one request reached the server');
        self::assertInstanceOf(PreparationFailedException::class, $completion->policyCause);
        self::assertStringContainsString('only part of the body', $completion->policyCause->preparationCause->getMessage());
    }

    public function testTheSameUploadIsSentAgainWhenTheCallerAssertsTheBody(): void
    {
        $before = $this->seen('/cut');

        $this->client(3)->sendDetailed($this->upload('asserted'), Duration::seconds(20));

        self::assertSame(3, $this->seen('/cut') - $before, 'three requests reached the server');
    }
}
