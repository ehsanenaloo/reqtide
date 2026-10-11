<?php

declare(strict_types=1);

/**
 * Raw measurements of the Redis backends. No targets, no budgets, no claims.
 *
 *   php -d pcov.enabled=0 benchmarks/redis-bench.php --host=redis74 [--port=6379] [--iterations=2000] [--warmup=300]
 *        [--contention=1,8,32] [--calls=300] [--out=build/bench/redis.json]
 *
 * For every scenario: `warmup` operations discarded, then `iterations` operations timed one by one with hrtime(true) (all
 * raw samples are stored). The server's own counters are reset before the timed loop (`CONFIG RESETSTAT`) and read after it:
 * the number of `EVALSHA` calls per operation (the client round trips) and the server's `usec_per_call` for the scripts.
 * Memory is the change of `used_memory` after creating 1,000 partitions of a kind. Contention runs the same call from 1, 8 or
 * 32 processes on one hot partition or on one partition each, started on a barrier of the server clock.
 *
 * Needs a Redis server with `CONFIG RESETSTAT` allowed. It writes only below the key prefix of its own registry and removes
 * it at the end.
 */

namespace Reqtide\Benchmarks;

use Enaxon\Reqtide\Execution\OperationSafety;
use Enaxon\Reqtide\Execution\ReplaySafetyContract;
use Enaxon\Reqtide\Outcome\CircuitVerdict;
use Enaxon\Reqtide\Outcome\ExceptionClassifier;
use Enaxon\Reqtide\Pipeline\PipelineBuilder;
use Enaxon\Reqtide\Policy\AttemptTimeoutRequirement;
use Enaxon\Reqtide\Policy\BackoffMode;
use Enaxon\Reqtide\Policy\ConcurrencyPolicy;
use Enaxon\Reqtide\Policy\CooldownPolicy;
use Enaxon\Reqtide\Policy\JitterMode;
use Enaxon\Reqtide\Policy\RateLimitPolicy;
use Enaxon\Reqtide\Policy\RetryBudgetPolicy;
use Enaxon\Reqtide\Policy\TokenBucketOptions;
use Enaxon\Reqtide\Redis\Client\CommandEffect;
use Enaxon\Reqtide\Redis\Client\RedisBridge;
use Enaxon\Reqtide\Redis\RedisCircuitBackend;
use Enaxon\Reqtide\Redis\RedisConcurrencyBackend;
use Enaxon\Reqtide\Redis\RedisConnectionOptions;
use Enaxon\Reqtide\Redis\RedisCooldownBackend;
use Enaxon\Reqtide\Redis\RedisEnvironment;
use Enaxon\Reqtide\Redis\RedisRateLimitBackend;
use Enaxon\Reqtide\Redis\RedisRegistry;
use Enaxon\Reqtide\Redis\RedisRegistryAdmin;
use Enaxon\Reqtide\Redis\RedisRetryBudgetBackend;
use Enaxon\Reqtide\Redis\Script\Script;
use Enaxon\Reqtide\Redis\Tests\Support\BarrierHarness;
use Enaxon\Reqtide\Redis\Tests\Support\RedisEndpoint;
use Enaxon\Reqtide\Tests\Contract\ContractCircuitRig;
use Enaxon\Reqtide\Tests\Contract\ContractIdentities;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\PositiveInt;
use Enaxon\Reqtide\Value\Ratio;
use Predis\Client;

(static function (): void {
    foreach ([getenv('COMPOSER_VENDOR_DIR') !== false ? getenv('COMPOSER_VENDOR_DIR') . '/autoload.php' : null, '/opt/reqtide-vendor/autoload.php', __DIR__ . '/../vendor/autoload.php'] as $file) {
        if ($file !== null && is_file($file)) {
            require $file;

            return;
        }
    }
    fwrite(STDERR, "No Composer autoloader found.\n");
    exit(2);
})();

$options = getopt('', ['host:', 'port::', 'iterations::', 'warmup::', 'contention::', 'calls::', 'out::', 'scenario::']);
$host = is_string($options['host'] ?? null) ? $options['host'] : '';
$port = is_string($options['port'] ?? null) ? (int) $options['port'] : 6379;
$iterations = is_string($options['iterations'] ?? null) ? (int) $options['iterations'] : 2000;
$warmup = is_string($options['warmup'] ?? null) ? (int) $options['warmup'] : 300;
$calls = is_string($options['calls'] ?? null) ? (int) $options['calls'] : 300;
$workerCounts = array_map('intval', explode(',', is_string($options['contention'] ?? null) ? $options['contention'] : '1,8,32'));
$only = is_string($options['scenario'] ?? null) ? $options['scenario'] : null;
$out = is_string($options['out'] ?? null) ? $options['out'] : 'build/bench/redis.json';
if ($host === '' || $iterations < 100) {
    fwrite(STDERR, "usage: redis-bench.php --host=HOST [--port=N] [--iterations>=100] ...\n");
    exit(2);
}

$secret = 'a-host-managed-secret-of-32-bytes!!';
$prefix = 'rt_bench_' . bin2hex(random_bytes(4));
$connection = new RedisConnectionOptions($host, 2.0, 10.0, $port);
$registry = new RedisRegistry($prefix, 'bench', $secret, 100_000, 400_000);
(new RedisRegistryAdmin($connection, $registry))->initialize();
$client = new Client(['scheme' => 'tcp', 'host' => $host, 'port' => $port, 'timeout' => 5.0, 'read_write_timeout' => 30.0]);

/** @return array<string, float|int> */
$stats = static function (array $samples): array {
    sort($samples);
    $n = count($samples);
    $rank = static fn(float $p): int => $samples[max(0, (int) ceil($p * $n) - 1)];

    return ['count' => $n, 'min' => $samples[0], 'p50' => $rank(0.50), 'p95' => $rank(0.95), 'p99' => $rank(0.99), 'max' => $samples[$n - 1], 'mean' => array_sum($samples) / $n];
};

$commandstats = static function () use ($client): array {
    $text = (string) $client->executeRaw(['INFO', 'commandstats']);
    $result = [];
    if (preg_match_all('~^cmdstat_([a-z|_]+):calls=(\d+),usec=(\d+),usec_per_call=([0-9.]+)~m', $text, $matches, PREG_SET_ORDER) > 0) {
        foreach ($matches as $m) {
            $result[$m[1]] = ['calls' => (int) $m[2], 'usec' => (int) $m[3], 'usec_per_call' => (float) $m[4]];
        }
    }

    return $result;
};

$usedMemory = static function () use ($client): int {
    $text = (string) $client->executeRaw(['INFO', 'memory']);

    return preg_match('~^used_memory:(\d+)~m', $text, $m) === 1 ? (int) $m[1] : 0;
};

$ids = new ContractIdentities();
$identity = ContractIdentities::identity('bench');

$cooldown = new RedisCooldownBackend($connection, $registry);
$cooldownPolicy = new CooldownPolicy($identity, Duration::seconds(60));
$concurrency = new RedisConcurrencyBackend($connection, $registry);
$concurrencyPolicy = new ConcurrencyPolicy($identity, new PositiveInt(1000), Duration::seconds(30));
$rate = new RedisRateLimitBackend($connection, $registry);
$typicalBucket = new RateLimitPolicy(ContractIdentities::identity('typical'), new TokenBucketOptions(new PositiveInt(1_000_000), new PositiveInt(1000), Duration::seconds(1)));
$wideBucket = new RateLimitPolicy(ContractIdentities::identity('wide'), new TokenBucketOptions(new PositiveInt(9_000_000_000), new PositiveInt(9_000_000_000), Duration::seconds(1)));
$budget = new RedisRetryBudgetBackend($connection, $registry);
$budgetPolicy = new RetryBudgetPolicy($identity, new PositiveInt(1_000_000), new PositiveInt(1000), Duration::seconds(1), Duration::seconds(60));
$circuit = new RedisCircuitBackend($connection, $registry);
$circuitPolicy = ContractCircuitRig::countPolicy(identity: $identity);
$bridge = RedisBridge::forOptions($connection);
$noop = new Script('return 1');

$builder = PipelineBuilder::profile('bench', 'v1')
    ->partition('bench', 'local', 'redis')
    ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::Cooperative)
    ->retry(2, BackoffMode::Constant, Duration::milliseconds(5), Duration::seconds(1), JitterMode::None)
    ->retryBudget(1_000_000, 1000, Duration::seconds(1), Duration::seconds(30))
    ->rateLimit(PipelineBuilder::tokenBucket(1_000_000, 1000, Duration::seconds(1)))
    ->circuitBreaker(PipelineBuilder::lastCalls(100), 20, Ratio::of(1, 2), Duration::seconds(30), 1, 1, Duration::seconds(10))
    ->bulkhead(1000, Duration::seconds(30))
    ->cooldown(Duration::seconds(60));
$safety = OperationSafety::replayableRead(ReplaySafetyContract::forReadOnlyCallable('bench', Duration::seconds(86_400)));
$pipeline = $builder->compile(RedisEnvironment::for($builder->definition(), ExceptionClassifier::create(), $connection, $registry));

/** @var array<string, \Closure(): mixed> $scenarios */
$scenarios = [
    'ping' => static fn(): mixed => $client->ping(),
    'script_noop' => static fn(): mixed => $bridge->execute($noop, [], [], CommandEffect::ReadOnly),
    'cooldown_extend' => static fn(): mixed => $cooldown->extend($cooldownPolicy, Duration::milliseconds(1), $ids->command()),
    'cooldown_inspect' => static fn(): mixed => $cooldown->inspect($cooldownPolicy),
    'concurrency_acquire_release' => static function () use ($concurrency, $concurrencyPolicy, $ids): mixed {
        $permit = $concurrency->acquire($concurrencyPolicy, $ids->owner(), $ids->command())->permit;

        return $permit === null ? null : $concurrency->release($concurrencyPolicy, $permit, $ids->command());
    },
    'ratelimit_consume_typical' => static fn(): mixed => $rate->consume($typicalBucket, new PositiveInt(1), $ids->command()),
    'ratelimit_consume_wide' => static fn(): mixed => $rate->consume($wideBucket, new PositiveInt(1), $ids->command()),
    'ratelimit_inspect' => static fn(): mixed => $rate->inspect($typicalBucket, new PositiveInt(1)),
    'retrybudget_reserve_commit' => static function () use ($budget, $budgetPolicy, $ids): mixed {
        $reservation = $budget->reserve($budgetPolicy, $ids->attempt(), $ids->owner(), $ids->command())->reservation;

        return $reservation === null ? null : $budget->commit($budgetPolicy, $reservation, $ids->command());
    },
    'circuit_acquire_record' => static function () use ($circuit, $circuitPolicy, $ids): mixed {
        $permit = $circuit->acquire($circuitPolicy, $ids->attempt(), $ids->owner(), $ids->command())->permit;

        return $permit === null ? null : $circuit->record($circuitPolicy, $permit, CircuitVerdict::Success, $ids->command());
    },
    'pipeline_all_five' => static fn(): mixed => $pipeline->execute(static fn(): int => 1, $pipeline->newContext(Duration::seconds(5), $safety)),
];

$results = [];
foreach ($scenarios as $name => $operation) {
    if ($only !== null && $only !== $name) {
        continue;
    }
    for ($i = 0; $i < $warmup; ++$i) {
        $operation();
    }
    gc_collect_cycles();
    $client->executeRaw(['CONFIG', 'RESETSTAT']);
    $samples = new \SplFixedArray($iterations);
    for ($i = 0; $i < $iterations; ++$i) {
        $start = hrtime(true);
        $operation();
        $samples[$i] = hrtime(true) - $start;
    }
    $after = $commandstats();
    $list = $samples->toArray();
    $evalshaCalls = (int) ($after['evalsha']['calls'] ?? 0);
    $results[$name] = [
        'samples_ns' => $list,
        'stats_ns' => $stats($list),
        'evalsha_calls_per_operation' => $evalshaCalls / $iterations,
        'evalsha_usec_per_call' => $after['evalsha']['usec_per_call'] ?? null,
        'commandstats' => $after,
    ];
    fwrite(STDERR, sprintf("%-30s p50 %8d ns  p95 %8d ns  p99 %8d ns  evalsha/op %.2f  server usec/evalsha %s\n", $name, $results[$name]['stats_ns']['p50'], $results[$name]['stats_ns']['p95'], $results[$name]['stats_ns']['p99'], $results[$name]['evalsha_calls_per_operation'], (string) ($results[$name]['evalsha_usec_per_call'] ?? '-')));
}

// Memory: the change of used_memory after creating 1,000 partitions (and 1,000 records) of each kind.
$memory = [];
if ($only === null) {
    $kinds = [
        'cooldown' => static fn(int $i): mixed => $cooldown->extend(new CooldownPolicy(ContractIdentities::identity('m' . $i), Duration::seconds(60)), Duration::seconds(30), $ids->command()),
        'concurrency_lease' => static fn(int $i): mixed => $concurrency->acquire(new ConcurrencyPolicy(ContractIdentities::identity('m' . $i), new PositiveInt(2), Duration::seconds(60)), $ids->owner(), $ids->command()),
        'ratelimit_bucket' => static fn(int $i): mixed => $rate->consume(new RateLimitPolicy(ContractIdentities::identity('m' . $i), new TokenBucketOptions(new PositiveInt(100), new PositiveInt(10), Duration::seconds(1))), new PositiveInt(1), $ids->command()),
        'retrybudget_reservation' => static fn(int $i): mixed => $budget->reserve(new RetryBudgetPolicy(ContractIdentities::identity('m' . $i), new PositiveInt(10), new PositiveInt(1), Duration::seconds(1), Duration::seconds(60)), $ids->attempt(), $ids->owner(), $ids->command()),
        'circuit_permit' => static fn(int $i): mixed => $circuit->acquire(ContractCircuitRig::countPolicy(identity: ContractIdentities::identity('m' . $i)), $ids->attempt(), $ids->owner(), $ids->command()),
    ];
    foreach ($kinds as $kind => $create) {
        $before = $usedMemory();
        for ($i = 0; $i < 1000; ++$i) {
            $create($i);
        }
        $delta = $usedMemory() - $before;
        $memory[$kind] = ['partitions' => 1000, 'used_memory_delta_bytes' => $delta, 'bytes_per_partition' => $delta / 1000];
        fwrite(STDERR, sprintf("memory %-26s %8.1f bytes per partition (with its first record)\n", $kind, $delta / 1000));
    }
    // A tombstone: 1,000 owners of one partition acquire and release; each leaves one terminal record in the partition
    // hash and one entry in the registry queue. MEMORY USAGE of those two keys, before and after, avoids the noise of the
    // server-wide counter.
    $tombPolicy = new ConcurrencyPolicy(ContractIdentities::identity('tomb'), new PositiveInt(2000), Duration::seconds(60));
    $layout = $registry->keys();
    $partitionKey = $layout->policyPartition(\Enaxon\Reqtide\Redis\State\StateKind::Concurrency, $tombPolicy->identity);
    $usage = static fn(string $key): int => (int) $client->executeRaw(['MEMORY', 'USAGE', $key]);
    $concurrency->release($tombPolicy, $concurrency->acquire($tombPolicy, $ids->owner(), $ids->command())->permit ?? throw new \RuntimeException('no permit'), $ids->command());
    $before = $usage($partitionKey) + $usage($layout->tombstoneQueue());
    for ($i = 0; $i < 1000; ++$i) {
        $permit = $concurrency->acquire($tombPolicy, $ids->owner(), $ids->command())->permit;
        if ($permit !== null) {
            $concurrency->release($tombPolicy, $permit, $ids->command());
        }
    }
    $delta = $usage($partitionKey) + $usage($layout->tombstoneQueue()) - $before;
    $memory['concurrency_tombstone'] = ['records' => 1000, 'memory_usage_delta_bytes' => $delta, 'bytes_per_record' => $delta / 1000];
    fwrite(STDERR, sprintf("memory %-26s %8.1f bytes per record (hash field and queue entry, MEMORY USAGE)
", 'concurrency_tombstone', $delta / 1000));
}

// Contention: one hot partition against one partition each, from 1, 8 and 32 processes.
$contention = [];
if ($only === null && $workerCounts !== [0]) {
    $endpoint = RedisEndpoint::fromEnvironment();
    putenv('RT_REDIS_HOST=' . $host);
    putenv('RT_REDIS_PORT=' . $port);
    $endpoint = RedisEndpoint::fromEnvironment();
    if ($endpoint !== null) {
        foreach (['hot' => false, 'spread' => true] as $shape => $spread) {
            foreach ($workerCounts as $count) {
                $contentionPrefix = $prefix . '_c' . $shape . $count;
                $contentionRegistry = new RedisRegistry($contentionPrefix, 'bench', $secret, 100_000, 400_000);
                (new RedisRegistryAdmin($connection, $contentionRegistry))->initialize();
                $workers = BarrierHarness::run($endpoint, $contentionPrefix, 'bench', $secret, $count, ['scenario' => 'rate', 'capacity' => 9_000_000_000, 'calls' => $calls, 'mixedCosts' => false, 'spread' => $spread], 100_000, 400_000, 180.0);
                $elapsed = [];
                $accepted = 0;
                foreach ($workers as $worker) {
                    $extra = is_array($worker['extra'] ?? null) ? $worker['extra'] : [];
                    $elapsed[] = is_int($extra['elapsedNs'] ?? null) ? $extra['elapsedNs'] : 0;
                    $accepted += is_int($worker['accepted'] ?? null) ? $worker['accepted'] : 0;
                }
                $longest = max($elapsed);
                $contention[] = [
                    'shape' => $shape,
                    'processes' => $count,
                    'calls_per_process' => $calls,
                    'accepted' => $accepted,
                    'longest_process_ns' => $longest,
                    'calls_per_second' => $longest > 0 ? $accepted / ($longest / 1e9) : null,
                    'process_elapsed_ns' => $elapsed,
                ];
                fwrite(STDERR, sprintf("contention %-6s %2d processes: %d calls in %.0f ms (%.0f calls/s)\n", $shape, $count, $accepted, $longest / 1e6, $longest > 0 ? $accepted / ($longest / 1e9) : 0));
                $cursor = '0';
                do {
                    /** @var array{0: string, 1: list<string>} $page */
                    $page = $client->scan($cursor, ['MATCH' => $contentionPrefix . ':*', 'COUNT' => 1000]);
                    $cursor = (string) $page[0];
                    if ($page[1] !== []) {
                        $client->del($page[1]);
                    }
                } while ($cursor !== '0');
                $client->del(['start:' . $contentionPrefix]);
            }
        }
    }
}

$info = (string) $client->executeRaw(['INFO', 'server']);
$cleanup = '0';
do {
    /** @var array{0: string, 1: list<string>} $page */
    $page = $client->scan($cleanup, ['MATCH' => $prefix . ':*', 'COUNT' => 1000]);
    $cleanup = (string) $page[0];
    if ($page[1] !== []) {
        $client->del($page[1]);
    }
} while ($cleanup !== '0');

$persistence = $client->executeRaw(['CONFIG', 'GET', 'save']);
$policy = $client->executeRaw(['CONFIG', 'GET', 'maxmemory-policy']);
$output = [
    'kind' => 'redis-bench',
    'note' => 'Raw measurements. No targets, budgets, claims or comparisons.',
    'environment' => [
        'php' => PHP_VERSION,
        'php_sapi' => PHP_SAPI,
        'predis' => \Composer\InstalledVersions::getPrettyVersion('predis/predis'),
        'redis_version' => preg_match('~^redis_version:(\S+)~m', $info, $m) === 1 ? $m[1] : null,
        'redis_mode' => preg_match('~^redis_mode:(\S+)~m', $info, $m) === 1 ? $m[1] : null,
        'redis_os' => preg_match('~^os:(.+)$~m', $info, $m) === 1 ? trim($m[1]) : null,
        'redis_persistence_save' => $persistence,
        'redis_maxmemory_policy' => $policy,
        'client_host' => php_uname(),
        'opcache_enable_cli' => ini_get('opcache.enable_cli'),
        'cpus_visible' => is_file('/proc/cpuinfo') ? substr_count((string) file_get_contents('/proc/cpuinfo'), 'processor') : null,
        'load_average_end' => function_exists('sys_getloadavg') ? sys_getloadavg() : null,
        'network_path' => 'container to container on one Docker network; no TLS',
    ],
    'parameters' => ['iterations' => $iterations, 'warmup' => $warmup, 'contention_calls_per_process' => $calls, 'contention_processes' => $workerCounts],
    'scenarios' => $results,
    'memory' => $memory,
    'contention' => $contention,
];
$directory = dirname($out);
if (!is_dir($directory) && !mkdir($directory, 0775, true)) {
    fwrite(STDERR, "cannot create $directory\n");
    exit(1);
}
file_put_contents($out, json_encode($output, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
fwrite(STDERR, "wrote $out\n");
