<?php

declare(strict_types=1);

namespace Comparison\Adapters;

use Comparison\BaseAdapter;
use Comparison\GuzzleWire;
use Comparison\Result;
use Comparison\VirtualTime as VT;
use GuzzleRetry\GuzzleRetryMiddleware;
use Spatie\GuzzleRateLimiterMiddleware\Deferrer;
use Spatie\GuzzleRateLimiterMiddleware\InMemoryStore;
use Spatie\GuzzleRateLimiterMiddleware\RateLimiterMiddleware;

/**
 * spatie/guzzle-rate-limiter-middleware with its own injectable Deferrer (the library's documented
 * seam for time and sleeping), driven over the MockHandler-backed scripted transport.
 */
final class SpatieRateAdapter extends BaseAdapter
{
    public function id(): string
    {
        return 'spatie-guzzle-rate-limiter';
    }

    public function describe(): array
    {
        return [
            'package' => 'spatie/guzzle-rate-limiter-middleware',
            'version' => self::version('spatie/guzzle-rate-limiter-middleware') . ' (on guzzlehttp/guzzle ' . self::version('guzzlehttp/guzzle') . ')',
            'role' => 'Guzzle middleware that DELAYS (sleeps) requests to stay under N per second/minute (sliding log of timestamps).',
            'not_enabled' => 'A custom persistent Store (default InMemoryStore is used; cross-process sharing needs a user-written Store), perMinute() limits (scenarios use perSecond).',
        ];
    }

    protected function scope(): string
    {
        return 'Library only delays requests to a per-second/per-minute limit: it has no retry, deadline, circuit breaker, bulkhead, events or typed rejections, so this scenario does not apply.';
    }

    private function deferrer(): Deferrer
    {
        return new class () implements Deferrer {
            public int $sleptMs = 0;

            public function getCurrentTime(): int
            {
                return (int) round(VT::now() * 1000);
            }

            public function sleep(int $milliseconds): void
            {
                $this->sleptMs += $milliseconds;
                VT::advance($milliseconds / 1000);
            }
        };
    }

    protected function s06(): Result
    {
        return Result::na('Algorithm is a sliding log of request timestamps (RateLimiter::delayUntilNextRequest), not a token bucket: there is no burst capacity or fractional refill to test.');
    }

    protected function s07(): Result
    {
        $obs = [];
        foreach (['t0+W-0.001' => 0.999, 't0+W' => 1.0, 't0+W+0.001' => 1.001] as $label => $at) {
            VT::reset();
            $wire = new GuzzleWire([[200]]);
            $d = $this->deferrer();
            $mw = RateLimiterMiddleware::perSecond(3, new InMemoryStore(), $d);
            $stack = $mw($wire->handler());
            for ($i = 0; $i < 3; $i++) {
                GuzzleWire::run($stack);
            }
            VT::jumpTo($at);
            GuzzleWire::run($stack);
            $obs[$label] = ['4th_reached_wire_at' => $wire->times()[3] ?? null];
        }
        $early = $obs['t0+W-0.001']['4th_reached_wire_at'] < 1.0 || $obs['t0+W']['4th_reached_wire_at'] < 1.0;
        $late = $obs['t0+W-0.001']['4th_reached_wire_at'] > 1.001;
        $txt = '3 requests at t0=0 (limit 3/s), 4th requested at the labelled instant; time it reached the wire: ' . json_encode($obs) . ' (the middleware sleeps instead of rejecting).';

        return !$early && !$late ? Result::pass($txt, $obs) : Result::fail($txt, 'behaviour-defect', $obs);
    }

    protected function s15(): Result
    {
        $out = [];
        foreach (['retry_outer(limiter(wire))' => true, 'limiter_outer(retry(wire))' => false] as $label => $retryOuter) {
            VT::reset();
            $wire = new GuzzleWire([[503], [503], [200]]);
            $store = new InMemoryStore();
            $limiter = RateLimiterMiddleware::perSecond(100, $store, $this->deferrer());
            $retry = GuzzleRetryMiddleware::factory(['retry_on_status' => [503], 'max_retry_attempts' => 3, 'default_retry_multiplier' => 0.0]);
            $stack = $retryOuter ? $retry($limiter($wire->handler())) : $limiter($retry($wire->handler()));
            GuzzleWire::run($stack);
            $out[$label] = ['consumed' => \count($store->get()), 'sent' => $wire->count()];
        }
        $ok = $out['retry_outer(limiter(wire))']['consumed'] === $out['retry_outer(limiter(wire))']['sent']
            && $out['limiter_outer(retry(wire))']['consumed'] === $out['limiter_outer(retry(wire))']['sent'];
        $obs = 'rate slots recorded vs wire requests (retry provided by caseyamcl/guzzle_retry_middleware as test fixture): ' . json_encode($out) . '. Cancel-before-send sub-case not run (middleware pushes the timestamp immediately before calling the next handler).';

        return $ok ? Result::pass($obs, $out) : Result::fail($obs, 'composition-dependent', $out);
    }

    protected function s17(): Result
    {
        $wire = new GuzzleWire([[200]]);
        $d = $this->deferrer();
        $stack = RateLimiterMiddleware::perSecond(1, new InMemoryStore(), $d)($wire->handler());
        VT::jumpTo(10_000.0);
        GuzzleWire::run($stack);
        VT::jumpTo(10_000.0 - 3600.0);
        $before = VT::elapsed();
        GuzzleWire::run($stack);
        $waited = VT::elapsed() - $before;
        $report = ['slept_ms_requested_by_limiter' => $d->sleptMs, 'virtual_seconds_until_second_request_reached_wire' => $waited, 'interval_seconds' => 1];
        $obs = 'limit 1/s; request at T, clock jumps back 3600s, next request: ' . json_encode($report) . ' (stored timestamps are in the "future", so the oldest-request age is negative and the computed delay is interval + 3600s).';

        return $waited > 2.0 ? Result::fail($obs, 'wall-clock-dependence', $report) : Result::pass($obs, $report);
    }

    protected function s16(): Result
    {
        return Result::na('The middleware never rejects: it sleeps until a slot is free and defines no exception types, so there is no rejection taxonomy to inspect.');
    }

    protected function s21(): Result
    {
        return Result::na('No public API that executes a user callback and returns its value; it is a Guzzle middleware.');
    }
}
