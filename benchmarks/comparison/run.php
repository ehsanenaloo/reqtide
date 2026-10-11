<?php

declare(strict_types=1);

/**
 * Behavioural conformance runner.
 *
 *   MSYS_NO_PATHCONV=1 docker compose -f tools/docker/compose.yaml run --rm php84 php benchmarks/comparison/run.php
 *
 * Optional: --only=S01,S08   --out=<dir>   (default: benchmarks/comparison/results)
 * Vendor dir is resolved from $COMPARISON_VENDOR_DIR, else build/comparison/vendor (see README.md).
 */

use Comparison\Adapters\CaseyamclRetryAdapter;
use Comparison\Adapters\DuatAdapter;
use Comparison\Adapters\GaneshaAdapter;
use Comparison\Adapters\ReqtideAdapter;
use Comparison\Adapters\SpatieRateAdapter;
use Comparison\Adapters\SymfonyHttpClientAdapter;
use Comparison\Adapters\SymfonyRateLimiterAdapter;
use Comparison\Scenarios;
use Comparison\Status;

$root = dirname(__DIR__, 2);
$vendor = getenv('COMPARISON_VENDOR_DIR') ?: $root . '/build/comparison/vendor';
if (!is_file($vendor . '/autoload.php')) {
    fwrite(STDERR, "Vendor not installed at $vendor. Run (see benchmarks/comparison/README.md):\n  MSYS_NO_PATHCONV=1 docker compose -f tools/docker/compose.yaml run --rm -e COMPOSER_VENDOR_DIR=/app/build/comparison/vendor php84 composer install -d benchmarks/comparison\n");
    exit(3);
}
require $vendor . '/autoload.php';

$opts = getopt('', ['only::', 'out::']);
$only = isset($opts['only']) ? array_filter(explode(',', (string) $opts['only'])) : [];
$outDir = (string) ($opts['out'] ?? __DIR__ . '/results');
@mkdir($outDir, 0777, true);

ini_set('memory_limit', '1G');
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    if (!(error_reporting() & $no)) {
        return false;
    }
    // Deprecations raised inside vendor code are recorded, not fatal.
    if (in_array($no, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
        $GLOBALS['__deprecations'][] = $str . ' @ ' . basename($file) . ':' . $line;

        return true;
    }
    throw new ErrorException($str, 0, $no, $file, $line);
});
$GLOBALS['__deprecations'] = [];

$adapters = [
    new ReqtideAdapter(),
    new SymfonyRateLimiterAdapter(),
    new SymfonyHttpClientAdapter(),
    new CaseyamclRetryAdapter(),
    new SpatieRateAdapter(),
    new GaneshaAdapter(),
    new DuatAdapter(),
];

$scenarios = array_values(array_filter(Scenarios::all(), static fn (array $s): bool => $only === [] || in_array($s['id'], $only, true)));

$cells = [];
$errors = 0;
foreach ($scenarios as $sc) {
    foreach ($adapters as $a) {
        $t0 = hrtime(true);
        try {
            $r = $a->run($sc);
            $cell = $r->jsonSerialize();
        } catch (Throwable $e) {
            $errors++;
            $cell = ['status' => 'ERROR', 'gap' => null, 'observation' => 'Adapter crashed (harness bug or unexpected library failure): ' . $e->getMessage(), 'data' => []];
            fwrite(STDERR, sprintf("[ERROR] %s/%s: %s\n", $a->id(), $sc['id'], $e->getMessage()));
        }
        $cell['wall_ms'] = round((hrtime(true) - $t0) / 1e6, 1);
        $cells[$sc['id']][$a->id()] = $cell;
    }
}

$meta = [
    'generated_at' => gmdate('c'),
    'php' => PHP_VERSION,
    'os' => php_uname('s') . ' ' . php_uname('r'),
    'time_model' => 'virtual wall clock (Comparison\\VirtualTime); library code unmodified',
    'libraries' => array_map(static fn ($a): array => ['id' => $a->id()] + $a->describe(), $adapters),
    'deprecations_in_vendor_code' => array_values(array_unique($GLOBALS['__deprecations'])),
    'harness_errors' => $errors,
];

$json = ['meta' => $meta, 'scenarios' => $scenarios, 'results' => $cells];
file_put_contents($outDir . '/latest.json', json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) . "\n");

// ---- Markdown ----
$short = [
    'reqtide' => 'Reqtide', 'symfony-rate-limiter' => 'sf/rate-limiter', 'symfony-http-client' => 'sf/http-client',
    'caseyamcl-guzzle-retry' => 'caseyamcl retry', 'spatie-guzzle-rate-limiter' => 'spatie rate', 'ackintosh-ganesha' => 'ganesha', 'matheus85-duat' => 'duat',
];
$sym = ['PASS' => 'PASS', 'FAIL' => 'FAIL', 'NOT_APPLICABLE' => 'N/A', 'NOT_EXPRESSIBLE' => 'NOT_EXPR', 'INFO' => 'INFO', 'NOT_RUN' => 'NOT_RUN', 'ERROR' => 'ERROR'];
$md = "# Competitor conformance results\n\nGenerated {$meta['generated_at']} on PHP {$meta['php']} ({$meta['os']}). Raw: latest.json.\n\n## Matrix\n\n| # | Scenario |";
foreach ($adapters as $a) {
    $md .= ' ' . $short[$a->id()] . ' |';
}
$md .= "\n|---|---|" . str_repeat('---|', count($adapters)) . "\n";
$counts = [];
foreach ($scenarios as $sc) {
    $md .= "| {$sc['id']} | {$sc['title']} |";
    foreach ($adapters as $a) {
        $c = $cells[$sc['id']][$a->id()];
        $label = $sym[$c['status']] ?? $c['status'];
        if ($c['status'] === 'FAIL' && $c['gap']) {
            $label .= ' (' . $c['gap'] . ')';
        }
        $md .= " $label |";
        $counts[$a->id()][$c['status']] = ($counts[$a->id()][$c['status']] ?? 0) + 1;
    }
    $md .= "\n";
}
$md .= "\n## Summary per library\n\n| Library | PASS | FAIL | N/A | NOT_EXPR | INFO | NOT_RUN | ERROR |\n|---|---|---|---|---|---|---|---|\n";
foreach ($adapters as $a) {
    $k = $counts[$a->id()] ?? [];
    $md .= sprintf("| %s | %d | %d | %d | %d | %d | %d | %d |\n", $short[$a->id()], $k['PASS'] ?? 0, $k['FAIL'] ?? 0, $k['NOT_APPLICABLE'] ?? 0, $k['NOT_EXPRESSIBLE'] ?? 0, $k['INFO'] ?? 0, $k['NOT_RUN'] ?? 0, $k['ERROR'] ?? 0);
}
$md .= "\n## Observations (what actually happened)\n";
foreach ($scenarios as $sc) {
    $md .= "\n### {$sc['id']} {$sc['title']}\n\n";
    foreach ($adapters as $a) {
        $c = $cells[$sc['id']][$a->id()];
        if (in_array($c['status'], ['NOT_APPLICABLE', 'NOT_RUN'], true)) {
            $md .= "- **{$short[$a->id()]}** {$c['status']}: {$c['observation']}\n";
        } else {
            $md .= "- **{$short[$a->id()]}** {$c['status']}" . ($c['gap'] ? " ({$c['gap']})" : '') . ": {$c['observation']}\n";
        }
    }
}
file_put_contents($outDir . '/latest.md', $md);

echo $md;
fwrite(STDERR, sprintf("\nWrote %s/latest.json and latest.md (%d scenarios x %d adapters, %d harness errors)\n", $outDir, count($scenarios), count($adapters), $errors));
exit($errors > 0 ? 2 : 0);
