<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Tests\Unit;

use Composer\InstalledVersions;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Handler\CurlMultiHandler;
use PHPUnit\Framework\TestCase;

/** The Guzzle facts the package depends on, checked without a server. */
final class GuzzlePinTest extends TestCase
{
    public function testInstalledGuzzleIsSevenAtOrAboveTheFloor(): void
    {
        $version = InstalledVersions::getVersion('guzzlehttp/guzzle');
        self::assertNotNull($version);
        $plain = ltrim($version, 'v');
        self::assertTrue(version_compare($plain, '7.15.5', '>='), 'guzzlehttp/guzzle ' . $version . ' is below the 7.15.5 floor');
        self::assertTrue(version_compare($plain, '8.0.0', '<'), 'Guzzle 8 is not part of the beta');
    }

    public function testTheSupportedHandlersExist(): void
    {
        self::assertTrue(class_exists(CurlHandler::class));
        self::assertTrue(class_exists(CurlMultiHandler::class));
        self::assertTrue(extension_loaded('curl'), 'the supported handlers need ext-curl');
    }
}
