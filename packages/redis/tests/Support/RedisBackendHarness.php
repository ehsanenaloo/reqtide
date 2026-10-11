<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Support;

use Enaxon\Reqtide\Policy\RetryBudgetPolicy;
use Enaxon\Reqtide\Redis\RedisCircuitBackend;
use Enaxon\Reqtide\Redis\RedisConcurrencyBackend;
use Enaxon\Reqtide\Redis\RedisConnectionOptions;
use Enaxon\Reqtide\Redis\RedisCooldownBackend;
use Enaxon\Reqtide\Redis\RedisRateLimitBackend;
use Enaxon\Reqtide\Redis\RedisRegistry;
use Enaxon\Reqtide\Redis\RedisRegistryAdmin;
use Enaxon\Reqtide\Redis\RedisRegistryMaintenance;
use Enaxon\Reqtide\Redis\RedisRetryBudgetBackend;
use Enaxon\Reqtide\Redis\Script\ScriptLibrary;
use Enaxon\Reqtide\Redis\State\StateKind;
use Enaxon\Reqtide\State\CircuitBackend;
use Enaxon\Reqtide\State\ConcurrencyBackend;
use Enaxon\Reqtide\State\CooldownBackend;
use Enaxon\Reqtide\State\RateLimitBackend;
use Enaxon\Reqtide\State\RetryBudgetBackend;
use Enaxon\Reqtide\State\StateScope;
use Enaxon\Reqtide\Tests\Contract\CircuitHarness;
use Enaxon\Reqtide\Tests\Contract\ConcurrencyHarness;
use Enaxon\Reqtide\Tests\Contract\ControllableTime;
use Enaxon\Reqtide\Tests\Contract\CooldownHarness;
use Enaxon\Reqtide\Tests\Contract\RateLimitHarness;
use Enaxon\Reqtide\Tests\Contract\RetryBudgetHarness;
use Predis\Client;

/**
 * The shared contract suites' view of a Redis server with a controllable clock. Every backend the harness creates gets its
 * own registry (a fresh key prefix, explicitly initialized with the requested ceilings), so backends created by one
 * harness are independent state that share one clock, as the contract requires. `dispose()` deletes everything the
 * harness created.
 */
final class RedisBackendHarness implements CircuitHarness, ConcurrencyHarness, CooldownHarness, RateLimitHarness, RetryBudgetHarness
{
    private const SECRET = 'a-host-managed-secret-of-32-bytes!!';

    /** @var list<string> */
    private array $prefixes = [];

    /** @var list<RedisRegistry> */
    private array $registries = [];

    /** @var array<int, RedisRegistry> registry of each retry budget backend, by object id */
    private array $budgetRegistries = [];

    private function __construct(private readonly RedisEndpoint $endpoint, private readonly ControlledClock $clock) {}

    public static function create(): self
    {
        [$endpoint, $file] = RedisEndpoint::forClockedTest();
        $observer = new Client($endpoint->parameters(10.0));
        $clock = new ControlledClock($file, static function () use ($observer): array {
            /** @var array{0: numeric-string, 1: numeric-string} $time */
            $time = $observer->time();

            return [(int) $time[0], (int) $time[1]];
        });

        return new self($endpoint, $clock);
    }

    public function time(): ControllableTime
    {
        return $this->clock;
    }

    public function expectedScope(): StateScope
    {
        return StateScope::SharedAuthority;
    }

    public function expectsFiniteIoTimeout(): bool
    {
        return true;
    }


    /**
     * Runs bounded maintenance steps on every registry of this harness until a step removes nothing. It changes no
     * protective state.
     */
    public function reclaimExpired(): void
    {
        foreach ($this->registries as $registry) {
            $maintenance = new RedisRegistryMaintenance($this->options(), $registry);
            for ($round = 0; $round < 1_000; ++$round) {
                $removed = $maintenance->step();
                if ($removed['terminalRecords'] === 0 && $removed['partitions'] === 0) {
                    break;
                }
            }
        }
    }

    public function cooldown(int $maxPartitions = 10_000, int $maxRecords = 20_000): CooldownBackend
    {
        return new RedisCooldownBackend($this->options(), $this->registry($maxPartitions, $maxRecords));
    }

    public function concurrency(int $maxPartitions = 10_000, int $maxRecords = 20_000): ConcurrencyBackend
    {
        return new RedisConcurrencyBackend($this->options(), $this->registry($maxPartitions, $maxRecords));
    }

    public function rateLimit(int $maxPartitions = 10_000): RateLimitBackend
    {
        return new RedisRateLimitBackend($this->options(), $this->registry($maxPartitions));
    }

    public function circuit(int $maxPartitions = 10_000, int $maxRecords = 20_000): CircuitBackend
    {
        return new RedisCircuitBackend($this->options(), $this->registry($maxPartitions, $maxRecords));
    }

    public function retryBudget(int $maxPartitions = 10_000, int $maxRecords = 20_000): RetryBudgetBackend
    {
        $registry = $this->registry($maxPartitions, $maxRecords);
        $backend = new RedisRetryBudgetBackend($this->options(), $registry);
        $this->budgetRegistries[spl_object_id($backend)] = $registry;

        return $backend;
    }

    /**
     * Balance and pending cost in scaled units as of the protected effective time, computed by the production Lua text
     * (accrue, then expire, on local values only) with a driver that writes nothing.
     */
    public function observeBudget(RetryBudgetBackend $backend, RetryBudgetPolicy $policy): ?array
    {
        $registry = $this->budgetRegistries[spl_object_id($backend)] ?? throw new \LogicException('The backend was not created by this harness.');
        $driver = file_get_contents(dirname(__DIR__) . '/Support/lua/budget_view_driver.lua');
        if ($driver === false) {
            throw new \RuntimeException('The budget view driver is missing.');
        }
        $partition = $registry->keys()->policyPartition(StateKind::RetryBudget, $policy->identity);
        $reply = $this->client()->eval(
            ScriptLibrary::libraryText(ScriptLibrary::INDEXED) . "\n" . $driver,
            3,
            $registry->keys()->metadata(),
            $partition,
            $partition . ':a',
            $registry->identity,
            (string) RedisRegistry::SCHEMA_VERSION,
        );
        if (!is_array($reply) || $reply === []) {
            return null;
        }

        /** @var array{0: numeric-string, 1: numeric-string} $reply */
        return ['balance' => (int) $reply[0], 'pending' => (int) $reply[1]];
    }

    /** The redis keys (all registries of this harness) as seen by the server, for assertions about stored state. */
    public function client(): Client
    {
        return new Client($this->endpoint->parameters(10.0));
    }

    /** @return list<string> the key prefixes of the registries created so far */
    public function prefixes(): array
    {
        return $this->prefixes;
    }

    public function dispose(): void
    {
        $client = $this->client();
        foreach ($this->prefixes as $prefix) {
            $cursor = '0';
            do {
                /** @var array{0: string, 1: list<string>} $page */
                $page = $client->scan($cursor, ['MATCH' => $prefix . ':*', 'COUNT' => 500]);
                $cursor = (string) $page[0];
                if ($page[1] !== []) {
                    $client->del($page[1]);
                }
            } while ($cursor !== '0');
        }
        $this->prefixes = [];
    }

    public function options(): RedisConnectionOptions
    {
        return new RedisConnectionOptions($this->endpoint->host, 2.0, 5.0, $this->endpoint->port);
    }

    /**
     * A fresh, explicitly initialized registry (own key prefix). Pass it to several backends to share state. It is created
     * with the clock at the origin of the axis and the clock is put back, so the authority watermark starts at the origin
     * and a suite that moves the clock to zero is not held back by the moment the backend happened to be built.
     */
    public function registry(int $maxPartitions = 10_000, int $maxRecords = 20_000): RedisRegistry
    {
        $prefix = 'rt_c_' . bin2hex(random_bytes(6));
        $registry = new RedisRegistry($prefix, 'contract', self::SECRET, $maxPartitions, $maxRecords);
        $this->prefixes[] = $prefix;
        $this->registries[] = $registry;
        $position = $this->clock->now();
        $this->clock->moveTo(0);
        try {
            (new RedisRegistryAdmin($this->options(), $registry))->initialize();
        } finally {
            $this->clock->moveTo($position);
        }

        return $registry;
    }
}
