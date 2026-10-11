<?php

declare(strict_types=1);

/**
 * Raw measurements of the Guzzle client overhead. No targets, no budgets, no claims.
 *
 *   php -d pcov.enabled=0 benchmarks/guzzle-bench.php [--iterations=1000] [--warmup=100] [--cold-runs=7] [--out=build/bench/guzzle.json]
 *
 * A fixture server (the one of the Guzzle tests) runs on loopback in a child process and answers `GET /status/200`. For every
 * scenario `warmup` calls are discarded and `iterations` calls are timed one by one with hrtime(true); every raw sample is
 * stored. The scenarios differ only in what stands between the caller and the same cURL transfer: a bare Guzzle client; the
 * Reqtide client with a pipeline of attempt and one retry only; the Reqtide client with all five policies over memory state;
 * the same, called through the promise. The first request is measured apart, in a fresh PHP process for each run: the time
 * from a created client to the end of its first response, because Guzzle's initialisation and the autoloading of the classes
 * it needs happen there. `--php-d=name=value` (repeatable) is passed to those fresh processes, for example an OPcache file cache.
 */

namespace Reqtide\Benchmarks;

use Enaxon\Reqtide\Guzzle\GuzzleConfiguration;
use Enaxon\Reqtide\Guzzle\HttpOperation;
use Enaxon\Reqtide\Guzzle\HttpOutcomeClassifier;
use Enaxon\Reqtide\Guzzle\ReqtideClient;
use Enaxon\Reqtide\Guzzle\Tests\Support\FixtureServer;
use Enaxon\Reqtide\Pipeline\PipelineBuilder;
use Enaxon\Reqtide\Policy\AttemptTimeoutRequirement;
use Enaxon\Reqtide\Policy\BackoffMode;
use Enaxon\Reqtide\Policy\JitterMode;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\Ratio;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;

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

// `--code-root=DIR` (or RT_BENCH_CODE_ROOT): load the library classes from a copy of the sources on a local disk, ahead of
// Composer's autoloader. A Docker bind mount of a Windows folder makes reading source files slow, which dominates the first request.
$codeRoot = getenv('RT_BENCH_CODE_ROOT');
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--code-root=')) {
        $codeRoot = substr($argument, strlen('--code-root='));
        putenv('RT_BENCH_CODE_ROOT=' . $codeRoot);
    }
}
if (is_string($codeRoot) && $codeRoot !== '' && is_dir($codeRoot)) {
    $map = [
        'Enaxon\\Reqtide\\Guzzle\\Tests\\' => $codeRoot . '/packages/guzzle/tests/',
        'Enaxon\\Reqtide\\Guzzle\\' => $codeRoot . '/packages/guzzle/src/',
        'Enaxon\\Reqtide\\Redis\\' => $codeRoot . '/packages/redis/src/',
        'Enaxon\\Reqtide\\' => $codeRoot . '/src/',
    ];
    spl_autoload_register(static function (string $class) use ($map): void {
        foreach ($map as $prefix => $directory) {
            if (str_starts_with($class, $prefix)) {
                $file = $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($file)) {
                    require $file;

                    return;
                }
            }
        }
    }, true, true);
}

$options = getopt('', ['code-root::', 'iterations::', 'warmup::', 'cold-runs::', 'out::', 'cold:', 'base:', 'php-d::']);
$phpD = isset($options['php-d']) ? (array) $options['php-d'] : [];

$minimal = static fn(): PipelineBuilder => PipelineBuilder::profile('bench', 'v1')
    ->partition('bench', 'local', 'guzzle')
    ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::TransportEnforced)
    ->retry(1, BackoffMode::Constant, Duration::milliseconds(5), Duration::seconds(1), JitterMode::None);
$five = static fn(): PipelineBuilder => PipelineBuilder::profile('bench', 'v1')
    ->partition('bench', 'local', 'guzzle')
    ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::TransportEnforced)
    ->retry(2, BackoffMode::Constant, Duration::milliseconds(5), Duration::seconds(1), JitterMode::None)
    ->retryBudget(1_000_000, 1000, Duration::seconds(1), Duration::seconds(30))
    ->rateLimit(PipelineBuilder::tokenBucket(1_000_000, 1000, Duration::seconds(1)))
    ->circuitBreaker(PipelineBuilder::lastCalls(100), 20, Ratio::of(1, 2), Duration::seconds(30), 1, 1, Duration::seconds(10))
    ->bulkhead(1000, Duration::seconds(30))
    ->cooldown(Duration::seconds(60));
$operationFor = static fn(string $base): HttpOperation => HttpOperation::read(new Request('GET', $base . '/status/200'), 'bench.read', Duration::seconds(3600), HttpOutcomeClassifier::starter()->retryableOutcomeCodes());

// Child mode: one fresh process measures the first request.
if (isset($options['cold'], $options['base']) && is_string($options['cold']) && is_string($options['base'])) {
    $base = $options['base'];
    $start = hrtime(true);
    if ($options['cold'] === 'bare') {
        $client = new Client(['handler' => HandlerStack::create(new CurlHandler()), 'http_errors' => false]);
        $client->send(new Request('GET', $base . '/status/200'), ['timeout' => 2.0, 'connect_timeout' => 2.0]);
    } else {
        $client = ReqtideClient::inMemory($options['cold'] === 'reqtide-five' ? $five() : $minimal(), new GuzzleConfiguration());
        $client->send($operationFor($base), Duration::seconds(5));
    }
    echo json_encode(['cold' => $options['cold'], 'first_request_ns' => hrtime(true) - $start, 'included_files' => count(get_included_files())]) . "\n";
    exit(0);
}

$iterations = is_string($options['iterations'] ?? null) ? (int) $options['iterations'] : 1000;
$warmup = is_string($options['warmup'] ?? null) ? (int) $options['warmup'] : 100;
$coldRuns = is_string($options['cold-runs'] ?? null) ? (int) $options['cold-runs'] : 7;
$out = is_string($options['out'] ?? null) ? $options['out'] : 'build/bench/guzzle.json';

$server = FixtureServer::start();
$base = $server->baseUri;
$bare = new Client(['handler' => HandlerStack::create(new CurlHandler()), 'http_errors' => false]);
$barePsr = new Request('GET', $base . '/status/200');
$reqtideMinimal = ReqtideClient::inMemory($minimal(), new GuzzleConfiguration());
$reqtideFive = ReqtideClient::inMemory($five(), new GuzzleConfiguration());
$operation = $operationFor($base);

/** @var array<string, \Closure(): mixed> $scenarios */
$scenarios = [
    'bare_guzzle_curl_handler' => static fn(): mixed => $bare->send($barePsr, ['timeout' => 2.0, 'connect_timeout' => 2.0]),
    'reqtide_sync_attempt_only' => static fn(): mixed => $reqtideMinimal->send($operation, Duration::seconds(5)),
    'reqtide_sync_five_policies' => static fn(): mixed => $reqtideFive->send($operation, Duration::seconds(5)),
    'reqtide_promise_five_policies' => static fn(): mixed => $reqtideFive->sendAsync($operation, Duration::seconds(5))->wait(),
];

$stats = static function (array $samples): array {
    sort($samples);
    $n = count($samples);
    $rank = static fn(float $p): int => $samples[max(0, (int) ceil($p * $n) - 1)];

    return ['count' => $n, 'min' => $samples[0], 'p50' => $rank(0.50), 'p95' => $rank(0.95), 'p99' => $rank(0.99), 'max' => $samples[$n - 1], 'mean' => array_sum($samples) / $n];
};

$results = [];
foreach ($scenarios as $name => $call) {
    for ($i = 0; $i < $warmup; ++$i) {
        $call();
    }
    gc_collect_cycles();
    $samples = new \SplFixedArray($iterations);
    for ($i = 0; $i < $iterations; ++$i) {
        $start = hrtime(true);
        $call();
        $samples[$i] = hrtime(true) - $start;
    }
    $list = $samples->toArray();
    $results[$name] = ['samples_ns' => $list, 'stats_ns' => $stats($list)];
    fwrite(STDERR, sprintf("%-32s p50 %8d ns  p95 %8d ns  p99 %8d ns\n", $name, $results[$name]['stats_ns']['p50'], $results[$name]['stats_ns']['p95'], $results[$name]['stats_ns']['p99']));
}
$served = $server->count('/status/200');

// First request, one fresh process per run, in rotation so that no kind always runs first.
$cold = ['bare' => [], 'reqtide-minimal' => [], 'reqtide-five' => []];
$coldFiles = [];
for ($run = 0; $run < $coldRuns; ++$run) {
    foreach (array_keys($cold) as $kind) {
        $command = [PHP_BINARY, '-d', 'pcov.enabled=0'];
        foreach ($phpD as $setting) {
            array_push($command, '-d', (string) $setting);
        }
        array_push($command, __FILE__, '--cold=' . $kind, '--base=' . $base);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            continue;
        }
        $line = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $decoded = json_decode(trim($line), true);
        if (is_array($decoded) && is_int($decoded['first_request_ns'] ?? null)) {
            $cold[$kind][] = $decoded['first_request_ns'];
            $coldFiles[$kind] = is_int($decoded['included_files'] ?? null) ? $decoded['included_files'] : null;
        }
    }
}
foreach ($cold as $kind => $samples) {
    if ($samples !== []) {
        fwrite(STDERR, sprintf("first request %-16s median %8.1f ms over %d fresh processes\n", $kind, $stats($samples)['p50'] / 1e6, count($samples)));
    }
}
$server->stop();

$output = [
    'kind' => 'guzzle-bench',
    'note' => 'Raw measurements. No targets, budgets, claims or comparisons.',
    'environment' => [
        'php' => PHP_VERSION,
        'php_sapi' => PHP_SAPI,
        'guzzle' => \Composer\InstalledVersions::getPrettyVersion('guzzlehttp/guzzle'),
        'curl' => curl_version()['version'] ?? null,
        'client_host' => php_uname(),
        'opcache_enable_cli' => ini_get('opcache.enable_cli'),
        'cpus_visible' => is_file('/proc/cpuinfo') ? substr_count((string) file_get_contents('/proc/cpuinfo'), 'processor') : null,
        'load_average_end' => function_exists('sys_getloadavg') ? sys_getloadavg() : null,
        'server' => 'the PHP fixture server of the Guzzle tests, loopback, one child process',
        'requests_served_by_timed_scenarios_and_warmup' => $served,
    ],
    'parameters' => ['iterations' => $iterations, 'warmup' => $warmup, 'cold_runs' => $coldRuns],
    'scenarios' => $results,
    'first_request' => array_map(static fn(array $samples): array => ['samples_ns' => $samples, 'stats_ns' => $samples === [] ? null : $stats($samples)], $cold),
    'first_request_included_files' => $coldFiles,
    'first_request_php_settings' => $phpD,
];
$directory = dirname($out);
if (!is_dir($directory) && !mkdir($directory, 0775, true)) {
    fwrite(STDERR, "cannot create $directory\n");
    exit(1);
}
file_put_contents($out, json_encode($output, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
fwrite(STDERR, "wrote $out\n");
