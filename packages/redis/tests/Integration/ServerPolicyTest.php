<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Integration;

use Enaxon\Reqtide\Exception\StateUnavailableException;
use Enaxon\Reqtide\Policy\CooldownPolicy;
use Enaxon\Reqtide\Redis\RedisConnectionOptions;
use Enaxon\Reqtide\Redis\RedisCooldownBackend;
use Enaxon\Reqtide\Redis\RedisRegistry;
use Enaxon\Reqtide\Redis\RedisRegistryAdmin;
use Enaxon\Reqtide\Redis\Tests\Support\RedisEndpoint;
use Enaxon\Reqtide\State\CooldownStatus;
use Enaxon\Reqtide\Tests\Contract\ContractIdentities;
use Enaxon\Reqtide\Value\CommandId;
use Enaxon\Reqtide\Value\Duration;
use PHPUnit\Framework\TestCase;
use Predis\Client;

/**
 * Operator misconfiguration. The server policy is readable and reported; a full server with `noeviction`
 * refuses every command definitely and stores nothing; a server that evicts keys can delete the registry, and that loss
 * is reported as an unavailable registry, never repaired or ignored.
 */
final class ServerPolicyTest extends TestCase
{
    private const SECRET = 'a-host-managed-secret-of-32-bytes!!';

    private string $prefix = '';

    private ?Client $client = null;

    private ?ContractIdentities $ids = null;

    protected function setUp(): void
    {
        $this->prefix = 'rt_pol_' . bin2hex(random_bytes(5));
        $this->client = new Client(RedisEndpoint::forTest()->parameters(10.0));
    }

    protected function tearDown(): void
    {
        if ($this->client === null) {
            return;
        }
        $this->config('maxmemory', '0');
        $this->config('maxmemory-policy', 'noeviction');
        foreach (['', 'junk:'] as $sub) {
            $cursor = '0';
            do {
                /** @var array{0: string, 1: list<string>} $page */
                $page = $this->client()->scan($cursor, ['MATCH' => $this->prefix . ':' . $sub . '*', 'COUNT' => 500]);
                $cursor = (string) $page[0];
                if ($page[1] !== []) {
                    $this->client()->del($page[1]);
                }
            } while ($cursor !== '0');
        }
    }

    private function client(): Client
    {
        return $this->client ?? throw new \LogicException('No client.');
    }

    private function config(string $name, string $value): void
    {
        $error = false;
        $this->client()->executeRaw(['CONFIG', 'SET', $name, $value], $error);
        self::assertFalse($error, 'CONFIG SET ' . $name . ' must be allowed on the test server.');
    }

    private function options(): RedisConnectionOptions
    {
        $endpoint = RedisEndpoint::forTest();

        return new RedisConnectionOptions($endpoint->host, 2.0, 2.0, $endpoint->port);
    }

    private function registry(): RedisRegistry
    {
        $registry = new RedisRegistry($this->prefix, 'policy-test', self::SECRET);
        (new RedisRegistryAdmin($this->options(), $registry))->initialize();

        return $registry;
    }

    private function usedMemory(): int
    {
        $info = $this->client()->info('memory');
        $memory = $info['Memory'] ?? $info['memory'] ?? $info;
        self::assertIsArray($memory);
        $used = $memory['used_memory'] ?? null;
        self::assertIsString($used);

        return (int) $used;
    }

    /** Fills the server with junk keys until a write is refused (noeviction) or $stop() is true. */
    private function fillUntil(callable $stop, int $limit = 50_000): bool
    {
        $value = str_repeat('x', 2_048);
        for ($i = 0; $i < $limit; ++$i) {
            $error = false;
            $this->client()->executeRaw(['SET', $this->prefix . ':junk:' . $i, $value], $error);
            if ($error) {
                return true;
            }
            if ($i % 20 === 0 && $stop()) {
                return true;
            }
        }

        return false;
    }

    private function cool(string $subject): CooldownPolicy
    {
        return new CooldownPolicy(ContractIdentities::identity($subject), Duration::seconds(60));
    }

    public function testTheReportShowsNoevictionAndAMemoryLimit(): void
    {
        $report = (new RedisRegistryAdmin($this->options(), $this->registry()))->inspectServer();

        self::assertSame('noeviction', $report->evictionPolicy);
        self::assertSame(0, $report->maxMemoryBytes);
        self::assertTrue($report->protectsState());
    }

    public function testAnEvictingPolicyWithAMemoryLimitIsReportedAsUnsafe(): void
    {
        $admin = new RedisRegistryAdmin($this->options(), $this->registry());
        $this->config('maxmemory-policy', 'allkeys-lru');
        self::assertTrue($admin->inspectServer()->protectsState(), 'without a memory limit nothing is evicted');

        $this->config('maxmemory', (string) ($this->usedMemory() + 50_000_000));
        $report = $admin->inspectServer();
        self::assertSame('allkeys-lru', $report->evictionPolicy);
        self::assertFalse($report->protectsState());
    }

    public function testAFullServerWithNoevictionRefusesDefinitelyAndStoresNothing(): void
    {
        $registry = $this->registry();
        $backend = new RedisCooldownBackend($this->options(), $registry);
        $admin = new RedisRegistryAdmin($this->options(), $registry);
        $backend->extend($this->cool('a'), Duration::seconds(30), $this->command());
        $before = $admin->verify();

        $this->config('maxmemory', (string) ($this->usedMemory() + 600_000));
        self::assertTrue($this->fillUntil(static fn(): bool => false), 'the server must refuse writes when it is full');
        // A full server hovers at its limit; a limit far below the use keeps it over the limit for every command that follows.
        $this->config('maxmemory', (string) max(1_048_576, intdiv($this->usedMemory(), 2)));

        foreach ([
            'new' => fn(): mixed => $backend->extend($this->cool('b'), Duration::seconds(30), $this->command()),
            'existing' => fn(): mixed => $backend->extend($this->cool('a'), Duration::seconds(90), $this->command()),
        ] as $name => $call) {
            try {
                $call();
                self::fail('A full server must refuse the command: ' . $name);
            } catch (StateUnavailableException $failure) {
                self::assertStringContainsString('oom', $failure->getMessage());
            }
        }

        $this->config('maxmemory', '0');
        $after = $admin->verify();
        self::assertSame([$before->partitions, $before->records], [$after->partitions, $after->records], 'a refused command changed no counter');
        self::assertSame(CooldownStatus::Clear, $backend->inspect($this->cool('b'))->status, 'and stored no cooldown');
        self::assertSame(CooldownStatus::Active, $backend->inspect($this->cool('a'))->status, 'the earlier cooldown is intact');
    }

    public function testAnEvictingServerCanDeleteTheRegistryAndTheLossIsReported(): void
    {
        $registry = $this->registry();
        $backend = new RedisCooldownBackend($this->options(), $registry);
        $admin = new RedisRegistryAdmin($this->options(), $registry);
        $backend->extend($this->cool('a'), Duration::seconds(30), $this->command());

        $this->config('maxmemory-policy', 'allkeys-lru');
        $this->config('maxmemory', (string) ($this->usedMemory() + 400_000));
        $metadata = $this->prefix . ':m';
        $lost = $this->fillUntil(fn(): bool => $this->client()->executeRaw(['TYPE', $metadata]) === 'none', 100_000);
        self::assertTrue($lost, 'the least recently used key of a full evicting server is deleted');

        $this->config('maxmemory', '0');
        $this->config('maxmemory-policy', 'noeviction');
        try {
            $admin->verify();
            self::fail('A lost registry must be reported.');
        } catch (StateUnavailableException) {
        }
        try {
            $backend->inspect($this->cool('a'));
            self::fail('A lost registry must reject protected dispatch.');
        } catch (StateUnavailableException) {
        }
        self::assertSame(0, $this->client()->exists($metadata), 'nothing re-created the registry');
    }

    private function command(): CommandId
    {
        return ($this->ids ??= new ContractIdentities())->command();
    }
}
