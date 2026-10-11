<?php

declare(strict_types=1);

namespace Comparison\Adapters;

use Comparison\BaseAdapter;
use Comparison\Result;
use Comparison\VirtualTime as VT;
use Symfony\Component\RateLimiter\Exception\MaxWaitDurationExceededException;
use Symfony\Component\RateLimiter\Exception\RateLimitExceededException;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * symfony/rate-limiter driven directly through RateLimiterFactory with InMemoryStorage.
 * The library reads time through unqualified microtime()/time() calls; those are redirected to the
 * virtual clock by src/shadows.php (library code is unmodified).
 */
final class SymfonyRateLimiterAdapter extends BaseAdapter
{
    public function id(): string
    {
        return 'symfony-rate-limiter';
    }

    public function describe(): array
    {
        return [
            'package' => 'symfony/rate-limiter',
            'version' => self::version('symfony/rate-limiter'),
            'role' => 'Rate limiting (token_bucket, fixed_window, sliding_window policies); admission decision via consume()/reserve().',
            'not_enabled' => 'Lock factory (not needed for single-process InMemoryStorage), CacheStorage and compound limiters, sliding_window policy (a different algorithm than the scenarios target), reserve()->wait() blocking mode (scenarios use non-blocking consume()).',
        ];
    }

    protected function scope(): string
    {
        return 'Library is a rate limiter only: it has no retry, deadline, circuit breaker, bulkhead or event facilities, so this scenario does not apply.';
    }

    private function limiter(array $cfg): LimiterInterface
    {
        static $n = 0;
        $factory = new RateLimiterFactory($cfg + ['id' => 'k' . ++$n], new InMemoryStorage());

        return $factory->create();
    }

    protected function s06(): Result
    {
        $l = $this->limiter(['policy' => 'token_bucket', 'limit' => 2, 'rate' => ['interval' => '2 seconds', 'amount' => 1]]);
        $got = [];
        $probe = function (string $label, float $t) use (&$got, $l): void {
            VT::jumpTo($t);
            $got[$label] = $l->consume(1)->isAccepted();
        };
        $probe('t0.a', 0.0);
        $probe('t0.b', 0.0);
        $probe('t0.c(3rd)', 0.0);
        $probe('t1.999', 1.999);
        $probe('t2.0', 2.0);
        $probe('t2.0(again)', 2.0);
        $probe('t5.0', 5.0);
        $probe('t5.0(again)', 5.0);
        $probe('t5.999', 5.999);
        $probe('t6.0', 6.0);
        $want = ['t0.a' => true, 't0.b' => true, 't0.c(3rd)' => false, 't1.999' => false, 't2.0' => true, 't2.0(again)' => false,
            't5.0' => true, 't5.0(again)' => false, 't5.999' => false, 't6.0' => true];
        $obs = 'decisions ' . json_encode($got);

        return $got === $want
            ? Result::pass($obs, ['decisions' => $got])
            : Result::fail($obs . ' expected ' . json_encode($want), 'behaviour-defect', ['decisions' => $got, 'expected' => $want]);
    }

    protected function s07(): Result
    {
        $out = [];
        foreach (['t0+W-0.001' => 9.999, 't0+W' => 10.0, 't0+W+0.001' => 10.001] as $label => $t) {
            $l = $this->limiter(['policy' => 'fixed_window', 'limit' => 3, 'interval' => '10 seconds']);
            VT::jumpTo(0.0);
            for ($i = 0; $i < 3; $i++) {
                $l->consume(1);
            }
            VT::jumpTo($t);
            $out[$label] = $l->consume(1)->isAccepted();
        }
        $ok = $out['t0+W-0.001'] === false && $out['t0+W+0.001'] === true;
        $obs = '4th request accepted? ' . json_encode($out) . ($out['t0+W'] ? '' : ' (not admitted at exactly t0+W; Window::add resets on a strict ">" comparison)');

        return $ok ? Result::pass($obs, $out) : Result::fail($obs, 'behaviour-defect', $out);
    }

    protected function s16(): Result
    {
        $l = $this->limiter(['policy' => 'token_bucket', 'limit' => 1, 'rate' => ['interval' => '10 seconds', 'amount' => 1]]);
        $l->consume(1);
        $rl = $l->consume(1);
        $facts = [];
        $facts['consume_rejected'] = ['class' => $rl::class, 'isAccepted' => $rl->isAccepted(), 'retryAfter' => $rl->getRetryAfter()->format('U')];
        try {
            $rl->ensureAccepted();
        } catch (\Throwable $e) {
            $facts['ensureAccepted'] = ['class' => $e::class, 'isRateLimitExceeded' => $e instanceof RateLimitExceededException, 'getRetryAfter' => $e instanceof RateLimitExceededException ? $e->getRetryAfter()->format('U') : null];
        }
        try {
            $l->reserve(1, 1.0);
        } catch (\Throwable $e) {
            $facts['reserve_maxTime'] = ['class' => $e::class, 'isMaxWait' => $e instanceof MaxWaitDurationExceededException, 'hasRateLimit' => method_exists($e, 'getRateLimit')];
        }
        $typed = ($facts['ensureAccepted']['isRateLimitExceeded'] ?? false) && ($facts['reserve_maxTime']['isMaxWait'] ?? false);
        $retryAt = ($facts['ensureAccepted']['getRetryAfter'] ?? null) !== null;
        $obs = 'Only cause produced: rate limit. ' . json_encode($facts) . '. Library produces no circuit-open/bulkhead/deadline/retries-exhausted causes.';

        return $typed && $retryAt ? Result::pass($obs, $facts) : Result::fail($obs, 'missing-capability', $facts);
    }

    protected function s17(): Result
    {
        $report = [];
        $configs = [
            'token_bucket' => [['policy' => 'token_bucket', 'limit' => 1, 'rate' => ['interval' => '10 seconds', 'amount' => 1]], 10.0],
            'fixed_window' => [['policy' => 'fixed_window', 'limit' => 1, 'interval' => '10 seconds'], 10.0],
        ];
        $fail = false;
        foreach ($configs as $name => [$cfg, $interval]) {
            $l = $this->limiter($cfg);
            $T = 10_000.0;
            VT::jumpTo($T);
            $l->consume(1); // exhausts
            VT::jumpTo($T - 3600.0);
            $overAdmit = $l->consume(1)->isAccepted(); // immediately after the jump: must not admit
            $elapsed = 0.0;
            $recovered = null;
            while ($elapsed <= 4000.0) {
                $elapsed += 1.0;
                VT::jumpTo($T - 3600.0 + $elapsed);
                if ($l->consume(1)->isAccepted()) {
                    $recovered = $elapsed;
                    break;
                }
            }
            $report[$name] = ['over_admitted_at_jump' => $overAdmit, 'recovered_after_elapsed_seconds' => $recovered, 'interval' => $interval];
            if ($overAdmit || $recovered === null || $recovered > 2 * $interval) {
                $fail = true;
            }
        }
        $obs = 'Exhaust at T, clock jumps back 3600s, then 1s steps: ' . json_encode($report);

        return $fail ? Result::fail($obs, 'wall-clock-dependence', $report) : Result::pass($obs, $report);
    }

    protected function s13(): Result
    {
        return Result::na('Library has no permits/concurrency capacity and no cancellation signal; a Reservation cannot be cancelled or refunded (LimiterInterface exposes only reserve(), consume(), reset()). Rate-quota accounting is covered by S15.');
    }
}
