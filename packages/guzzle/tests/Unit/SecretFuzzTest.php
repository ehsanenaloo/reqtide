<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Tests\Unit;

use Enaxon\Reqtide\Completion\ExecutionCompletion;
use Enaxon\Reqtide\Error\ProblemCarrier;
use Enaxon\Reqtide\Guzzle\GuzzleConfiguration;
use Enaxon\Reqtide\Guzzle\HttpOperation;
use Enaxon\Reqtide\Guzzle\HttpOutcomeClassifier;
use Enaxon\Reqtide\Guzzle\ReqtideClient;
use Enaxon\Reqtide\Guzzle\Tests\Support\FixtureServer;
use Enaxon\Reqtide\Pipeline\PipelineBuilder;
use Enaxon\Reqtide\Policy\AttemptTimeoutRequirement;
use Enaxon\Reqtide\Policy\BackoffMode;
use Enaxon\Reqtide\Policy\JitterMode;
use Enaxon\Reqtide\Telemetry\EventSink;
use Enaxon\Reqtide\Testing\RecordingEventSink;
use Enaxon\Reqtide\Time\CancellationToken;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\Ratio;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Secret leak check for the Guzzle package: random secrets are placed in every part of a request that a caller may put a secret in
 * (user information, path, query, fragment, headers, cookie, body, credential key and idempotency identity), the client is
 * run through success, retries, exhausted retries, a refused connection, a refused admission, an open circuit and a
 * cancellation, and everything Reqtide produces (events, summaries, diagnostics, problems, policy and classifier failures)
 * is searched for every secret. The business result belongs to the caller: a Guzzle exception message holds the full URL and
 * is not examined.
 */
final class SecretFuzzTest extends TestCase
{
    private const CASES = 120;

    private static FixtureServer $server;

    public static function setUpBeforeClass(): void
    {
        self::$server = FixtureServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    private static function secret(): string
    {
        return 'SEC' . bin2hex(random_bytes(12));
    }

    private function retrying(EventSink $events): ReqtideClient
    {
        return ReqtideClient::inMemory(
            PipelineBuilder::profile('fuzz', 'v1')
                ->partition('test', 'local', 'fuzz')
                ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::TransportEnforced)
                ->retry(3, BackoffMode::Constant, Duration::milliseconds(2), Duration::seconds(1), JitterMode::None)
                ->retryBudget(10, 10, Duration::seconds(3600), Duration::seconds(12))
                ->rateLimit(PipelineBuilder::tokenBucket(5, 1, Duration::seconds(3600)))
                ->circuitBreaker(PipelineBuilder::lastCalls(4), 2, Ratio::of(1, 2), Duration::seconds(60), 1, 1, Duration::seconds(10)),
            new GuzzleConfiguration(),
            $events,
        );
    }

    private function singleAttempt(EventSink $events): ReqtideClient
    {
        return ReqtideClient::inMemory(
            PipelineBuilder::profile('fuzz-once', 'v1')
                ->partition('test', 'local', 'fuzz')
                ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::TransportEnforced)
                ->retry(1, BackoffMode::Constant, Duration::milliseconds(2), Duration::seconds(1), JitterMode::None),
            new GuzzleConfiguration(),
            $events,
        );
    }

    /**
     * What Reqtide produced for one completion, as text.
     *
     * @param ExecutionCompletion<ResponseInterface> $completion
     */
    private static function dumpOf(ExecutionCompletion $completion): string
    {
        $text = print_r([$completion->summary, $completion->diagnostics, $completion->problem()], true);
        foreach ([$completion->policyCause, $completion->classifierCause] as $failure) {
            if ($failure !== null) {
                $text .= get_class($failure) . "\n" . $failure->getMessage() . "\n" . ($failure instanceof ProblemCarrier ? print_r($failure->problem(), true) : '');
            }
        }

        return $text;
    }

    public function testNoSecretPlacedInARequestReachesAnythingReqtideProduces(): void
    {
        $closed = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($closed);
        $name = (string) stream_socket_get_name($closed, false);
        fclose($closed);
        $codes = HttpOutcomeClassifier::starter()->retryableOutcomeCodes();
        $dispositions = [];

        for ($case = 0; $case < self::CASES; ++$case) {
            $events = new RecordingEventSink();
            $retrying = $this->retrying($events);
            $once = $this->singleAttempt($events);
            $secrets = [];
            $make = static function () use (&$secrets): string {
                return $secrets[] = self::secret();
            };
            $user = $make();
            $password = $make();
            $segment = $make();
            $query = $make();
            $fragment = $make();
            $bearer = $make();
            $cookie = $make();
            $custom = $make();
            $body = $make();
            $key = $make();
            $identity = $make();
            $base = self::$server->baseUri;
            $withUserInfo = static fn(string $uri): string => preg_replace('~^http://~', 'http://' . $user . ':' . $password . '@', $uri) ?? $uri;
            $headers = ['Authorization' => 'Bearer ' . $bearer, 'Cookie' => 'session=' . $cookie, 'X-Api-Key' => $custom];

            $completions = [];
            $paths = ['/status/200', '/status/503', '/status/503', '/status/200', '/status/200', '/status/200', '/status/500'];
            foreach ($paths as $index => $path) {
                $uri = $withUserInfo($base . $path . '/' . $segment . '?token=' . $query . '#' . $fragment);
                $request = new Request($index % 3 === 2 ? 'POST' : 'GET', $uri, $headers + ['Idempotency-Key' => $identity], $index % 3 === 2 ? 'secret=' . $body : null);
                $operation = $index % 3 === 2
                    ? HttpOperation::idempotentMutation($request, 'fuzz.create', $identity, 'fuzz account', 'returns the stored response', Duration::seconds(60), retryableOutcomeCodes: $codes, credentialKey: $key)
                    : HttpOperation::read($request, 'fuzz.read', Duration::seconds(60), $codes, credentialKey: $key);
                $completions[] = $retrying->sendDetailed($operation, Duration::seconds(5));
            }

            $single = HttpOperation::single(new Request('POST', $withUserInfo($base . '/status/500/' . $segment . '?token=' . $query), $headers, 'secret=' . $body), 'fuzz.create', $key);
            $completions[] = $once->sendDetailed($single, Duration::seconds(5));

            $refused = HttpOperation::single(new Request('GET', 'http://' . $user . ':' . $password . '@' . $name . '/' . $segment . '?token=' . $query, $headers), 'fuzz.read', $key);
            $completions[] = $once->sendDetailed($refused, Duration::seconds(5));

            $cancelled = new CancellationToken();
            $cancelled->cancel();
            $completions[] = $once->sendDetailed(HttpOperation::single(new Request('GET', $base . '/status/200/' . $segment . '?token=' . $query, $headers), 'fuzz.read', $key), Duration::seconds(5), $cancelled);

            $dump = print_r($events->events(), true);
            foreach ($completions as $completion) {
                $dump .= self::dumpOf($completion);
                $dispositions[$completion->disposition->value] = true;
            }
            self::assertGreaterThan(10, count($events->events()), 'the events were produced');
            foreach ($secrets as $secret) {
                if (str_contains($dump, $secret)) {
                    self::fail('A secret reached the events or summaries in case ' . $case . ': ' . $secret);
                }
            }
        }

        self::assertContains('succeeded', array_keys($dispositions));
        self::assertContains('failed', array_keys($dispositions));
        self::assertContains('cancelled', array_keys($dispositions));
        self::assertGreaterThanOrEqual(4, count($dispositions), 'the runs covered more than success and failure: ' . implode(', ', array_keys($dispositions)));
    }
}
