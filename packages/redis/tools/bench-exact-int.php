<?php

declare(strict_types=1);

/**
 * Raw microbenchmark of the Lua exact-integer library on a real server. Prints samples, never a verdict.
 *
 *   RT_REDIS_HOST=redis74 RT_REDIS_PORT=6379 php packages/redis/tools/bench-exact-int.php [iterations] [samples]
 *
 * Each sample runs the case in a loop inside one script and reports nanoseconds per call from os.clock (CPU time of the
 * server process while the script runs). Machine-dependent; compare only samples taken together. Not a throughput claim.
 */

use Predis\Client;

$vendor = getenv('COMPOSER_VENDOR_DIR');
$vendor = is_string($vendor) && $vendor !== '' ? $vendor : dirname(__DIR__, 3) . '/vendor';
require $vendor . '/autoload.php';

$host = getenv('RT_REDIS_HOST');
if (!is_string($host) || $host === '') {
    fwrite(STDERR, "bench-exact-int: set RT_REDIS_HOST (and RT_REDIS_PORT).\n");
    exit(2);
}
$port = getenv('RT_REDIS_PORT');
$iterations = isset($argv[1]) && ctype_digit($argv[1]) ? (int) $argv[1] : 2000;
$samples = isset($argv[2]) && ctype_digit($argv[2]) ? (int) $argv[2] : 5;

$library = file_get_contents(dirname(__DIR__) . '/resources/lua/lib/exact_int.lua');
if ($library === false) {
    fwrite(STDERR, "bench-exact-int: library not found.\n");
    exit(2);
}

$body = <<<'LUA'
local n = exact_int.parse_int(ARGV[1])
local a, b, r, d, cap = exact_int.parse_int(ARGV[2]), exact_int.parse_int(ARGV[3]), exact_int.parse_int(ARGV[4]),
    exact_int.parse_int(ARGV[5]), exact_int.parse_int(ARGV[6])
local started = os.clock()
for _ = 1, n do
    exact_int.mul_add_div_floor_capped(a, b, r, d, cap)
end
local elapsed = os.clock() - started
return exact_int.format_int(math.floor(elapsed * 1000000000 / n))
LUA;

$cases = [
    'native path: 600000 * 100000000 / 60000000' => ['600000', '100000000', '0', '60000000', '9007199254740991'],
    'limb path, small: 1000000000 * 100000000 / 100000000 + cap' => ['1000000000', '100000000', '0', '100000000', '9007199254740991'],
    'limb path, 2^40 operands' => ['1099511627776', '1048575', '1', '1000003', '9007199254740991'],
    'limb path, worst: (2^53-1)^2 / 2^52 with cap above' => ['9007199254740991', '9007199254740991', '0', '4503599627370496', '9007199254740991'],
    'limb path, near-cap quotient' => ['9007199254740990', '4503599627370496', '1', '4503599627370495', '9007199254740991'],
];

$client = new Client(['scheme' => 'tcp', 'host' => $host, 'port' => $port !== false && $port !== '' ? (int) $port : 6379, 'timeout' => 120.0, 'read_write_timeout' => 120.0]);
$info = $client->info('server');
$server = $info['Server'] ?? $info['server'] ?? [];
echo 'server redis_version=' . ($server['redis_version'] ?? '?') . ' php=' . PHP_VERSION . " iterations={$iterations} samples={$samples}\n";

foreach ($cases as $name => $arguments) {
    $values = [];
    for ($i = 0; $i < $samples; ++$i) {
        $values[] = (int) $client->eval($library . "\n" . $body, 0, (string) $iterations, ...$arguments);
    }
    echo str_pad((string) $name, 64) . ' ns/call: ' . implode(' ', $values) . "\n";
}
