<?php

declare(strict_types=1);

namespace Comparison\Adapters;

use Comparison\BaseAdapter;
use Comparison\DuatVirtualClock;
use Comparison\Result;
use Comparison\VirtualTime as VT;
use Duat\Backoff\Backoff;
use Duat\Duat;
use Duat\Exception\BulkheadFullException;
use Duat\Exception\CircuitOpenException;
use Duat\Exception\RetryExhaustedException;
use Duat\Exception\TimeoutExceededException;
use Duat\Store\InMemoryStore;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * matheus85/duat 0.2.0: callable-wrapping resilience pipeline (retry, circuit breaker, timeout, bulkhead,
 * fallback). Uses the library's documented seams: Duat::clock() and InMemoryStore(Clock), so no function
 * shadowing is involved. Concurrency is modelled with PHP Fibers (a suspended callback = an in-flight
 * call); the library itself is synchronous.
 */
final class DuatAdapter extends BaseAdapter
{
    private int $seq = 0;

    public function id(): string
    {
        return 'matheus85-duat';
    }

    public function describe(): array
    {
        return [
            'package' => 'matheus85/duat',
            'version' => self::version('matheus85/duat'),
            'role' => 'Callable-wrapping pipeline: retry, circuit breaker, timeout (post-hoc deadline), bulkhead, fallback; PSR-14 events; in-memory/PSR-16/Redis state.',
            'not_enabled' => 'RedisStore / Psr16Store (state backends not in the image; InMemoryStore with the virtual clock is used), PHP attributes / ProxyFactory, fallback(), jitter in Backoff (Backoff::constant used for determinism), event dispatcher (only enabled in S18).',
        ];
    }

    protected function scope(): string
    {
        return 'Library wraps callables with retry/breaker/timeout/bulkhead/fallback; it has no rate limiter (README: "planned") or HTTP awareness, so this scenario does not apply.';
    }

    /** @return array{0:DuatVirtualClock,1:InMemoryStore,2:string} */
    private function env(): array
    {
        $clock = new DuatVirtualClock();

        return [$clock, new InMemoryStore($clock), 'op' . ++$this->seq];
    }

    private function base(DuatVirtualClock $c, InMemoryStore $s, string $name): Duat
    {
        return Duat::for($name)->clock($c)->store($s);
    }

    /** One call; returns 'ok' | 'fail' | 'rejected:circuit' | 'rejected:bulkhead' | 'other:<class>'. */
    private function attempt(Duat $d, bool $fails): string
    {
        try {
            $d->call(static function () use ($fails): string {
                if ($fails) {
                    throw new \RuntimeException('upstream failure');
                }

                return 'ok';
            });

            return 'ok';
        } catch (CircuitOpenException) {
            return 'rejected:circuit';
        } catch (BulkheadFullException) {
            return 'rejected:bulkhead';
        } catch (\RuntimeException) {
            return 'fail';
        } catch (\Throwable $e) {
            return 'other:' . $e::class;
        }
    }

    private function openCircuit(Duat $d): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->attempt($d, true);
        }
    }

    protected function s01(): Result
    {
        [$c, $s, $n] = $this->env();
        $d = $this->base($c, $s, $n)->timeout(10.0)->retry(maxAttempts: 20, backoff: Backoff::constant(4000.0));
        $times = [];
        $err = null;
        try {
            $d->call(static function () use (&$times): never {
                $times[] = VT::elapsed();
                throw new \RuntimeException('503');
            });
        } catch (\Throwable $e) {
            $err = $e::class;
        }
        $late = array_values(array_filter($times, static fn (float $t): bool => $t >= 10.0));
        $obs = sprintf('timeout(10)->retry(20, constant 4s): attempt start times %s, gave up at %s with %s; %d attempt(s) at or after D. RetryPolicy skips a wait when delay >= Context::remainingBudget().', json_encode($times), self::s(VT::elapsed()), $err, \count($late));

        return $late === [] && VT::elapsed() <= 10.0 ? Result::pass($obs, ['times' => $times]) : Result::fail($obs, 'behaviour-defect', ['times' => $times, 'returned_at' => VT::elapsed()]);
    }

    /** User-written glue that smuggles a server hint into Backoff (the library has no Retry-After concept). */
    private function hintBackoff(\stdClass $hint): Backoff
    {
        return new class ($hint) extends Backoff {
            public function __construct(private readonly \stdClass $hint)
            {
            }

            public function delayMs(int $attempt, \Duat\Contract\Randomizer $randomizer): float
            {
                return max(2000.0, $this->hint->ms ?? 0.0);
            }
        };
    }

    protected function s02(): Result
    {
        [$c, $s, $n] = $this->env();
        $hint = new \stdClass();
        $d = $this->base($c, $s, $n)->timeout(10.0)->retry(maxAttempts: 20, backoff: $this->hintBackoff($hint));
        $sent = [];
        try {
            $d->call(static function () use (&$sent, $hint): never {
                $sent[] = VT::elapsed();
                $hint->ms = 30_000.0; // user glue: parse Retry-After and publish it to the Backoff
                throw new \RuntimeException('503 retry-after 30');
            });
        } catch (\Throwable) {
        }
        $obs = 'No Retry-After support: Backoff::delayMs(int $attempt, Randomizer $randomizer) receives neither the exception nor any response, and the onRetry hook returns void. A user-written Backoff subclass sharing mutable state with the callback can emulate it; that glue (not library code) gave: requests at ' . json_encode($sent) . ', returned at ' . self::s(VT::elapsed()) . '.';

        return Result::notExpressible($obs);
    }

    protected function s03(): Result
    {
        return Result::notExpressible('No Retry-After concept: Backoff::delayMs(int $attempt, Randomizer $randomizer) cannot see the exception or response (see S02). The library parses no headers and has no HTTP awareness.');
    }

    protected function s04(): Result
    {
        [$c, $s, $n] = $this->env();
        $total = 0;
        $d = $this->base($c, $s, $n)->retry(maxAttempts: 4, backoff: Backoff::constant(0.0));
        for ($i = 0; $i < 1000; $i++) {
            try {
                $d->call(static function () use (&$total): never {
                    $total++;
                    throw new \RuntimeException('503');
                });
            } catch (\Throwable) {
            }
        }
        $retryOnlyExtra = $total - 1000;

        [$c2, $s2, $n2] = $this->env();
        $total2 = 0;
        $rejected = 0;
        $d2 = $this->base($c2, $s2, $n2)->retry(maxAttempts: 4, backoff: Backoff::constant(0.0))->circuitBreaker(failureRateThreshold: 0.5, minimumCalls: 10);
        for ($i = 0; $i < 1000; $i++) {
            try {
                $d2->call(static function () use (&$total2): never {
                    $total2++;
                    throw new \RuntimeException('503');
                });
            } catch (CircuitOpenException) {
                $rejected++;
            } catch (\Throwable) {
            }
        }
        $data = ['retry_only_wire_requests' => $total, 'retry_only_extra' => $retryOnlyExtra, 'with_breaker_wire_requests' => $total2, 'with_breaker_ops_rejected_without_calling' => $rejected];
        $obs = sprintf('retry(4 attempts) only: %d wire requests, extra %d (%.0f%%). With circuit breaker stacked inside the retry (min 10 calls, 50%%): %d wire requests, %d of 1000 operations rejected without any request. Bounded only because the breaker stops primary traffic too; there is no retry budget (shared cap on retries while first attempts continue).', $total, $retryOnlyExtra, $retryOnlyExtra / 10, $total2, $rejected);

        return ($total2 - 1000) <= 200 && $total2 < 1000
            ? Result::pass($obs, $data + ['mechanism' => 'circuit-breaker, not a retry budget'])
            : Result::fail($obs, 'missing-capability', $data);
    }

    protected function s05(): Result
    {
        return Result::na('Library is protocol-agnostic (wraps arbitrary callables): there is no HTTP method or idempotency notion to default on. Note: retry() defaults to retryOn=[Throwable::class], i.e. every failure of any wrapped callable is retried when retry() is added.');
    }

    protected function s08(): Result
    {
        $mk = function (): Duat {
            [$c, $s, $n] = $this->env();

            return $this->base($c, $s, $n)->circuitBreaker(failureRateThreshold: 0.5, minimumCalls: 4);
        };
        $seq = static fn (Duat $d, array $outcomes, callable $attempt): array => array_map(static fn (bool $f): string => $attempt($d, $f), $outcomes);
        $attempt = fn (Duat $d, bool $f): string => $this->attempt($d, $f);

        $s1 = $seq($mk(), [true, true, true, true, true], $attempt);
        $s2 = $seq($mk(), [false, false, true, true, false], $attempt);
        $s3 = $seq($mk(), [false, false, false, true, false], $attempt);
        $ok1 = $s1 === ['fail', 'fail', 'fail', 'fail', 'rejected:circuit'];
        $ok2 = $s2[4] === 'rejected:circuit';
        $ok3 = $s3[4] === 'ok';
        $data = ['seq1' => $s1, 'seq2_SSFF' => $s2, 'seq3_SSSF' => $s3];
        $obs = 'circuitBreaker(rate 0.5, minimumCalls 4): ' . json_encode($data);

        return $ok1 && $ok2 && $ok3 ? Result::pass($obs, $data) : Result::fail($obs, 'behaviour-defect', $data);
    }

    /** @return array{0:int,1:array<int,\Fiber>} admitted count and live fibers */
    private function parkedCallers(Duat $d, int $callers): array
    {
        $entered = 0;
        $fibers = [];
        for ($i = 0; $i < $callers; $i++) {
            $f = new \Fiber(static function () use ($d, &$entered): string {
                try {
                    return (string) $d->call(static function () use (&$entered): string {
                        $entered++;
                        \Fiber::suspend();

                        return 'ok';
                    });
                } catch (\Throwable $e) {
                    return $e::class;
                }
            });
            $f->start();
            $fibers[] = $f;
        }

        return [$entered, $fibers];
    }

    protected function s09(): Result
    {
        $data = [];
        $pass = true;
        foreach ([2, 1] as $p) {
            [$c, $s, $n] = $this->env();
            $d = $this->base($c, $s, $n)->circuitBreaker(failureRateThreshold: 0.5, minimumCalls: 4, cooldownSeconds: 30, halfOpenMaxCalls: $p);
            $this->openCircuit($d);
            VT::advance(31.0);
            [$entered, $fibers] = $this->parkedCallers($d, 5);
            $data["P=$p"] = ['admitted_of_5' => $entered];
            $pass = $pass && $entered === $p;
            unset($fibers);
        }
        $obs = 'open, cooldown elapsed, 5 callers started before any probe returns (callbacks parked in Fibers): ' . json_encode($data);

        return $pass ? Result::pass($obs, $data) : Result::fail($obs, 'behaviour-defect', $data);
    }

    protected function s10(): Result
    {
        [$c, $s, $n] = $this->env();
        $d = $this->base($c, $s, $n)->circuitBreaker(failureRateThreshold: 0.5, minimumCalls: 4, cooldownSeconds: 30, halfOpenMaxCalls: 1);
        $this->openCircuit($d);
        VT::advance(31.0);
        [$entered, $fibers] = $this->parkedCallers($d, 1);          // probe admitted, parked forever
        $right = [$this->attempt($d, false), $this->attempt($d, false)];
        VT::advance(45.0);                                          // 1.5x cooldown later
        $later = $this->attempt($d, false);
        $data = ['probe_admitted' => $entered === 1, 'callers_right_after' => $right, 'caller_after_1.5x_cooldown' => $later];
        $obs = 'probe admitted and never resumed; ' . json_encode($data) . '. (half_open_calls has a TTL of cooldownSeconds, so the slot self-heals.)';
        $ok = $entered === 1 && $right === ['rejected:circuit', 'rejected:circuit'] && $later === 'ok';
        unset($fibers);

        return $ok ? Result::pass($obs, $data) : Result::fail($obs, 'behaviour-defect', $data);
    }

    protected function s11(): Result
    {
        $run = function (?bool $staleFails): array {
            VT::reset();
            [$c, $s, $n] = $this->env();
            $d = $this->base($c, $s, $n)->circuitBreaker(failureRateThreshold: 0.5, minimumCalls: 4, cooldownSeconds: 30, halfOpenMaxCalls: 1);
            $a = new \Fiber(static function () use ($d): string {
                try {
                    return (string) $d->call(static function (): string {
                        if (\Fiber::suspend() === 'fail') {
                            throw new \RuntimeException('late failure');
                        }

                        return 'ok';
                    });
                } catch (\Throwable $e) {
                    return $e::class;
                }
            });
            $a->start();                       // call A in flight since t=0 (circuit closed)
            $this->openCircuit($d);            // opens at t=0
            VT::set(31.0);
            $probe = $this->attempt($d, true); // probe fails -> reopen at t=31
            VT::set(40.0);
            if ($staleFails !== null) {
                $a->resume($staleFails ? 'fail' : 'ok');
            }
            if ($staleFails === false) {
                VT::set(45.0);

                return ['probe' => $probe, 'at_45' => $this->attempt($d, false)];
            }
            VT::set(60.999);
            $before = $this->attempt($d, false);
            VT::set(61.001);
            $after = $this->attempt($d, false);

            return ['probe' => $probe, 'at_60.999' => $before, 'at_61.001' => $after];
        };
        $control = $run(null);
        $staleFail = $run(true);
        $staleOk = $run(false);
        $ctrlOk = $control['at_60.999'] === 'rejected:circuit' && $control['at_61.001'] === 'ok';
        $failOk = $staleFail['at_60.999'] === 'rejected:circuit' && $staleFail['at_61.001'] === 'ok';
        $okOk = $staleOk['at_45'] === 'rejected:circuit';
        $data = ['control_no_stale' => $control, 'stale_failure' => $staleFail, 'stale_success' => $staleOk, 'control_valid' => $ctrlOk];
        $obs = json_encode($data) . '. A late failure re-enters recordFailure(), re-evaluates the window and calls open() again, rewriting opened_at (the cooldown restarts from the stale result). A late success only increments a bucket.';

        return $failOk && $okOk ? Result::pass($obs, $data) : Result::fail($obs, 'behaviour-defect', $data);
    }

    protected function s12(): Result
    {
        [$c, $s, $n] = $this->env();
        $d = $this->base($c, $s, $n)->bulkhead(maxConcurrent: 1, leaseSeconds: 1);
        $running = 0;
        $peak = 0;
        $park = function () use ($d, &$running, &$peak): \Fiber {
            $f = new \Fiber(static function () use ($d, &$running, &$peak): string {
                try {
                    return (string) $d->call(static function () use (&$running, &$peak): string {
                        $running++;
                        $peak = max($peak, $running);
                        try {
                            \Fiber::suspend();
                        } finally {
                            $running--;
                        }

                        return 'ok';
                    });
                } catch (\Throwable $e) {
                    return $e::class;
                }
            });
            $f->start();

            return $f;
        };
        $a = $park();                 // A takes the only permit at t=0
        VT::set(2.0);                 // A exceeds its 1s lease
        $b = $park();                 // B admitted (lease expired) and still running
        $a->resume();                 // A finishes late: its release runs while B is running
        $cFiber = $park();            // C arrives while B is running
        $cResult = $cFiber->isTerminated() ? $cFiber->getReturn() : 'admitted (running)';
        $data = ['peak_concurrency' => $peak, 'C' => $cResult, 'B_still_running' => !$b->isTerminated()];
        $obs = 'limit 1, lease 1s: ' . json_encode($data) . '. BulkheadPolicy releases by increment(-1) on a shared counter with no holder identity, so a late release decrements the counter now owned by B.';

        return $peak <= 1 ? Result::pass($obs, $data) : Result::fail($obs, 'behaviour-defect', $data);
    }

    protected function s13(): Result
    {
        [$c, $s, $n] = $this->env();
        $circuit = $this->base($c, $s, $n)->bulkhead(1)->circuitBreaker(failureRateThreshold: 0.5, minimumCalls: 4);
        $this->openCircuit($circuit);
        $abort = $this->attempt($circuit, false);                         // admitted by bulkhead, refused by inner layer before dispatch
        $probe = $this->attempt($this->base($c, $s, $n)->bulkhead(1), false); // same name/store: is the permit back?
        $obs = 'Library has no cancellation token/signal, so "cancel before dispatch" cannot be expressed. Nearest expressible case (informational): bulkhead(1) outside an OPEN circuit breaker rejects before dispatch (' . $abort . ') and capacity is returned (fresh caller: ' . $probe . ').';

        return Result::notExpressible($obs);
    }

    protected function s14(): Result
    {
        $data = [];
        foreach (['Exception' => new \RuntimeException('x'), 'Error' => new \Error('y')] as $label => $throwable) {
            [$c, $s, $n] = $this->env();
            $d = $this->base($c, $s, $n)->bulkhead(1);
            try {
                $d->call(static function () use ($throwable): never {
                    throw $throwable;
                });
            } catch (\Throwable) {
            }
            $data[$label] = $this->attempt($d, false);
        }
        $obs = 'after a callback throws, next caller admitted? ' . json_encode($data) . ' (BulkheadPolicy releases in finally).';

        return $data === ['Exception' => 'ok', 'Error' => 'ok'] ? Result::pass($obs, $data) : Result::fail($obs, 'behaviour-defect', $data);
    }

    protected function s16(): Result
    {
        $facts = [];
        $describe = static function (\Throwable $e): array {
            $props = array_map(static fn (\ReflectionProperty $p): string => $p->getName(), (new \ReflectionClass($e))->getProperties(\ReflectionProperty::IS_PUBLIC));

            return ['class' => $e::class, 'public_fields' => $props, 'has_retry_time_field' => (bool) array_filter($props, static fn (string $p): bool => (bool) preg_match('/retry|until|after|wait|reset|delay/i', $p))];
        };
        [$c, $s, $n] = $this->env();
        $d = $this->base($c, $s, $n)->circuitBreaker(failureRateThreshold: 0.5, minimumCalls: 4);
        $this->openCircuit($d);
        try {
            $d->call(static fn (): int => 1);
        } catch (\Throwable $e) {
            $facts['circuit_open'] = $describe($e);
        }
        [$c, $s, $n] = $this->env();
        $b = $this->base($c, $s, $n)->bulkhead(1);
        [$entered, $fibers] = $this->parkedCallers($b, 2);
        $f2 = $fibers[1]->isTerminated() ? $fibers[1]->getReturn() : null;
        $facts['bulkhead_full'] = ['class' => $f2, 'public_fields' => (new \ReflectionClass(BulkheadFullException::class))->getProperties(\ReflectionProperty::IS_PUBLIC) ? array_map(static fn (\ReflectionProperty $p): string => $p->getName(), (new \ReflectionClass(BulkheadFullException::class))->getProperties(\ReflectionProperty::IS_PUBLIC)) : []];
        [$c, $s, $n] = $this->env();
        try {
            $this->base($c, $s, $n)->retry(maxAttempts: 2, backoff: Backoff::constant(0.0))->call(static function (): never {
                throw new \RuntimeException('x');
            });
        } catch (\Throwable $e) {
            $facts['retries_exhausted'] = $describe($e);
        }
        [$c, $s, $n] = $this->env();
        try {
            $this->base($c, $s, $n)->timeout(1.0, throwOnLateSuccess: true)->call(static function (): int {
                VT::advance(2.0);

                return 1;
            });
        } catch (\Throwable $e) {
            $facts['deadline_exceeded'] = $describe($e);
        }
        $distinct = \count(array_unique(array_filter(array_map(static fn ($x) => $x['class'] ?? null, $facts)))) === 4;
        $circuitRetryAt = $facts['circuit_open']['has_retry_time_field'] ?? false;
        $obs = 'Typed and distinct: ' . ($distinct ? 'yes' : 'NO') . ' (CircuitOpenException, BulkheadFullException, RetryExhaustedException, TimeoutExceededException, all extending DuatException). Facts: ' . json_encode($facts) . '. CircuitOpenException exposes only the circuit name: no retry-at/remaining-cooldown. No rate-limit cause (no limiter). TimeoutExceededException is only thrown after the callable returns and only with throwOnLateSuccess.';
        unset($fibers);

        return $distinct && $circuitRetryAt ? Result::pass($obs, $facts) : Result::fail($obs, 'missing-capability', $facts);
    }

    protected function s17(): Result
    {
        [$c, $s, $n] = $this->env();
        $d = $this->base($c, $s, $n)->circuitBreaker(failureRateThreshold: 0.5, minimumCalls: 4, cooldownSeconds: 30);
        VT::set(10_000.0);
        $this->openCircuit($d);
        VT::set(10_000.0 - 3600.0);
        $over = $this->attempt($d, false) !== 'rejected:circuit';
        $elapsed = 0.0;
        $recovered = null;
        while ($elapsed <= 4000.0) {
            $elapsed += 1.0;
            VT::set(10_000.0 - 3600.0 + $elapsed);
            if ($this->attempt($d, false) !== 'rejected:circuit') {
                $recovered = $elapsed;
                break;
            }
        }
        $data = ['over_admitted_at_jump' => $over, 'recovered_after_elapsed_seconds' => $recovered, 'cooldown' => 30];
        $obs = 'circuit opened at T, clock back 3600s, then 1s steps: ' . json_encode($data) . ' (opened_at lies in the future, so now - opened_at < cooldown until the clock catches up).';

        return $over || $recovered === null || $recovered > 60 ? Result::fail($obs, 'wall-clock-dependence', $data) : Result::pass($obs, $data);
    }

    protected function s18(): Result
    {
        [$c, $s, $n] = $this->env();
        $dispatcher = new class () implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                throw new \RuntimeException('observer failed on ' . $event::class);
            }
        };
        $d = $this->base($c, $s, $n)->events($dispatcher)->retry(maxAttempts: 3, backoff: Backoff::constant(0.0));
        $i = 0;
        $result = null;
        $escaped = null;
        try {
            $result = $d->call(static function () use (&$i): string {
                return ++$i === 1 ? throw new \RuntimeException('first attempt fails') : 'business-value';
            });
        } catch (\Throwable $e) {
            $escaped = $e->getMessage();
        }
        [$c2, $s2, $n2] = $this->env();
        $j = 0;
        $control = $this->base($c2, $s2, $n2)->retry(maxAttempts: 3, backoff: Backoff::constant(0.0))->call(static function () use (&$j): string {
            return ++$j === 1 ? throw new \RuntimeException('first attempt fails') : 'business-value';
        });
        $data = ['result' => $result, 'exception_escaped' => $escaped, 'attempts_made' => $i, 'control_without_observer' => $control];
        $obs = 'retry once then succeed, PSR-14 dispatcher throws on every event: ' . json_encode($data) . '. Context::dispatch() has no try/catch; RetryPolicy dispatches inside its catch block.';

        return $result === 'business-value' ? Result::pass($obs, $data) : Result::fail($obs, 'behaviour-defect', $data);
    }

    protected function s21(): Result
    {
        $root = dirname(__DIR__, 3);
        $vendor = getenv('COMPARISON_VENDOR_DIR') ?: $root . '/build/comparison/vendor';
        $dir = sys_get_temp_dir() . '/cmp-phpstan-' . getmypid();
        @mkdir($dir);
        $probe = $dir . '/probe.php';
        file_put_contents($probe, "<?php\ndeclare(strict_types=1);\nuse Duat\\Duat;\nfunction probe(): void\n{\n    \$r = Duat::for('x')->retry()->call(fn (): int => 1);\n    \\PHPStan\\dumpType(\$r);\n}\n");
        $cmd = [PHP_BINARY, '-d', 'memory_limit=1G', $vendor . '/phpstan/phpstan/phpstan', 'analyse', '--level=max', '--no-progress', '--error-format=json', '--autoload-file=' . $vendor . '/autoload.php', '--configuration=', $probe];
        $proc = proc_open(array_values(array_filter($cmd, static fn (string $a): bool => $a !== '--configuration=')), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir, ['TMPDIR' => $dir, 'PATH' => getenv('PATH') ?: '']);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        proc_close($proc);
        $json = json_decode((string) $out, true);
        $msgs = [];
        foreach ($json['files'] ?? [] as $f) {
            foreach ($f['messages'] as $m) {
                $msgs[] = $m['message'];
            }
        }
        $dump = null;
        foreach ($msgs as $m) {
            if (preg_match('/Dumped type: (.+)$/', $m, $mm)) {
                $dump = $mm[1];
            }
        }
        $templates = (int) trim((string) shell_exec('grep -rE "@template" ' . escapeshellarg($vendor . '/matheus85/duat/src') . ' | wc -l'));
        $data = ['phpstan_level' => 'max', 'inferred_type' => $dump, '@template_annotations_in_duat_src' => $templates, 'phpstan_version' => self::version('phpstan/phpstan'), 'stderr' => trim((string) $err)];
        $obs = 'PHPStan max: $r = Duat::for("x")->retry()->call(fn (): int => 1) is inferred as "' . ($dump ?? 'UNKNOWN (probe failed: ' . trim((string) $err . ' ' . implode(' | ', $msgs)) . ')') . '"; ' . $templates . ' @template annotations in the library source (call() is declared "@param callable(): mixed $fn" and returns mixed).';
        @unlink($probe);
        @rmdir($dir);
        if ($dump === null) {
            throw new \RuntimeException('PHPStan probe produced no dumpType result: ' . $obs);
        }

        return $dump === 'int' ? Result::pass($obs, $data) : Result::fail($obs, 'missing-capability', $data);
    }
}
