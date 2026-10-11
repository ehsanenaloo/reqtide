<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Integration;

use Enaxon\Reqtide\Redis\RedisConnectionOptions;
use Enaxon\Reqtide\Redis\RedisRegistry;
use Enaxon\Reqtide\Redis\RedisRegistryAdmin;
use Enaxon\Reqtide\Redis\Tests\Support\RedisEndpoint;
use PHPUnit\Framework\TestCase;
use Predis\Client;

/**
 * Effective backend time on a real server: pair arithmetic with checked carries, the
 * `max(watermark, state, observed)` rule, a backward clock simulated through the stored watermark (the faketime lane does
 * not exist yet), no auto-bootstrap, and a read-only begin that writes nothing.
 */
final class EffectiveTimeLuaTest extends TestCase
{
    private const SECRET = 'a-host-managed-secret-of-32-bytes!!';

    private static string $script;

    private string $prefix = '';

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        $parts = [];
        foreach (['resources/lua/lib/exact_int.lua', 'resources/lua/lib/time.lua', 'resources/lua/lib/registry.lua', 'tests/Support/lua/registry_time_driver.lua'] as $file) {
            $text = file_get_contents($root . '/' . $file);
            self::assertIsString($text);
            $parts[] = $text;
        }
        self::$script = implode("\n", $parts);
    }

    protected function setUp(): void
    {
        $this->prefix = 'rt_test_' . bin2hex(random_bytes(5));
    }

    protected function tearDown(): void
    {
        $endpoint = RedisEndpoint::fromEnvironment();
        if ($endpoint !== null && $this->prefix !== '') {
            (new Client($endpoint->parameters(10.0)))->del($this->prefix . ':m');
        }
    }

    private function client(): Client
    {
        return new Client(RedisEndpoint::forTest()->parameters(10.0));
    }

    /**
     * @param list<string> $keys
     * @param list<string> $arguments
     * @return list<string>
     */
    private function driver(string $operation, array $arguments, array $keys = []): array
    {
        $reply = $this->client()->eval(self::$script, count($keys), ...$keys, ...[$operation, ...$arguments]);
        self::assertIsArray($reply);

        return array_map(static function (mixed $item): string {
            self::assertIsString($item);

            return $item;
        }, array_values($reply));
    }

    private function serverSeconds(): int
    {
        $time = $this->client()->time();
        self::assertIsArray($time);
        self::assertIsString($time[0]);

        return (int) $time[0];
    }

    private function initialize(): void
    {
        $endpoint = RedisEndpoint::forTest();
        $registry = new RedisRegistry($this->prefix, 'eu1', self::SECRET);
        (new RedisRegistryAdmin(new RedisConnectionOptions($endpoint->host, 2.0, 2.0, $endpoint->port), $registry))->initialize();
    }

    /**
     * @return iterable<string, array{list<string>, list<string>}> watermark, state and observed pairs, then effective seconds, micros and regression
     */
    public static function effective(): iterable
    {
        yield 'forward jump' => [['100', '5', '100', '7', '200', '0'], ['200', '0', '0']];
        yield 'backward observation uses the watermark' => [['1000', '0', '0', '0', '100', '0'], ['1000', '0', '1']];
        yield 'state time ahead of the watermark' => [['10', '0', '50', '3', '20', '0'], ['50', '3', '1']];
        yield 'frozen clock' => [['7', '500', '7', '500', '7', '500'], ['7', '500', '0']];
        yield 'observed equals the watermark' => [['5', '999999', '0', '0', '5', '999999'], ['5', '999999', '0']];
        yield 'one microsecond forward across the second' => [['5', '999999', '0', '0', '6', '0'], ['6', '0', '0']];
        yield 'one microsecond backward within the second' => [['5', '999999', '0', '0', '5', '999998'], ['5', '999999', '1']];
        yield 'seconds largest exact value' => [['9007199254740991', '0', '0', '0', '9007199254740991', '1'], ['9007199254740991', '1', '0']];
        yield 'microseconds not normalized' => [['1', '1000000', '0', '0', '2', '0'], ['err:RT_CORRUPT time pair is not normalized', '', '']];
    }

    /**
     * @param list<string> $input
     * @param list<string> $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('effective')]
    public function testEffectiveTimeIsTheLexicographicMaximum(array $input, array $expected): void
    {
        self::assertSame($expected, $this->driver('effective', $input));
    }

    public function testDurationsAreAddedWithACheckedCarry(): void
    {
        $cases = [
            [['1', '999999', '1'], ['2', '0']],
            [['1', '0', '999999'], ['1', '999999']],
            [['1', '500000', '500000'], ['2', '0']],
            [['0', '0', '9007199254740991'], ['9007199254', '740991']],
            [['9007199254740991', '0', '0'], ['9007199254740991', '0']],
            [['9007199254740991', '0', '1000000'], ['err:RT_CONFIG time addition leaves the exact range', '']],
            [['9007199254740990', '999999', '1'], ['9007199254740991', '0']],
            [['9007199254740991', '999999', '1'], ['err:RT_CONFIG time addition leaves the exact range', '']],
            [['9007199254740991', '0', '999999'], ['9007199254740991', '999999']],
        ];
        $reply = $this->driver('add', array_merge(...array_column($cases, 0)));
        foreach ($cases as $index => [$input, $expected]) {
            self::assertSame($expected, array_slice($reply, $index * 2, 2), implode(',', $input));
        }
    }

    public function testDifferencesAreExactOrRejected(): void
    {
        $cases = [
            [['10', '5', '9', '7'], '999998'],
            [['10', '0', '10', '0'], '0'],
            [['9007199254', '0', '0', '0'], '9007199254000000'],
            [['9007199254', '740991', '0', '0'], '9007199254740991'],
            [['9007199254', '740992', '0', '0'], 'err:RT_CONFIG time difference leaves the exact range'],
            [['9007199255', '0', '0', '0'], 'err:RT_CONFIG time difference leaves the exact range'],
            [['1', '0', '2', '0'], 'err:RT_CORRUPT negative time difference'],
        ];
        $reply = $this->driver('diff', array_merge(...array_column($cases, 0)));
        foreach ($cases as $index => [$input, $expected]) {
            self::assertSame($expected, $reply[$index], implode(',', $input));
        }
    }

    public function testTheServerClockIsReadAsANormalizedPairAndTheWatermarkOnlyMovesForward(): void
    {
        $this->initialize();
        $previous = [0, 0];
        for ($i = 0; $i < 5; ++$i) {
            $reply = $this->driver('begin', ['eu1', '1', '0', '0', '1'], [$this->prefix . ':m']);
            self::assertCount(7, $reply);
            [$es, $eu, $regressed, , , $afterSeconds, $afterMicros] = $reply;
            self::assertSame('0', $regressed, 'a healthy clock does not regress');
            self::assertLessThan(1_000_000, (int) $eu);
            self::assertSame([$es, $eu], [$afterSeconds, $afterMicros], 'the committed watermark is the effective time');
            self::assertGreaterThanOrEqual($previous, [(int) $afterSeconds, (int) $afterMicros]);
            $previous = [(int) $afterSeconds, (int) $afterMicros];
        }
    }

    public function testABackwardClockCannotLowerTheWatermarkOrGiveAFreshStartToARecreatedPartition(): void
    {
        $this->initialize();
        $key = $this->prefix . ':m';
        $future = $this->serverSeconds() + 3600;
        $this->client()->hset($key, 'wm_s', (string) $future);
        $this->client()->hset($key, 'wm_u', '250000');

        // The server's own time is an hour behind the retained watermark: a regression.
        $reply = $this->driver('begin', ['eu1', '1', '0', '0', '1'], [$key]);
        self::assertSame([(string) $future, '250000', '1', (string) $future, '250000', (string) $future, '250000'], $reply, 'effective time stays at the watermark, which is not lowered');

        // A partition recreated after deletion has no stored state time (zero); it still starts at the watermark.
        $recreated = $this->driver('begin', ['eu1', '1', '0', '0', '0'], [$key]);
        self::assertSame([(string) $future, '250000', '1'], array_slice($recreated, 0, 3));

        // The watermark never moves backwards, even when asked to.
        self::assertSame([(string) $future, '250000'], $this->driver('commit_raw', ['eu1', '1', '1', '0'], [$key]));
        self::assertSame([(string) $future, '250001'], $this->driver('commit_raw', ['eu1', '1', (string) $future, '250001'], [$key]), 'a later effective time does advance it');
    }

    public function testReadOnlyBeginWritesNothing(): void
    {
        $this->initialize();
        $key = $this->prefix . ':m';
        $before = $this->client()->hgetall($key);
        $this->driver('begin', ['eu1', '1', '0', '0', '0'], [$key]);
        self::assertSame($before, $this->client()->hgetall($key));
    }

    public function testThereIsNoAutoBootstrapAndAMissingWatermarkIsRejected(): void
    {
        $key = $this->prefix . ':m';
        self::assertSame(['err:RT_NO_REGISTRY registry is not initialized'], $this->driver('begin', ['eu1', '1', '0', '0', '1'], [$key]));
        self::assertSame(0, $this->client()->exists($key), 'a protected command must not create the registry');

        $this->initialize();
        $this->client()->hdel($key, ['wm_u']);
        self::assertSame(['err:RT_CORRUPT registry field is missing'], $this->driver('begin', ['eu1', '1', '0', '0', '1'], [$key]));
    }

    public function testAnotherDeploymentsIdentityIsRefused(): void
    {
        $this->initialize();
        self::assertSame(['err:RT_IDENTITY registry identity differs from the expected one'], $this->driver('begin', ['other', '1', '0', '0', '1'], [$this->prefix . ':m']));
    }
}
