<?php

declare(strict_types=1);

/**
 * One worker process of the multi-process suites. It is started by BarrierHarness with a JSON argument, builds
 * its own backend and connection, prints READY, waits until the shared server clock reaches `startAt` (so every worker uses
 * the authority's clock, polling with no sleep longer than 200 microseconds; the start instant is published by the coordinator), runs its scenario and prints one JSON line.
 *
 * Scenarios: rate, bulkhead, budget, circuit, churn. A worker never sleeps to hide a race: the only waits are the barrier
 * and the pause of a held permit.
 */

use Enaxon\Reqtide\Exception\StateCapacityExceededException;
use Enaxon\Reqtide\Policy\ConcurrencyPolicy;
use Enaxon\Reqtide\Policy\CooldownPolicy;
use Enaxon\Reqtide\Policy\RateLimitPolicy;
use Enaxon\Reqtide\Policy\RetryBudgetPolicy;
use Enaxon\Reqtide\Policy\TokenBucketOptions;
use Enaxon\Reqtide\Redis\RedisCircuitBackend;
use Enaxon\Reqtide\Redis\RedisConcurrencyBackend;
use Enaxon\Reqtide\Redis\RedisConnectionOptions;
use Enaxon\Reqtide\Redis\RedisCooldownBackend;
use Enaxon\Reqtide\Redis\RedisRateLimitBackend;
use Enaxon\Reqtide\Redis\RedisRegistry;
use Enaxon\Reqtide\Redis\RedisRetryBudgetBackend;
use Enaxon\Reqtide\Tests\Contract\ContractCircuitRig;
use Enaxon\Reqtide\Tests\Contract\ContractIdentities;
use Enaxon\Reqtide\Value\AttemptId;
use Enaxon\Reqtide\Value\CommandId;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\PermitOwnerId;
use Enaxon\Reqtide\Value\PositiveInt;
use Predis\Client;

$spec = json_decode((string) ($argv[1] ?? ''), true, 8, JSON_THROW_ON_ERROR);
if (!is_array($spec)) {
    exit(2);
}
$str = static fn(string $key): string => is_scalar($spec[$key] ?? null) ? (string) $spec[$key] : '';
$int = static fn(string $key): int => is_numeric($spec[$key] ?? null) ? (int) $spec[$key] : 0;
$flt = static fn(string $key): float => is_numeric($spec[$key] ?? null) ? (float) $spec[$key] : 0.0;
require $str('autoload');

$workerId = $int('workerId');
$registry = new RedisRegistry($str('prefix'), $str('identity'), $str('secret'), $int('maxPartitions'), $int('maxRecords'));
$options = new RedisConnectionOptions($str('host'), 2.0, 10.0, $int('port'));
$client = new Client(['scheme' => 'tcp', 'host' => $str('host'), 'port' => $int('port'), 'timeout' => 5.0, 'read_write_timeout' => 5.0]);

mt_srand($int('seed') + $workerId);
$counter = 0;
$hex = static function () use ($workerId, &$counter): string {
    return sprintf('%08x%08x%016x', $workerId + 1, ++$counter, random_int(1, PHP_INT_MAX));
};
$command = static fn(): CommandId => CommandId::fromLowerHex($hex());
$owner = static fn(): PermitOwnerId => PermitOwnerId::fromLowerHex($hex());
$attempt = static fn(): AttemptId => AttemptId::fromLowerHex($hex());
$identity = static fn(string $subject) => ContractIdentities::identity($subject);

echo "READY\n";
fflush(STDOUT);

// The coordinator publishes the start instant (server clock) once every worker is ready.
$startKey = 'start:' . $str('prefix');
do {
    $published = $client->get($startKey);
    if ($published === null) {
        usleep(200);
    }
} while ($published === null);
$startAt = (float) $published;

// Barrier on the server clock.
do {
    /** @var array{0: numeric-string, 1: numeric-string} $time */
    $time = $client->time();
    $now = (float) $time[0] + (float) $time[1] / 1e6;
    if ($now < $startAt) {
        usleep(200);
    }
} while ($now < $startAt);

$result = ['worker' => $workerId, 'accepted' => 0, 'rejected' => 0, 'acceptedCost' => 0, 'capacityErrors' => 0, 'errors' => [], 'extra' => []];
$record = static function (Throwable $failure) use (&$result): void {
    $result['errors'][] = get_class($failure) . ': ' . substr($failure->getMessage(), 0, 120);
};

switch ($spec['scenario']) {
    case 'rate':
        $backend = new RedisRateLimitBackend($options, $registry);
        $policy = new RateLimitPolicy($identity(($spec['spread'] ?? false) === true ? 'w' . $workerId : 'shared'), new TokenBucketOptions(new PositiveInt($int('capacity')), new PositiveInt(1), Duration::seconds(3600)));
        $startedAt = hrtime(true);
        for ($i = 0; $i < $int('calls'); ++$i) {
            $cost = ($spec['mixedCosts'] ?? false) === true ? 1 + (($workerId + $i) % 3) : 1;
            try {
                $decision = $backend->consume($policy, new PositiveInt($cost), $command());
                if ($decision->status->value === 'accepted') {
                    ++$result['accepted'];
                    $result['acceptedCost'] += $cost;
                } else {
                    ++$result['rejected'];
                }
            } catch (Throwable $failure) {
                $record($failure);
            }
        }
        $result['extra']['elapsedNs'] = hrtime(true) - $startedAt;
        break;

    case 'bulkhead':
        $backend = new RedisConcurrencyBackend($options, $registry);
        $policy = new ConcurrencyPolicy($identity('shared'), new PositiveInt($int('capacity')), Duration::seconds(30));
        $maxInflight = 0;
        for ($i = 0; $i < $int('calls'); ++$i) {
            try {
                $admission = $backend->acquire($policy, $owner(), $command());
                if ($admission->permit === null) {
                    ++$result['rejected'];
                    continue;
                }
                ++$result['accepted'];
                // The probe counts only permits that hold the lease: between acquire and release.
                $inflight = (int) $client->incr('probe:' . $str('prefix'));
                $maxInflight = max($maxInflight, $inflight);
                usleep(random_int(100, 1500));
                $client->decr('probe:' . $str('prefix'));
                $backend->release($policy, $admission->permit, $command());
            } catch (Throwable $failure) {
                $record($failure);
            }
        }
        $result['extra']['maxInflight'] = $maxInflight;
        break;

    case 'bulkhead-crash':
        // Acquire and die without releasing: the lease is the only way back.
        $backend = new RedisConcurrencyBackend($options, $registry);
        $policy = new ConcurrencyPolicy($identity('shared'), new PositiveInt($int('capacity')), Duration::seconds($int('leaseSeconds')));
        try {
            $admission = $backend->acquire($policy, $owner(), $command());
            $result['accepted'] = $admission->permit === null ? 0 : 1;
        } catch (Throwable $failure) {
            $record($failure);
        }
        echo json_encode($result, JSON_THROW_ON_ERROR) . "\n";
        fflush(STDOUT);
        // Wait to be killed; never release.
        sleep(60);
        exit(0);

    case 'budget':
        $result['extra']['released'] = 0;
        $backend = new RedisRetryBudgetBackend($options, $registry);
        $policy = new RetryBudgetPolicy($identity('shared'), new PositiveInt($int('capacity')), new PositiveInt(1), Duration::seconds(3600), Duration::seconds(60));
        for ($i = 0; $i < $int('calls'); ++$i) {
            try {
                $decision = $backend->reserve($policy, $attempt(), $owner(), $command());
                if ($decision->reservation === null) {
                    ++$result['rejected'];
                    continue;
                }
                ++$result['accepted'];
                if (($workerId + $i) % 4 === 0) {
                    $backend->release($policy, $decision->reservation, $command());
                    ++$result['extra']['released'];
                } else {
                    $backend->commit($policy, $decision->reservation, $command());
                    $result['acceptedCost'] += 1;
                }
            } catch (Throwable $failure) {
                $record($failure);
            }
        }
        break;

    case 'circuit':
        $backend = new RedisCircuitBackend($options, $registry);
        $policy = ContractCircuitRig::countPolicy(minimum: 2, probeCapacity: $int('probes'), openSeconds: $int('openSeconds'), identity: $identity('shared'));
        try {
            $admission = $backend->acquire($policy, $attempt(), $owner(), $command());
            $result['extra']['status'] = $admission->status->value;
            $result['extra']['kind'] = $admission->permit?->kind->value;
            $result['extra']['generation'] = $admission->permit?->generation->value;
            if ($admission->permit !== null) {
                $result['accepted'] = 1;
            }
        } catch (Throwable $failure) {
            $record($failure);
        }
        break;

    case 'churn':
        $concurrency = new RedisConcurrencyBackend($options, $registry);
        $rate = new RedisRateLimitBackend($options, $registry);
        $cooldown = new RedisCooldownBackend($options, $registry);
        $end = hrtime(true) + (int) ($flt('seconds') * 1e9);
        $operations = 0;
        while (hrtime(true) < $end) {
            $subject = 's' . mt_rand(0, $int('subjects') - 1);
            try {
                $kind = mt_rand(0, 2);
                if ($kind === 0) {
                    $policy = new ConcurrencyPolicy($identity($subject), new PositiveInt(2), Duration::seconds(2));
                    $admission = $concurrency->acquire($policy, $owner(), $command());
                    if ($admission->permit !== null && mt_rand(0, 3) > 0) {
                        $concurrency->release($policy, $admission->permit, $command());
                    }
                } elseif ($kind === 1) {
                    $policy = new RateLimitPolicy($identity($subject), new TokenBucketOptions(new PositiveInt(5), new PositiveInt(5), Duration::seconds(1)));
                    $rate->consume($policy, new PositiveInt(1), $command());
                } else {
                    $cooldown->extend(new CooldownPolicy($identity($subject), Duration::seconds(1)), Duration::milliseconds(mt_rand(1, 500)), $command());
                }
                ++$operations;
            } catch (StateCapacityExceededException) {
                ++$result['capacityErrors'];
            } catch (Throwable $failure) {
                $record($failure);
            }
        }
        $result['extra']['operations'] = $operations;
        break;
}

echo json_encode($result, JSON_THROW_ON_ERROR) . "\n";
