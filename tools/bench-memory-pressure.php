<?php

declare(strict_types=1);

/**
 * Latency of the in-memory concurrency backend when its registry is full of tombstones and every new allocation first
 * evicts the oldest ones. Prints percentiles for both paths and the workload; it states no verdict.
 *
 *   php tools/bench-memory-pressure.php [partitions] [allocations]
 *
 * Machine-dependent: compare only numbers taken in one run. The ordinary path allocates new partitions in an empty
 * registry; the pressure path allocates new partitions in a registry that holds `partitions` partitions with two
 * tombstones each (the record ceiling is twice the partition ceiling, so both ceilings are reached together).
 */

use Enaxon\Reqtide\Policy\ConcurrencyPolicy;
use Enaxon\Reqtide\State\Memory\MemoryConcurrencyBackend;
use Enaxon\Reqtide\Time\SystemClock;
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

$vendor = getenv('COMPOSER_VENDOR_DIR');
$vendor = is_string($vendor) && $vendor !== '' ? $vendor : dirname(__DIR__) . '/vendor';
require $vendor . '/autoload.php';

$partitions = isset($argv[1]) && ctype_digit($argv[1]) ? (int) $argv[1] : 10_000;
$allocations = isset($argv[2]) && ctype_digit($argv[2]) ? (int) $argv[2] : 5_000;

$counter = 0;
$policy = static function (int $n): ConcurrencyPolicy {
    $identity = new PolicyIdentity(
        new PartitionIdentity(new ApplicationId('bench'), new EnvironmentId('test'), null, new SubjectId('s' . $n)),
        new ProfileVersion('v1'),
        new PolicyId('concurrency'),
    );

    return new ConcurrencyPolicy($identity, new PositiveInt(2), Duration::seconds(60));
};
$owner = static function () use (&$counter): PermitOwnerId {
    return PermitOwnerId::fromLowerHex(str_pad(dechex(++$counter), 32, '0', STR_PAD_LEFT));
};
$command = static fn(): CommandId => CommandId::fromLowerHex(str_pad(dechex(random_int(1, PHP_INT_MAX)), 32, '0', STR_PAD_LEFT));

$percentile = static function (array $sorted, float $p): float {
    return $sorted[(int) min(count($sorted) - 1, floor($p * count($sorted)))];
};
$report = static function (string $label, array $micros) use ($percentile): void {
    sort($micros);
    printf(
        "%-34s n=%d p50=%.1fus p95=%.1fus p99=%.1fus max=%.1fus mean=%.1fus\n",
        $label,
        count($micros),
        $percentile($micros, 0.50),
        $percentile($micros, 0.95),
        $percentile($micros, 0.99),
        end($micros),
        array_sum($micros) / count($micros),
    );
};

$measure = static function (MemoryConcurrencyBackend $backend, int $first, int $count) use ($policy, $owner, $command): array {
    $micros = [];
    for ($i = 0; $i < $count; ++$i) {
        $p = $policy($first + $i);
        $started = hrtime(true);
        $admission = $backend->acquire($p, $owner(), $command());
        $micros[] = (hrtime(true) - $started) / 1000;
        if ($admission->permit !== null) {
            $backend->release($p, $admission->permit, $command());
        }
    }

    return $micros;
};

echo "workload: partitions ceiling={$partitions}, record ceiling=" . (2 * $partitions) . ", allocations measured={$allocations}, php " . PHP_VERSION . "\n";

$ordinary = new MemoryConcurrencyBackend(new SystemClock(), null, 2 * $partitions + $allocations, 4 * $partitions + 2 * $allocations);
$report('ordinary (room available)', $measure($ordinary, 0, $allocations));

$full = new MemoryConcurrencyBackend(new SystemClock(), null, $partitions, 2 * $partitions);
for ($n = 0; $n < $partitions; ++$n) {
    $p = $policy($n);
    for ($k = 0; $k < 2; ++$k) {
        $admission = $full->acquire($p, $owner(), $command());
        if ($admission->permit !== null) {
            $full->release($p, $admission->permit, $command());
        }
    }
}
$report('capacity pressure (full registry)', $measure($full, $partitions, $allocations));
printf("peak memory %.1f MiB\n", memory_get_peak_usage(true) / 1048576);
