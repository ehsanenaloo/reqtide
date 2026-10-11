<?php

declare(strict_types=1);

namespace Comparison\Adapters;

use Comparison\BaseAdapter;
use Comparison\Result;
use Comparison\VirtualTime as VT;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Component\HttpClient\ThrottlingHttpClient;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * symfony/http-client RetryableHttpClient (+ ThrottlingHttpClient for S15) over MockHttpClient.
 *
 * MockHttpClient does not provide a 'pause_handler' (RetryableHttpClient calls AsyncContext::pause(),
 * which silently does nothing without one). The harness therefore puts a pause_handler into every
 * MockResponse's info; it advances the virtual clock by the requested duration and stamps that
 * response's "sent" time, which models a real transport (curl/native) that starts a paused transfer
 * only after the pause. This is a harness-supplied model of transport behaviour, not library code.
 */
final class SymfonyHttpClientAdapter extends BaseAdapter
{
    /** @var list<array{created:float,sent:?float,method:string,status:int|string}> */
    private array $wire = [];

    public function id(): string
    {
        return 'symfony-http-client';
    }

    public function describe(): array
    {
        return [
            'package' => 'symfony/http-client',
            'version' => self::version('symfony/http-client'),
            'role' => 'HTTP client with RetryableHttpClient (retry/backoff/jitter/Retry-After) and ThrottlingHttpClient decorators.',
            'not_enabled' => 'max_duration / timeout request options (per-request, not enforced by MockHttpClient; see S01), custom RetryStrategyInterface implementations (e.g. one that caps delays), real transports (curl/native), RetryableHttpClient logger (only enabled in S18), multi-base_uri failover.',
        ];
    }

    protected function scope(): string
    {
        return 'Library is an HTTP client with retry and throttling decorators; it has no circuit breaker, bulkhead, or half-open probing, so this scenario does not apply.';
    }

    /**
     * @param list<array{0:int|string,1?:list<string>}> $script ordered responses; last one repeats. status 'err' = transport failure.
     */
    private function mock(array $script): MockHttpClient
    {
        $this->wire = [];
        $i = 0;

        return new MockHttpClient(function (string $method, string $url, array $options) use (&$i, $script): MockResponse {
            $step = $script[min($i, \count($script) - 1)];
            $i++;
            $idx = \count($this->wire);
            $this->wire[$idx] = ['created' => VT::elapsed(), 'sent' => null, 'method' => $method, 'status' => $step[0]];
            $pause = function (float $d) use ($idx): void {
                VT::advance($d);
                $this->wire[$idx]['sent'] = VT::elapsed();
            };
            $info = ['pause_handler' => $pause, 'primary_ip' => '192.0.2.1'];
            if ($step[0] === 'err') {
                return new MockResponse('', $info + ['error' => 'connection reset after write']);
            }

            return new MockResponse('', $info + ['http_code' => $step[0], 'response_headers' => $step[1] ?? []]);
        });
    }

    private function retrying(HttpClientInterface $inner, int $delayMs, int $max, float $mult = 1.0, ?AbstractLogger $logger = null): RetryableHttpClient
    {
        return new RetryableHttpClient($inner, new GenericRetryStrategy(delayMs: $delayMs, multiplier: $mult, maxDelayMs: 0, jitter: 0.0), $max, $logger);
    }

    /** @return float[] send time of each wire request */
    private function sendTimes(): array
    {
        return array_map(static fn (array $w): float => $w['sent'] ?? $w['created'], $this->wire);
    }

    private function drive(HttpClientInterface $c, string $method = 'GET'): string
    {
        try {
            return (string) $c->request($method, 'https://upstream.test/x')->getStatusCode();
        } catch (\Throwable $e) {
            return $e::class;
        }
    }

    protected function s01(): Result
    {
        $c = $this->retrying($this->mock([[503]]), 4000, 10);
        $outcome = $this->drive($c);
        $times = $this->sendTimes();
        $after = array_values(array_filter($times, static fn (float $t): bool => $t >= 10.0));
        $obs = sprintf(
            'attempt start times %s; returned at %s with %s. %d attempts started at or after D=10s. No total-deadline option exists: only max_retries (count) and per-request max_duration/timeout; each retry issues a new request, so a per-request limit does not span attempts (code read: RetryableHttpClient::request -> replaceRequest(same options); not executed because MockHttpClient does not enforce max_duration).',
            json_encode($times),
            self::s(VT::elapsed()),
            $outcome,
            \count($after),
        );

        return $after === [] && VT::elapsed() <= 10.0
            ? Result::pass($obs, ['times' => $times])
            : Result::fail($obs, 'missing-capability', ['times' => $times, 'returned_at' => VT::elapsed()]);
    }

    protected function s02(): Result
    {
        $c = $this->retrying($this->mock([[503, ['Retry-After: 30']], [200]]), 4000, 10);
        $outcome = $this->drive($c);
        $times = $this->sendTimes();
        $early = isset($times[1]) && $times[1] < 30.0;
        $obs = sprintf('requests=%d, send times %s, returned at %s (%s). Retry-After honoured exactly (never early: %s) but waited past D=10s because no deadline can be set.', \count($times), json_encode($times), self::s(VT::elapsed()), $outcome, $early ? 'NO' : 'yes');

        return \count($times) === 1 && VT::elapsed() <= 10.0 && !$early
            ? Result::pass($obs, ['times' => $times])
            : Result::fail($obs, 'missing-capability', ['times' => $times, 'returned_at' => VT::elapsed(), 'early' => $early]);
    }

    protected function s03(): Result
    {
        $c = $this->retrying($this->mock([[503, ['Retry-After: 6']], [200]]), 2000, 5);
        $this->drive($c);
        $t = $this->sendTimes();
        $gap = ($t[1] ?? NAN) - ($t[0] ?? NAN);

        VT::reset();
        $c2 = $this->retrying($this->mock([[503, ['Retry-After: 1']], [200]]), 4000, 5);
        $this->drive($c2);
        $t2 = $this->sendTimes();
        $gap2 = ($t2[1] ?? NAN) - ($t2[0] ?? NAN);

        $obs = sprintf('primary: Retry-After 6 vs backoff 2 -> gap %s. Informational: Retry-After 1 vs backoff 4 -> gap %s (header %s the configured backoff; RetryableHttpClient uses getDelayFromHeader() ?? strategy->getDelay()).', self::s($gap), self::s($gap2), $gap2 < 4.0 ? 'REPLACES' : 'does not shorten');

        return $gap >= 6.0 ? Result::pass($obs, ['gap_primary' => $gap, 'gap_info' => $gap2]) : Result::fail($obs, 'behaviour-defect', ['gap_primary' => $gap, 'gap_info' => $gap2]);
    }

    protected function s04(): Result
    {
        $c = $this->retrying($this->mock([[503]]), 0, 3);
        for ($i = 0; $i < 1000; $i++) {
            $this->drive($c);
        }
        $total = \count($this->wire);
        $extra = $total - 1000;
        $obs = sprintf('1000 failing ops, max_retries=3: %d wire requests, extra %d (%.0f%% of first attempts). No retry budget / shared limit across operations exists in RetryableHttpClient or GenericRetryStrategy.', $total, $extra, $extra / 10);

        return $extra <= 200 ? Result::pass($obs, ['total' => $total]) : Result::fail($obs, 'missing-capability', ['total' => $total, 'extra' => $extra]);
    }

    protected function s05(): Result
    {
        $counts = [];
        foreach (['a_post_500' => [500], 'b_post_transport_error' => ['err'], 'c_post_503' => [503], 'd_post_429' => [429]] as $label => $step) {
            $c = $this->retrying($this->mock([$step]), 0, 3);
            $this->drive($c, 'POST');
            $counts[$label] = \count($this->wire);
        }
        $get = $this->retrying($this->mock([[500]]), 0, 3);
        $this->drive($get, 'GET');
        $counts['control_get_500'] = \count($this->wire);
        $obs = 'requests sent with default GenericRetryStrategy statuses (retry delays zeroed): ' . json_encode($counts) . ' (default list retries 500/504/507/510 and transport errors only for idempotent methods; 423/425/429/502/503 for any method).';

        return $counts['a_post_500'] === 1 && $counts['b_post_transport_error'] === 1
            ? Result::pass($obs, $counts)
            : Result::fail($obs, 'behaviour-defect', $counts);
    }

    protected function s15(): Result
    {
        $out = [];
        foreach (['retry_outer(throttle(wire))' => true, 'throttle_outer(retry(wire))' => false] as $label => $retryOuter) {
            $limiter = (new RateLimiterFactory(['id' => 'q' . (int) $retryOuter, 'policy' => 'token_bucket', 'limit' => 100, 'rate' => ['interval' => '1 second', 'amount' => 100]], new InMemoryStorage()))->create();
            $mock = $this->mock([[503], [503], [200]]);
            $retryStrategy = new GenericRetryStrategy(delayMs: 0, multiplier: 1.0, maxDelayMs: 0, jitter: 0.0);
            $client = $retryOuter
                ? new RetryableHttpClient(new ThrottlingHttpClient($mock, $limiter), $retryStrategy, 3)
                : new ThrottlingHttpClient(new RetryableHttpClient($mock, $retryStrategy, 3), $limiter);
            $this->drive($client);
            $consumed = 100 - $limiter->consume(0)->getRemainingTokens();
            $out[$label] = ['consumed' => $consumed, 'sent' => \count($this->wire)];
        }
        $ok = $out['retry_outer(throttle(wire))']['consumed'] === $out['retry_outer(throttle(wire))']['sent']
            && $out['throttle_outer(retry(wire))']['consumed'] === $out['throttle_outer(retry(wire))']['sent'];
        $obs = 'quota consumed vs wire requests: ' . json_encode($out) . '. ThrottlingHttpClient reserves one token per request() call it sees, so retries made by an inner RetryableHttpClient are invisible to it. Cancel-before-send sub-case not run: ThrottlingHttpClient calls inner request() before reserving and MockHttpClient has no unsent state to cancel.';

        return $ok ? Result::pass($obs, $out) : Result::fail($obs, 'composition-dependent', $out);
    }

    protected function s16(): Result
    {
        $c = $this->retrying($this->mock([[503]]), 0, 2);
        $r = $c->request('GET', 'https://upstream.test/x');
        $status = $r->getStatusCode();
        $class = null;
        try {
            $r->getContent();
        } catch (\Throwable $e) {
            $class = $e::class;
        }
        $retryCount = $r->getInfo('retry_count');
        $facts = ['status_after_exhaustion' => $status, 'exception_on_getContent' => $class, 'info_retry_count' => $retryCount];
        $obs = 'After retries are exhausted the final 503 is returned like any non-retried 5xx (' . json_encode($facts) . '). The only trace is the info key retry_count (an integer; consumers must compare it with their own max_retries to infer exhaustion). No distinct retries-exhausted type; no rate-limit/circuit/bulkhead/deadline causes are produced by this package.';

        return Result::fail($obs, 'missing-capability', $facts);
    }

    protected function s18(): Result
    {
        $logger = new class () extends AbstractLogger {
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('observer failed');
            }
        };
        $c = $this->retrying($this->mock([[503], [200]]), 0, 3, 1.0, $logger);
        $outcome = $this->drive($c);
        $control = $this->drive($this->retrying($this->mock([[503], [200]]), 0, 3, 1.0, null));
        $obs = 'retry once then 200 with a PSR-3 logger that throws on every call: operation outcome = ' . $outcome . ' (control without logger: ' . $control . '; expected 200). RetryableHttpClient calls $logger->info() inside the async chunk handler without a try/catch.';

        return $outcome === '200' ? Result::pass($obs) : Result::fail($obs, 'behaviour-defect', ['outcome' => $outcome, 'control' => $control]);
    }

    protected function s13(): Result
    {
        return Result::na('Library has no concurrency permits; throttling quota is covered by S15.');
    }

    protected function s14(): Result
    {
        return Result::na('Library has no concurrency permits to release.');
    }

    protected function s06(): Result
    {
        return Result::na('Rate algorithms live in symfony/rate-limiter (tested directly in that adapter); ThrottlingHttpClient only calls reserve().');
    }

    protected function s07(): Result
    {
        return Result::na('Rate algorithms live in symfony/rate-limiter (tested directly in that adapter).');
    }

    protected function s17(): Result
    {
        return Result::na('The only wall-clock use in the retry path is parsing an HTTP-date Retry-After; clock regression of limiter state is covered in the symfony/rate-limiter adapter. Not tested separately.');
    }

    protected function s21(): Result
    {
        return Result::na('No public API that executes a user callback; the client returns ResponseInterface.');
    }
}
