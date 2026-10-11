<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Integration;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Policy\CooldownPolicy;
use Enaxon\Reqtide\Redis\Client\CommandEffect;
use Enaxon\Reqtide\Redis\Client\RedisBridge;
use Enaxon\Reqtide\Redis\RedisConnectionOptions;
use Enaxon\Reqtide\Redis\RedisCooldownBackend;
use Enaxon\Reqtide\Redis\RedisRegistry;
use Enaxon\Reqtide\Redis\RedisRegistryAdmin;
use Enaxon\Reqtide\Redis\Script\ScriptLibrary;
use Enaxon\Reqtide\Redis\Tests\Support\RedisEndpoint;
use Enaxon\Reqtide\Tests\Contract\ContractIdentities;
use Enaxon\Reqtide\Value\Duration;
use PHPUnit\Framework\TestCase;
use Predis\Client;

/**
 * Retirement leaves partition and queue keys behind; purging removes exactly those, only after retirement, and a
 * registry created again under the same prefix starts with true counters.
 */
final class RegistryPurgeTest extends TestCase
{
    private const SECRET = 'a-host-managed-secret-of-32-bytes!!';

    private string $prefix = '';

    private ?ContractIdentities $ids = null;

    protected function setUp(): void
    {
        $this->prefix = 'rt_purge_' . bin2hex(random_bytes(5));
    }

    protected function tearDown(): void
    {
        $this->deleteAll($this->prefix . ':*');
        $this->deleteAll('other_' . $this->prefix . ':*');
    }

    private function deleteAll(string $pattern): void
    {
        $client = $this->client();
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

    private function client(): Client
    {
        return new Client(RedisEndpoint::forTest()->parameters(10.0));
    }

    private function options(): RedisConnectionOptions
    {
        $endpoint = RedisEndpoint::forTest();

        return new RedisConnectionOptions($endpoint->host, 2.0, 2.0, $endpoint->port);
    }

    /** @return list<string> */
    private function keys(string $prefix): array
    {
        $found = [];
        $cursor = '0';
        do {
            /** @var array{0: string, 1: list<string>} $page */
            $page = $this->client()->scan($cursor, ['MATCH' => $prefix . ':*', 'COUNT' => 500]);
            $cursor = (string) $page[0];
            array_push($found, ...$page[1]);
        } while ($cursor !== '0');
        sort($found);

        return $found;
    }

    private function cool(string $subject): CooldownPolicy
    {
        return new CooldownPolicy(ContractIdentities::identity($subject), Duration::seconds(60));
    }

    public function testPurgeRemovesTheLeftoversOfARetiredRegistryOnly(): void
    {
        $registry = new RedisRegistry($this->prefix, 'purge-test', self::SECRET);
        $admin = new RedisRegistryAdmin($this->options(), $registry);
        $admin->initialize();
        $backend = new RedisCooldownBackend($this->options(), $registry);
        $this->ids ??= new ContractIdentities();
        for ($i = 0; $i < 3; ++$i) {
            $backend->extend($this->cool('s' . $i), Duration::seconds(30), $this->ids->command());
        }
        $other = 'other_' . $this->prefix;
        $this->client()->set($other . ':p:x', 'keep');
        $this->client()->set($this->prefix . ':note', 'not a registry key');

        $retired = $admin->retire();
        self::assertSame(3, $retired->partitions, 'retirement reports what it left behind');
        self::assertGreaterThanOrEqual(3, count($this->keys($this->prefix)));

        $removed = $admin->purgeRetired();
        self::assertSame(4, $removed, 'three partitions and the registry index');
        self::assertSame([$this->prefix . ':note'], $this->keys($this->prefix), 'a key that is not part of the layout stays');
        self::assertSame(['keep'], [$this->client()->get($other . ':p:x')], 'another prefix is never touched');
        self::assertSame(0, $admin->purgeRetired(), 'a second run finds nothing');

        $again = $admin->initialize();
        self::assertTrue($again->created);
        self::assertSame([0, 0], [$again->partitions, $again->records]);
    }

    public function testPurgeRefusesWhileTheRegistryExistsAndRemovesNothing(): void
    {
        $registry = new RedisRegistry($this->prefix, 'purge-test', self::SECRET);
        $admin = new RedisRegistryAdmin($this->options(), $registry);
        $admin->initialize();
        $backend = new RedisCooldownBackend($this->options(), $registry);
        $this->ids ??= new ContractIdentities();
        $backend->extend($this->cool('a'), Duration::seconds(30), $this->ids->command());
        $before = $this->keys($this->prefix);

        try {
            $admin->purgeRetired();
            self::fail('A registry in use must not be purged.');
        } catch (InvalidConfigurationException) {
        }
        self::assertSame($before, $this->keys($this->prefix));
        self::assertSame(1, $admin->verify()->partitions);
    }

    public function testInitializeRefusesAPrefixThatStillHoldsTheKeysOfARetiredRegistry(): void
    {
        $registry = new RedisRegistry($this->prefix, 'purge-test', self::SECRET);
        $admin = new RedisRegistryAdmin($this->options(), $registry);
        $admin->initialize();
        $backend = new RedisCooldownBackend($this->options(), $registry);
        $this->ids ??= new ContractIdentities();
        $backend->extend($this->cool('a'), Duration::seconds(30), $this->ids->command());
        $admin->retire();
        $leftovers = $this->keys($this->prefix);
        self::assertNotSame([], $leftovers);

        try {
            $admin->initialize();
            self::fail('A prefix with leftovers must not be adopted.');
        } catch (InvalidConfigurationException $refused) {
            self::assertStringContainsString('purgeRetired', $refused->getMessage());
        }
        self::assertSame($leftovers, $this->keys($this->prefix), 'nothing was written, the registry was not created');

        $admin->purgeRetired();
        $created = $admin->initialize();
        self::assertTrue($created->created);
        self::assertSame([0, 0], [$created->partitions, $created->records]);
    }

    public function testInitializeRefusesALeftoverTombstoneQueueAndStillConfirmsAnExistingRegistry(): void
    {
        $registry = new RedisRegistry($this->prefix, 'purge-test', self::SECRET);
        $admin = new RedisRegistryAdmin($this->options(), $registry);
        $queue = $registry->keys()->tombstoneQueue();
        $this->client()->rpush($queue, ['entry']);
        try {
            $admin->initialize();
            self::fail('A leftover queue must not be adopted.');
        } catch (InvalidConfigurationException) {
        }
        self::assertSame(0, $this->client()->exists($registry->keys()->metadata()));

        $this->client()->del($queue);
        self::assertTrue($admin->initialize()->created);
        $this->client()->rpush($queue, ['entry']);
        self::assertFalse($admin->initialize()->created, 'an existing registry is confirmed as before');
    }

    public function testThePurgeScriptChecksTheRegistryAgainWhenItRunsAndRemovesNothingIfItExists(): void
    {
        $registry = new RedisRegistry($this->prefix, 'purge-test', self::SECRET);
        $admin = new RedisRegistryAdmin($this->options(), $registry);
        $admin->initialize();
        $backend = new RedisCooldownBackend($this->options(), $registry);
        $this->ids ??= new ContractIdentities();
        $backend->extend($this->cool('a'), Duration::seconds(30), $this->ids->command());
        $metadata = $registry->keys()->metadata();
        $all = $this->keys($this->prefix);
        $page = array_values(array_filter($all, static fn(string $key): bool => $key !== $metadata));
        self::assertNotSame([], $page);

        // The page was read while the registry was retired; the registry is initialized again before the removal runs.
        $bridge = RedisBridge::forOptions($this->options());
        try {
            $bridge->execute(ScriptLibrary::registryPurge(), [$metadata, ...$page], [], CommandEffect::Mutating);
            self::fail('A live registry must not lose its keys.');
        } catch (InvalidConfigurationException) {
        }
        self::assertSame($all, $this->keys($this->prefix));
        self::assertSame(1, $admin->verify()->partitions);
    }

    public function testPurgeRefusesAnEmptyRegistryThatExists(): void
    {
        $registry = new RedisRegistry($this->prefix, 'purge-test', self::SECRET);
        $admin = new RedisRegistryAdmin($this->options(), $registry);
        $admin->initialize();
        $this->expectException(InvalidConfigurationException::class);
        $admin->purgeRetired();
    }
}
