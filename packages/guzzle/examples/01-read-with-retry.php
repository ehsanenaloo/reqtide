<?php

declare(strict_types=1);

/**
 * 01 - A read that is retried, counted at the server.
 *
 * `ReqtideClient` runs each call through a Reqtide pipeline. A GET is declared repeatable with `HttpOperation::read()`:
 * that statement is yours, and it names how long it holds and which outcomes may be retried. A 503 is a response, not an
 * exception: after three attempts you get the last response back. The numbers printed below are the server's own count of
 * the requests it received, not the client's.
 *
 * The server here is the small fixture server of this repository (loopback only). In your project it is your API.
 *
 * Run:  tools/run-guzzle-examples.sh   (or: php packages/guzzle/examples/01-read-with-retry.php in a prepared checkout)
 */

require __DIR__ . '/autoload.php';

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

$client = ReqtideClient::inMemory(
    PipelineBuilder::profile('orders-api', 'v1')
        ->partition('shop', 'prod', 'orders-api')
        // The transport enforces this timeout on every attempt.
        ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::TransportEnforced)
        // Up to three attempts, 20 ms apart, and a budget so that a failing server is not hammered.
        ->retry(3, BackoffMode::Constant, Duration::milliseconds(20), Duration::seconds(1), JitterMode::None)
        ->retryBudget(10, 10, Duration::seconds(60), Duration::seconds(12)),
);

$read = static fn(string $path): HttpOperation => HttpOperation::read(
    new Request('GET', $server->baseUri . $path),
    'orders.read',
    Duration::seconds(60),
    HttpOutcomeClassifier::starter()->retryableOutcomeCodes(),
);

$response = $client->send($read('/status/200'), Duration::seconds(10));
echo 'healthy server: status ', $response->getStatusCode(), ', requests at the server: ', $server->count('/status/200'), "\n";

$response = $client->send($read('/status/503'), Duration::seconds(10));
echo 'failing server: status ', $response->getStatusCode(), ', requests at the server: ', $server->count('/status/503'), "\n";

$completion = $client->sendDetailed($read('/status/503'), Duration::seconds(10));
echo 'detailed: ', $completion->summary->dispatched, ' dispatched, ', $completion->summary->retries, ' retries, disposition ', $completion->disposition->value, "\n";

$server->stop();
