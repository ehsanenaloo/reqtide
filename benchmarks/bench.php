<?php

declare(strict_types=1);

/**
 * Runs ONE scenario in this process and writes its raw samples as JSON.
 *
 *   php -d pcov.enabled=0 benchmarks/bench.php --scenario=empty_pipeline [--iterations=5000] [--warmup=1000]
 *                                              [--out=build/bench/empty_pipeline.json]
 *
 * Normally you run benchmarks/run-all.php instead, which starts one fresh process per scenario and repeat.
 *
 * Method:
 *  - warmup: `warmup` operations are executed and discarded (class loading, opcache/JIT warm state, caches).
 *  - measurement: `iterations` operations, each timed individually as hrtime(true) before/after.
 *    One sample = nanoseconds for one operation, including ExecutionContext construction.
 *  - The samples array is preallocated (SplFixedArray) before memory is first read, so the harness's own
 *    storage does not show up as retained memory growth.
 *  - gc_collect_cycles() runs before the measured loop and the GC stays enabled (a real caller has it on).
 *  - `timer_overhead_ns` samples hrtime(true) back to back (an empty timed region) to expose the cost of timing.
 */

namespace Reqtide\Benchmarks;

(static function (): void {
    $candidates = array_filter([
        getenv('COMPOSER_VENDOR_DIR') !== false ? getenv('COMPOSER_VENDOR_DIR') . '/autoload.php' : null,
        '/opt/reqtide-vendor/autoload.php',
        __DIR__ . '/../vendor/autoload.php',
    ]);
    foreach ($candidates as $file) {
        if (is_file($file)) {
            require $file;

            return;
        }
    }
    fwrite(STDERR, "No Composer autoloader found.\n");
    exit(2);
})();

require __DIR__ . '/scenarios.php';

$options = getopt('', ['scenario:', 'iterations::', 'warmup::', 'out::']);
$scenario = $options['scenario'] ?? null;
if (!is_string($scenario) || !in_array($scenario, Scenarios::names(), true)) {
    fwrite(STDERR, 'Usage: bench.php --scenario=<' . implode('|', Scenarios::names()) . ">\n");
    exit(2);
}
$iterations = (int) ($options['iterations'] ?? 5000);
$warmup = (int) ($options['warmup'] ?? 1000);
if ($iterations < 100 || $warmup < 0) {
    fwrite(STDERR, "iterations must be >= 100 and warmup >= 0\n");
    exit(2);
}

$operation = Scenarios::build($scenario);

// Timer overhead: back-to-back hrtime reads.
$overhead = new \SplFixedArray(1000);
for ($i = 0; $i < 1000; ++$i) {
    $a = hrtime(true);
    $b = hrtime(true);
    $overhead[$i] = $b - $a;
}

$samples = new \SplFixedArray($iterations);

for ($i = 0; $i < $warmup; ++$i) {
    $operation();
}
gc_collect_cycles();
$memoryBefore = memory_get_usage();
$memoryBeforeReal = memory_get_usage(true);

$sink = 0;
for ($i = 0; $i < $iterations; ++$i) {
    $start = hrtime(true);
    $result = $operation();
    $end = hrtime(true);
    $samples[$i] = $end - $start;
    $sink += $result === null ? 0 : 1;
}

$memoryAfter = memory_get_usage();
$memoryAfterReal = memory_get_usage(true);

if ($sink !== $iterations && $scenario !== 'baseline_context_only') {
    fwrite(STDERR, "Unexpected result count: the operation did not succeed every time ({$sink}/{$iterations}).\n");
    exit(1);
}

/** @param list<int> $sorted ascending */
$percentile = static function (array $sorted, float $p): int {
    // Nearest-rank method: the smallest sample such that at least p of all samples are <= it.
    $rank = (int) ceil($p * count($sorted));

    return $sorted[max(0, $rank - 1)];
};

$list = $samples->toArray();
$sorted = $list;
sort($sorted);
$overheadSorted = $overhead->toArray();
sort($overheadSorted);
$count = count($sorted);
$mean = array_sum($sorted) / $count;
$variance = 0.0;
foreach ($sorted as $value) {
    $variance += ($value - $mean) ** 2;
}

$result = [
    'scenario' => $scenario,
    'iterations' => $iterations,
    'warmup' => $warmup,
    'unit' => 'nanoseconds per operation',
    'percentile_method' => 'nearest-rank',
    'stats_ns' => [
        'min' => $sorted[0],
        'p50' => $percentile($sorted, 0.50),
        'p95' => $percentile($sorted, 0.95),
        'p99' => $percentile($sorted, 0.99),
        'max' => $sorted[$count - 1],
        'mean' => round($mean, 1),
        'stddev' => round(sqrt($variance / $count), 1),
    ],
    'timer_overhead_ns' => [
        'samples' => count($overheadSorted),
        'p50' => $percentile($overheadSorted, 0.50),
        'p99' => $percentile($overheadSorted, 0.99),
    ],
    'memory_bytes' => [
        'usage_before_measured_loop' => $memoryBefore,
        'usage_after_measured_loop' => $memoryAfter,
        'usage_delta' => $memoryAfter - $memoryBefore,
        'real_usage_before' => $memoryBeforeReal,
        'real_usage_after' => $memoryAfterReal,
        'peak_usage_process' => memory_get_peak_usage(),
        'peak_real_usage_process' => memory_get_peak_usage(true),
    ],
    'samples_ns' => $list,
];

$out = $options['out'] ?? null;
$json = json_encode($result, JSON_THROW_ON_ERROR);
if (is_string($out)) {
    if (!is_dir(dirname($out)) && !mkdir(dirname($out), 0777, true) && !is_dir(dirname($out))) {
        fwrite(STDERR, "Cannot create output directory for {$out}\n");
        exit(2);
    }
    file_put_contents($out, $json);
} else {
    echo $json, "\n";
}
