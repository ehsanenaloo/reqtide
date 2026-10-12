<?php

declare(strict_types=1);

/**
 * Raw measurements of the bounded cleanup paths of the Redis backends and of the ACL preflight. No targets, no claims.
 *   php -d pcov.enabled=0 benchmarks/redis-cleanup-bench.php --host=redis82 [--port=6379] [--out=build/bench/redis-cleanup.json]
 *
 * Redis runs one script at a time, so the duration of one script is also the longest wait it can cause a second client.
 * Each scenario reports the client-side wall time of one call (hrtime, connection and network included) and, under
 * `server`, the script durations the server itself recorded in its slow log (threshold 0), all in microseconds.
 *
 *  - acl_preflight: an EVAL that runs exactly the per-key ACL checks of a mutating script (16 commands per key, plus TIME)
 *    for 4 and 6 keys, against an EVAL that only returns, both with the same keys.
 *  - stale_prefix: a registry at its record ceiling (16 tombstones) with 20,000 stale queue entries in front; every
 *    concurrency acquire until one is admitted.
 *  - mass_expiry: one partition with 5,000 leases that expire in the same second; every acquire until the expired leases
 *    are gone, then the maintenance steps.
 *  - saturated: a registry whose record ceiling is full of live leases; acquires that are refused.
 */

namespace Reqtide\Benchmarks;

use Enaxon\Reqtide\Exception\StateCapacityExceededException;
use Enaxon\Reqtide\Policy\ConcurrencyPolicy;
use Enaxon\Reqtide\Redis\RedisConcurrencyBackend;
use Enaxon\Reqtide\Redis\RedisConnectionOptions;
use Enaxon\Reqtide\Redis\RedisRegistry;
use Enaxon\Reqtide\Redis\RedisRegistryAdmin;
use Enaxon\Reqtide\Redis\RedisRegistryMaintenance;
use Enaxon\Reqtide\State\ConcurrencyAdmissionStatus;
use Enaxon\Reqtide\Value\ApplicationId;
use Enaxon\Reqtide\Value\CommandId;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\EnvironmentId;
use Enaxon\Reqtide\Value\PartitionIdentity;
use Enaxon\Reqtide\Value\PermitOwnerId;
use Enaxon\Reqtide\Value\PolicyId;
use Enaxon\Reqtide\Value\PolicyIdentity;
use Enaxon\Reqtide\Value\PositiveInt;
use Enaxon\Reqtide\Value\ProfileVersion;
use Enaxon\Reqtide\Value\SubjectId;
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

$options = getopt('', ['host:', 'port::', 'out::']);
$host = is_string($options['host'] ?? null) ? $options['host'] : '';
$port = is_string($options['port'] ?? null) ? (int) $options['port'] : 6379;
$out = is_string($options['out'] ?? null) ? $options['out'] : 'build/bench/redis-cleanup.json';
if ($host === '') {
    fwrite(STDERR, "usage: redis-cleanup-bench.php --host=HOST [--port=N] [--out=FILE]\n");
    exit(2);
}

$secret = 'a-host-managed-secret-of-32-bytes!!';
$connection = new RedisConnectionOptions($host, 2.0, 30.0, $port);
$client = new Client(['scheme' => 'tcp', 'host' => $host, 'port' => $port, 'timeout' => 5.0, 'read_write_timeout' => 60.0]);
$registries = [];
$registry = static function (int $maxRecords) use ($connection, $secret, &$registries): RedisRegistry {
    $registry = new RedisRegistry('rt_cleanup_' . bin2hex(random_bytes(4)), 'bench', $secret, 10_000, $maxRecords);
    (new RedisRegistryAdmin($connection, $registry))->initialize();
    $registries[] = $registry;

    return $registry;
};
$partition = new PartitionIdentity(new ApplicationId('bench'), new EnvironmentId('local'), null, new SubjectId('cleanup'));
$lease = static fn(string $name, int $capacity, int $seconds): ConcurrencyPolicy => new ConcurrencyPolicy(
    new PolicyIdentity($partition, new ProfileVersion('v1'), new PolicyId($name)),
    new PositiveInt($capacity),
    Duration::seconds($seconds),
);
$owner = static fn(): PermitOwnerId => PermitOwnerId::fromLowerHex(bin2hex(random_bytes(16)));
$command = static fn(): CommandId => CommandId::fromLowerHex(bin2hex(random_bytes(16)));
$micros = static fn(int $start): int => intdiv(hrtime(true) - $start, 1000);

/** @param list<int> $samples @return array<string, int|float> */
$stats = static function (array $samples): array {
    sort($samples);
    $n = count($samples);
    if ($n === 0) {
        return ['count' => 0];
    }
    $rank = static fn(float $p): int => $samples[max(0, (int) ceil($p * $n) - 1)];

    return ['count' => $n, 'min' => $samples[0], 'p50' => $rank(0.50), 'p95' => $rank(0.95), 'p99' => $rank(0.99), 'max' => $samples[$n - 1]];
};
$client->executeRaw(['CONFIG', 'SET', 'slowlog-log-slower-than', '0']);
$client->executeRaw(['CONFIG', 'SET', 'slowlog-max-len', '100000']);
/** Script durations the server recorded since the last call. @return array<string, int|float> */
$server = static function () use ($client, $stats): array {
    $durations = [];
    foreach ((array) $client->executeRaw(['SLOWLOG', 'GET', '100000']) as $entry) {
        if (is_array($entry) && is_array($entry[3] ?? null) && in_array(strtoupper((string) ($entry[3][0] ?? '')), ['EVALSHA', 'EVAL', 'FCALL'], true)) {
            $durations[] = (int) $entry[2];
        }
    }
    $client->executeRaw(['SLOWLOG', 'RESET']);

    return $stats($durations);
};
$results = ['host' => $host, 'redis' => (string) ($client->info('server')['Server']['redis_version'] ?? 'unknown'), 'php' => PHP_VERSION, 'unit' => 'microseconds'];

// ---- acl_preflight -------------------------------------------------------------------------------------------------
$preflight = <<<'LUA'
local list = { { 'HSET', 'f', 'v' }, { 'HDEL', 'f' }, { 'DEL' }, { 'ZADD', '0', 'm' }, { 'ZREM', 'm' }, { 'RPUSH', 'v' }, { 'LPOP' },
    { 'HGET', 'f' }, { 'HMGET', 'f' }, { 'HGETALL' }, { 'HEXISTS', 'f' }, { 'HLEN' }, { 'EXISTS' }, { 'LINDEX', '0' },
    { 'ZRANGE', '0', '-1' }, { 'ZRANGEBYSCORE', '-inf', '+inf' } }
for _, key in ipairs(KEYS) do
    for _, entry in ipairs(list) do
        if not redis.acl_check_cmd(entry[1], key, unpack(entry, 2)) then
            return 0
        end
    end
end
redis.acl_check_cmd('TIME')
return 1
LUA;
foreach ([4, 6] as $keyCount) {
    $keys = array_map(static fn(int $i): string => 'rt_cleanup_acl:' . $i, range(1, $keyCount));
    foreach (['empty' => 'return 1', 'preflight' => $preflight] as $label => $script) {
        $sha = (string) $client->script('load', $script);
        $samples = [];
        for ($i = 0; $i < 3000; ++$i) {
            $start = hrtime(true);
            $client->evalsha($sha, $keyCount, ...$keys);
            if ($i >= 500) {
                $samples[] = $micros($start);
            }
        }
        $client->executeRaw(['SLOWLOG', 'RESET']);
        for ($i = 0; $i < 2000; ++$i) {
            $client->evalsha($sha, $keyCount, ...$keys);
        }
        $results['acl_preflight'][$keyCount . '_keys'][$label] = ['client' => $stats($samples), 'server' => $server()];
    }
}

// ---- stale_prefix --------------------------------------------------------------------------------------------------
$small = $registry(16);
$backend = new RedisConcurrencyBackend($connection, $small);
$policy = $lease('stale-a', 16, 60);
for ($i = 0; $i < 16; ++$i) {
    $admission = $backend->acquire($policy, $owner(), $command());
    if ($admission->permit !== null) {
        $backend->release($policy, $admission->permit, $command());
    }
}
$queue = $small->keys()->tombstoneQueue();
$gone = $small->keys()->prefix . ':gone';
$entries = [];
for ($i = 0; $i < 20_000; ++$i) {
    $entries[] = '100 0 ' . $gone . ' ' . str_pad(dechex($i), 32, '0', STR_PAD_LEFT);
}
foreach (array_chunk($entries, 1000) as $chunk) {
    $client->lpush($queue, $chunk);
}
$server();
$samples = [];
$calls = 0;
while ($calls < 1000) {
    ++$calls;
    $start = hrtime(true);
    try {
        $admitted = $backend->acquire($lease('stale-b', 1, 60), $owner(), $command())->status === ConcurrencyAdmissionStatus::Accepted;
    } catch (StateCapacityExceededException) {
        $admitted = false;
    }
    $samples[] = $micros($start);
    if ($admitted) {
        break;
    }
}
$results['stale_prefix'] = ['stale_entries' => 20_000, 'calls_until_admitted' => $calls, 'client' => $stats($samples), 'server' => $server()];

// ---- mass_expiry ---------------------------------------------------------------------------------------------------
$large = $registry(20_000);
$backend = new RedisConcurrencyBackend($connection, $large);
$wide = $lease('wide', 5_000, 1);
for ($i = 0; $i < 5_000; ++$i) {
    $backend->acquire($wide, $owner(), $command());
}
usleep(2_100_000);
$server();
$samples = [];
for ($i = 0; $i < 60; ++$i) {
    $start = hrtime(true);
    $backend->acquire($wide, $owner(), $command());
    $samples[] = $micros($start);
}
$results['mass_expiry']['acquire'] = ['expired_leases' => 5_000, 'client' => $stats($samples), 'server' => $server()];

// The same expiry left to maintenance alone, on a second partition.
$other = $lease('wide-2', 5_000, 1);
for ($i = 0; $i < 5_000; ++$i) {
    $backend->acquire($other, $owner(), $command());
}
usleep(2_100_000);
$maintenance = new RedisRegistryMaintenance($connection, $large);
$server();
$samples = [];
for ($i = 0; $i < 200; ++$i) {
    $start = hrtime(true);
    $step = $maintenance->step();
    $samples[] = $micros($start);
    if (!$step['more']) {
        break;
    }
}
$results['mass_expiry']['maintenance_step'] = ['client' => $stats($samples), 'server' => $server()];

// ---- saturated -----------------------------------------------------------------------------------------------------
$full = $registry(2_000);
$backend = new RedisConcurrencyBackend($connection, $full);
for ($i = 0; $i < 2_000; ++$i) {
    $backend->acquire($lease('live-' . intdiv($i, 100), 100, 600), $owner(), $command());
}
$server();
$samples = [];
for ($i = 0; $i < 300; ++$i) {
    $start = hrtime(true);
    try {
        $backend->acquire($lease('refused', 1, 600), $owner(), $command());
    } catch (StateCapacityExceededException) {
    }
    $samples[] = $micros($start);
}
$results['saturated_refusal'] = ['live_records' => 2_000, 'client' => $stats($samples), 'server' => $server()];

foreach ($registries as $created) {
    $admin = new RedisRegistryAdmin($connection, $created);
    $admin->retire();
    while ($admin->purgeRetired() > 0) {
    }
}
foreach ($client->keys('rt_cleanup_acl:*') as $key) {
    $client->del($key);
}

if (!is_dir(dirname($out))) {
    mkdir(dirname($out), 0775, true);
}
file_put_contents($out, json_encode($results, JSON_PRETTY_PRINT) . "\n");
echo json_encode($results, JSON_PRETTY_PRINT), "\n";
