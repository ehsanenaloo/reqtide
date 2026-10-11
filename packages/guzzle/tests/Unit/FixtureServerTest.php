<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Tests\Unit;

use Enaxon\Reqtide\Guzzle\Tests\Support\FixtureServer;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;

/**
 * The fixture server is test infrastructure for the Guzzle tests, so its behaviors are pinned here: what it
 * records, how it answers, and (as a characterization of the Guzzle 7.15 / cURL behavior the design depends on) how a
 * real client reacts to each failure mode.
 */
final class FixtureServerTest extends TestCase
{
    private static FixtureServer $server;

    public static function setUpBeforeClass(): void
    {
        self::$server = FixtureServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    protected function setUp(): void
    {
        self::$server->reset();
    }

    /** @return int the cURL error number carried by a Guzzle exception */
    private static function errno(GuzzleException $e): int
    {
        self::assertTrue($e instanceof RequestException || $e instanceof ConnectException);
        $context = $e->getHandlerContext();

        return is_int($context['errno'] ?? null) ? $context['errno'] : -1;
    }

    public function testAStatusIsReturnedAndTheRequestIsRecordedAtTheServer(): void
    {
        $response = self::$server->client()->get('/status/503?x=1');
        self::assertSame(503, $response->getStatusCode());

        $log = self::$server->log();
        self::assertCount(1, $log);
        self::assertSame(1, $log[0]['seq']);
        self::assertSame('GET', $log[0]['method']);
        self::assertSame('/status/503?x=1', $log[0]['target']);
        self::assertSame(0, $log[0]['bodyLength']);
    }

    public function testEveryRetryAfterLineIsSentRaw(): void
    {
        $response = self::$server->client()->get('/status/429?ra=7&ra=Fri%2C%2031%20Dec%202038%2023%3A59%3A59%20GMT&ra=%20abc');
        self::assertSame(429, $response->getStatusCode());
        self::assertSame(['7', 'Fri, 31 Dec 2038 23:59:59 GMT', 'abc'], $response->getHeader('Retry-After'), 'Guzzle trims the value; the server sent three lines');
    }

    public function testResetClearsTheLogAndControlRequestsAreNotRecorded(): void
    {
        self::$server->client()->get('/status/200');
        self::assertCount(1, self::$server->log());
        self::$server->reset();
        $cleared = self::$server->log();
        self::assertSame([], $cleared);
        self::$server->client()->get('/status/200');
        $log = self::$server->log();
        self::assertCount(1, $log);
        self::assertSame(1, $log[0]['seq'], 'the sequence restarts at one after a reset');
    }

    public function testASizedBodyIsRecordedWithLengthAndHash(): void
    {
        $response = self::$server->client()->post('/echo', ['body' => 'hello world']);
        self::assertSame(['length' => 11, 'sha1' => sha1('hello world')], json_decode((string) $response->getBody(), true));

        $entry = self::$server->log()[0];
        self::assertSame(11, $entry['bodyLength']);
        self::assertSame(sha1('hello world'), $entry['bodySha1']);
        self::assertSame(base64_encode('hello world'), $entry['bodyPreview']);
        self::assertFalse($entry['chunked']);
    }

    public function testASeekableBodyIsRewoundAndResentIdentically(): void
    {
        $request = new Request('POST', '/echo', [], Utils::streamFor('payload-7'));
        $client = self::$server->client();
        $client->send($request);
        $client->send($request);

        $log = self::$server->log();
        self::assertCount(2, $log);
        self::assertSame([9, 9], array_column($log, 'bodyLength'));
        self::assertSame($log[0]['bodySha1'], $log[1]['bodySha1']);
    }

    public function testANonSeekableBodyIsSilentlyResentEmptyWhichIsWhyReplayNeedsAFactory(): void
    {
        $pieces = ['abc', 'def'];
        $stream = new PumpStream(static function (int $length) use (&$pieces): string|false {
            return array_shift($pieces) ?? false;
        });
        $request = new Request('POST', '/echo', [], $stream);
        $client = self::$server->client();
        $client->send($request);
        $client->send($request);

        $log = self::$server->log();
        self::assertSame([true, true], array_column($log, 'chunked'));
        self::assertSame([6, 0], array_column($log, 'bodyLength'), 'the second send carries no body and raises no error');
    }

    public function testSlowAnswersAfterItsDelay(): void
    {
        $started = hrtime(true);
        $response = self::$server->client()->get('/slow?ms=300');
        self::assertSame(200, $response->getStatusCode());
        self::assertGreaterThanOrEqual(0.28, (hrtime(true) - $started) / 1e9);
        self::assertLessThan(1.5, (hrtime(true) - $started) / 1e9);
    }

    public function testTheTotalTimeoutCutsADrippingBodyAndReadTimeoutDoesNot(): void
    {
        $started = hrtime(true);
        try {
            self::$server->client(0.35)->get('/drip?chunks=10&size=1&interval_ms=100');
            self::fail('expected a timeout');
        } catch (ConnectException $e) {
            self::assertSame(28, self::errno($e));
            self::assertLessThan(0.9, (hrtime(true) - $started) / 1e9);
        }

        $started = hrtime(true);
        $response = self::$server->client(5.0)->get('/drip?chunks=10&size=1&interval_ms=100', ['read_timeout' => 0.15]);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('dddddddddd', (string) $response->getBody());
        self::assertGreaterThanOrEqual(0.9, (hrtime(true) - $started) / 1e9, 'read_timeout has no effect with the cURL handler');
    }

    public function testASilenceAfterTheHeadersEndsInATimeout(): void
    {
        $started = hrtime(true);
        try {
            self::$server->client(0.4)->get('/hang-after-headers');
            self::fail('expected a timeout');
        } catch (GuzzleException $e) {
            self::assertSame(28, self::errno($e));
            self::assertLessThan(1.5, (hrtime(true) - $started) / 1e9);
        }
    }

    public function testAServerThatNeverAnswersEndsInATimeoutButTheRequestWasDispatched(): void
    {
        try {
            self::$server->client(0.4)->post('/never-answer', ['body' => 'x']);
            self::fail('expected a timeout');
        } catch (GuzzleException $e) {
            self::assertSame(28, self::errno($e));
        }
        self::assertSame(1, self::$server->count('/never-answer'), 'the server saw the request that the client gave up on');
    }

    public function testAConnectionClosedWithoutAReplyIsAnEmptyReplyError(): void
    {
        try {
            self::$server->client()->get('/close-no-reply');
            self::fail('expected an error');
        } catch (GuzzleException $e) {
            self::assertSame(52, self::errno($e));
        }
        self::assertSame(1, self::$server->count('/close-no-reply'));
    }

    public function testAConnectionResetAfterTheRequestIsRecordedAndSurfacesAsAReceiveError(): void
    {
        if (!function_exists('socket_import_stream')) {
            self::markTestSkipped('ext-sockets is needed for a TCP reset');
        }
        try {
            self::$server->client()->get('/reset-connection');
            self::fail('a reset must not produce a response');
        } catch (GuzzleException $e) {
            self::assertSame(56, self::errno($e));
        }

        self::assertSame(1, self::$server->count('/reset-connection'));
    }

    public function testARedirectIsRecordedOnceAndFollowedOnlyWhenAskedTo(): void
    {
        $response = self::$server->client()->get('/redirect?to=/status/200&code=302');
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/status/200', $response->getHeaderLine('Location'));
        self::assertSame(1, count(self::$server->log()));

        self::$server->reset();
        $followed = self::$server->client(5.0, true)->get('/redirect?to=/status/200&code=302');
        self::assertSame(200, $followed->getStatusCode());
        self::assertSame(['/redirect?to=/status/200&code=302', '/status/200'], array_column(self::$server->log(), 'target'));
    }

    public function testExpectContinueIsAnsweredUnlessTheRouteWithholdsIt(): void
    {
        $client = self::$server->client();
        $client->post('/echo', ['body' => 'abc', 'headers' => ['Expect' => '100-continue']]);
        $client->post('/no-continue', ['body' => 'abc', 'headers' => ['Expect' => '100-continue']]);

        $log = self::$server->log();
        self::assertIsArray($log[0]['headers']);
        self::assertSame(['100-continue'], $log[0]['headers']['expect']);
        self::assertTrue($log[0]['expectContinueSent']);
        self::assertFalse($log[1]['expectContinueSent'], 'cURL then waits about a second and sends the body anyway');
        self::assertSame([3, 3], array_column($log, 'bodyLength'));
    }
}
