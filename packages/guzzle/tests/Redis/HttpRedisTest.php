<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Tests\Redis;

use Enaxon\Reqtide\Exception\IndeterminateStateException;
use Enaxon\Reqtide\Guzzle\HttpOperation;
use Enaxon\Reqtide\Guzzle\HttpOutcomeClassifier;
use Enaxon\Reqtide\Guzzle\Tests\Support\FixtureServer;
use Enaxon\Reqtide\Redis\RedisConnectionOptions;
use Enaxon\Reqtide\Redis\RedisRegistry;
use Enaxon\Reqtide\Redis\RedisRegistryAdmin;
use Enaxon\Reqtide\Redis\Tests\Support\BarrierHarness;
use Enaxon\Reqtide\Redis\Tests\Support\FaultProxy;
use Enaxon\Reqtide\Redis\Tests\Support\RedisEndpoint;
use Enaxon\Reqtide\Redis\Tests\Support\ScriptWarmer;
use Enaxon\Reqtide\Value\Duration;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;
use Predis\Client;

/**
 * Redis state and Guzzle together: independent processes and a fault proxy in front of Redis, with the HTTP
 * fixture server's own record as the judge of what was dispatched. The numbers that matter are request counts at the server.
 */
final class HttpRedisTest extends TestCase
{
    private const SECRET = 'a-host-managed-secret-of-32-bytes!!';

    private const IDENTITY = 'http-redis';

    private static FixtureServer $server;

    private string $prefix = '';

    private ?FaultProxy $proxy = null;

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
        $this->prefix = 'rt_hr_' . bin2hex(random_bytes(5));
        ScriptWarmer::warm($this->redis());
    }

    protected function tearDown(): void
    {
        $this->proxy?->stop();
        $client = $this->redis();
        foreach ([$this->prefix . ':*', 'start:' . $this->prefix] as $pattern) {
            $cursor = '0';
            do {
                /** @var array{0: string, 1: list<string>} $page */
                $page = $client->scan($cursor, ['MATCH' => $pattern, 'COUNT' => 500]);
                $cursor = (string) $page[0];
                if ($page[1] !== []) {
                    $client->del($page[1]);
                }
            } while ($cursor !== '0');
        }
    }

    private function redis(): Client
    {
        return new Client(RedisEndpoint::forTest()->parameters(10.0));
    }

    private function direct(): RedisConnectionOptions
    {
        $endpoint = RedisEndpoint::forTest();

        return new RedisConnectionOptions($endpoint->host, 2.0, 5.0, $endpoint->port);
    }

    private function registry(): RedisRegistry
    {
        $registry = new RedisRegistry($this->prefix, self::IDENTITY, self::SECRET);
        (new RedisRegistryAdmin($this->direct(), $registry))->initialize();

        return $registry;
    }

    private function proxy(): FaultProxy
    {
        return $this->proxy ??= FaultProxy::start(RedisEndpoint::forTest());
    }

    private function read(string $path): HttpOperation
    {
        return HttpOperation::read(new Request('GET', self::$server->baseUri . $path), 'fixture.read', Duration::seconds(60), HttpOutcomeClassifier::starter()->retryableOutcomeCodes());
    }

    /**
     * @param array<string, mixed> $extra
     * @return list<array{statuses: array<string, int>, exceptions: array<string, int>}>
     */
    private function workers(int $count, string $pipeline, string $path, int $calls, int $capacity = 1, array $extra = []): array
    {
        $results = BarrierHarness::run(
            RedisEndpoint::forTest(),
            $this->prefix,
            self::IDENTITY,
            self::SECRET,
            $count,
            ['scenario' => 'http', 'pipeline' => $pipeline, 'path' => $path, 'calls' => $calls, 'capacity' => $capacity, 'baseUri' => self::$server->baseUri] + $extra,
            worker: __DIR__ . '/http-worker.php',
        );
        $typed = [];
        foreach ($results as $result) {
            self::assertIsArray($result['statuses']);
            self::assertIsArray($result['exceptions']);
            /** @var array<string, int> $statuses */
            $statuses = $result['statuses'];
            /** @var array<string, int> $exceptions */
            $exceptions = $result['exceptions'];
            $typed[] = ['statuses' => $statuses, 'exceptions' => $exceptions];
        }

        return $typed;
    }

    /**
     * @param list<array{statuses: array<string, int>, exceptions: array<string, int>}> $results
     */
    private static function total(array $results, string $field, ?string $key = null): int
    {
        $sum = 0;
        foreach ($results as $result) {
            foreach ($result[$field === 'statuses' ? 'statuses' : 'exceptions'] as $name => $count) {
                if ($key === null || (string) $name === $key) {
                    $sum += $count;
                }
            }
        }

        return $sum;
    }

    public function testOneRateLimitSharedByManyProcessesAdmitsExactlyItsCapacityAtTheServer(): void
    {
        $this->registry();
        $results = $this->workers(6, 'rate', '/status/200', 5, 7);

        self::assertSame(7, self::total($results, 'statuses', '200'), 'seven responses');
        self::assertSame(23, self::total($results, 'exceptions'), 'the other 23 calls were refused');
        self::assertSame(7, self::$server->count('/status/200'), 'and the server received exactly seven requests');
    }

    public function testARetryAfterSeenByOneProcessStopsTheOthersUntilItEnds(): void
    {
        $registry = $this->registry();
        $client = HttpRedisPipelines::client('cooldown', $this->direct(), $registry);
        $started = hrtime(true);

        $response = $client->send($this->read('/status/429?ra=4'), Duration::seconds(5));
        self::assertSame(429, $response->getStatusCode());

        $during = $this->workers(4, 'cooldown', '/status/200', 3);
        self::assertSame(12, self::total($during, 'exceptions'), 'every call of every process was deferred');
        self::assertSame(0, self::$server->count('/status/200'), 'and none reached the server while the Retry-After lasted');

        // The cooldown ends by itself: poll until a request goes through (not before about four seconds).
        $deadline = hrtime(true) + 12_000_000_000;
        $sent = false;
        while (hrtime(true) < $deadline && !$sent) {
            try {
                $sent = $client->send($this->read('/status/200'), Duration::seconds(1))->getStatusCode() === 200;
            } catch (\Throwable) {
                usleep(100_000);
            }
        }
        self::assertTrue($sent, 'the cooldown ended and the request went through');
        self::assertGreaterThan(3.5, (hrtime(true) - $started) / 1e9, 'not before the four seconds of Retry-After');

        $after = $this->workers(4, 'cooldown', '/status/200', 3);
        self::assertSame(12, self::total($after, 'statuses', '200'));
    }

    public function testFailuresSeenByManyProcessesOpenOneCircuitForAll(): void
    {
        $this->registry();
        $first = $this->workers(4, 'circuit', '/status/500', 3);
        $dispatched = self::$server->count('/status/500');

        self::assertSame(12, self::total($first, 'statuses', '500') + self::total($first, 'exceptions'), 'every call was answered');
        self::assertGreaterThanOrEqual(4, $dispatched, 'the circuit needed its samples first');
        self::assertLessThan(12, $dispatched, 'and once it was open, calls were refused without a request');

        $second = $this->workers(4, 'circuit', '/status/500', 3);
        self::assertSame(12, self::total($second, 'exceptions'), 'in a second run every call of every process is refused');
        self::assertSame($dispatched, self::$server->count('/status/500'), 'and the server saw no further request');
    }

    public function testALostRateDebitReplyDispatchesNothing(): void
    {
        $registry = $this->registry();
        $client = HttpRedisPipelines::client('rate', $this->proxy()->options(1.0), $registry, 5);

        $this->proxy()->mode('drop-reply@1');
        try {
            $client->send($this->read('/status/200'), Duration::seconds(5));
            self::fail('The uncertain debit must surface.');
        } catch (IndeterminateStateException) {
        }
        self::assertSame(0, self::$server->count('/status/200'), 'nothing was dispatched after a debit of unknown outcome');

        $this->proxy()->mode('pass');
        self::assertSame(200, $client->send($this->read('/status/200'), Duration::seconds(5))->getStatusCode());
        self::assertSame(1, self::$server->count('/status/200'));
    }

    public function testALostReleaseReplyKeepsTheResponseAndTheSlotIsFree(): void
    {
        $registry = $this->registry();
        $client = HttpRedisPipelines::client('bulkhead', $this->proxy()->options(1.0), $registry);

        // The permit is acquired first and released second; the reply of the release is lost.
        $this->proxy()->mode('drop-reply@2');
        $response = $client->send($this->read('/status/200'), Duration::seconds(5));
        $this->proxy()->mode('pass');

        self::assertSame(200, $response->getStatusCode(), 'the response of the server is the result');
        self::assertSame(1, self::$server->count('/status/200'));
        self::assertSame(200, $client->send($this->read('/status/200'), Duration::seconds(5))->getStatusCode(), 'the release was applied: the single slot is free');
        self::assertSame(2, self::$server->count('/status/200'));
    }

    public function testALostRetryCommitReplySendsNoRetry(): void
    {
        $registry = $this->registry();
        $client = HttpRedisPipelines::client('budget', $this->proxy()->options(1.0), $registry);

        // The first attempt gets a 503. The reserve of the retry is the first script call, its commit the second.
        $this->proxy()->mode('drop-reply@2');
        $completion = $client->sendDetailed($this->read('/status/503'), Duration::seconds(5));
        $this->proxy()->mode('pass');

        self::assertSame(1, self::$server->count('/status/503'), 'the retry was not sent after a commit of unknown outcome');
        self::assertSame(1, $completion->summary->dispatched);
        self::assertSame(0, $completion->summary->retries);

        // For contrast: without the fault the same operation is retried once.
        self::$server->reset();
        $client->sendDetailed($this->read('/status/503'), Duration::seconds(5));
        self::assertSame(2, self::$server->count('/status/503'));
    }

    public function testNoSecretOfARequestIsStoredInRedisKeysOrValues(): void
    {
        $registry = $this->registry();
        $secrets = [];
        foreach (['query', 'user', 'bearer', 'cookie', 'body', 'key', 'identity'] as $name) {
            $secrets[$name] = 'SEC' . bin2hex(random_bytes(12));
        }
        $headers = ['Authorization' => 'Bearer ' . $secrets['bearer'], 'Cookie' => 'session=' . $secrets['cookie'], 'Idempotency-Key' => $secrets['identity']];
        $uri = preg_replace('~^http://~', 'http://' . $secrets['user'] . ':x@', self::$server->baseUri) . '/status/503?token=' . $secrets['query'];
        $codes = HttpOutcomeClassifier::starter()->retryableOutcomeCodes();

        foreach (['rate', 'cooldown', 'circuit', 'budget', 'bulkhead'] as $kind) {
            $client = HttpRedisPipelines::client($kind, $this->direct(), $registry, 5);
            $operation = $kind === 'budget'
                ? HttpOperation::idempotentMutation(new Request('POST', $uri, $headers, 'secret=' . $secrets['body']), 'fuzz.create', $secrets['identity'], 'scope', 'stored', Duration::seconds(60), retryableOutcomeCodes: $codes, credentialKey: $secrets['key'])
                : HttpOperation::read(new Request('GET', $uri, $headers), 'fuzz.read', Duration::seconds(60), $codes, credentialKey: $secrets['key']);
            $client->sendDetailed($operation, Duration::seconds(5));
        }

        $redis = $this->redis();
        $dump = '';
        /** @var list<string> $keys */
        $keys = $redis->keys($this->prefix . ':*');
        self::assertGreaterThan(3, count($keys), 'state was stored');
        foreach ($keys as $key) {
            $dump .= $key . "\n";
            $typeReply = $redis->executeRaw(['TYPE', $key]);
            $type = is_string($typeReply) ? $typeReply : '';
            $value = $redis->executeRaw(['GET', $key]);
            $dump .= match ($type) {
                'hash' => print_r($redis->hgetall($key), true),
                'zset' => print_r($redis->zrange($key, 0, -1, ['withscores' => true]), true),
                'list' => print_r($redis->lrange($key, 0, -1), true),
                default => is_string($value) ? $value : '',
            };
        }
        foreach ($secrets as $name => $secret) {
            self::assertStringNotContainsString($secret, $dump, 'the ' . $name . ' secret must not be stored in Redis');
        }
    }
}
