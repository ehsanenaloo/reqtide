<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Unit;

use Composer\InstalledVersions;
use Enaxon\Reqtide\Redis\Tests\Support\RedisEndpoint;
use PHPUnit\Framework\TestCase;
use Predis\Client;

/** The Predis facts the Redis package depends on, checked without a server. */
final class PredisPinTest extends TestCase
{
    public function testInstalledPredisIsAtLeastTheFloorOfThePackage(): void
    {
        $version = InstalledVersions::getVersion('predis/predis');
        self::assertNotNull($version);
        // 3.6.1 busy-loops when the peer closes in the middle of a bulk reply (see TruncatedReplyBoundedTimeTest).
        self::assertTrue(version_compare(ltrim($version, 'v'), '3.7.0', '>='), 'predis/predis ' . $version . ' is below the 3.7.0 floor');
    }

    public function testParametersBuiltForTheTestsDisableRetries(): void
    {
        $reflection = new \ReflectionClass(RedisEndpoint::class);
        $endpoint = $reflection->newInstanceWithoutConstructor();
        foreach (['host' => 'redis.invalid', 'port' => 6379, 'expectedVersion' => null] as $property => $value) {
            $reflection->getProperty($property)->setValue($endpoint, $value);
        }

        $parameters = $endpoint->parameters();
        self::assertArrayNotHasKey('retry', $parameters, 'a retry key would let Predis re-send a command after a failure');
        self::assertSame(2.0, $parameters['timeout']);
        self::assertSame(2.0, $parameters['read_write_timeout']);

        $retry = (new Client($parameters))->getConnection()->getParameters()->retry;
        self::assertSame(0, $retry->getRetries(), 'Predis retries nothing unless a retry key is given');
    }
}
