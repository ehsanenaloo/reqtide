<?php

declare(strict_types=1);

/*
 * Cost of the replay body digest (BodyIntegrity::Verified) per check, for memory and temporary-file streams.
 * Each attempt reads the body twice for the digest (preparation and final check) and once more to send it.
 *
 *   php benchmarks/body-digest.php
 */

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

use Enaxon\Reqtide\Guzzle\Operation\BodyDigest;
use GuzzleHttp\Psr7\Stream;
use GuzzleHttp\Psr7\Utils;

$sizes = ['0 B' => 0, '1 KiB' => 1024, '1 MiB' => 1 << 20, '8 MiB' => 8 << 20, '16 MiB' => 16 << 20];
$runs = 20;

printf("PHP %s, %d runs per row, median time of one digest\n", PHP_VERSION, $runs);
printf("%-8s %-6s %12s %12s %14s\n", 'size', 'stream', 'median ms', 'MB/s', 'peak memory MB');
foreach ($sizes as $label => $size) {
    foreach (['memory', 'file'] as $kind) {
        $payload = str_repeat('a', $size);
        if ($kind === 'memory') {
            $body = Utils::streamFor($payload);
        } else {
            $handle = fopen('php://temp/maxmemory:0', 'w+b');
            if ($handle === false) {
                throw new RuntimeException('no temporary stream');
            }
            fwrite($handle, $payload);
            $body = new Stream($handle);
        }
        unset($payload);
        memory_reset_peak_usage();
        $times = [];
        for ($i = 0; $i < $runs; ++$i) {
            $start = hrtime(true);
            BodyDigest::of($body, $size);
            $times[] = hrtime(true) - $start;
        }
        sort($times);
        $median = $times[intdiv($runs, 2)] / 1e6;
        $rate = $size === 0 || $median <= 0.0 ? 0.0 : ($size / (1 << 20)) / ($median / 1000);
        printf("%-8s %-6s %12.3f %12.0f %14.1f\n", $label, $kind, $median, $rate, memory_get_peak_usage() / (1 << 20));
    }
}
