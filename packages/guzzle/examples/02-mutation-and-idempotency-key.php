<?php

declare(strict_types=1);

/**
 * 02 - A mutation: repeated only when you say the server deduplicates it.
 *
 * A POST is never repeated on a guess. `HttpOperation::idempotentMutation()` is your statement that the server treats a
 * repeat with the same identity (here the `Idempotency-Key` header) as the same request. Reqtide then sends the same body and
 * the same key on every attempt and checks the key before each one. `HttpOperation::single()` is the other choice: one
 * attempt, never repeated, and Core refuses to run it in a pipeline that retries.
 *
 * The counts, lengths and checksums below are read from the server's own log.
 *
 * Run:  tools/run-guzzle-examples.sh
 */

require __DIR__ . '/autoload.php';

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Guzzle\HttpOperation;
use Enaxon\Reqtide\Guzzle\HttpOutcomeClassifier;
use Enaxon\Reqtide\Guzzle\ReqtideClient;
use Enaxon\Reqtide\Guzzle\Tests\Support\FixtureServer;
use Enaxon\Reqtide\Pipeline\PipelineBuilder;
use Enaxon\Reqtide\Policy\AttemptTimeoutRequirement;
use Enaxon\Reqtide\Policy\BackoffMode;
use Enaxon\Reqtide\Policy\JitterMode;
use Enaxon\Reqtide\Value\Duration;
use GuzzleHttp\Psr7\Request;

$server = FixtureServer::start();

$profile = static fn(int $attempts): PipelineBuilder => PipelineBuilder::profile('billing-api', 'v1')
    ->partition('shop', 'prod', 'billing-api')
    ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::TransportEnforced)
    ->retry($attempts, BackoffMode::Constant, Duration::milliseconds(20), Duration::seconds(1), JitterMode::None);

$retrying = ReqtideClient::inMemory($profile(3)->retryBudget(10, 10, Duration::seconds(60), Duration::seconds(12)));
$once = ReqtideClient::inMemory($profile(1));

$charge = HttpOperation::idempotentMutation(
    new Request('POST', $server->baseUri . '/status/503', ['Idempotency-Key' => 'order-1042'], 'amount=1999&currency=CAD'),
    'billing.charge',
    'order-1042',
    'this account and endpoint',
    'returns the stored response for 24 hours',
    Duration::seconds(60),
    retryableOutcomeCodes: HttpOutcomeClassifier::starter()->retryableOutcomeCodes(),
);
$response = $retrying->send($charge, Duration::seconds(10));

$log = $server->log();
$bodies = array_unique(array_column($log, 'bodySha1'));
$keys = array_unique(array_map(static fn(array $entry): string => implode(',', $entry['headers']['idempotency-key'] ?? []), $log));
echo 'server answered ', $response->getStatusCode(), ' to ', count($log), ' attempts', "\n";
echo 'same body every time: ', count($bodies) === 1 ? 'yes' : 'no', "\n";
echo 'same key every time: ', $keys === ['order-1042'] ? 'yes' : 'no', "\n";

$server->reset();
$single = HttpOperation::single(new Request('POST', $server->baseUri . '/status/503', [], 'amount=1999'), 'billing.charge');
$once->send($single, Duration::seconds(10));
echo 'a single operation: ', count($server->log()), " request\n";

try {
    $retrying->send($single, Duration::seconds(10));
} catch (InvalidConfigurationException) {
    echo 'a single operation in a retrying pipeline is refused before anything is sent: ', count($server->log()), " request in all\n";
}

$server->stop();
