<?php

declare(strict_types=1);

/**
 * The driver of the PHP-FPM lane (tools/check-fpm.sh). It sends requests to the application in `public/` through nginx and
 * PHP-FPM and checks what an FPM deployment really does:
 *
 *   1. the SAPI is FPM;
 *   2. a process-local rate limit starts every request with a full quota (the documented limit of FPM);
 *   3. a Redis rate limit is shared by all requests and workers, also when the requests run at the same time;
 *   4. a Redis bulkhead admits exactly its capacity under concurrent requests and refuses the others at once;
 *   5. a deadline passed inside the callable is reported as exceeded;
 *   6. a retry with a Redis retry budget works inside a request.
 *
 *   php tools/fpm-lane/drive.php --base=http://web --redis-host=redis82
 */

use Enaxon\Reqtide\Redis\RedisConnectionOptions;
use Enaxon\Reqtide\Redis\RedisRegistry;
use Enaxon\Reqtide\Redis\RedisRegistryAdmin;

foreach ([getenv('COMPOSER_VENDOR_DIR') !== false ? getenv('COMPOSER_VENDOR_DIR') . '/autoload.php' : null, '/opt/reqtide-vendor/autoload.php', __DIR__ . '/../../vendor/autoload.php'] as $file) {
    if ($file !== null && is_file($file)) {
        require $file;
        break;
    }
}

$options = getopt('', ['base:', 'redis-host:', 'redis-port::']);
$base = rtrim(is_string($options['base'] ?? null) ? $options['base'] : '', '/');
$redisHost = is_string($options['redis-host'] ?? null) ? $options['redis-host'] : '';
$redisPort = is_string($options['redis-port'] ?? null) ? (int) $options['redis-port'] : 6379;
if ($base === '' || $redisHost === '') {
    fwrite(STDERR, "usage: drive.php --base=URL --redis-host=HOST [--redis-port=N]\n");
    exit(2);
}

$failures = 0;
$check = static function (bool $ok, string $what) use (&$failures): void {
    echo ($ok ? 'PASS  ' : 'FAIL  '), $what, "\n";
    if (!$ok) {
        ++$failures;
    }
};

/**
 * @param list<string> $paths
 * @return list<array<string, mixed>>
 */
$request = static function (array $paths) use ($base): array {
    $multi = curl_multi_init();
    $handles = [];
    foreach ($paths as $i => $path) {
        $handle = curl_init($base . $path);
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
        curl_multi_add_handle($multi, $handle);
        $handles[$i] = $handle;
    }
    do {
        $status = curl_multi_exec($multi, $running);
        if ($running > 0) {
            curl_multi_select($multi, 0.05);
        }
    } while ($running > 0 && $status === CURLM_OK);
    $answers = [];
    foreach ($handles as $i => $handle) {
        $decoded = json_decode((string) curl_multi_getcontent($handle), true);
        $answers[$i] = is_array($decoded) ? $decoded + ['http' => curl_getinfo($handle, CURLINFO_HTTP_CODE)] : ['ok' => false, 'http' => curl_getinfo($handle, CURLINFO_HTTP_CODE)];
        curl_multi_remove_handle($multi, $handle);
    }
    curl_multi_close($multi);

    return $answers;
};
/** @return array<string, mixed> */
$one = static fn(string $path): array => $request([$path])[0];
/** @param list<array<string, mixed>> $answers */
$count = static fn(array $answers, string $result): int => count(array_filter($answers, static fn(array $a): bool => ($a['result'] ?? null) === $result));

$connection = new RedisConnectionOptions($redisHost, 2.0, 10.0, $redisPort);
$registries = [];
$newRun = static function () use ($connection, &$registries): string {
    $run = bin2hex(random_bytes(6));
    $registry = new RedisRegistry('fpm_' . $run, 'fpm-lane', 'a-host-managed-secret-of-32-bytes!!');
    (new RedisRegistryAdmin($connection, $registry))->initialize();
    $registries[] = $registry;

    return $run;
};

// 1. SAPI
$info = $one('/info');
$check(($info['sapi'] ?? null) === 'fpm-fcgi', 'the application runs under PHP-FPM (sapi ' . ($info['sapi'] ?? '?') . ', PHP ' . ($info['php'] ?? '?') . ', ' . ($info['uname'] ?? '?') . ')');

// 2. process-local state does not survive a request
$answers = $request(array_fill(0, 6, '/memory'));
$sequential = [];
for ($i = 0; $i < 6; ++$i) {
    $sequential[] = $one('/memory');
}
$check($count($sequential, 'served') === 6, 'a process-local rate limit of one token serves every one of six sequential requests (state starts fresh per request)');
$workers = array_unique(array_map(static fn(array $a): int => (int) ($a['pid'] ?? 0), array_merge($answers, $sequential)));
$check(count($workers) >= 2, 'the requests were spread over several workers (' . count($workers) . ' distinct pids)');

// 3. Redis state is shared by requests and workers
$run = $newRun();
$sequential = [];
for ($i = 0; $i < 6; ++$i) {
    $sequential[] = $one('/redis?run=' . $run);
}
$check($count($sequential, 'served') === 1 && $count($sequential, 'refused') === 5, 'a Redis rate limit of one token serves exactly one of six sequential requests');
$run = $newRun();
$concurrent = $request(array_fill(0, 12, '/redis?run=' . $run));
$check($count($concurrent, 'served') === 1 && $count($concurrent, 'refused') === 11, 'a Redis rate limit of one token serves exactly one of twelve concurrent requests over several workers');

// 4. bulkhead under concurrency
$run = $newRun();
$started = microtime(true);
$concurrent = $request(array_fill(0, 6, '/bulkhead?run=' . $run));
$elapsed = microtime(true) - $started;
$check($count($concurrent, 'served') === 2 && $count($concurrent, 'refused') === 4, 'a Redis bulkhead of two permits admits exactly two of six concurrent requests and refuses four');
$refusedMilliseconds = array_map(static fn(array $a): int => (int) ($a['ms'] ?? 0), array_filter($concurrent, static fn(array $a): bool => ($a['result'] ?? null) === 'refused'));
$check($elapsed < 1.5, sprintf('the six requests ended together after %.0f ms: a refusal does not wait for the permits', $elapsed * 1000));
$after = $one('/bulkhead?run=' . $run);
$check(($after['result'] ?? null) === 'served', 'the permits were released after the requests: the next request is served');

// 5. deadline
$deadline = $one('/deadline');
$check(($deadline['result'] ?? null) === 'deadline' && ($deadline['ms'] ?? 0) >= 450, 'a callable that runs past the deadline is reported as exceeded (not interrupted: ' . ($deadline['ms'] ?? '?') . ' ms)');

// 6. retry with a retry budget
$run = $newRun();
$retry = $one('/retry?run=' . $run);
$check(($retry['result'] ?? null) === 'served', 'a call that fails once is retried once under a Redis retry budget and is served');

foreach ($registries as $registry) {
    $admin = new RedisRegistryAdmin($connection, $registry);
    $admin->retire();
    while ($admin->purgeRetired() > 0) {
    }
}

echo $failures === 0 ? "fpm lane: all checks passed\n" : "fpm lane: {$failures} check(s) failed\n";
exit($failures === 0 ? 0 : 1);
