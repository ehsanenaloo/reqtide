<?php

declare(strict_types=1);

namespace Comparison\Adapters;

require_once __DIR__ . '/../src/ReqtideRig.php';

use Comparison\BaseAdapter;
use Comparison\Result;
use Comparison\ReqtideRig as R;
use Comparison\ReqtideCancelBeforeDispatch;
use Comparison\ReqtideWire;
use Comparison\UpstreamFailure;
use Comparison\WireStatus;
use Comparison\WireTransportFailure;
use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Exception\AdmissionDeferredException;
use Enaxon\Reqtide\Exception\AdmissionRejectedException;
use Enaxon\Reqtide\Outcome\ExceptionClassifier;
use Enaxon\Reqtide\Pipeline\PipelineBuilder;
use Enaxon\Reqtide\Policy\BackoffMode;
use Enaxon\Reqtide\Policy\FixedWindowAnchor;
use Enaxon\Reqtide\Policy\JitterMode;
use Enaxon\Reqtide\Policy\RateAlgorithmOptions;
use Enaxon\Reqtide\Telemetry\CircuitTransitioned;
use Enaxon\Reqtide\Testing\RecordingEventSink;
use Enaxon\Reqtide\Time\CancellationToken;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\PositiveInt;
use Enaxon\Reqtide\Value\Ratio;

/**
 * Enaxon Reqtide (enaxon/reqtide, repository root, working tree, unreleased), driven ONLY through its public API:
 * PipelineBuilder -> PipelineDefinition::compile(RuntimeEnvironment) -> CompiledPipeline::execute()/executeDetailed().
 *
 * Time is virtual through src/Testing (VirtualClock + VirtualDelayScheduler) and the process-local memory
 * backends. InMemoryEnvironment cannot take a virtual clock (it hard-wires SystemClock and a sleeping scheduler),
 * so the RuntimeEnvironment is assembled by hand in Comparison\ReqtideRig, exactly as InMemoryEnvironment's own
 * docblock prescribes for deterministic tests. No function shadowing is involved: Reqtide reads time only through
 * its Clock/DelayScheduler seams.
 *
 * Rules followed (see the former extension-point docblock): shared expectations from src/Scenarios.php, never
 * adjusted; Reqtide has NO numeric defaults, so every required option (attempt timeout, leases, retry budget size,
 * circuit probe settings) is chosen here and named in the observation of the scenario that uses it; any choice that
 * influences a verdict is flagged "ADAPTATION" in the observation. Concurrency (S09-S12) is modelled with Fibers like
 * the other adapters. Reqtide has no HTTP awareness: the scripted upstream raises UpstreamFailure/WireStatus and the
 * ExceptionClassifier (the application's decision, as the library intends) maps it.
 */
final class ReqtideAdapter extends BaseAdapter
{
    public function __construct()
    {
        R::boot();
    }

    public function id(): string
    {
        return 'reqtide';
    }

    public function describe(): array
    {
        return [
            'package' => 'enaxon/reqtide (repository root, src/)',
            'version' => 'working tree (unreleased)',
            'role' => 'Resilience pipeline for PHP HTTP clients: rate limiting, retry with deadline and Retry-After, circuit breaker, bulkhead.',
            'not_enabled' => 'Only the process-local memory backends were exercised; the Redis backends are a separate package and are not part of this comparison. Real SystemClock/BoundedBlockingScheduler (VirtualClock + VirtualDelayScheduler used, runtime adapter is the real SynchronousRuntimeAdapter). JitterMode::None everywhere (jitter is optional; constant/exponential backoff only). Cooldown policy (shared Retry-After pause) not enabled in any scenario. Fallback operations and FallbackScope not used. EventSink is a NullEventSink except in S18. Pipelines are built through PipelineBuilder + CompiledPipeline (the identity generator is the library default; DeterministicIdentityGenerator is not injected because the public compile() path does not take one and observations contain no identifiers). Every required option with no default (attempt timeout 5 s unless a scenario says otherwise, leases, retry-budget size, probe settings) is chosen per scenario and named in its observation.',
        ];
    }

    protected function scope(): string
    {
        return 'Reqtide Core covers retry, rate limit, circuit breaker and bulkhead; every scenario has an executable adaptation, so this default is never used.';
    }

    // ------------------------------------------------------------------ shared builders

    private static function base(float $attemptTimeoutSeconds = 5.0): PipelineBuilder
    {
        return R::base(R::secs($attemptTimeoutSeconds));
    }

    /** retry(): constant backoff, no jitter; maximum delay is a ceiling that is deliberately never the binding limit. */
    private static function withRetry(PipelineBuilder $b, int $attempts, float $baseDelay, float $maxDelay = 60.0, int $budgetCapacity = 1000, int $budgetRefill = 1000, float $budgetInterval = 1.0): PipelineBuilder
    {
        return $b
            ->retry($attempts, BackoffMode::Constant, R::secs($baseDelay), R::secs($maxDelay), JitterMode::None)
            ->retryBudget($budgetCapacity, $budgetRefill, R::secs($budgetInterval), Duration::seconds(30));
    }

    /** Circuit: count window 10, min samples 4, 50 %, open 30 s, probes P, lease L. */
    private static function withCircuit(PipelineBuilder $b, int $probes = 1, float $lease = 60.0): PipelineBuilder
    {
        return $b->circuitBreaker(PipelineBuilder::lastCalls(10), 4, Ratio::of(1, 2), Duration::seconds(30), $probes, $probes, R::secs($lease));
    }

    /** @param array<string,mixed> $v */
    private static function j(array $v): string
    {
        return (string) json_encode($v, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function t(float $v): float
    {
        return round($v, 3);
    }

    // ------------------------------------------------------------------ S01-S05 retry

    protected function s01(): Result
    {
        $rig = R::make(self::withRetry(self::base(), 20, 4.0), R::httpClassifier());
        $wire = new ReqtideWire($rig, static fn (int $n) => new WireStatus(503));
        [, $e] = $rig->run($wire, 10.0, $rig->readOnly());
        $late = array_values(array_filter($wire->sent, static fn (float $t): bool => $t >= 10.0));
        $data = ['attempt_start_times' => array_map(self::t(...), $wire->sent), 'returned_at' => self::t($rig->elapsed()), 'outcome' => $e === null ? 'ok' : R::label($e)];
        // Control: with a 1000s deadline the same pipeline stops only at the count limit (20 attempts), so the 10s run was bounded by time alone.
        $ctl = R::make(self::withRetry(self::base(), 20, 4.0), R::httpClassifier());
        $ctlWire = new ReqtideWire($ctl, static fn (int $n) => new WireStatus(503));
        $ctl->run($ctlWire, 1000.0, $ctl->readOnly());
        $data['control_deadline_1000s_attempts'] = \count($ctlWire->sent);
        $obs = sprintf('Deadline 10s (newContext timeout), retry(maxAttempts=20, constant 4s, no jitter) + retryBudget(capacity 1000), every attempt 503: attempt start times %s, gave up at %s with %s (the original 503 exception); %d attempt(s) at or after D. The retry loop declines a wait that does not fit the remaining deadline (code read, not asserted here: OperationRun::decideRetry, reason retry_delay_exceeds_deadline). Control: with a 1000s deadline the same pipeline makes %d attempts (the count limit), so the 10s run was stopped by time alone.', json_encode($data['attempt_start_times']), self::s($rig->elapsed()), $data['outcome'], \count($late), $data['control_deadline_1000s_attempts']);

        return $late === [] && $rig->elapsed() <= 10.0 ? Result::pass($obs, $data) : Result::fail($obs, 'behaviour-defect', $data);
    }

    protected function s02(): Result
    {
        $rig = R::make(self::withRetry(self::base(), 20, 2.0), R::httpClassifier());
        $wire = new ReqtideWire($rig, static fn (int $n) => $n === 1 ? new WireStatus(503, 30) : null);
        [$value, $e] = $rig->run($wire, 10.0, $rig->readOnly());
        $wait = $e instanceof AdmissionDeferredException ? $e->decision->retryAfter?->nanoseconds / 1e9 : null;
        $data = ['requests' => \count($wire->sent), 'send_times' => array_map(self::t(...), $wire->sent), 'returned_at' => self::t($rig->elapsed()), 'outcome' => $value ?? ($e === null ? 'ok' : R::label($e)), 'deferral_wait_seconds' => $wait, 'previous' => ($e?->getPrevious() === null ? null : $e->getPrevious()::class)];
        $obs = sprintf('Deadline 10s, Retry-After 30 on the first 503, retry(20, constant 2s): requests=%d at %s, returned at %s as %s%s. The hint is read through ExceptionClassifier::retryAfterFrom(); the retry is converted into a typed deferral carrying the wait and the original 503 as previous.', $data['requests'], json_encode($data['send_times']), self::s($rig->elapsed()), $data['outcome'], $wait !== null ? sprintf(' (wait %ss)', $wait) : '');

        return $data['requests'] === 1 && $rig->elapsed() <= 10.0 ? Result::pass($obs, $data) : Result::fail($obs, 'behaviour-defect', $data);
    }

    protected function s03(): Result
    {
        $gap = function (int $retryAfter, float $backoff): array {
            $rig = R::make(self::withRetry(self::base(), 5, $backoff), R::httpClassifier());
            $wire = new ReqtideWire($rig, static fn (int $n) => $n === 1 ? new WireStatus(503, $retryAfter) : null);
            [$value, $e] = $rig->run($wire, 120.0, $rig->readOnly());

            return ['requests' => \count($wire->sent), 'gap' => isset($wire->sent[1]) ? self::t($wire->sent[1] - $wire->sent[0]) : null, 'outcome' => $value ?? ($e === null ? 'ok' : R::label($e))];
        };
        $primary = $gap(6, 2.0);
        $info = $gap(1, 4.0);
        $data = ['primary_retry_after_6_backoff_2' => $primary, 'informational_retry_after_1_backoff_4' => $info];
        $obs = sprintf('Deadline 120s. primary: Retry-After 6 vs constant backoff 2 -> gap %ss. Informational: Retry-After 1 vs backoff 4 -> gap %ss. Reqtide ADDS the backoff to the server hint (scheduled = min(maximumDelay, retryAfter + backoff)) instead of taking the larger or replacing it, so the hint is always a lower bound (code: OperationRun::decideRetry, "scheduled = bound + jittered"). maximumDelay is 60s here; a hint above maximumDelay becomes a deferral (reason retry_after_exceeds_maximum_delay).', $primary['gap'], $info['gap']);

        return $primary['gap'] !== null && $primary['gap'] >= 6.0 ? Result::pass($obs, $data) : Result::fail($obs, 'behaviour-defect', $data);
    }

    protected function s04(): Result
    {
        $run = function (int $capacity): array {
            $rig = R::make(self::withRetry(self::base(), 4, 0.0, 0.0, $capacity, $capacity, 60.0), R::httpClassifier());
            $total = 0;
            $wire = static function (AttemptContext $a) use (&$total): never {
                ++$total;
                throw new WireStatus(503);
            };
            for ($i = 0; $i < 1000; $i++) {
                $rig->run($wire, 30.0, $rig->readOnly());
            }

            return ['budget_capacity' => $capacity, 'wire_requests' => $total, 'extra' => $total - 1000, 'extra_percent' => ($total - 1000) / 10];
        };
        $main = $run(100);
        $sensitivity = [$run(1000), $run(3000)];
        $data = ['main' => $main, 'sensitivity' => $sensitivity];
        $obs = sprintf('1000 failing operations, maxAttempts=4 (3 retries each), delays 0. ADAPTATION: retryBudget is mandatory and has no default; it is a token bucket (absolute tokens), not a ratio of first attempts. Sized to 10%% of the 1000 first attempts (capacity 100, refill 100 per 60s, no virtual time passes) following the SRE ~10%% guidance cited by the scenario: %d wire requests, extra %d (%s%%). Sensitivity (same code, bigger pool): capacity 1000 -> extra %d (%s%%); capacity 3000 -> extra %d (%s%%). The bound is the configured pool; Reqtide cannot express "20%% of first attempts" as a ratio, and an over-sized pool gives no protection.', $main['wire_requests'], $main['extra'], $main['extra_percent'], $sensitivity[0]['extra'], $sensitivity[0]['extra_percent'], $sensitivity[1]['extra'], $sensitivity[1]['extra_percent']);

        return $main['extra'] <= 200 ? Result::pass($obs, $data) : Result::fail($obs, 'behaviour-defect', $data);
    }

    protected function s05(): Result
    {
        $post = static function (\Throwable $failure) {
            // Default configuration: retry() is not called, so the pipeline has maxAttempts = 1.
            $rig = R::make(self::base(), R::httpClassifier());
            $wire = new ReqtideWire($rig, static fn (int $n) => $failure, 'POST');
            $rig->run($wire);

            return \count($wire->sent);
        };
        $sent = [
            'a_post_500' => $post(new WireStatus(500)),
            'b_post_transport_error' => $post(new WireTransportFailure('connection reset')),
            'c_post_503' => $post(new WireStatus(503)),
            'd_post_429' => $post(new WireStatus(429)),
        ];
        // Opt-in retry, caller does not assert replay safety (the default context is OperationSafety::unsafe()).
        $rig = R::make(self::withRetry(self::base(), 3, 0.0, 0.0), R::httpClassifier());
        $wire = new ReqtideWire($rig, static fn (int $n) => new WireStatus(500), 'POST');
        [, $refused] = $rig->run($wire);
        $optInUnsafe = ['requests' => \count($wire->sent), 'exception' => $refused === null ? null : $refused::class];
        // Control: the same opt-in pipeline for a GET the caller explicitly asserts as repeatable.
        $rig = R::make(self::withRetry(self::base(), 3, 0.0, 0.0), R::httpClassifier());
        $wire = new ReqtideWire($rig, static fn (int $n) => new WireStatus(500));
        $rig->run($wire, 60.0, $rig->readOnly());
        $control = \count($wire->sent);
        $data = ['default_config_requests' => $sent, 'opt_in_retry3_unsafe_context' => $optInUnsafe, 'control_opt_in_retry3_get_with_readOnly_assertion' => $control];
        $obs = sprintf('Default configuration (no retry() call => maxAttempts 1): requests %s. ADAPTATION NOTE: Reqtide has no default retry and no HTTP-method awareness, so this row is trivially satisfied by the default. Opt-in retry(3) with the default context (OperationSafety::unsafe()): %d request(s), refused before dispatch with %s ("maxAttempts greater than one requires an explicit replay-safety contract"). Control: the same opt-in pipeline retries a GET the caller asserts repeatable (ReplaySafetyContract::forReadOnlyCallable): %d requests. Reqtide never verifies the assertion, so a caller can still wrongly assert a POST.', json_encode($sent), $optInUnsafe['requests'], $optInUnsafe['exception'] ?? 'nothing', $control);

        $ok = $sent['a_post_500'] === 1 && $sent['b_post_transport_error'] === 1 && $optInUnsafe['exception'] === InvalidConfigurationException::class && $control === 3;

        return $ok ? Result::pass($obs, $data) : Result::fail($obs, 'behaviour-defect', $data);
    }

    // ------------------------------------------------------------------ S06-S07 rate

    protected function s06(): Result
    {
        $rig = R::make(self::base()->rateLimit(PipelineBuilder::tokenBucket(2, 1, Duration::seconds(2))));
        $probe = static function (R $rig): bool {
            return $rig->call(R::succeeding()) === 'ok';
        };
        $d = [];
        $d['t0.a'] = $probe($rig);
        $d['t0.b'] = $probe($rig);
        $d['t0.c(3rd)'] = $probe($rig);
        $rig->at(1.999);
        $d['t1.999'] = $probe($rig);
        $rig->at(2.0);
        $d['t2.0'] = $probe($rig);
        $d['t2.0(again)'] = $probe($rig);
        $rig->at(5.0);
        $d['t5.0'] = $probe($rig);
        $d['t5.0(again)'] = $probe($rig);
        $rig->at(5.999);
        $d['t5.999'] = $probe($rig);
        $rig->at(6.0);
        $d['t6.0'] = $probe($rig);
        $expected = ['t0.a' => true, 't0.b' => true, 't0.c(3rd)' => false, 't1.999' => false, 't2.0' => true, 't2.0(again)' => false, 't5.0' => true, 't5.0(again)' => false, 't5.999' => false, 't6.0' => true];
        $obs = 'tokenBucket(capacity 2, refill 1 token per 2s), rateLimit cost 1, bucket starts full, one execute() per probe (admitted = callable ran): decisions ' . json_encode($d);

        return $d === $expected ? Result::pass($obs, $d) : Result::fail($obs, 'behaviour-defect', ['observed' => $d, 'expected' => $expected]);
    }

    protected function s07(): Result
    {
        $probe = function (RateAlgorithmOptions $limit, float $at): bool {
            $rig = R::make(self::base()->rateLimit($limit));
            for ($i = 0; $i < 3; $i++) {
                if ($rig->call(R::succeeding()) !== 'ok') {
                    throw new \RuntimeException('first three requests must be admitted');
                }
            }
            $rig->at($at);

            return $rig->call(R::succeeding()) === 'ok';
        };
        $variants = [
            'fixed_window_aligned' => fn (): RateAlgorithmOptions => PipelineBuilder::fixedWindow(3, Duration::seconds(10), FixedWindowAnchor::Aligned),
            'fixed_window_first_hit' => fn (): RateAlgorithmOptions => PipelineBuilder::fixedWindow(3, Duration::seconds(10), FixedWindowAnchor::FirstHit),
            'sliding_counter' => fn (): RateAlgorithmOptions => PipelineBuilder::slidingWindow(3, Duration::seconds(10)),
        ];
        $data = [];
        foreach ($variants as $name => $algo) {
            foreach (['t0+W-0.001' => 9.999, 't0+W' => 10.0, 't0+W+0.001' => 10.001] as $label => $at) {
                $data[$name][$label] = $probe($algo(), $at);
            }
        }
        $slidingFirst = null;
        for ($at = 10.0; $at <= 40.0; $at += 0.5) {
            if ($probe(PipelineBuilder::slidingWindow(3, Duration::seconds(10)), $at)) {
                $slidingFirst = $at;
                break;
            }
        }
        $data['sliding_counter']['first_admission_seconds_after_t0_0.5s_steps'] = $slidingFirst;
        $fixedOk = static fn (array $v): bool => $v['t0+W-0.001'] === false && $v['t0+W+0.001'] === true;
        $obs = 'limit 3 per W=10s, 3 admitted at t0 (t0 is on a 10s wall boundary so Aligned and FirstHit coincide), 4th request (a fresh limiter per probe) admitted? ' . json_encode($data) . '. Verdict on the two FIXED-window anchors (the scenario names fixed/sliding window boundary); the approximate sliding counter is reported only as data: it weights the previous window, so its 4th request is not admitted at t0+W+0.001 (first admission seen at about +' . ($slidingFirst ?? 'n/a') . 's). That is safe (never early) but would miss the scenario\'s "within 1ms" liveness clause, and it is a different algorithm from the exact sliding log some competitors use, which is why it is not the graded one.';

        return $fixedOk($data['fixed_window_aligned']) && $fixedOk($data['fixed_window_first_hit']) ? Result::pass($obs, $data) : Result::fail($obs, 'behaviour-defect', $data);
    }

    // ------------------------------------------------------------------ S08-S11 circuit

    protected function s08(): Result
    {
        $mk = fn (): R => R::make(self::withCircuit(self::base(), 1));
        $s1 = $mk()->sequence([true, true, true, true, true]);
        $s2 = $mk()->sequence([false, false, true, true, false]);
        $s3 = $mk()->sequence([false, false, false, true, false]);
        $ok1 = $s1 === ['fail', 'fail', 'fail', 'fail', 'rejected:circuit_open'];
        $ok2 = $s2[4] === 'rejected:circuit_open';
        $ok3 = $s3[4] === 'ok';
        $data = ['seq1_FFFF_then_5th' => $s1, 'seq2_SSFF_then_5th' => $s2, 'seq3_SSSF_then_5th' => $s3];
        $obs = 'circuitBreaker(lastCalls(10), minimumSamples 4, Ratio 1/2, open 30s, probes 1, lease 60s): ' . json_encode($data);

        return $ok1 && $ok2 && $ok3 ? Result::pass($obs, $data) : Result::fail($obs, 'behaviour-defect', $data);
    }

    protected function s09(): Result
    {
        $data = [];
        $pass = true;
        foreach ([2, 1] as $p) {
            $rig = R::make(self::withCircuit(self::base(), $p));
            $rig->openCircuit();
            $rig->at(31.0);
            $entered = 0;
            $fibers = [];
            for ($i = 0; $i < 5; $i++) {
                $fibers[] = $rig->park($entered);
            }
            $rejected = [];
            foreach ($fibers as $f) {
                if ($f->isTerminated()) {
                    $rejected[] = $f->getReturn();
                }
            }
            $data["P=$p"] = ['admitted_of_5' => $entered, 'others' => $rejected];
            $pass = $pass && $entered === $p;
            unset($fibers);
        }
        $obs = 'open at t=0, 31s elapsed (cooldown 30s), 5 callers started before any probe reports (callbacks parked in Fibers), probeCapacity = probeSuccessThreshold = P: ' . json_encode($data);

        return $pass ? Result::pass($obs, $data) : Result::fail($obs, 'behaviour-defect', $data);
    }

    protected function s10(): Result
    {
        $run = function (float $lease): array {
            $rig = R::make(self::withCircuit(self::base(), 1, $lease));
            $rig->openCircuit();
            $rig->at(31.0);
            $entered = 0;
            $probe = $rig->park($entered);                     // admitted, never reports
            $right = [$rig->call(R::succeeding()), $rig->call(R::succeeding())];
            $rig->at(31.0 + 45.0);                             // 1.5 x cooldown later
            $later = $rig->call(R::succeeding());
            $replacementAt = null;
            if ($later !== 'ok') {
                for ($t = 31.0 + 45.0; $t <= 31.0 + 200.0; $t += 1.0) {
                    $rig->at($t);
                    if ($rig->call(R::succeeding()) === 'ok') {
                        $replacementAt = $t - 31.0;
                        break;
                    }
                }
            }
            unset($probe);

            return ['permit_lease' => $lease, 'probe_admitted' => $entered === 1, 'callers_right_after' => $right, 'caller_after_1.5x_cooldown' => $later, 'replacement_admitted_seconds_after_probe_if_not_at_1.5x' => $replacementAt];
        };
        $main = $run(30.0);
        $sens = $run(60.0);
        $ok = $main['probe_admitted'] && $main['callers_right_after'] === ['rejected:circuit_probe_capacity_exhausted', 'rejected:circuit_probe_capacity_exhausted'] && $main['caller_after_1.5x_cooldown'] === 'ok';
        $data = ['main_lease_30s' => $main, 'sensitivity_lease_60s' => $sens];
        $obs = 'probe admitted at +31s and never reported (parked Fiber); others immediately, then a caller 45s later. ADAPTATION: the probe slot is released only when its permit lease expires, and permitLease has no default; 30s (= open duration, the same self-heal horizon duat uses) is the graded setting: ' . json_encode($main) . '. Sensitivity with lease 60s (also valid): ' . json_encode($sens) . ' - recovery time equals the configured lease: the replacement probe is admitted exactly 60s (= 2x cooldown) after the abandoned one, which meets the scenario\'s "within 2x cooldown" wording but not the stricter 1.5x-cooldown probe the adapters use; with a lease above 2x cooldown it would not.';

        return $ok ? Result::pass($obs, $data) : Result::fail($obs, 'behaviour-defect', $data);
    }

    protected function s11(): Result
    {
        $run = function (?bool $staleFails): array {
            $rig = R::make(self::withCircuit(self::base(), 1, 60.0));
            $entered = 0;
            $a = $rig->park($entered);          // call A in flight since t=0 (circuit closed); its permit lease (60s) outlives t=40
            $rig->openCircuit();                // opens at t=0
            $rig->at(31.0);
            $probe = $rig->call(R::failing());  // probe fails -> reopen at t=31 (next probe due t=61)
            $rig->at(40.0);
            if ($staleFails !== null) {
                $a->resume($staleFails ? 'fail' : 'ok');
            }
            if ($staleFails === false) {
                $rig->at(45.0);

                return ['probe' => $probe, 'at_45' => $rig->call(R::succeeding())];
            }
            $rig->at(60.999);
            $before = $rig->call(R::succeeding());
            $rig->at(61.001);
            $after = $rig->call(R::succeeding());

            return ['probe' => $probe, 'at_60.999' => $before, 'at_61.001' => $after];
        };
        $control = $run(null);
        $staleFail = $run(true);
        $staleOk = $run(false);
        $ctrlOk = $control['at_60.999'] === 'rejected:circuit_open' && $control['at_61.001'] === 'ok';
        $failOk = $staleFail['at_60.999'] === 'rejected:circuit_open' && $staleFail['at_61.001'] === 'ok';
        $okOk = $staleOk['at_45'] === 'rejected:circuit_open';
        $data = ['control_no_stale' => $control, 'stale_failure' => $staleFail, 'stale_success' => $staleOk, 'control_valid' => $ctrlOk];
        $obs = json_encode($data) . '. Permit lease is 60s so the stale result is rejected by the generation/phase fence, not by lease expiry; control valid: ' . ($ctrlOk ? 'yes' : 'NO') . '.';
        if (!$ctrlOk) {
            throw new \RuntimeException('S11 control invalid: ' . $obs);
        }

        return $failOk && $okOk ? Result::pass($obs, $data) : Result::fail($obs, 'behaviour-defect', $data);
    }

    // ------------------------------------------------------------------ S12-S14 bulkhead

    protected function s12(): Result
    {
        // ADAPTATION: the lease must exceed attemptTimeout + 1s (PipelineDefinition), so 1s is not expressible; attempt 100ms, lease 1.2s.
        $rig = R::make(self::base(0.1)->bulkhead(1, R::secs(1.2)));
        $running = 0;
        $peak = 0;
        $park = function () use ($rig, &$running, &$peak): \Fiber {
            $f = new \Fiber(function () use ($rig, &$running, &$peak): string {
                return $rig->call(static function (AttemptContext $a) use (&$running, &$peak): string {
                    ++$running;
                    $peak = max($peak, $running);
                    try {
                        \Fiber::suspend();
                    } finally {
                        --$running;
                    }

                    return 'ok';
                }, 600.0);
            });
            $f->start();

            return $f;
        };
        $a = $park();                 // A takes the only permit at t=0
        $rig->at(2.0);                // A exceeds its 1.2s lease
        $b = $park();                 // B admitted (lease expired) and still running
        $a->resume();                 // A finishes late: its release runs while B is running
        $c = $park();                 // C arrives while B is running
        $cResult = $c->isTerminated() ? $c->getReturn() : 'admitted (running)';
        $cRejected = $c->isTerminated() && $c->getReturn() === 'rejected:concurrency_capacity_exhausted';
        $data = ['C' => $cResult, 'C_rejected' => $cRejected, 'B_still_running' => !$b->isTerminated(), 'A_result' => $a->isTerminated() ? $a->getReturn() : 'running', 'physical_peak_callbacks_running' => $peak];
        $obs = 'ADAPTATION: limit 1; lease 1.2s with attempt timeout 100ms (the library requires lease > attempt timeout + 1s, so the scenario\'s 1s lease cannot be configured): ' . json_encode($data) . '. GRADED on the scenario\'s observed question (is C admitted while B still runs? no, so A\'s late release did not free B\'s permit). The physical peak of 2 is A and B overlapping: the scenario starts B after A\'s lease expired while A is still running, so any lease-based bulkhead shows it and the literal clause "peak concurrency never exceeds 1" cannot be satisfied.';

        return $cRejected ? Result::pass($obs, $data) : Result::fail($obs, 'behaviour-defect', $data);
    }

    protected function s13(): Result
    {
        $token = new CancellationToken();
        $rig = R::make(self::base()->bulkhead(1, Duration::seconds(60)));
        $heldAtCancel = null;
        // Public AttemptOperation seam: cancel from verifyReplay(), which runs AFTER admission (permits held) and BEFORE dispatch.
        $operation = new ReqtideCancelBeforeDispatch($token, static function () use (&$heldAtCancel, $rig): void {
            $heldAtCancel = $rig->call(R::succeeding());   // control: the single permit must be held at this instant
        });
        $context = $rig->context(30.0, null, $token);
        $completion = $rig->pipeline->executeOperationDetailed($operation, $context);
        $fresh = $rig->call(R::succeeding());
        // Capacity is exactly 1 again: park a fresh caller, a second one must be refused (so nothing leaked AND nothing was inflated).
        $entered = 0;
        $holder = $rig->park($entered);
        $second = $rig->call(R::succeeding());
        $data = ['disposition' => $completion->disposition->value, 'permit_held_at_cancel_instant' => $heldAtCancel === 'rejected:concurrency_capacity_exhausted', 'dispatched' => $operation->dispatched, 'summary_dispatched' => $completion->summary->dispatched, 'admittedNotDispatched' => $completion->summary->admittedNotDispatched, 'fresh_caller_after_cancel' => $fresh, 'second_caller_while_one_runs' => $second];
        unset($holder);
        $obs = 'bulkhead(1, lease 60s); the caller\'s own CancellationToken is cancelled from PreparedAttempt::verifyReplay() (after the permit is held, before dispatch): ' . json_encode($data);
        $ok = $heldAtCancel === 'rejected:concurrency_capacity_exhausted' && $completion->disposition->value === 'cancelled' && $operation->dispatched === 0 && $fresh === 'ok' && $second === 'rejected:concurrency_capacity_exhausted';

        return $ok ? Result::pass($obs, $data) : Result::fail($obs, 'behaviour-defect', $data);
    }

    protected function s14(): Result
    {
        $data = [];
        foreach (['Exception' => new \RuntimeException('x'), 'Error' => new \Error('y')] as $label => $throwable) {
            $rig = R::make(self::base()->bulkhead(1, Duration::seconds(60)));
            [, $e] = $rig->run(static function (AttemptContext $a) use ($throwable): never {
                throw $throwable;
            });
            $data[$label] = ['propagated_unchanged' => $e === $throwable, 'next_caller' => $rig->call(R::succeeding())];
        }
        $obs = 'after the callback throws, is the original throwable propagated and the next caller admitted? ' . json_encode($data);
        $ok = $data['Exception']['next_caller'] === 'ok' && $data['Error']['next_caller'] === 'ok';

        return $ok ? Result::pass($obs, $data) : Result::fail($obs, 'behaviour-defect', $data);
    }

    // ------------------------------------------------------------------ S15 quota accounting

    protected function s15(): Result
    {
        $capacity = 1000;
        $builder = fn (): PipelineBuilder => self::withRetry(self::base(), 5, 0.1)->rateLimit(PipelineBuilder::tokenBucket($capacity, 1, Duration::seconds(3600)));
        $consumed = function (R $rig) use ($capacity): int {
            $policy = $rig->definition->rateLimit;
            $backend = $rig->env->rateLimit;

            return $capacity - $backend->inspect($policy, new PositiveInt(1))->available->value;
        };
        $twoFailures = static fn (int $n) => $n <= 2 ? new WireStatus(503) : null;

        // 1. Native composition: one pipeline holding both retry and rate limit.
        $rig = R::make($builder(), R::httpClassifier());
        $wire = new ReqtideWire($rig, $twoFailures);
        $rig->run($wire, 60.0, $rig->readOnly());
        $native = ['consumed' => $consumed($rig), 'sent' => \count($wire->sent)];

        // 2/3. User-built nesting of two single-purpose pipelines (the other "orders"; not the library's native path).
        $nested = function (bool $rateOuter) use ($capacity): array {
            $rate = R::make(self::base()->rateLimit(PipelineBuilder::tokenBucket($capacity, 1, Duration::seconds(3600))), R::httpClassifier());
            $retry = R::make(self::withRetry(self::base(), 5, 0.1), R::httpClassifier());
            // Same virtual time for both: use the retry rig's clock as the shared one is not needed (delays are tiny); consumption is count based.
            $wire = new ReqtideWire($retry, static fn (int $n) => $n <= 2 ? new WireStatus(503) : null);
            if ($rateOuter) {
                $rate->run(static fn (AttemptContext $a): string => (string) $retry->run($wire, 60.0, $retry->readOnly())[0]);
            } else {
                $retry->run(static fn (AttemptContext $a): string => $rate->call($wire) === 'ok' ? 'ok' : throw new UpstreamFailure('503'), 60.0, $retry->readOnly());
            }
            $policy = $rate->definition->rateLimit;

            return ['consumed' => $capacity - $rate->env->rateLimit->inspect($policy, new PositiveInt(1))->available->value, 'sent' => \count($wire->sent)];
        };
        $rateOuter = $nested(true);

        // 4. Cancelled after admission (rate debited) and before the request is sent.
        $token = new CancellationToken();
        $rig2 = R::make($builder(), R::httpClassifier());
        $operation = new ReqtideCancelBeforeDispatch($token);
        $completion = $rig2->pipeline->executeOperationDetailed($operation, $rig2->context(30.0, $rig2->readOnly(), $token));
        $cancel = ['consumed' => $consumed($rig2), 'sent' => $operation->dispatched, 'disposition' => $completion->disposition->value, 'admittedNotDispatched' => $completion->summary->admittedNotDispatched];

        $retriesOk = $native['consumed'] === $native['sent'];
        $cancelOk = $cancel['consumed'] === $cancel['sent'];
        $data = ['native_single_pipeline' => $native, 'user_nested_rate_outer_of_retry' => $rateOuter, 'cancel_before_send' => $cancel];
        $obs = sprintf('One GET fails twice (503) then succeeds. Native composition (retry + rateLimit in one pipeline; rate is debited for every dispatch opportunity): consumed %d, sent %d. Informational, user-built nesting of two single-purpose pipelines with the rate limiter OUTSIDE the retry: consumed %d, sent %d (the same composition-order hazard the competitors show, reachable only by hand). Cancel-before-send (token cancelled after admission and rate debit, before dispatch): consumed %d, sent %d, disposition %s, admittedNotDispatched %d - quota is NOT refunded by design (a transport failure never refunds it, and quota may remain consumed after a cancel before dispatch). Every attempt is charged.', $native['consumed'], $native['sent'], $rateOuter['consumed'], $rateOuter['sent'], $cancel['consumed'], $cancel['sent'], $cancel['disposition'], $cancel['admittedNotDispatched']);

        if (!$retriesOk) {
            return Result::fail($obs, 'behaviour-defect', $data);
        }

        return $cancelOk ? Result::pass($obs, $data) : Result::fail($obs, 'missing-capability', $data);
    }

    // ------------------------------------------------------------------ S16 taxonomy

    protected function s16(): Result
    {
        $facts = [];
        $describe = static function (\Throwable $e): array {
            $row = ['class' => $e::class];
            if (method_exists($e, 'reason')) {
                $row['reason_code'] = $e->reason()->value;
            }
            if (isset($e->decision)) {
                $row['retry_after_seconds'] = $e->decision->retryAfter === null ? null : $e->decision->retryAfter->nanoseconds / 1e9;
                $row['status'] = $e->decision->status->value;
            }
            if ($e instanceof \Enaxon\Reqtide\Error\ProblemCarrier) {
                $row['problem_retryability'] = $e->problem()->retryability()->value;
            }

            return $row;
        };

        $rig = R::make(self::base()->rateLimit(PipelineBuilder::tokenBucket(1, 1, Duration::seconds(10))));
        $rig->call(R::succeeding());
        $facts['rate_limited'] = $describe($rig->run(R::succeeding())[1]);

        $rig = R::make(self::withCircuit(self::base(), 1));
        $rig->openCircuit();
        $facts['circuit_open'] = $describe($rig->run(R::succeeding())[1]);

        $rig = R::make(self::base()->bulkhead(1, Duration::seconds(60)));
        $entered = 0;
        $holder = $rig->park($entered);
        $facts['bulkhead_full'] = $describe($rig->run(R::succeeding())[1]);
        unset($holder);

        $rig = R::make(self::base());
        $facts['deadline_exceeded'] = $describe($rig->run(static function (AttemptContext $a) use ($rig): string {
            $rig->clock->advance(Duration::seconds(5));

            return 'late';
        }, 1.0)[1]);

        $rig = R::make(self::withRetry(self::base(), 3, 0.0, 0.0), R::httpClassifier());
        $context = $rig->context(30.0, $rig->readOnly());
        $completion = $rig->pipeline->executeDetailed(R::failing(), $context);
        $simple = $rig->run(R::failing(), 30.0, $rig->readOnly())[1];
        $facts['retries_exhausted'] = ['detailed_mode' => ['disposition' => $completion->disposition->value, 'problem_code' => $completion->problem()?->code->value, 'attempts' => \count($completion->attempts)], 'simple_mode_exception' => $simple === null ? null : $simple::class, 'typed_in_simple_mode' => $simple instanceof \Enaxon\Reqtide\Exception\ReqtideException];

        $keys = [];
        foreach (['rate_limited', 'circuit_open', 'bulkhead_full', 'deadline_exceeded'] as $k) {
            $keys[] = ($facts[$k]['class'] ?? '?') . '|' . ($facts[$k]['reason_code'] ?? '?');
        }
        $keys[] = 'detailed|' . ($facts['retries_exhausted']['detailed_mode']['problem_code'] ?? '?');
        $distinct = \count(array_unique($keys)) === 5 && !\in_array('detailed|?', $keys, true);
        $retryAt = ($facts['rate_limited']['retry_after_seconds'] ?? null) !== null && ($facts['circuit_open']['retry_after_seconds'] ?? null) !== null;
        $obs = 'Five causes provoked. Distinct (class, ReasonCode enum) pairs: ' . ($distinct ? 'yes' : 'NO') . '; rate-limit and circuit-open rejections carry a retry-after: ' . ($retryAt ? 'yes' : 'NO') . '. Facts: ' . json_encode($facts) . '. Limitation: retries-exhausted is machine-readable only through executeDetailed() (problem()->code / diagnostics); the throwing execute() returns the original business exception unchanged (by design: business exceptions are never wrapped).';

        return $distinct && $retryAt ? Result::pass($obs, $facts) : Result::fail($obs, 'missing-capability', $facts);
    }

    // ------------------------------------------------------------------ S17 clock regression

    protected function s17(): Result
    {
        $cases = [
            'token_bucket' => [fn (): R => R::make(self::base()->rateLimit(PipelineBuilder::tokenBucket(1, 1, Duration::seconds(10)))), 10, fn (R $r) => $r->call(R::succeeding())],
            'fixed_window_aligned' => [fn (): R => R::make(self::base()->rateLimit(PipelineBuilder::fixedWindow(1, Duration::seconds(10), FixedWindowAnchor::Aligned))), 10, fn (R $r) => $r->call(R::succeeding())],
            'circuit_breaker' => [fn (): R => R::make(self::withCircuit(self::base(), 1)), 30, fn (R $r) => $r->call(R::succeeding())],
        ];
        $data = [];
        $pass = true;
        foreach ($cases as $name => [$mk, $interval, $probe]) {
            $rig = $mk();
            if ($name === 'circuit_breaker') {
                $rig->openCircuit();
            } else {
                $rig->call(R::succeeding());             // consume the only unit
                if ($rig->call(R::succeeding()) === 'ok') {
                    throw new \RuntimeException("$name: limiter not exhausted");
                }
            }
            $rig->clock->setWall($rig->clock->wallNow()->modify('-3600 seconds'));  // wall jumps BACK 3600s, monotonic unchanged
            $over = $probe($rig) === 'ok';
            $elapsed = 0.0;
            $recovered = null;
            while ($elapsed < 4000.0 && !$over) {
                $rig->clock->advance(Duration::seconds(1));
                $elapsed += 1.0;
                if ($probe($rig) === 'ok') {
                    $recovered = $elapsed;
                    break;
                }
            }
            $data[$name] = ['over_admitted_at_jump' => $over, 'recovered_after_elapsed_seconds' => $recovered, 'interval' => $interval];
            $pass = $pass && !$over && $recovered !== null && $recovered <= 2 * $interval;
        }
        $obs = 'Exhaust at T, wall clock set back 3600s (VirtualClock::setWall, monotonic untouched), then 1s steps that advance both domains: ' . json_encode($data) . '. The memory backends measure with the monotonic clock; the wall clock is read once to place Aligned window boundaries.';

        return $pass ? Result::pass($obs, $data) : Result::fail($obs, 'wall-clock-dependence', $data);
    }

    // ------------------------------------------------------------------ S18 observer failure

    protected function s18(): Result
    {
        $retryRun = function (bool $throwing): array {
            $sink = new RecordingEventSink();
            if ($throwing) {
                $sink->throwOnEmit(new \RuntimeException('observer failed'));
            }
            $rig = R::make(self::withRetry(self::base(), 3, 0.0, 0.0), R::httpClassifier(), $sink);
            $i = 0;
            $context = $rig->context(30.0, $rig->readOnly());
            $completion = $rig->pipeline->executeDetailed(static function (AttemptContext $a) use (&$i): string {
                return ++$i === 1 ? throw new WireStatus(503) : 'business-value';
            }, $context);
            $value = $completion->terminalOutcome?->kind->value === 'value' ? $completion->terminalOutcome->value() : null;

            return ['result' => $value, 'disposition' => $completion->disposition->value, 'attempts' => $i, 'events_seen_by_sink' => $sink->count(), 'accounting_complete' => $completion->summary->accountingComplete];
        };
        $circuitRun = function (bool $throwing): array {
            $sink = new RecordingEventSink();
            if ($throwing) {
                $sink->throwOnEmit(new \RuntimeException('observer failed'));
            }
            $rig = R::make(self::withCircuit(self::base(), 1), null, $sink);
            $labels = $rig->sequence([true, true, true, true, true]);

            return ['labels' => $labels, 'transition_events_seen' => \count($sink->ofType(CircuitTransitioned::class))];
        };
        $control = ['retry' => $retryRun(false), 'circuit' => $circuitRun(false)];
        $thrown = ['retry' => $retryRun(true), 'circuit' => $circuitRun(true)];
        $sameRetry = $thrown['retry']['result'] === $control['retry']['result'] && $thrown['retry']['disposition'] === $control['retry']['disposition'] && $thrown['retry']['attempts'] === $control['retry']['attempts'];
        $sameCircuit = $thrown['circuit']['labels'] === $control['circuit']['labels'];
        $data = ['control_without_throwing_observer' => $control, 'throwing_observer' => $thrown];
        $obs = 'RecordingEventSink that throws on every emit (after recording). retry-once-then-succeed and 4-failure circuit trip: ' . json_encode($data) . '. Business result identical: retry ' . ($sameRetry ? 'yes' : 'NO') . ', circuit ' . ($sameCircuit ? 'yes' : 'NO') . '. Effect on the caller is limited to summary.accountingComplete=false (a bounded diagnostic), never the result (OperationRun::emit()/complete() catch sink failures).';

        return $sameRetry && $sameCircuit ? Result::pass($obs, $data) : Result::fail($obs, 'behaviour-defect', $data);
    }

    // ------------------------------------------------------------------ S19-S21 static

    protected function s19(): Result
    {
        return $this->manifestMetric('S19');
    }

    protected function s20(): Result
    {
        return $this->manifestMetric('S20');
    }

    private function manifestMetric(string $id): Result
    {
        $root = dirname(__DIR__, 3);
        $file = $root . '/composer.json';
        if (!is_file($file)) {
            return new Result(\Comparison\Status::NotRun, 'composer.json not found.');
        }
        $m = json_decode((string) file_get_contents($file), true);
        $req = $m['require'] ?? [];
        $runtime = array_filter(array_keys($req), static fn (string $n): bool => $n !== 'php' && !str_starts_with($n, 'ext-') && !str_starts_with($n, 'lib-') && $n !== 'php-64bit');
        if ($id === 'S19') {
            // Corroborate the manifest with a source scan: every `use` import in src that is not Enaxon\Reqtide.
            $foreign = [];
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->getExtension() !== 'php') {
                    continue;
                }
                if (preg_match_all('/^use (?!function |const )([A-Za-z_\\\\]+)(?: as \w+)?;/m', (string) file_get_contents($f->getPathname()), $mm)) {
                    foreach ($mm[1] as $import) {
                        if (!str_starts_with($import, 'Enaxon\\Reqtide\\')) {
                            $foreign[$import] = true;
                        }
                    }
                }
            }
            ksort($foreign);

            return Result::info(sprintf('manifest-derived (composer.json "require", not an installed resolution): %d package dependencies%s. Source scan of src: imports outside Enaxon\\Reqtide: %s.', \count($runtime), $runtime ? ': ' . implode(', ', $runtime) : '', $foreign ? implode(', ', array_keys($foreign)) : '(none)'), ['require' => $req, 'foreign_imports' => array_keys($foreign)]);
        }

        return Result::info(sprintf('manifest-derived: php "%s"', $req['php'] ?? 'none declared'), ['require' => $req]);
    }

    protected function s21(): Result
    {
        $root = dirname(__DIR__, 3);
        $vendor = getenv('COMPARISON_VENDOR_DIR') ?: $root . '/build/comparison/vendor';
        $dir = sys_get_temp_dir() . '/cmp-phpstan-reqtide-' . getmypid();
        @mkdir($dir);
        $probe = $dir . '/probe.php';
        $bootstrap = $dir . '/bootstrap.php';
        file_put_contents($bootstrap, "<?php\nrequire " . var_export($vendor . '/autoload.php', true) . ";\nrequire_once " . var_export($root . '/benchmarks/comparison/src/ReqtideRig.php', true) . ";\n\\Comparison\\ReqtideRig::boot();\n");
        // Two probes: (1) the same literal-bodied callable used for the other libraries, (2) a callable whose int comes from an opaque source,
        // because PHPStan folds `fn (): int => 1` to the constant type 1 (a harness artifact, not a library property).
        file_put_contents($probe, "<?php\ndeclare(strict_types=1);\nuse Enaxon\\Reqtide\\Execution\\AttemptContext;\nuse Enaxon\\Reqtide\\Pipeline\\CompiledPipeline;\nuse Enaxon\\Reqtide\\Value\\Duration;\nfunction opaque(): int\n{\n    return (int) \$_SERVER['argc'];\n}\nfunction probe(CompiledPipeline \$pipeline): void\n{\n    \$literal = \$pipeline->execute(fn (AttemptContext \$a): int => 1, \$pipeline->newContext(Duration::seconds(5)));\n    \\PHPStan\\dumpType(\$literal);\n    \$opaque = \$pipeline->execute(fn (AttemptContext \$a): int => opaque(), \$pipeline->newContext(Duration::seconds(5)));\n    \\PHPStan\\dumpType(\$opaque);\n}\n");
        $cmd = [PHP_BINARY, '-d', 'memory_limit=1G', $vendor . '/phpstan/phpstan/phpstan', 'analyse', '--level=max', '--no-progress', '--error-format=json', '--autoload-file=' . $bootstrap, $probe];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir, ['TMPDIR' => $dir, 'PATH' => getenv('PATH') ?: '']);
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
        $dumps = [];
        foreach ($msgs as $m) {
            if (preg_match('/Dumped type: (.+)$/', $m, $mm)) {
                $dumps[] = $mm[1];
            }
        }
        [$literal, $dump] = [$dumps[0] ?? null, $dumps[1] ?? null];
        $templates = (int) trim((string) shell_exec('grep -rE "@template" ' . escapeshellarg($root . '/src') . ' | wc -l'));
        $data = ['phpstan_level' => 'max', 'inferred_type_opaque_int_callable' => $dump, 'inferred_type_literal_callable' => $literal, '@template_annotations_in_core_src' => $templates, 'phpstan_version' => self::version('phpstan/phpstan'), 'stderr' => trim((string) $err), 'other_messages' => array_values(array_filter($msgs, static fn (string $m): bool => !str_starts_with($m, 'Dumped type')))];
        $obs = 'PHPStan max: $r = $pipeline->execute(fn (AttemptContext $a): int => opaque(), $pipeline->newContext(Duration::seconds(5))) is inferred as "' . ($dump ?? 'UNKNOWN (probe failed: ' . trim((string) $err . ' ' . implode(' | ', $msgs)) . ')') . '". The literal-bodied callable `fn (): int => 1` (the form used for the other libraries) is inferred as "' . ($literal ?? 'UNKNOWN') . '" because PHPStan folds the constant; graded on the opaque-int callable so the expected type `int` is meaningful. ' . $templates . ' @template annotations in src (execute() is declared "@template T @param callable(AttemptContext):T $operation @return T"; newContext() returns ExecutionContext<never> so the callable alone fixes T).';
        @unlink($probe);
        @unlink($bootstrap);
        @rmdir($dir);
        if ($dump === null || $literal === null) {
            throw new \RuntimeException('PHPStan probe produced no dumpType result: ' . $obs);
        }

        return $dump === 'int' ? Result::pass($obs, $data) : Result::fail($obs, 'missing-capability', $data);
    }
}
