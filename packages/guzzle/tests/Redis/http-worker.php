<?php

declare(strict_types=1);

/**
 * One worker process of the cross-process HTTP scenarios, started by BarrierHarness with a JSON argument. It
 * builds a ReqtideClient over Redis backends, prints READY, waits for the start instant published by the coordinator on the
 * server clock, sends `calls` GET requests to `baseUri . path` and prints one JSON line: the count of each status it got and
 * of each exception class it got instead.
 */

use Enaxon\Reqtide\Guzzle\HttpOperation;
use Enaxon\Reqtide\Guzzle\HttpOutcomeClassifier;
use Enaxon\Reqtide\Guzzle\Tests\Redis\HttpRedisPipelines;
use Enaxon\Reqtide\Redis\RedisConnectionOptions;
use Enaxon\Reqtide\Redis\RedisRegistry;
use Enaxon\Reqtide\Value\Duration;
use GuzzleHttp\Psr7\Request;
use Predis\Client;

$spec = json_decode((string) ($argv[1] ?? ''), true, 8, JSON_THROW_ON_ERROR);
if (!is_array($spec)) {
    exit(2);
}
$str = static fn(string $key): string => is_scalar($spec[$key] ?? null) ? (string) $spec[$key] : '';
$int = static fn(string $key): int => is_numeric($spec[$key] ?? null) ? (int) $spec[$key] : 0;
require $str('autoload');

$registry = new RedisRegistry($str('prefix'), $str('identity'), $str('secret'), $int('maxPartitions'), $int('maxRecords'));
$options = new RedisConnectionOptions($str('host'), 2.0, 10.0, $int('port'));
$client = new Client(['scheme' => 'tcp', 'host' => $str('host'), 'port' => $int('port'), 'timeout' => 5.0, 'read_write_timeout' => 5.0]);
$http = HttpRedisPipelines::client($str('pipeline'), $options, $registry, $int('capacity'));

echo "READY\n";
fflush(STDOUT);

$startKey = 'start:' . $str('prefix');
do {
    $published = $client->get($startKey);
    if ($published === null) {
        usleep(200);
    }
} while ($published === null);
$startAt = is_numeric($published) ? (float) $published : 0.0;
do {
    /** @var array{0: numeric-string, 1: numeric-string} $time */
    $time = $client->time();
    $now = (float) $time[0] + (float) $time[1] / 1e6;
    if ($now < $startAt) {
        usleep(200);
    }
} while ($now < $startAt);

$statuses = [];
$exceptions = [];
for ($i = 0; $i < $int('calls'); ++$i) {
    $operation = HttpOperation::read(new Request('GET', $str('baseUri') . $str('path')), 'fixture.read', Duration::seconds(60), HttpOutcomeClassifier::starter()->retryableOutcomeCodes());
    try {
        $response = $http->send($operation, Duration::seconds(2));
        $code = (string) $response->getStatusCode();
        $statuses[$code] = ($statuses[$code] ?? 0) + 1;
    } catch (Throwable $failure) {
        $name = (new ReflectionClass($failure))->getShortName();
        $exceptions[$name] = ($exceptions[$name] ?? 0) + 1;
    }
}

echo json_encode(['worker' => $int('workerId'), 'statuses' => $statuses, 'exceptions' => $exceptions, 'errors' => []], JSON_THROW_ON_ERROR) . "\n";
