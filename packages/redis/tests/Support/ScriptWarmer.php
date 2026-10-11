<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Support;

use Enaxon\Reqtide\Redis\Script\Script;
use Enaxon\Reqtide\Redis\Script\ScriptLibrary;
use PHPUnit\Framework\Assert;
use Predis\Client;

/** Loads every shipped script into the server cache, so that a fault hits an execution and not a cache miss. */
final class ScriptWarmer
{
    private function __construct() {}

    public static function warm(Client $client): void
    {
        $methods = (new \ReflectionClass(ScriptLibrary::class))->getMethods(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_STATIC);
        $loaded = 0;
        foreach ($methods as $method) {
            if ($method->getNumberOfParameters() === 0 && (string) $method->getReturnType() === Script::class) {
                $script = $method->invoke(null);
                Assert::assertInstanceOf(Script::class, $script);
                Assert::assertSame($script->digest, $client->executeRaw(['SCRIPT', 'LOAD', $script->text]));
                ++$loaded;
            }
        }
        Assert::assertGreaterThanOrEqual(15, $loaded, 'every shipped script was loaded');
    }
}
