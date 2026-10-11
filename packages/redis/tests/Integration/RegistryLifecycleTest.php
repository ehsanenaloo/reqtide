<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Integration;

use Enaxon\Reqtide\Exception\CorruptStateException;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Exception\StateUnavailableException;
use Enaxon\Reqtide\Redis\RedisConnectionOptions;
use Enaxon\Reqtide\Redis\RedisRegistry;
use Enaxon\Reqtide\Redis\RedisRegistryAdmin;
use Enaxon\Reqtide\Redis\Tests\Support\RedisEndpoint;
use PHPUnit\Framework\TestCase;
use Predis\Client;

/**
 * The registry lifecycle against a real server. Absent registry rejects, initialization is explicit and
 * idempotent, a wrong identity or schema is a typed corrupt-state error, a damaged watermark is rejected, retirement is
 * observable, and nothing creates a registry implicitly.
 */
final class RegistryLifecycleTest extends TestCase
{
    private const SECRET = 'a-host-managed-secret-of-32-bytes!!';

    private string $prefix = '';

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

    private function admin(): Client
    {
        return new Client(RedisEndpoint::forTest()->parameters(10.0));
    }

    private function registry(string $identity = 'eu1-prod', int $partitions = 10_000, int $records = 20_000): RedisRegistry
    {
        return new RedisRegistry($this->prefix, $identity, self::SECRET, $partitions, $records);
    }

    private function adminFor(RedisRegistry $registry): RedisRegistryAdmin
    {
        $endpoint = RedisEndpoint::forTest();

        return new RedisRegistryAdmin(new RedisConnectionOptions($endpoint->host, 2.0, 2.0, $endpoint->port), $registry);
    }

    public function testAnAbsentRegistryIsUnavailableAndVerifyCreatesNothing(): void
    {
        $admin = $this->adminFor($this->registry());
        try {
            $admin->verify();
            self::fail('expected an exception');
        } catch (StateUnavailableException) {
        }
        self::assertSame(0, $this->admin()->exists($this->prefix . ':m'));
    }

    public function testInitializeCreatesWithTheServerTimeAsWatermarkAndIsIdempotent(): void
    {
        $registry = $this->registry();
        $admin = $this->adminFor($registry);
        $before = $this->admin()->time();
        self::assertIsArray($before);
        self::assertIsString($before[0]);

        $created = $admin->initialize();
        self::assertTrue($created->created);
        self::assertSame(0, $created->partitions);
        self::assertSame(0, $created->records);
        self::assertSame(10_000, $created->maxPartitions);
        self::assertSame(20_000, $created->maxRecords);
        self::assertGreaterThanOrEqual((int) $before[0], $created->watermarkSeconds);
        self::assertLessThan((int) $before[0] + 30, $created->watermarkSeconds);
        self::assertLessThan(1_000_000, $created->watermarkMicroseconds);

        $again = $admin->initialize();
        self::assertFalse($again->created);
        self::assertSame($created->watermarkSeconds, $again->watermarkSeconds);
        self::assertSame($created->watermarkMicroseconds, $again->watermarkMicroseconds);
        self::assertEquals($again, $admin->verify(), 'verify reports the same stored state');
    }

    public function testInitializeWithDifferentCeilingsIsRefused(): void
    {
        $this->adminFor($this->registry())->initialize();
        $this->expectException(InvalidConfigurationException::class);
        $this->adminFor($this->registry('eu1-prod', 5, 10))->initialize();
    }

    public function testAWrongIdentityIsACorruptStateErrorForEveryOperation(): void
    {
        $this->adminFor($this->registry())->initialize();
        $other = $this->adminFor($this->registry('some-other-deployment'));
        foreach ([fn() => $other->verify(), fn() => $other->initialize(), fn() => $other->retire()] as $call) {
            try {
                $call();
                self::fail('expected an exception');
            } catch (CorruptStateException) {
            }
        }
        self::assertSame(1, $this->admin()->exists($this->prefix . ':m'), 'a refused retirement deletes nothing');
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function damage(): iterable
    {
        yield 'schema mismatch' => [['HSET', 'schema', '99']];
        yield 'missing watermark seconds' => [['HDEL', 'wm_s']];
        yield 'missing watermark micros' => [['HDEL', 'wm_u']];
        yield 'non-numeric watermark' => [['HSET', 'wm_s', 'abc']];
        yield 'non-normalized microseconds' => [['HSET', 'wm_u', '1000000']];
        yield 'fractional counter' => [['HSET', 'partitions', '1.5']];
        yield 'counter above its ceiling' => [['HSET', 'partitions', '10001']];
        yield 'missing identity' => [['HDEL', 'identity']];
    }

    /** @param list<string> $change */
    #[\PHPUnit\Framework\Attributes\DataProvider('damage')]
    public function testADamagedRegistryIsACorruptStateErrorAndIsNeverRepaired(array $change): void
    {
        $admin = $this->adminFor($this->registry());
        $admin->initialize();
        $key = $this->prefix . ':m';
        $command = array_shift($change);
        $this->admin()->executeRaw([$command, $key, ...$change]);
        $snapshot = $this->admin()->hgetall($key);

        foreach ([fn() => $admin->verify(), fn() => $admin->initialize()] as $call) {
            try {
                $call();
                self::fail('expected an exception');
            } catch (CorruptStateException) {
            }
        }
        self::assertSame($snapshot, $this->admin()->hgetall($key), 'neither verify nor initialize repairs a damaged registry');
    }

    public function testRetirementIsObservableAndAfterItTheRegistryIsAbsent(): void
    {
        $admin = $this->adminFor($this->registry());
        $created = $admin->initialize();

        $retired = $admin->retire();
        self::assertFalse($retired->created);
        self::assertSame($created->watermarkSeconds, $retired->watermarkSeconds, 'retirement reports the watermark it deleted');
        self::assertSame(0, $this->admin()->exists($this->prefix . ':m'));

        $this->expectException(StateUnavailableException::class);
        $admin->verify();
    }

    public function testRetirementReportsPartitionsLeftBehind(): void
    {
        $admin = $this->adminFor($this->registry());
        $admin->initialize();
        $this->admin()->hset($this->prefix . ':m', 'partitions', '3');
        $this->admin()->hset($this->prefix . ':m', 'records', '7');

        $retired = $admin->retire();
        self::assertSame(3, $retired->partitions);
        self::assertSame(7, $retired->records);
    }
}
