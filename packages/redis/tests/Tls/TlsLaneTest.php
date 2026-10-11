<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Tls;

use Enaxon\Reqtide\Exception\InfrastructureException;
use Enaxon\Reqtide\Exception\StateUnavailableException;
use Enaxon\Reqtide\Policy\ConcurrencyPolicy;
use Enaxon\Reqtide\Policy\CooldownPolicy;
use Enaxon\Reqtide\Redis\Client\CommandEffect;
use Enaxon\Reqtide\Redis\Client\RedisBridge;
use Enaxon\Reqtide\Redis\RedisConcurrencyBackend;
use Enaxon\Reqtide\Redis\RedisConnectionOptions;
use Enaxon\Reqtide\Redis\RedisCooldownBackend;
use Enaxon\Reqtide\Redis\RedisRegistry;
use Enaxon\Reqtide\Redis\RedisRegistryAdmin;
use Enaxon\Reqtide\Redis\Script\Script;
use Enaxon\Reqtide\Redis\Tests\Support\RedisEndpoint;
use Enaxon\Reqtide\State\ConcurrencyAdmissionStatus;
use Enaxon\Reqtide\State\CooldownStatus;
use Enaxon\Reqtide\Tests\Contract\ContractIdentities;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\PositiveInt;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

/**
 * Redis over TLS with a throwaway CA (compose profile "redis-tls"). The server accepts only TLS. Verification of the peer
 * and of the host name is always on: a certificate from another CA, a name that does not match and a plain connection to
 * the TLS port all fail as unavailable, in bounded time, and the library offers no way to switch verification off.
 */
final class TlsLaneTest extends TestCase
{
    private const SECRET = 'a-host-managed-secret-of-32-bytes!!';

    private string $ca = '';

    private string $otherCa = '';

    private ?RedisRegistry $registry = null;

    protected function setUp(): void
    {
        $endpoint = RedisEndpoint::forTest();
        $ca = getenv('RT_TLS_CA');
        $other = getenv('RT_TLS_OTHER_CA');
        if (!is_string($ca) || !is_file($ca) || !is_string($other) || !is_file($other)) {
            if (RedisEndpoint::required()) {
                Assert::fail('RT_REQUIRE_REDIS=1 but RT_TLS_CA and RT_TLS_OTHER_CA do not name certificate files.');
            }
            self::markTestSkipped('No TLS Redis configured (run bash tools/check-redis-tls.sh).');
        }
        $this->ca = $ca;
        $this->otherCa = $other;
        self::assertNotSame('', $endpoint->host);
    }

    protected function tearDown(): void
    {
        if ($this->registry !== null) {
            $bridge = RedisBridge::forOptions($this->tls());
            // Remove what the test created (the retire script leaves keys; delete the registry keys by pattern).
            $script = new Script("local c = redis.call('SCAN', 0, 'MATCH', ARGV[1], 'COUNT', 1000) for _, k in ipairs(c[2]) do redis.call('DEL', k) end return #c[2]");
            try {
                $bridge->execute($script, [], [$this->registry->keyPrefix . ':*'], CommandEffect::Mutating);
            } catch (\Throwable) {
            }
        }
    }

    private function tls(?string $ca = null, ?string $serverName = null): RedisConnectionOptions
    {
        $endpoint = RedisEndpoint::forTest();

        return new RedisConnectionOptions($endpoint->host, 3.0, 3.0, $endpoint->port, null, null, true, $ca ?? $this->ca, $serverName);
    }

    public function testScriptsRunOverTlsWithTheTrustedCa(): void
    {
        $registry = new RedisRegistry('rt_tls_' . bin2hex(random_bytes(4)), 'tls-test', self::SECRET);
        $this->registry = $registry;
        $options = $this->tls();
        (new RedisRegistryAdmin($options, $registry))->initialize();
        $ids = new ContractIdentities();
        $identity = ContractIdentities::identity('tls');

        $cooldown = new RedisCooldownBackend($options, $registry);
        $cooldownPolicy = new CooldownPolicy($identity, Duration::seconds(60));
        $cooldown->extend($cooldownPolicy, Duration::seconds(30), $ids->command());
        self::assertSame(CooldownStatus::Active, $cooldown->inspect($cooldownPolicy)->status);

        $concurrency = new RedisConcurrencyBackend($options, $registry);
        $policy = new ConcurrencyPolicy($identity, new PositiveInt(1), Duration::seconds(30));
        $admission = $concurrency->acquire($policy, $ids->owner(), $ids->command());
        self::assertSame(ConcurrencyAdmissionStatus::Accepted, $admission->status);
        self::assertSame(ConcurrencyAdmissionStatus::CapacityExhausted, $concurrency->acquire($policy, $ids->owner(), $ids->command())->status);
    }

    public function testTheServerNameMayBeSetWhenTheHostIsAnotherName(): void
    {
        // The certificate also names "localhost": verifying that name works although the connection goes to the service name.
        $options = $this->tls(null, 'localhost');
        $registry = new RedisRegistry('rt_tls_' . bin2hex(random_bytes(4)), 'tls-test', self::SECRET);
        $this->registry = $registry;

        self::assertTrue((new RedisRegistryAdmin($options, $registry))->initialize()->created);
    }

    public function testACertificateFromAnotherCaIsRefusedInBoundedTime(): void
    {
        $registry = new RedisRegistry('rt_tls_' . bin2hex(random_bytes(4)), 'tls-test', self::SECRET);
        $started = hrtime(true);

        $this->expectException(StateUnavailableException::class);
        try {
            (new RedisRegistryAdmin($this->tls($this->otherCa), $registry))->verify();
        } finally {
            self::assertLessThan(4.0, (hrtime(true) - $started) / 1e9);
        }
    }

    public function testAServerNameThatDoesNotMatchTheCertificateIsRefused(): void
    {
        $registry = new RedisRegistry('rt_tls_' . bin2hex(random_bytes(4)), 'tls-test', self::SECRET);

        $this->expectException(StateUnavailableException::class);
        (new RedisRegistryAdmin($this->tls(null, 'not-the-server.example'), $registry))->verify();
    }

    public function testAMutationOverAConnectionThatCannotBeVerifiedIsUnavailableAndNeverIndeterminate(): void
    {
        $registry = new RedisRegistry('rt_tls_' . bin2hex(random_bytes(4)), 'tls-test', self::SECRET);
        $backend = new RedisCooldownBackend($this->tls($this->otherCa), $registry);

        // The handshake fails before any command is written, so the result is definite.
        $this->expectException(StateUnavailableException::class);
        $backend->extend(new CooldownPolicy(ContractIdentities::identity('tls'), Duration::seconds(60)), Duration::seconds(5), (new ContractIdentities())->command());
    }

    public function testAPlainConnectionToTheTlsPortFailsInBoundedTime(): void
    {
        $endpoint = RedisEndpoint::forTest();
        $plain = new RedisConnectionOptions($endpoint->host, 3.0, 2.0, $endpoint->port);
        $registry = new RedisRegistry('rt_tls_' . bin2hex(random_bytes(4)), 'tls-test', self::SECRET);
        $started = hrtime(true);

        try {
            (new RedisRegistryAdmin($plain, $registry))->verify();
            self::fail('A plain connection to a TLS port must fail.');
        } catch (InfrastructureException) {
            self::assertLessThan(5.0, (hrtime(true) - $started) / 1e9);
        }
    }
}
