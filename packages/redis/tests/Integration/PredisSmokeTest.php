<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Integration;

use Enaxon\Reqtide\Redis\Tests\Support\RedisEndpoint;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Predis\Response\ServerException;
use Predis\Response\Status;

/**
 * Connection smoke test against a real server. It proves only what later work stands on: Predis 3.7 reaches the lane's
 * Redis with finite timeouts and no retry, scripts load and run by digest, and a missing script is a plain server error.
 * It is not evidence about any Reqtide state behaviour.
 */
final class PredisSmokeTest extends TestCase
{
    private const SCRIPT = "return {ARGV[1], redis.call('PING').ok, _VERSION}";

    private function client(): Client
    {
        return new Client(RedisEndpoint::forTest()->parameters());
    }

    public function testServerAnswersPing(): void
    {
        $reply = $this->client()->ping();
        self::assertInstanceOf(Status::class, $reply);
        self::assertSame('PONG', $reply->getPayload());
    }

    public function testServerIsTheVersionOfTheLane(): void
    {
        $endpoint = RedisEndpoint::forTest();
        $info = $this->client()->info('server');
        self::assertIsArray($info);
        $server = $info['Server'] ?? $info['server'] ?? null;
        self::assertIsArray($server);
        $version = $server['redis_version'] ?? null;
        self::assertIsString($version);

        if ($endpoint->expectedVersion === null) {
            self::markTestIncomplete('RT_REDIS_EXPECT_VERSION is not set; the server reports ' . $version . '.');
        }
        self::assertSame($endpoint->expectedVersion, $version);
    }

    public function testNoRetryIsConfigured(): void
    {
        $retry = $this->client()->getConnection()->getParameters()->retry;
        self::assertSame(0, $retry->getRetries());
    }

    public function testScriptLoadsAndRunsByDigestOnLua51(): void
    {
        $client = $this->client();
        $digest = $client->script('LOAD', self::SCRIPT);
        self::assertIsString($digest);
        self::assertSame(sha1(self::SCRIPT), $digest);

        self::assertSame(['x', 'PONG', 'Lua 5.1'], $client->evalsha($digest, 0, 'x'));
    }

    public function testAnUnknownDigestIsAPlainServerErrorWithoutAHiddenFallback(): void
    {
        $client = $this->client();
        $digest = sha1('reqtide smoke test: this script was never loaded');
        try {
            $client->evalsha($digest, 0);
            self::fail('An unknown digest must fail.');
        } catch (ServerException $exception) {
            self::assertStringStartsWith('NOSCRIPT', $exception->getMessage());
        }
    }
}
