<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Unit;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Redis\RedisConnectionOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RedisConnectionOptionsTest extends TestCase
{
    public function testParametersCarryFiniteTimeoutsNoRetryAndNoPersistence(): void
    {
        $parameters = (new RedisConnectionOptions('redis.internal', 0.5, 0.25, 6380))->predisParameters();

        self::assertSame([
            'scheme' => 'tcp',
            'host' => 'redis.internal',
            'port' => 6380,
            'timeout' => 0.5,
            'read_write_timeout' => 0.25,
            'persistent' => false,
        ], $parameters);
        self::assertArrayNotHasKey('retry', $parameters, 'without a retry key Predis makes no retry');
    }

    public function testThereIsNoRetryOption(): void
    {
        $names = array_map(
            static fn(\ReflectionParameter $parameter): string => $parameter->getName(),
            (new \ReflectionMethod(RedisConnectionOptions::class, '__construct'))->getParameters(),
        );
        foreach ($names as $name) {
            self::assertStringNotContainsStringIgnoringCase('retry', $name);
            self::assertStringNotContainsStringIgnoringCase('persistent', $name);
            self::assertStringNotContainsStringIgnoringCase('verify', $name, 'TLS verification cannot be switched off');
        }
    }

    public function testBothTimeoutsAreRequired(): void
    {
        $required = array_filter(
            (new \ReflectionMethod(RedisConnectionOptions::class, '__construct'))->getParameters(),
            static fn(\ReflectionParameter $parameter): bool => !$parameter->isOptional(),
        );
        self::assertSame(['host', 'connectTimeoutSeconds', 'readWriteTimeoutSeconds'], array_values(array_map(
            static fn(\ReflectionParameter $parameter): string => $parameter->getName(),
            $required,
        )));
    }

    /**
     * @return iterable<string, array{float, float}>
     */
    public static function badTimeouts(): iterable
    {
        yield 'zero connect' => [0.0, 1.0];
        yield 'negative read' => [1.0, -1.0];
        yield 'infinite' => [INF, 1.0];
        yield 'not a number' => [1.0, NAN];
        yield 'above one hour' => [1.0, 3600.5];
    }

    #[DataProvider('badTimeouts')]
    public function testNonFiniteOrOutOfRangeTimeoutsAreRejected(float $connect, float $readWrite): void
    {
        $this->expectException(InvalidConfigurationException::class);
        new RedisConnectionOptions('redis', $connect, $readWrite);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function badEndpoints(): iterable
    {
        yield 'empty host' => ['', 6379];
        yield 'scheme in host' => ['tcp://redis', 6379];
        yield 'whitespace' => ['red is', 6379];
        yield 'port zero' => ['redis', 0];
        yield 'port too large' => ['redis', 65536];
    }

    #[DataProvider('badEndpoints')]
    public function testMalformedEndpointsAreRejected(string $host, int $port): void
    {
        $this->expectException(InvalidConfigurationException::class);
        new RedisConnectionOptions($host, 1.0, 1.0, $port);
    }

    public function testTlsParametersAlwaysVerifyThePeerAndTheName(): void
    {
        $ca = tempnam(sys_get_temp_dir(), 'rtca');
        self::assertIsString($ca);
        try {
            $parameters = (new RedisConnectionOptions('redis', 1.0, 1.0, 6380, tls: true, caFile: $ca, tlsServerName: 'redis.example'))->predisParameters();
        } finally {
            unlink($ca);
        }
        self::assertSame('tls', $parameters['scheme']);
        self::assertSame(['verify_peer' => true, 'verify_peer_name' => true, 'cafile' => $ca, 'peer_name' => 'redis.example'], $parameters['ssl']);
    }

    public function testTlsSettingsWithoutTlsAndAMissingCaFileAreRejected(): void
    {
        try {
            new RedisConnectionOptions('redis', 1.0, 1.0, caFile: '/nonexistent/ca.pem');
            self::fail('expected an exception');
        } catch (InvalidConfigurationException) {
        }
        $this->expectException(InvalidConfigurationException::class);
        new RedisConnectionOptions('redis', 1.0, 1.0, tls: true, caFile: '/nonexistent/ca.pem');
    }

    public function testACredentialIsNeverShownInDebugOutputOrMessagesAndCannotBeSerialized(): void
    {
        $secret = 'p4ss-' . bin2hex(random_bytes(4));
        $options = new RedisConnectionOptions('redis', 1.0, 1.0, username: 'svc', password: $secret);

        ob_start();
        var_dump($options);
        $dump = (string) ob_get_clean();
        self::assertStringNotContainsString($secret, $dump);
        self::assertStringContainsString('***', $dump);
        self::assertSame($secret, $options->predisParameters()['password']);

        try {
            serialize($options);
            self::fail('expected an exception');
        } catch (\LogicException $e) {
            self::assertStringNotContainsString($secret, $e->getMessage());
        }

        try {
            new RedisConnectionOptions('redis', 1.0, 1.0, username: 'svc');
            self::fail('expected an exception');
        } catch (InvalidConfigurationException $e) {
            self::assertStringNotContainsString($secret, $e->getMessage());
        }
    }
}
