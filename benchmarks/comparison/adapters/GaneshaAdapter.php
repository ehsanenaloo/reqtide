<?php

declare(strict_types=1);

namespace Comparison\Adapters;

use Ackintosh\Ganesha;
use Ackintosh\Ganesha\Builder;
use Ackintosh\Ganesha\Exception\RejectedException;
use Ackintosh\Ganesha\GuzzleMiddleware;
use Ackintosh\Ganesha\Storage\Adapter\Apcu;
use Comparison\ArrayApcuStore;
use Comparison\BaseAdapter;
use Comparison\GuzzleWire;
use Comparison\Result;
use Comparison\VirtualTime as VT;

/**
 * ackintosh/ganesha, Count and Rate strategies, real Apcu storage adapter over an in-process
 * apcu_* stand-in (see ArrayApcuStore). Ganesha reads whole-second time() inside
 * Ackintosh\Ganesha\Strategy, redirected to the virtual clock.
 *
 * Ganesha is a passive state tracker: the caller asks isAvailable() and reports success()/failure().
 * "Concurrent callers" are modelled as several isAvailable() calls before any report.
 */
final class GaneshaAdapter extends BaseAdapter
{
    private const SVC = 'svc';

    public function id(): string
    {
        return 'ackintosh-ganesha';
    }

    public function describe(): array
    {
        return [
            'package' => 'ackintosh/ganesha',
            'version' => self::version('ackintosh/ganesha'),
            'role' => 'Circuit breaker (Count and Rate strategies) with pluggable storage; Guzzle middleware and Symfony HttpClient wrapper.',
            'not_enabled' => 'Redis/Memcached/MongoDB/APCu storage adapters (extensions/servers not present in the image; real Apcu adapter used over an in-process array store), GaneshaHttpClient (Symfony wrapper), custom failure detectors, storage-key customisation, Redis sliding time window (only the tumbling window supported by the Apcu adapter is exercised).',
        ];
    }

    protected function scope(): string
    {
        return 'Library is a circuit-breaker state tracker only: it has no retry, rate limiting, deadline, bulkhead or permit lifecycle, so this scenario does not apply.';
    }

    private function count(int $threshold = 4, int $interval = 30): Ganesha
    {
        return Builder::withCountStrategy()->adapter(new Apcu(new ArrayApcuStore()))->failureCountThreshold($threshold)->intervalToHalfOpen($interval)->build();
    }

    private function rate(int $interval = 30): Ganesha
    {
        return Builder::withRateStrategy()->adapter(new Apcu(new ArrayApcuStore()))->timeWindow(60)->failureRateThreshold(50)->minimumRequests(4)->intervalToHalfOpen($interval)->build();
    }

    /** @return array<string,Ganesha> */
    private function both(): array
    {
        return ['count(threshold=4)' => $this->count(), 'rate(min=4,50%)' => $this->rate()];
    }

    /** One call: returns 'rejected' | 'ok' | 'fail'. */
    private function call(Ganesha $g, bool $fails): string
    {
        if (!$g->isAvailable(self::SVC)) {
            return 'rejected';
        }
        $fails ? $g->failure(self::SVC) : $g->success(self::SVC);

        return $fails ? 'fail' : 'ok';
    }

    private function open(Ganesha $g): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->call($g, true);
        }
    }

    protected function s08(): Result
    {
        $report = [];
        $pass = true;
        foreach ($this->both() as $name => $_) {
            $isRate = str_starts_with($name, 'rate');
            $mk = fn (): Ganesha => $isRate ? $this->rate() : $this->count();
            $g = $mk();
            $seq1 = [];
            for ($i = 0; $i < 5; $i++) {
                $seq1[] = $this->call($g, true);
            }
            $ok1 = $seq1 === ['fail', 'fail', 'fail', 'fail', 'rejected'];
            $entry = ['seq1_4_failures_then_5th' => $seq1, 'seq1_ok' => $ok1];
            $pass = $pass && $ok1;
            if ($isRate) {
                $g2 = $mk();
                $s2 = [$this->call($g2, false), $this->call($g2, false), $this->call($g2, true), $this->call($g2, true), $this->call($g2, false)];
                $g3 = $mk();
                $s3 = [$this->call($g3, false), $this->call($g3, false), $this->call($g3, false), $this->call($g3, true), $this->call($g3, false)];
                $ok2 = $s2[4] === 'rejected';
                $ok3 = $s3[4] === 'ok';
                $entry += ['seq2_SSFF_then_5th' => $s2, 'seq2_ok' => $ok2, 'seq3_SSSF_then_5th' => $s3, 'seq3_ok' => $ok3];
                $pass = $pass && $ok2 && $ok3;
            } else {
                $entry['seq2_seq3'] = 'not applicable: count strategy has no failure-rate notion';
            }
            $report[$name] = $entry;
        }
        $obs = 'Per strategy: ' . json_encode($report);

        return $pass ? Result::pass($obs, $report) : Result::fail($obs, 'behaviour-defect', $report);
    }

    protected function s09(): Result
    {
        $report = [];
        $pass = true;
        foreach (['count' => fn () => $this->count(), 'rate' => fn () => $this->rate()] as $name => $mk) {
            $g = $mk();
            $this->open($g);
            VT::advance(31.0);
            $admitted = 0;
            for ($i = 0; $i < 5; $i++) {
                $admitted += $g->isAvailable(self::SVC) ? 1 : 0;
            }
            $report[$name] = ['admitted_of_5' => $admitted];
            $pass = $pass && $admitted === 1;
        }
        $obs = 'open, 31s elapsed (interval 30s), 5 isAvailable() calls before any report: ' . json_encode($report) . '. The probe count is not configurable; the observed behaviour is a single probe (isHalfOpen() rewrites lastFailureTime on the first call).';

        return $pass ? Result::pass($obs, $report) : Result::fail($obs, 'behaviour-defect', $report);
    }

    protected function s10(): Result
    {
        $report = [];
        $pass = true;
        foreach (['count' => fn () => $this->count(), 'rate' => fn () => $this->rate()] as $name => $mk) {
            $g = $mk();
            $this->open($g);
            VT::advance(31.0);
            $probe = $g->isAvailable(self::SVC); // admitted, never reports
            $immediately = [$g->isAvailable(self::SVC), $g->isAvailable(self::SVC)];
            VT::advance(45.0); // 1.5x cooldown later
            $later = $g->isAvailable(self::SVC);
            $report[$name] = ['probe_admitted' => $probe, 'callers_right_after' => $immediately, 'caller_after_1.5x_cooldown' => $later];
            $pass = $pass && $probe && $immediately === [false, false] && $later === true;
        }
        $obs = 'probe admitted at +31s and never reported; others immediately, then a caller 45s later: ' . json_encode($report) . '. (Recovery in the rate strategy is by tumbling-window ageing of the failure counters, not by a probe timeout.)';

        return $pass ? Result::pass($obs, $report) : Result::fail($obs, 'behaviour-defect', $report);
    }

    protected function s11(): Result
    {
        $report = [];
        $pass = true;
        foreach (['count' => fn () => $this->count(), 'rate' => fn () => $this->rate()] as $name => $mk) {
            $row = [];
            foreach (['control_no_stale' => null, 'stale_failure' => true, 'stale_success' => false] as $variant => $stale) {
                VT::reset();
                $g = $mk();
                $this->open($g);                // opened at t=0 (call "A" would have started before this)
                VT::set(31.0);
                $probe = $g->isAvailable(self::SVC);
                $g->failure(self::SVC);          // probe fails -> reopen at t=31
                VT::set(40.0);
                if ($stale === true) {
                    $g->failure(self::SVC);      // stale failure from the previous era
                } elseif ($stale === false) {
                    $g->success(self::SVC);      // stale success
                }
                if ($variant === 'stale_success') {
                    VT::set(45.0);
                    $row[$variant] = ['probe_admitted' => $probe, 'still_open_at_45' => !$g->isAvailable(self::SVC)];
                } else {
                    // whole-second clock: due strictly after 31+30 -> t=61 rejected, t=62 admitted
                    VT::set(61.0);
                    $at61 = $g->isAvailable(self::SVC);
                    VT::set(62.0);
                    $at62 = $g->isAvailable(self::SVC);
                    $row[$variant] = ['probe_admitted' => $probe, 'admitted_at_61' => $at61, 'admitted_at_62' => $at62];
                }
            }
            $report[$name] = $row;
            $failOk = $row['stale_failure']['admitted_at_61'] === false && $row['stale_failure']['admitted_at_62'] === true;
            $succOk = $row['stale_success']['still_open_at_45'] === true;
            $ctrlOk = $row['control_no_stale']['admitted_at_61'] === false && $row['control_no_stale']['admitted_at_62'] === true;
            $report[$name]['verdict'] = ['control_ok' => $ctrlOk, 'stale_failure_ok' => $failOk, 'stale_success_ok' => $succOk];
            $pass = $pass && $failOk && $succOk;
        }
        $obs = json_encode($report) . '. Ganesha has no notion of a call/generation: failure() is a bare counter+timestamp update, so a result from before the reopen is indistinguishable from a fresh one. (Clock is whole seconds, so next probe is due strictly after 31+30s.)';

        return $pass ? Result::pass($obs, $report) : Result::fail($obs, 'missing-capability', $report);
    }

    protected function s16(): Result
    {
        $g = $this->count();
        $this->open($g);
        $wire = new GuzzleWire([[200]]);
        $mw = (new GuzzleMiddleware($g))($wire->handler());
        $class = null;
        $methods = [];
        try {
            $p = $mw(new \GuzzleHttp\Psr7\Request('GET', 'https://upstream.test/x'), ['ganesha.service_name' => self::SVC]);
            $p->wait();
        } catch (\Throwable $e) {
            $class = $e::class;
            $methods = array_map(static fn (\ReflectionMethod $m): string => $m->getName(), (new \ReflectionClass($e))->getMethods(\ReflectionMethod::IS_PUBLIC));
            $methods = array_values(array_filter($methods, static fn (string $m): bool => !in_array($m, ['getMessage', 'getCode', 'getFile', 'getLine', 'getTrace', 'getTraceAsString', 'getPrevious', '__toString', '__wakeup', '__construct', 'withServiceName'], true)));
        }
        $hasRetryTime = (bool) array_filter($methods, static fn (string $m): bool => (bool) preg_match('/retry|until|after|wait|reset/i', $m));
        $facts = ['rejection_class' => $class, 'is_RejectedException' => $class === RejectedException::class, 'extra_public_methods' => $methods, 'has_retry_time_accessor' => $hasRetryTime, 'raw_isAvailable_returns' => 'bool'];
        $obs = 'Only cause produced: circuit open. ' . json_encode($facts) . '. Library produces no rate-limit/bulkhead/deadline/retries-exhausted causes.';

        return $class === RejectedException::class && $hasRetryTime ? Result::pass($obs, $facts) : Result::fail($obs, 'missing-capability', $facts);
    }

    protected function s17(): Result
    {
        $report = [];
        $fail = false;
        foreach (['count' => fn () => $this->count(), 'rate' => fn () => $this->rate()] as $name => $mk) {
            VT::reset();
            $g = $mk();
            VT::set(10_000.0);
            $this->open($g);
            VT::set(10_000.0 - 3600.0);
            $overAdmit = $g->isAvailable(self::SVC);
            $elapsed = 0.0;
            $recovered = null;
            while ($elapsed <= 4000.0) {
                $elapsed += 1.0;
                VT::set(10_000.0 - 3600.0 + $elapsed);
                if ($g->isAvailable(self::SVC)) {
                    $recovered = $elapsed;
                    break;
                }
            }
            $report[$name] = ['over_admitted_at_jump' => $overAdmit, 'recovered_after_elapsed_seconds' => $recovered, 'interval' => 30];
            $fail = $fail || $overAdmit || $recovered === null || $recovered > 60;
        }
        $obs = 'open at T, clock back 3600s, then 1s steps: ' . json_encode($report) . ' (Rate strategy additionally keys counters by floor(time/window), so after a regression it reads the older window\'s counters).';

        return $fail ? Result::fail($obs, 'wall-clock-dependence', $report) : Result::pass($obs, $report);
    }

    protected function s18(): Result
    {
        $g = $this->count();
        $g->subscribe(static function (string $event, string $service, string $message): never {
            throw new \RuntimeException('observer failed on ' . $event);
        });
        $escaped = null;
        $state = null;
        try {
            $this->open($g);
        } catch (\Throwable $e) {
            $escaped = $e->getMessage();
        }
        $state = $g->isAvailable(self::SVC) ? 'available' : 'tripped';
        $facts = ['exception_escaped_from_failure()' => $escaped, 'circuit_state_afterwards' => $state];
        $obs = 'subscriber throws on the "tripped" event; 4th failure() call: ' . json_encode($facts) . '. Ganesha::notify() calls subscribers without try/catch (only StorageException is caught around the strategy call), so the caller\'s own failure handling is interrupted by the observer exception. State is recorded before notification.';

        return $escaped === null ? Result::pass($obs, $facts) : Result::fail($obs, 'behaviour-defect', $facts);
    }

    protected function s21(): Result
    {
        return Result::na('No public API that executes a user callback; the caller drives isAvailable()/success()/failure() (bool/void), so there is no return type to preserve.');
    }
}
