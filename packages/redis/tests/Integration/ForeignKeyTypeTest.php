<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Integration;

use Enaxon\Reqtide\Exception\CorruptStateException;
use Enaxon\Reqtide\Policy\ConcurrencyPolicy;
use Enaxon\Reqtide\Redis\RedisConcurrencyBackend;
use Enaxon\Reqtide\Redis\RedisConnectionOptions;
use Enaxon\Reqtide\Redis\RedisRegistry;
use Enaxon\Reqtide\Redis\RedisRegistryAdmin;
use Enaxon\Reqtide\Redis\State\StateKind;
use Enaxon\Reqtide\Redis\Tests\Support\RedisEndpoint;
use Enaxon\Reqtide\Tests\Contract\ContractIdentities;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\PositiveInt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Predis\Client;

/**
 * A key under the registry prefix that holds a value of the wrong type is damaged state: the server answers WRONGTYPE, the
 * call fails as CorruptStateException (never as an indeterminate outcome) and a registry that had no records gets none.
 */
final class ForeignKeyTypeTest extends TestCase
{
    private const SECRET = 'a-host-managed-secret-of-32-bytes!!';

    private string $prefix = '';

    protected function setUp(): void
    {
        $this->prefix = 'rt_wt_' . bin2hex(random_bytes(5));
    }

    protected function tearDown(): void
    {
        $client = $this->client();
        $cursor = '0';
        do {
            /** @var array{0: string, 1: list<string>} $page */
            $page = $client->scan($cursor, ['MATCH' => $this->prefix . ':*', 'COUNT' => 500]);
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

    /** @return iterable<string, array{string}> */
    public static function foreignKeys(): iterable
    {
        yield 'partition' => ['partition'];
        yield 'tombstone queue' => ['queue'];
        yield 'metadata' => ['metadata'];
    }

    #[DataProvider('foreignKeys')]
    public function testAKeyOfTheWrongTypeIsCorruptStateAndNothingIsWritten(string $which): void
    {
        $registry = new RedisRegistry($this->prefix, 'wrongtype-test', self::SECRET);
        $admin = new RedisRegistryAdmin($this->options(), $registry);
        $admin->initialize();
        $policy = new ConcurrencyPolicy(ContractIdentities::identity('a'), new PositiveInt(2), Duration::seconds(30));
        $key = match ($which) {
            'partition' => $registry->keys()->policyPartition(StateKind::Concurrency, $policy->identity),
            'queue' => $registry->keys()->tombstoneQueue(),
            default => $registry->keys()->metadata(),
        };
        $client = $this->client();
        $client->del($key);
        $client->set($key, 'foreign');
        $before = $client->keys($this->prefix . ':*');
        sort($before);

        try {
            (new RedisConcurrencyBackend($this->options(), $registry))->acquire($policy, (new ContractIdentities())->owner(), (new ContractIdentities())->command());
            self::fail('expected CorruptStateException');
        } catch (CorruptStateException $failure) {
            self::assertStringNotContainsString('foreign', $failure->getMessage());
        }

        $after = $client->keys($this->prefix . ':*');
        sort($after);
        self::assertSame($before, $after, 'no key was created or removed');
        self::assertSame('foreign', $client->get($key));
    }
}
