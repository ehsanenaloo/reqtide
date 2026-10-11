<?php

declare(strict_types=1);

namespace Comparison\Adapters;

use Comparison\BaseAdapter;
use Comparison\GuzzleWire;
use Comparison\Result;
use Comparison\VirtualTime as VT;
use GuzzleRetry\GuzzleRetryMiddleware;

/**
 * caseyamcl/guzzle_retry_middleware over a MockHandler-backed scripted transport.
 * The middleware reads whole-second time() inside namespace GuzzleRetry, redirected to the virtual clock.
 * Middleware options are passed through the middleware factory defaults.
 */
final class CaseyamclRetryAdapter extends BaseAdapter
{
    public function id(): string
    {
        return 'caseyamcl-guzzle-retry';
    }

    public function describe(): array
    {
        return [
            'package' => 'caseyamcl/guzzle_retry_middleware',
            'version' => self::version('caseyamcl/guzzle_retry_middleware') . ' (on guzzlehttp/guzzle ' . self::version('guzzlehttp/guzzle') . ')',
            'role' => 'Guzzle middleware: retry on 429/503 with Retry-After support, optional give_up_after_secs.',
            'not_enabled' => 'retry_on_methods (default "*" = all methods; restricting it is optional config not enabled in S05 default run), retry_on_timeout, max_allowable_timeout_secs, should_retry_callback, expose_retry_header, on_retry_callback (these are optional; only the options each scenario names were set).',
        ];
    }

    protected function scope(): string
    {
        return 'Library is a retry middleware only: it has no rate limiter, circuit breaker or bulkhead, so this scenario does not apply.';
    }

    /** @param array<string,mixed> $opts */
    private function stack(GuzzleWire $wire, array $opts): callable
    {
        return (GuzzleRetryMiddleware::factory($opts))($wire->handler());
    }

    private const CONST4 = 4.0;

    protected function s01(): Result
    {
        $wire = new GuzzleWire([[503]]);
        $opts = ['retry_on_status' => [503], 'max_retry_attempts' => 20, 'give_up_after_secs' => 10, 'default_retry_multiplier' => static fn (int $n, $r): float => self::CONST4];
        $out = GuzzleWire::run($this->stack($wire, $opts));
        $times = $wire->times();
        $late = array_values(array_filter($times, static fn (float $t): bool => $t >= 10.0));
        $obs = sprintf('give_up_after_secs=10, constant 4s delay: attempt start times %s, returned at %s with %s; %d attempt(s) started at or after D (the last wait appears to be clipped from 4s to the remaining 2s by determineDelayTimeout(), so the final attempt lands exactly on the deadline; borderline: the library works in whole seconds and checks request_timestamp < first + give_up_after_secs only for attempts after that one).', json_encode($times), self::s(VT::elapsed()), $out, \count($late));

        return $late === [] && VT::elapsed() <= 10.0 ? Result::pass($obs, ['times' => $times]) : Result::fail($obs, 'behaviour-defect', ['times' => $times, 'returned_at' => VT::elapsed()]);
    }

    protected function s02(): Result
    {
        $wire = new GuzzleWire([[503, ['Retry-After' => '30']], [200]]);
        $opts = ['retry_on_status' => [503], 'max_retry_attempts' => 20, 'give_up_after_secs' => 10, 'default_retry_multiplier' => static fn (int $n, $r): float => 2.0];
        $out = GuzzleWire::run($this->stack($wire, $opts));
        $times = $wire->times();
        $early = isset($times[1]) && $times[1] < 30.0;
        $obs = sprintf('give_up_after_secs=10, Retry-After 30: requests=%d at %s, returned at %s with %s.', \count($times), json_encode($times), self::s(VT::elapsed()), $out);

        return \count($times) === 1 && VT::elapsed() <= 10.0 && !$early ? Result::pass($obs, ['times' => $times]) : Result::fail($obs, 'behaviour-defect', ['times' => $times, 'returned_at' => VT::elapsed()]);
    }

    protected function s03(): Result
    {
        $wire = new GuzzleWire([[503, ['Retry-After' => '6']], [200]]);
        GuzzleWire::run($this->stack($wire, ['retry_on_status' => [503], 'default_retry_multiplier' => static fn (int $n, $r): float => 2.0]));
        $t = $wire->times();
        $gap = ($t[1] ?? NAN) - ($t[0] ?? NAN);

        VT::reset();
        $wire2 = new GuzzleWire([[503, ['Retry-After' => '1']], [200]]);
        GuzzleWire::run($this->stack($wire2, ['retry_on_status' => [503], 'default_retry_multiplier' => static fn (int $n, $r): float => 4.0]));
        $t2 = $wire2->times();
        $gap2 = ($t2[1] ?? NAN) - ($t2[0] ?? NAN);

        $obs = sprintf('primary: Retry-After 6 vs backoff 2 -> gap %s. Informational: Retry-After 1 vs backoff 4 -> gap %s (the header replaces the computed delay in determineDelayTimeout()).', self::s($gap), self::s($gap2));

        return $gap >= 6.0 ? Result::pass($obs, ['gap_primary' => $gap, 'gap_info' => $gap2]) : Result::fail($obs, 'behaviour-defect', ['gap_primary' => $gap, 'gap_info' => $gap2]);
    }

    protected function s04(): Result
    {
        $wire = new GuzzleWire([[503]]);
        $stack = $this->stack($wire, ['retry_on_status' => [503], 'max_retry_attempts' => 3, 'default_retry_multiplier' => 0.0]);
        for ($i = 0; $i < 1000; $i++) {
            GuzzleWire::run($stack);
        }
        $total = $wire->count();
        $extra = $total - 1000;
        $obs = sprintf('1000 failing ops, max_retry_attempts=3, zero delay: %d wire requests, extra %d (%.0f%% of first attempts). No cross-operation retry budget exists; limits are per request (max_retry_attempts, give_up_after_secs).', $total, $extra, $extra / 10);

        return $extra <= 200 ? Result::pass($obs, ['total' => $total]) : Result::fail($obs, 'missing-capability', ['total' => $total, 'extra' => $extra]);
    }

    protected function s05(): Result
    {
        $counts = [];
        $base = ['default_retry_multiplier' => 0.0]; // only the delay is changed; retry_on_status / retry_on_methods / max attempts are library defaults
        foreach (['a_post_500' => [500], 'b_post_transport_error' => ['err'], 'c_post_503' => [503], 'd_post_429' => [429]] as $label => $step) {
            $wire = new GuzzleWire([$step]);
            GuzzleWire::run($this->stack($wire, $base), 'POST');
            $counts[$label] = $wire->count();
        }
        $wire = new GuzzleWire([[500]]);
        GuzzleWire::run($this->stack($wire, $base + ['retry_on_status' => [500, 503, 429]]), 'POST');
        $counts['info_non_default_post_500_with_retry_on_status_incl_500'] = $wire->count();
        $obs = 'requests sent with library-default retry_on_status (429,503), retry_on_methods ("*": every method) and max_retry_attempts (10), delay zeroed: ' . json_encode($counts) . '. POST is retried on 429/503 because retry_on_methods defaults to all methods; a POST 500 is not retried only because 500 is not in the default status list.';

        return $counts['a_post_500'] === 1 && $counts['b_post_transport_error'] === 1 ? Result::pass($obs, $counts) : Result::fail($obs, 'behaviour-defect', $counts);
    }

    protected function s16(): Result
    {
        $wire = new GuzzleWire([[503]]);
        $out = GuzzleWire::run($this->stack($wire, ['retry_on_status' => [503], 'max_retry_attempts' => 2, 'default_retry_multiplier' => 0.0, 'expose_retry_header' => true]));
        $client = new \GuzzleHttp\Client(['handler' => $this->stack(new GuzzleWire([[503]]), ['retry_on_status' => [503], 'max_retry_attempts' => 2, 'default_retry_multiplier' => 0.0, 'expose_retry_header' => true]), 'http_errors' => false]);
        $header = $client->request('GET', 'https://upstream.test/x')->getHeaderLine('X-Retry-Counter');
        $facts = ['outcome_after_exhaustion' => $out, 'X-Retry-Counter (opt-in expose_retry_header)' => $header];
        $obs = 'After exhaustion or a give_up_after_secs stop the final upstream response/exception is passed through unchanged (' . json_encode($facts) . '); the middleware defines no exception types of its own and the only machine-readable trace is an opt-in response header. No rate-limit/circuit/bulkhead causes are produced by this package.';

        return Result::fail($obs, 'missing-capability', $facts);
    }

    protected function s18(): Result
    {
        return Result::na('The only hook is on_retry_callback, which receives the request/options by reference and may mutate them, so it is an interceptor rather than an observer; no event/observer facility to test.');
    }

    protected function s17(): Result
    {
        return Result::na('Keeps no cross-operation time-based state (no limiter or breaker); per-operation deadline use of time() is not part of this scenario.');
    }

    protected function s21(): Result
    {
        return Result::na('No public API that executes a user callback and returns its value; it is a Guzzle middleware over promises.');
    }
}
