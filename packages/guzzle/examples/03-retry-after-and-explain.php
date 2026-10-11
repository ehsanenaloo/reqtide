<?php

declare(strict_types=1);

/**
 * 03 - `Retry-After`, a cooldown, and `explain()`.
 *
 * A 429 with `Retry-After: 30` starts a cooldown for the partition. While it lasts, further calls are deferred without a
 * request: the server's count stays at one. `Retry-After` is read strictly (seconds or an HTTP date); a value that cannot be
 * read starts no cooldown. `explain()` describes the compiled pipeline and sends nothing.
 *
 * Run:  tools/run-guzzle-examples.sh
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
    PipelineBuilder::profile('search-api', 'v1')
        ->partition('shop', 'prod', 'search-api')
        ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::TransportEnforced)
        ->retry(1, BackoffMode::Constant, Duration::milliseconds(20), Duration::seconds(1), JitterMode::None)
        // How long the cooldown record is kept after it has ended.
        ->cooldown(Duration::seconds(60)),
);

$read = static fn(string $path): HttpOperation => HttpOperation::read(
    new Request('GET', $server->baseUri . $path),
    'search.read',
    Duration::seconds(60),
    HttpOutcomeClassifier::starter()->retryableOutcomeCodes(),
);

$explanation = $client->explain();
echo 'policies: ', implode(', ', $explanation->enabledPolicies), "\n";
echo 'requests after explain(): ', count($server->log()), "\n";

$response = $client->send($read('/status/429?ra=30'), Duration::seconds(5));
echo 'throttled: status ', $response->getStatusCode(), ', requests at the server: ', count($server->log()), "\n";

$completion = $client->sendDetailed($read('/status/200'), Duration::seconds(1));
echo 'next call during the cooldown: ', $completion->disposition->value, ', requests at the server: ', count($server->log()), "\n";
echo 'reason: ', $completion->problem()?->code->value ?? 'none', "\n";

$server->stop();
