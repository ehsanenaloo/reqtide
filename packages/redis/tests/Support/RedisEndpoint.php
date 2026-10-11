<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Where the integration tests find a Redis server, and how they behave when there is none.
 *
 * Environment:
 *  - RT_REDIS_HOST            host name of the server under test; unset means "no server available"
 *  - RT_REDIS_PORT            port, default 6379
 *  - RT_REQUIRE_REDIS         "1" turns a missing server into a failure instead of a skip (always set in CI)
 *  - RT_REDIS_EXPECT_VERSION  exact server version the lane claims to run (for example 7.4.11)
 */
final readonly class RedisEndpoint
{
    private function __construct(
        public string $host,
        public int $port,
        public ?string $expectedVersion,
    ) {}

    /** The configured endpoint, or null when no server is configured. */
    public static function fromEnvironment(): ?self
    {
        $host = getenv('RT_REDIS_HOST');
        if (!is_string($host) || $host === '') {
            return null;
        }
        $port = getenv('RT_REDIS_PORT');
        $expected = getenv('RT_REDIS_EXPECT_VERSION');

        return new self(
            $host,
            is_string($port) && ctype_digit($port) ? (int) $port : 6379,
            is_string($expected) && $expected !== '' ? $expected : null,
        );
    }

    public static function required(): bool
    {
        return getenv('RT_REQUIRE_REDIS') === '1';
    }

    /**
     * The endpoint for a test that needs a server. Without one the test is skipped with a stated reason, or fails
     * when RT_REQUIRE_REDIS=1, so CI can never go green by silently not testing Redis.
     */
    public static function forTest(): self
    {
        $endpoint = self::fromEnvironment();
        if ($endpoint !== null) {
            return $endpoint;
        }
        if (self::required()) {
            Assert::fail('RT_REQUIRE_REDIS=1 but RT_REDIS_HOST is not set: no Redis server is configured.');
        }
        Assert::markTestSkipped('No Redis server configured (set RT_REDIS_HOST; run composer check:redis for the container lanes).');
    }

    /**
     * The endpoint of a server whose clock the tests control, and the file that holds that clock (RT_FAKETIME_FILE, the
     * file the libfaketime server reads). Without both, the test is skipped with a stated reason, or fails when
     * RT_REQUIRE_REDIS=1.
     *
     * @return array{self, string}
     */
    public static function forClockedTest(): array
    {
        $endpoint = self::forTest();
        $file = getenv('RT_FAKETIME_FILE');
        if (!is_string($file) || $file === '' || !is_dir(dirname($file))) {
            if (self::required()) {
                Assert::fail('RT_REQUIRE_REDIS=1 but RT_FAKETIME_FILE does not name a clock file on a shared directory.');
            }
            Assert::markTestSkipped('No controllable-clock Redis configured (set RT_FAKETIME_FILE; run bash tools/check-redis.sh --clock).');
        }

        return [$endpoint, $file];
    }

    /**
     * Predis parameters with finite timeouts and no retry. The `retry` key is left out on purpose: Predis then
     * disables retries, and a re-sent command could apply a write twice.
     *
     * @return array<string, mixed>
     */
    public function parameters(float $timeoutSeconds = 2.0): array
    {
        return [
            'scheme' => 'tcp',
            'host' => $this->host,
            'port' => $this->port,
            'timeout' => $timeoutSeconds,
            'read_write_timeout' => $timeoutSeconds,
            'persistent' => false,
        ];
    }
}
