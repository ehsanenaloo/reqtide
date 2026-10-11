<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Tests\Unit;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Execution\BoundedMetadata;
use Enaxon\Reqtide\Execution\OperationMetadata;
use Enaxon\Reqtide\Execution\OperationSafety;
use Enaxon\Reqtide\Guzzle\HttpOutcomeClassifier;
use Enaxon\Reqtide\Guzzle\Tests\Support\CustomClassifier;
use Enaxon\Reqtide\Outcome\Acceptability;
use Enaxon\Reqtide\Outcome\CircuitVerdict;
use Enaxon\Reqtide\Outcome\FallbackEligibility;
use Enaxon\Reqtide\Outcome\Outcome;
use Enaxon\Reqtide\Outcome\OutcomeClassification;
use Enaxon\Reqtide\Outcome\RetryEligibility;
use Enaxon\Reqtide\Testing\VirtualClock;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\TooManyRedirectsException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/** The classification rules for responses and transfer exceptions. */
final class HttpOutcomeClassifierTest extends TestCase
{
    private const WALL = '2026-10-09T12:00:00.000000Z';

    /**
     * @template T
     * @param Outcome<T> $outcome
     */
    private function classify(Outcome $outcome, ?HttpOutcomeClassifier $classifier = null): OutcomeClassification
    {
        $clock = (new VirtualClock())->startingAt(new \DateTimeImmutable(self::WALL));

        return ($classifier ?? HttpOutcomeClassifier::starter($clock))->classify($outcome, new OperationMetadata(OperationSafety::unsafe(), BoundedMetadata::empty()));
    }

    /**
     * @param array<string, string|list<string>> $headers
     * @return Outcome<ResponseInterface>
     */
    private function response(int $status, array $headers = []): Outcome
    {
        return Outcome::fromValue(new Response($status, $headers), BoundedMetadata::empty());
    }

    /** @return Outcome<never> */
    private function transport(int $errno): Outcome
    {
        return Outcome::fromException(new ConnectException('cURL error ' . $errno, new Request('GET', 'https://api.example.com/'), null, ['errno' => $errno]), BoundedMetadata::empty());
    }

    /**
     * @return iterable<string, array{int, RetryEligibility, CircuitVerdict, Acceptability}>
     */
    public static function statuses(): iterable
    {
        yield '200' => [200, RetryEligibility::Ineligible, CircuitVerdict::Success, Acceptability::Acceptable];
        yield '204' => [204, RetryEligibility::Ineligible, CircuitVerdict::Success, Acceptability::Acceptable];
        yield '302' => [302, RetryEligibility::Ineligible, CircuitVerdict::Success, Acceptability::Acceptable];
        yield '400' => [400, RetryEligibility::Ineligible, CircuitVerdict::Success, Acceptability::Unacceptable];
        yield '401' => [401, RetryEligibility::Ineligible, CircuitVerdict::Success, Acceptability::Unacceptable];
        yield '404' => [404, RetryEligibility::Ineligible, CircuitVerdict::Success, Acceptability::Unacceptable];
        yield '408' => [408, RetryEligibility::Eligible, CircuitVerdict::Failure, Acceptability::Unacceptable];
        yield '429' => [429, RetryEligibility::Eligible, CircuitVerdict::Neutral, Acceptability::Unacceptable];
        yield '500 is not retried by default' => [500, RetryEligibility::Ineligible, CircuitVerdict::Failure, Acceptability::Unacceptable];
        yield '501' => [501, RetryEligibility::Ineligible, CircuitVerdict::Success, Acceptability::Unacceptable];
        yield '502' => [502, RetryEligibility::Eligible, CircuitVerdict::Failure, Acceptability::Unacceptable];
        yield '503' => [503, RetryEligibility::Eligible, CircuitVerdict::Failure, Acceptability::Unacceptable];
        yield '504' => [504, RetryEligibility::Eligible, CircuitVerdict::Failure, Acceptability::Unacceptable];
        yield '505' => [505, RetryEligibility::Ineligible, CircuitVerdict::Success, Acceptability::Unacceptable];
    }

    #[DataProvider('statuses')]
    public function testTheStarterTableForResponses(int $status, RetryEligibility $retry, CircuitVerdict $circuit, Acceptability $acceptability): void
    {
        $result = $this->classify($this->response($status));
        self::assertSame($retry, $result->retry);
        self::assertSame($circuit, $result->circuit);
        self::assertSame($acceptability, $result->acceptability);
        self::assertSame(FallbackEligibility::Ineligible, $result->fallback);
        self::assertSame('http_status_' . $status, $result->reasonCode);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function errorStatuses(): iterable
    {
        foreach ([400, 401, 404, 408, 429, 500, 501, 502, 503, 504, 505] as $status) {
            yield (string) $status => [$status];
        }
    }

    /**
     * `http_errors` on or off must not change the meaning. The exception carries the same response.
     */
    #[DataProvider('errorStatuses')]
    public function testAnExceptionCarryingTheResponseIsClassifiedLikeTheResponse(int $status): void
    {
        $request = new Request('GET', 'https://api.example.com/');
        $response = new Response($status, ['Retry-After' => '7']);
        $exception = $status < 500 ? new ClientException('m', $request, $response) : new ServerException('m', $request, $response);

        self::assertEquals(
            $this->classify(Outcome::fromValue($response, BoundedMetadata::empty())),
            $this->classify(Outcome::fromException($exception, BoundedMetadata::empty())),
        );
    }

    /**
     * @return iterable<string, array{int, RetryEligibility, CircuitVerdict}>
     */
    public static function transportErrors(): iterable
    {
        foreach ([6 => 'resolve', 7 => 'refused', 28 => 'timeout', 52 => 'empty reply', 55 => 'send', 56 => 'receive'] as $errno => $name) {
            yield $name => [$errno, RetryEligibility::Eligible, CircuitVerdict::Failure];
        }
        foreach ([35 => 'tls connect', 60 => 'certificate verification', 58 => 'client certificate', 77 => 'ca file', 90 => 'pinned key'] as $errno => $name) {
            yield $name => [$errno, RetryEligibility::Ineligible, CircuitVerdict::Failure];
        }
        foreach ([3 => 'malformed url', 1 => 'unsupported protocol', 43 => 'bad argument'] as $errno => $name) {
            yield $name => [$errno, RetryEligibility::Ineligible, CircuitVerdict::Neutral];
        }
        yield 'unlisted errno' => [99, RetryEligibility::Ineligible, CircuitVerdict::Failure];
    }

    #[DataProvider('transportErrors')]
    public function testTheStarterTableForTransportErrors(int $errno, RetryEligibility $retry, CircuitVerdict $circuit): void
    {
        $result = $this->classify($this->transport($errno));
        self::assertSame($retry, $result->retry);
        self::assertSame($circuit, $result->circuit);
        self::assertSame(Acceptability::Unacceptable, $result->acceptability);
        self::assertSame('http_transport_errno_' . $errno, $result->reasonCode);
        self::assertNull($result->retryAfterLowerBound);
    }

    public function testARequestExceptionWithoutAResponseUsesItsErrnoToo(): void
    {
        $exception = new RequestException('tls', new Request('GET', 'https://api.example.com/'), null, null, ['errno' => 60]);
        $result = $this->classify(Outcome::fromException($exception, BoundedMetadata::empty()));
        self::assertSame(RetryEligibility::Ineligible, $result->retry);
        self::assertSame('http_transport_errno_60', $result->reasonCode);
    }

    public function testOtherExceptionsAreNeverRetried(): void
    {
        $request = new Request('GET', 'https://api.example.com/');
        foreach ([
            [new TooManyRedirectsException('loop', $request), 'http_too_many_redirects'],
            [new ConnectException('no errno', $request), 'http_exception'],
            [new \RuntimeException('boom'), 'http_exception'],
        ] as [$exception, $code]) {
            $result = $this->classify(Outcome::fromException($exception, BoundedMetadata::empty()));
            self::assertSame(RetryEligibility::Ineligible, $result->retry, $code);
            self::assertSame(CircuitVerdict::Failure, $result->circuit, $code);
            self::assertSame($code, $result->reasonCode);
        }
    }

    public function testRetryAfterIsReadStrictlyForTheConfiguredStatusesOnly(): void
    {
        self::assertSame(7_000_000_000, $this->classify($this->response(429, ['Retry-After' => '7']))->retryAfterLowerBound?->nanoseconds);
        self::assertSame(
            30_000_000_000,
            $this->classify($this->response(503, ['Retry-After' => 'Fri, 09 Oct 2026 12:00:30 GMT']))->retryAfterLowerBound?->nanoseconds,
            'a date is converted once against the clock of the classifier',
        );
        self::assertSame(120_000_000_000, $this->classify($this->response(503, ['Retry-After' => ['7', '120']]))->retryAfterLowerBound?->nanoseconds, 'the greatest valid line wins');

        self::assertNull($this->classify($this->response(503, ['Retry-After' => 'soon']))->retryAfterLowerBound, 'malformed creates no lower bound');
        self::assertNull($this->classify($this->response(503, ['Retry-After' => '9007199255']))->retryAfterLowerBound, 'out of range is not truncated');
        self::assertNull($this->classify($this->response(503))->retryAfterLowerBound);
        self::assertNull($this->classify($this->response(200, ['Retry-After' => '7']))->retryAfterLowerBound, 'a status outside the list is not read');
        self::assertNull($this->classify($this->response(502, ['Retry-After' => '7']))->retryAfterLowerBound);
    }

    public function testTablesAreExplicitAndReplaceable(): void
    {
        $clock = (new VirtualClock())->startingAt(new \DateTimeImmutable(self::WALL));
        $custom = CustomClassifier::custom($clock);

        $five = $this->classify($this->response(500), $custom);
        self::assertSame(RetryEligibility::Eligible, $five->retry);
        self::assertSame(FallbackEligibility::Eligible, $five->fallback);
        self::assertSame(RetryEligibility::Ineligible, $this->classify($this->response(503), $custom)->retry, 'replaced, not extended');

        $empty = HttpOutcomeClassifier::empty($clock);
        foreach ([408, 429, 500, 502, 503, 504] as $status) {
            $result = $this->classify($this->response($status), $empty);
            self::assertSame(RetryEligibility::Ineligible, $result->retry, (string) $status);
            self::assertSame(CircuitVerdict::Success, $result->circuit);
            self::assertNull($result->retryAfterLowerBound);
        }
        self::assertSame(RetryEligibility::Ineligible, $this->classify($this->transport(7), $empty)->retry);
        self::assertSame([], $empty->retryableOutcomeCodes());
    }

    public function testNoClassificationIsRetryEligibleUnlessItsCodeIsListed(): void
    {
        $clock = (new VirtualClock())->startingAt(new \DateTimeImmutable(self::WALL));
        foreach ([HttpOutcomeClassifier::starter($clock), CustomClassifier::custom($clock), HttpOutcomeClassifier::empty($clock)] as $classifier) {
            $codes = $classifier->retryableOutcomeCodes();
            for ($status = 100; $status <= 599; ++$status) {
                $result = $this->classify($this->response($status), $classifier);
                if ($result->retry === RetryEligibility::Eligible) {
                    self::assertContains($result->reasonCode, $codes);
                }
            }
            for ($errno = 1; $errno <= 120; ++$errno) {
                $result = $this->classify($this->transport($errno), $classifier);
                if ($result->retry === RetryEligibility::Eligible) {
                    self::assertContains($result->reasonCode, $codes);
                }
            }
        }
    }

    public function testStarterRetryableCodes(): void
    {
        self::assertSame(
            ['http_status_408', 'http_status_429', 'http_status_502', 'http_status_503', 'http_status_504', 'http_transport_errno_6', 'http_transport_errno_7', 'http_transport_errno_28', 'http_transport_errno_52', 'http_transport_errno_55', 'http_transport_errno_56'],
            HttpOutcomeClassifier::starter()->retryableOutcomeCodes(),
        );
    }

    public function testTlsAndMalformedRequestErrorsCanNeverBeMadeRetryable(): void
    {
        foreach ([60, 35, 3, 1, 43] as $errno) {
            try {
                HttpOutcomeClassifier::starter()->withRetryableTransportErrnos($errno);
                self::fail('expected an exception for ' . $errno);
            } catch (InvalidConfigurationException) {
                self::assertSame(RetryEligibility::Ineligible, $this->classify($this->transport($errno))->retry);
            }
        }
    }

    public function testInvalidConfigurationIsRejected(): void
    {
        foreach ([fn() => HttpOutcomeClassifier::starter()->withRetryableStatuses(99), fn() => HttpOutcomeClassifier::starter()->withFallbackStatuses(600), fn() => HttpOutcomeClassifier::starter()->withRetryableTransportErrnos(0), fn() => HttpOutcomeClassifier::starter()->withRetryableTransportErrnos(201)] as $make) {
            try {
                $make();
                self::fail('expected an exception');
            } catch (InvalidConfigurationException) {
            }
        }
        $this->expectException(InvalidConfigurationException::class);
        HttpOutcomeClassifier::starter()->withRetryAfterStatuses(0);
    }
}
