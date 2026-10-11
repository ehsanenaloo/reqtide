<?php

declare(strict_types=1);

/**
 * Runs every scenario in a fresh PHP process, `repeats` times each, and writes ONE raw result file.
 *
 *   php -d pcov.enabled=0 benchmarks/run-all.php [--iterations=5000] [--warmup=1000] [--repeats=3]
 *                                                [--php-d=opcache.enable_cli=1 ...] [--out=build/bench/<name>.json]
 *
 * The output holds the environment description, the parameters and, for every run, ALL raw samples plus
 * the derived percentiles. Nothing in it is a target, a budget or a claim. See benchmarks/README.md.
 */

namespace Reqtide\Benchmarks;

$options = getopt('', ['iterations::', 'warmup::', 'repeats::', 'out::', 'scenario::', 'php-d::']);
$iterations = (int) ($options['iterations'] ?? 5000);
$warmup = (int) ($options['warmup'] ?? 1000);
$repeats = (int) ($options['repeats'] ?? 3);
$only = isset($options['scenario']) && is_string($options['scenario']) ? $options['scenario'] : null;
$out = isset($options['out']) && is_string($options['out'])
    ? $options['out']
    : 'build/bench/local-php' . PHP_VERSION . '.json';

$scenarios = ['baseline_context_only', 'empty_pipeline', 'rate_limit_only', 'circuit_only', 'concurrency_only', 'retry_budget_only', 'cooldown_only', 'combined_all_five'];
if ($only !== null) {
    $scenarios = [$only];
}

/** Reads a file, returning null when unavailable (these are Linux-only introspection files). */
$read = static function (string $path): ?string {
    $content = @file_get_contents($path);

    return $content === false ? null : trim($content);
};

$cpuModel = null;
$cpuInfo = $read('/proc/cpuinfo');
if ($cpuInfo !== null && preg_match('/^model name\s*:\s*(.+)$/m', $cpuInfo, $m) === 1) {
    $cpuModel = $m[1];
}
$memTotal = null;
$memInfo = $read('/proc/meminfo');
if ($memInfo !== null && preg_match('/^MemTotal:\s*(\d+) kB/m', $memInfo, $m) === 1) {
    $memTotal = (int) $m[1] * 1024;
}

// A digest of the library source the numbers belong to: path + sha1 of every file, hashed once more.
$root = dirname(__DIR__);
$files = [];
foreach (['src'] as $dir) {
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $files[substr($file->getPathname(), strlen($root) + 1)] = sha1_file($file->getPathname());
        }
    }
}
ksort($files);
$sourceDigest = sha1(json_encode($files, JSON_THROW_ON_ERROR));

$environment = [
    'php_version' => PHP_VERSION,
    'php_sapi' => PHP_SAPI,
    'php_int_size_bytes' => PHP_INT_SIZE,
    'os' => php_uname(),
    'os_family' => PHP_OS_FAMILY,
    'in_container' => is_file('/.dockerenv'),
    'cpu_model' => $cpuModel,
    'cpu_logical_cores_visible' => (int) ($read('/proc/cpuinfo') !== null ? preg_match_all('/^processor\s*:/m', (string) $cpuInfo) : 0),
    'cgroup_cpu_max' => $read('/sys/fs/cgroup/cpu.max'),
    'memory_total_bytes_visible' => $memTotal,
    'cgroup_memory_max' => $read('/sys/fs/cgroup/memory.max'),
    'loaded_extensions' => get_loaded_extensions(),
    'opcache_loaded' => extension_loaded('Zend OPcache'),
    'opcache.enable_cli' => ini_get('opcache.enable_cli'),
    'opcache.jit' => ini_get('opcache.jit'),
    'pcov.enabled' => ini_get('pcov.enabled'),
    'xdebug_loaded' => extension_loaded('xdebug'),
    'memory_limit' => ini_get('memory_limit'),
    'zend.assertions' => ini_get('zend.assertions'),
    'gc_enabled' => gc_enabled(),
    'source_digest_sha1' => $sourceDigest,
    'source_files_hashed' => count($files),
    'load_average_at_start' => function_exists('sys_getloadavg') ? sys_getloadavg() : null,
];

// Extra ini settings for the child processes, e.g. --php-d=opcache.enable_cli=1 (repeatable). The default
// keeps the container's stock configuration, apart from switching the coverage extension off.
$flags = ['-d', 'pcov.enabled=0'];
$extra = $options['php-d'] ?? [];
foreach (is_array($extra) ? $extra : [$extra] as $setting) {
    if (is_string($setting) && $setting !== '') {
        array_push($flags, '-d', $setting);
    }
}
$runs = [];
$failed = 0;
foreach ($scenarios as $scenario) {
    for ($repeat = 1; $repeat <= $repeats; ++$repeat) {
        $command = array_merge([PHP_BINARY], $flags, [
            __DIR__ . '/bench.php',
            '--scenario=' . $scenario,
            '--iterations=' . $iterations,
            '--warmup=' . $warmup,
        ]);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            fwrite(STDERR, "cannot start a benchmark process\n");
            exit(2);
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0 || $stdout === false) {
            fwrite(STDERR, "scenario {$scenario} repeat {$repeat} failed (status {$status}): {$stderr}\n");
            ++$failed;
            continue;
        }
        $run = json_decode($stdout, true, 8, JSON_THROW_ON_ERROR);
        $run['repeat'] = $repeat;
        $runs[] = $run;
        $s = $run['stats_ns'];
        fprintf(STDERR, "%-24s repeat %d  p50=%6d ns  p95=%6d ns  p99=%7d ns  max=%8d ns\n", $scenario, $repeat, $s['p50'], $s['p95'], $s['p99'], $s['max']);
    }
}

$environment['load_average_at_end'] = function_exists('sys_getloadavg') ? sys_getloadavg() : null;
$document = [
    'schema' => 'reqtide-bench-local/1',
    'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
    'parameters' => ['iterations' => $iterations, 'warmup' => $warmup, 'repeats' => $repeats, 'php_flags' => $flags],
    'environment' => $environment,
    'notes' => 'Raw measurements of the local in-memory slice, success path only. No targets or claims. Each run is a fresh PHP process.',
    'runs' => $runs,
];

if (!is_dir(dirname($out)) && !mkdir(dirname($out), 0777, true) && !is_dir(dirname($out))) {
    fwrite(STDERR, "cannot create the output directory for {$out}\n");
    exit(2);
}
// Pretty-print the structure but keep each sample list on one line (otherwise one sample per line).
$placeholders = [];
foreach ($document['runs'] as $index => $run) {
    $token = '@@SAMPLES_' . $index . '@@';
    $placeholders['"' . $token . '"'] = json_encode($run['samples_ns'], JSON_THROW_ON_ERROR);
    $document['runs'][$index]['samples_ns'] = $token;
}
$encoded = json_encode($document, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
file_put_contents($out, strtr($encoded, $placeholders));
fwrite(STDERR, "wrote {$out} (" . count($runs) . " runs, {$failed} failed)\n");
exit($failed === 0 ? 0 : 1);
