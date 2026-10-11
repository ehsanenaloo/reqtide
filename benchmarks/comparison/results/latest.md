# Competitor conformance results

Generated 2026-10-09T06:33:57+00:00 on PHP 8.4.26 (Linux 6.18.40.1-microsoft-standard-WSL2). Raw: latest.json.

## Matrix

| # | Scenario | Reqtide | sf/rate-limiter | sf/http-client | caseyamcl retry | spatie rate | ganesha | duat |
|---|---|---|---|---|---|---|---|---|
| S01 | Total deadline spans retries and waits | PASS | N/A | FAIL (missing-capability) | FAIL (behaviour-defect) | N/A | N/A | PASS |
| S02 | Retry-After larger than remaining deadline | PASS | N/A | FAIL (missing-capability) | PASS | N/A | N/A | NOT_EXPR |
| S03 | Retry-After honoured as a lower bound | PASS | N/A | PASS | PASS | N/A | N/A | NOT_EXPR |
| S04 | Retry budget bounds retry storms | PASS | N/A | FAIL (missing-capability) | FAIL (missing-capability) | N/A | N/A | PASS |
| S05 | Non-idempotent POST is not retried by default | PASS | N/A | PASS | PASS | N/A | N/A | N/A |
| S06 | Token bucket exact boundary and refill remainder | PASS | PASS | N/A | N/A | N/A | N/A | N/A |
| S07 | Fixed/sliding window boundary: no early admission | PASS | PASS | N/A | N/A | PASS | N/A | N/A |
| S08 | Circuit opens exactly at threshold and minimum samples | PASS | N/A | N/A | N/A | N/A | PASS | PASS |
| S09 | Half-open admits only the configured probes | PASS | N/A | N/A | N/A | N/A | PASS | PASS |
| S10 | An abandoned probe neither closes the circuit nor wedges it forever | PASS | N/A | N/A | N/A | N/A | PASS | PASS |
| S11 | A stale result after reopen cannot change breaker state | PASS | N/A | N/A | N/A | N/A | FAIL (missing-capability) | FAIL (behaviour-defect) |
| S12 | Late/non-owner release cannot free someone else's permit | PASS | N/A | N/A | N/A | N/A | N/A | FAIL (behaviour-defect) |
| S13 | Cancel before dispatch leaves no leaked capacity | PASS | N/A | N/A | N/A | N/A | N/A | NOT_EXPR |
| S14 | Permit released when the callback throws | PASS | N/A | N/A | N/A | N/A | N/A | PASS |
| S15 | Rate quota consumed equals requests actually sent | FAIL (missing-capability) | N/A | FAIL (composition-dependent) | N/A | FAIL (composition-dependent) | N/A | N/A |
| S16 | Typed, machine-readable rejection taxonomy | PASS | PASS | FAIL (missing-capability) | FAIL (missing-capability) | N/A | FAIL (missing-capability) | FAIL (missing-capability) |
| S17 | Wall-clock regression safety | PASS | FAIL (wall-clock-dependence) | N/A | N/A | FAIL (wall-clock-dependence) | FAIL (wall-clock-dependence) | FAIL (wall-clock-dependence) |
| S18 | Throwing observer does not change the business result | PASS | N/A | FAIL (behaviour-defect) | N/A | N/A | FAIL (behaviour-defect) | FAIL (behaviour-defect) |
| S19 | Runtime dependency count (transitive) | INFO | INFO | INFO | INFO | INFO | INFO | INFO |
| S20 | PHP floor | INFO | INFO | INFO | INFO | INFO | INFO | INFO |
| S21 | Static analysis preserves the callback return type (generics) | PASS | N/A | N/A | N/A | N/A | N/A | FAIL (missing-capability) |

## Summary per library

| Library | PASS | FAIL | N/A | NOT_EXPR | INFO | NOT_RUN | ERROR |
|---|---|---|---|---|---|---|---|
| Reqtide | 18 | 1 | 0 | 0 | 2 | 0 | 0 |
| sf/rate-limiter | 3 | 1 | 15 | 0 | 2 | 0 | 0 |
| sf/http-client | 2 | 6 | 11 | 0 | 2 | 0 | 0 |
| caseyamcl retry | 3 | 3 | 13 | 0 | 2 | 0 | 0 |
| spatie rate | 1 | 2 | 16 | 0 | 2 | 0 | 0 |
| ganesha | 3 | 4 | 12 | 0 | 2 | 0 | 0 |
| duat | 6 | 6 | 4 | 3 | 2 | 0 | 0 |

## Observations (what actually happened)

### S01 Total deadline spans retries and waits

- **Reqtide** PASS: Deadline 10s (newContext timeout), retry(maxAttempts=20, constant 4s, no jitter) + retryBudget(capacity 1000), every attempt 503: attempt start times [0,4,8], gave up at 8s with fail (the original 503 exception); 0 attempt(s) at or after D. The retry loop declines a wait that does not fit the remaining deadline (code read, not asserted here: OperationRun::decideRetry, reason retry_delay_exceeds_deadline). Control: with a 1000s deadline the same pipeline makes 20 attempts (the count limit), so the 10s run was stopped by time alone.
- **sf/rate-limiter** NOT_APPLICABLE: Library is a rate limiter only: it has no retry, deadline, circuit breaker, bulkhead or event facilities, so this scenario does not apply.
- **sf/http-client** FAIL (missing-capability): attempt start times [0,4,8,12,16,20,24,28,32,36,40]; returned at 40s with 503. 8 attempts started at or after D=10s. No total-deadline option exists: only max_retries (count) and per-request max_duration/timeout; each retry issues a new request, so a per-request limit does not span attempts (code read: RetryableHttpClient::request -> replaceRequest(same options); not executed because MockHttpClient does not enforce max_duration).
- **caseyamcl retry** FAIL (behaviour-defect): give_up_after_secs=10, constant 4s delay: attempt start times [0,4,8,10], returned at 10s with 503; 1 attempt(s) started at or after D (the last wait appears to be clipped from 4s to the remaining 2s by determineDelayTimeout(), so the final attempt lands exactly on the deadline; borderline: the library works in whole seconds and checks request_timestamp < first + give_up_after_secs only for attempts after that one).
- **spatie rate** NOT_APPLICABLE: Library only delays requests to a per-second/per-minute limit: it has no retry, deadline, circuit breaker, bulkhead, events or typed rejections, so this scenario does not apply.
- **ganesha** NOT_APPLICABLE: Library is a circuit-breaker state tracker only: it has no retry, rate limiting, deadline, bulkhead or permit lifecycle, so this scenario does not apply.
- **duat** PASS: timeout(10)->retry(20, constant 4s): attempt start times [0,4,8], gave up at 8s with RuntimeException; 0 attempt(s) at or after D. RetryPolicy skips a wait when delay >= Context::remainingBudget().

### S02 Retry-After larger than remaining deadline

- **Reqtide** PASS: Deadline 10s, Retry-After 30 on the first 503, retry(20, constant 2s): requests=1 at [0], returned at 0s as deferred:retry_after_exceeds_deadline (wait 30s). The hint is read through ExceptionClassifier::retryAfterFrom(); the retry is converted into a typed deferral carrying the wait and the original 503 as previous.
- **sf/rate-limiter** NOT_APPLICABLE: Library is a rate limiter only: it has no retry, deadline, circuit breaker, bulkhead or event facilities, so this scenario does not apply.
- **sf/http-client** FAIL (missing-capability): requests=2, send times [0,30], returned at 30s (200). Retry-After honoured exactly (never early: yes) but waited past D=10s because no deadline can be set.
- **caseyamcl retry** PASS: give_up_after_secs=10, Retry-After 30: requests=1 at [0], returned at 0s with 503.
- **spatie rate** NOT_APPLICABLE: Library only delays requests to a per-second/per-minute limit: it has no retry, deadline, circuit breaker, bulkhead, events or typed rejections, so this scenario does not apply.
- **ganesha** NOT_APPLICABLE: Library is a circuit-breaker state tracker only: it has no retry, rate limiting, deadline, bulkhead or permit lifecycle, so this scenario does not apply.
- **duat** NOT_EXPRESSIBLE: No Retry-After support: Backoff::delayMs(int $attempt, Randomizer $randomizer) receives neither the exception nor any response, and the onRetry hook returns void. A user-written Backoff subclass sharing mutable state with the callback can emulate it; that glue (not library code) gave: requests at [0], returned at 0s.

### S03 Retry-After honoured as a lower bound

- **Reqtide** PASS: Deadline 120s. primary: Retry-After 6 vs constant backoff 2 -> gap 8s. Informational: Retry-After 1 vs backoff 4 -> gap 5s. Reqtide ADDS the backoff to the server hint (scheduled = min(maximumDelay, retryAfter + backoff)) instead of taking the larger or replacing it, so the hint is always a lower bound (code: OperationRun::decideRetry, "scheduled = bound + jittered"). maximumDelay is 60s here; a hint above maximumDelay becomes a deferral (reason retry_after_exceeds_maximum_delay).
- **sf/rate-limiter** NOT_APPLICABLE: Library is a rate limiter only: it has no retry, deadline, circuit breaker, bulkhead or event facilities, so this scenario does not apply.
- **sf/http-client** PASS: primary: Retry-After 6 vs backoff 2 -> gap 6s. Informational: Retry-After 1 vs backoff 4 -> gap 1s (header REPLACES the configured backoff; RetryableHttpClient uses getDelayFromHeader() ?? strategy->getDelay()).
- **caseyamcl retry** PASS: primary: Retry-After 6 vs backoff 2 -> gap 6s. Informational: Retry-After 1 vs backoff 4 -> gap 1s (the header replaces the computed delay in determineDelayTimeout()).
- **spatie rate** NOT_APPLICABLE: Library only delays requests to a per-second/per-minute limit: it has no retry, deadline, circuit breaker, bulkhead, events or typed rejections, so this scenario does not apply.
- **ganesha** NOT_APPLICABLE: Library is a circuit-breaker state tracker only: it has no retry, rate limiting, deadline, bulkhead or permit lifecycle, so this scenario does not apply.
- **duat** NOT_EXPRESSIBLE: No Retry-After concept: Backoff::delayMs(int $attempt, Randomizer $randomizer) cannot see the exception or response (see S02). The library parses no headers and has no HTTP awareness.

### S04 Retry budget bounds retry storms

- **Reqtide** PASS: 1000 failing operations, maxAttempts=4 (3 retries each), delays 0. ADAPTATION: retryBudget is mandatory and has no default; it is a token bucket (absolute tokens), not a ratio of first attempts. Sized to 10% of the 1000 first attempts (capacity 100, refill 100 per 60s, no virtual time passes) following the SRE ~10% guidance cited by the scenario: 1100 wire requests, extra 100 (10%). Sensitivity (same code, bigger pool): capacity 1000 -> extra 1000 (100%); capacity 3000 -> extra 3000 (300%). The bound is the configured pool; Reqtide cannot express "20% of first attempts" as a ratio, and an over-sized pool gives no protection.
- **sf/rate-limiter** NOT_APPLICABLE: Library is a rate limiter only: it has no retry, deadline, circuit breaker, bulkhead or event facilities, so this scenario does not apply.
- **sf/http-client** FAIL (missing-capability): 1000 failing ops, max_retries=3: 4000 wire requests, extra 3000 (300% of first attempts). No retry budget / shared limit across operations exists in RetryableHttpClient or GenericRetryStrategy.
- **caseyamcl retry** FAIL (missing-capability): 1000 failing ops, max_retry_attempts=3, zero delay: 4000 wire requests, extra 3000 (300% of first attempts). No cross-operation retry budget exists; limits are per request (max_retry_attempts, give_up_after_secs).
- **spatie rate** NOT_APPLICABLE: Library only delays requests to a per-second/per-minute limit: it has no retry, deadline, circuit breaker, bulkhead, events or typed rejections, so this scenario does not apply.
- **ganesha** NOT_APPLICABLE: Library is a circuit-breaker state tracker only: it has no retry, rate limiting, deadline, bulkhead or permit lifecycle, so this scenario does not apply.
- **duat** PASS: retry(4 attempts) only: 4000 wire requests, extra 3000 (300%). With circuit breaker stacked inside the retry (min 10 calls, 50%): 10 wire requests, 998 of 1000 operations rejected without any request. Bounded only because the breaker stops primary traffic too; there is no retry budget (shared cap on retries while first attempts continue).

### S05 Non-idempotent POST is not retried by default

- **Reqtide** PASS: Default configuration (no retry() call => maxAttempts 1): requests {"a_post_500":1,"b_post_transport_error":1,"c_post_503":1,"d_post_429":1}. ADAPTATION NOTE: Reqtide has no default retry and no HTTP-method awareness, so this row is trivially satisfied by the default. Opt-in retry(3) with the default context (OperationSafety::unsafe()): 0 request(s), refused before dispatch with Enaxon\Reqtide\Exception\InvalidConfigurationException ("maxAttempts greater than one requires an explicit replay-safety contract"). Control: the same opt-in pipeline retries a GET the caller asserts repeatable (ReplaySafetyContract::forReadOnlyCallable): 3 requests. Reqtide never verifies the assertion, so a caller can still wrongly assert a POST.
- **sf/rate-limiter** NOT_APPLICABLE: Library is a rate limiter only: it has no retry, deadline, circuit breaker, bulkhead or event facilities, so this scenario does not apply.
- **sf/http-client** PASS: requests sent with default GenericRetryStrategy statuses (retry delays zeroed): {"a_post_500":1,"b_post_transport_error":1,"c_post_503":4,"d_post_429":4,"control_get_500":4} (default list retries 500/504/507/510 and transport errors only for idempotent methods; 423/425/429/502/503 for any method).
- **caseyamcl retry** PASS: requests sent with library-default retry_on_status (429,503), retry_on_methods ("*": every method) and max_retry_attempts (10), delay zeroed: {"a_post_500":1,"b_post_transport_error":1,"c_post_503":11,"d_post_429":11,"info_non_default_post_500_with_retry_on_status_incl_500":11}. POST is retried on 429/503 because retry_on_methods defaults to all methods; a POST 500 is not retried only because 500 is not in the default status list.
- **spatie rate** NOT_APPLICABLE: Library only delays requests to a per-second/per-minute limit: it has no retry, deadline, circuit breaker, bulkhead, events or typed rejections, so this scenario does not apply.
- **ganesha** NOT_APPLICABLE: Library is a circuit-breaker state tracker only: it has no retry, rate limiting, deadline, bulkhead or permit lifecycle, so this scenario does not apply.
- **duat** NOT_APPLICABLE: Library is protocol-agnostic (wraps arbitrary callables): there is no HTTP method or idempotency notion to default on. Note: retry() defaults to retryOn=[Throwable::class], i.e. every failure of any wrapped callable is retried when retry() is added.

### S06 Token bucket exact boundary and refill remainder

- **Reqtide** PASS: tokenBucket(capacity 2, refill 1 token per 2s), rateLimit cost 1, bucket starts full, one execute() per probe (admitted = callable ran): decisions {"t0.a":true,"t0.b":true,"t0.c(3rd)":false,"t1.999":false,"t2.0":true,"t2.0(again)":false,"t5.0":true,"t5.0(again)":false,"t5.999":false,"t6.0":true}
- **sf/rate-limiter** PASS: decisions {"t0.a":true,"t0.b":true,"t0.c(3rd)":false,"t1.999":false,"t2.0":true,"t2.0(again)":false,"t5.0":true,"t5.0(again)":false,"t5.999":false,"t6.0":true}
- **sf/http-client** NOT_APPLICABLE: Rate algorithms live in symfony/rate-limiter (tested directly in that adapter); ThrottlingHttpClient only calls reserve().
- **caseyamcl retry** NOT_APPLICABLE: Library is a retry middleware only: it has no rate limiter, circuit breaker or bulkhead, so this scenario does not apply.
- **spatie rate** NOT_APPLICABLE: Algorithm is a sliding log of request timestamps (RateLimiter::delayUntilNextRequest), not a token bucket: there is no burst capacity or fractional refill to test.
- **ganesha** NOT_APPLICABLE: Library is a circuit-breaker state tracker only: it has no retry, rate limiting, deadline, bulkhead or permit lifecycle, so this scenario does not apply.
- **duat** NOT_APPLICABLE: Library wraps callables with retry/breaker/timeout/bulkhead/fallback; it has no rate limiter (README: "planned") or HTTP awareness, so this scenario does not apply.

### S07 Fixed/sliding window boundary: no early admission

- **Reqtide** PASS: limit 3 per W=10s, 3 admitted at t0 (t0 is on a 10s wall boundary so Aligned and FirstHit coincide), 4th request (a fresh limiter per probe) admitted? {"fixed_window_aligned":{"t0+W-0.001":false,"t0+W":true,"t0+W+0.001":true},"fixed_window_first_hit":{"t0+W-0.001":false,"t0+W":true,"t0+W+0.001":true},"sliding_counter":{"t0+W-0.001":false,"t0+W":false,"t0+W+0.001":false,"first_admission_seconds_after_t0_0.5s_steps":13.5}}. Verdict on the two FIXED-window anchors (the scenario names fixed/sliding window boundary); the approximate sliding counter is reported only as data: it weights the previous window, so its 4th request is not admitted at t0+W+0.001 (first admission seen at about +13.5s). That is safe (never early) but would miss the scenario's "within 1ms" liveness clause, and it is a different algorithm from the exact sliding log some competitors use, which is why it is not the graded one.
- **sf/rate-limiter** PASS: 4th request accepted? {"t0+W-0.001":false,"t0+W":true,"t0+W+0.001":true}
- **sf/http-client** NOT_APPLICABLE: Rate algorithms live in symfony/rate-limiter (tested directly in that adapter).
- **caseyamcl retry** NOT_APPLICABLE: Library is a retry middleware only: it has no rate limiter, circuit breaker or bulkhead, so this scenario does not apply.
- **spatie rate** PASS: 3 requests at t0=0 (limit 3/s), 4th requested at the labelled instant; time it reached the wire: {"t0+W-0.001":{"4th_reached_wire_at":1},"t0+W":{"4th_reached_wire_at":1},"t0+W+0.001":{"4th_reached_wire_at":1.000999927520752}} (the middleware sleeps instead of rejecting).
- **ganesha** NOT_APPLICABLE: Library is a circuit-breaker state tracker only: it has no retry, rate limiting, deadline, bulkhead or permit lifecycle, so this scenario does not apply.
- **duat** NOT_APPLICABLE: Library wraps callables with retry/breaker/timeout/bulkhead/fallback; it has no rate limiter (README: "planned") or HTTP awareness, so this scenario does not apply.

### S08 Circuit opens exactly at threshold and minimum samples

- **Reqtide** PASS: circuitBreaker(lastCalls(10), minimumSamples 4, Ratio 1/2, open 30s, probes 1, lease 60s): {"seq1_FFFF_then_5th":["fail","fail","fail","fail","rejected:circuit_open"],"seq2_SSFF_then_5th":["ok","ok","fail","fail","rejected:circuit_open"],"seq3_SSSF_then_5th":["ok","ok","ok","fail","ok"]}
- **sf/rate-limiter** NOT_APPLICABLE: Library is a rate limiter only: it has no retry, deadline, circuit breaker, bulkhead or event facilities, so this scenario does not apply.
- **sf/http-client** NOT_APPLICABLE: Library is an HTTP client with retry and throttling decorators; it has no circuit breaker, bulkhead, or half-open probing, so this scenario does not apply.
- **caseyamcl retry** NOT_APPLICABLE: Library is a retry middleware only: it has no rate limiter, circuit breaker or bulkhead, so this scenario does not apply.
- **spatie rate** NOT_APPLICABLE: Library only delays requests to a per-second/per-minute limit: it has no retry, deadline, circuit breaker, bulkhead, events or typed rejections, so this scenario does not apply.
- **ganesha** PASS: Per strategy: {"count(threshold=4)":{"seq1_4_failures_then_5th":["fail","fail","fail","fail","rejected"],"seq1_ok":true,"seq2_seq3":"not applicable: count strategy has no failure-rate notion"},"rate(min=4,50%)":{"seq1_4_failures_then_5th":["fail","fail","fail","fail","rejected"],"seq1_ok":true,"seq2_SSFF_then_5th":["ok","ok","fail","fail","rejected"],"seq2_ok":true,"seq3_SSSF_then_5th":["ok","ok","ok","fail","ok"],"seq3_ok":true}}
- **duat** PASS: circuitBreaker(rate 0.5, minimumCalls 4): {"seq1":["fail","fail","fail","fail","rejected:circuit"],"seq2_SSFF":["ok","ok","fail","fail","rejected:circuit"],"seq3_SSSF":["ok","ok","ok","fail","ok"]}

### S09 Half-open admits only the configured probes

- **Reqtide** PASS: open at t=0, 31s elapsed (cooldown 30s), 5 callers started before any probe reports (callbacks parked in Fibers), probeCapacity = probeSuccessThreshold = P: {"P=2":{"admitted_of_5":2,"others":["rejected:circuit_probe_capacity_exhausted","rejected:circuit_probe_capacity_exhausted","rejected:circuit_probe_capacity_exhausted"]},"P=1":{"admitted_of_5":1,"others":["rejected:circuit_probe_capacity_exhausted","rejected:circuit_probe_capacity_exhausted","rejected:circuit_probe_capacity_exhausted","rejected:circuit_probe_capacity_exhausted"]}}
- **sf/rate-limiter** NOT_APPLICABLE: Library is a rate limiter only: it has no retry, deadline, circuit breaker, bulkhead or event facilities, so this scenario does not apply.
- **sf/http-client** NOT_APPLICABLE: Library is an HTTP client with retry and throttling decorators; it has no circuit breaker, bulkhead, or half-open probing, so this scenario does not apply.
- **caseyamcl retry** NOT_APPLICABLE: Library is a retry middleware only: it has no rate limiter, circuit breaker or bulkhead, so this scenario does not apply.
- **spatie rate** NOT_APPLICABLE: Library only delays requests to a per-second/per-minute limit: it has no retry, deadline, circuit breaker, bulkhead, events or typed rejections, so this scenario does not apply.
- **ganesha** PASS: open, 31s elapsed (interval 30s), 5 isAvailable() calls before any report: {"count":{"admitted_of_5":1},"rate":{"admitted_of_5":1}}. The probe count is not configurable; the observed behaviour is a single probe (isHalfOpen() rewrites lastFailureTime on the first call).
- **duat** PASS: open, cooldown elapsed, 5 callers started before any probe returns (callbacks parked in Fibers): {"P=2":{"admitted_of_5":2},"P=1":{"admitted_of_5":1}}

### S10 An abandoned probe neither closes the circuit nor wedges it forever

- **Reqtide** PASS: probe admitted at +31s and never reported (parked Fiber); others immediately, then a caller 45s later. ADAPTATION: the probe slot is released only when its permit lease expires, and permitLease has no default; 30s (= open duration, the same self-heal horizon duat uses) is the graded setting: {"permit_lease":30,"probe_admitted":true,"callers_right_after":["rejected:circuit_probe_capacity_exhausted","rejected:circuit_probe_capacity_exhausted"],"caller_after_1.5x_cooldown":"ok","replacement_admitted_seconds_after_probe_if_not_at_1.5x":null}. Sensitivity with lease 60s (also valid): {"permit_lease":60,"probe_admitted":true,"callers_right_after":["rejected:circuit_probe_capacity_exhausted","rejected:circuit_probe_capacity_exhausted"],"caller_after_1.5x_cooldown":"rejected:circuit_probe_capacity_exhausted","replacement_admitted_seconds_after_probe_if_not_at_1.5x":60} - recovery time equals the configured lease: the replacement probe is admitted exactly 60s (= 2x cooldown) after the abandoned one, which meets the scenario's "within 2x cooldown" wording but not the stricter 1.5x-cooldown probe the adapters use; with a lease above 2x cooldown it would not.
- **sf/rate-limiter** NOT_APPLICABLE: Library is a rate limiter only: it has no retry, deadline, circuit breaker, bulkhead or event facilities, so this scenario does not apply.
- **sf/http-client** NOT_APPLICABLE: Library is an HTTP client with retry and throttling decorators; it has no circuit breaker, bulkhead, or half-open probing, so this scenario does not apply.
- **caseyamcl retry** NOT_APPLICABLE: Library is a retry middleware only: it has no rate limiter, circuit breaker or bulkhead, so this scenario does not apply.
- **spatie rate** NOT_APPLICABLE: Library only delays requests to a per-second/per-minute limit: it has no retry, deadline, circuit breaker, bulkhead, events or typed rejections, so this scenario does not apply.
- **ganesha** PASS: probe admitted at +31s and never reported; others immediately, then a caller 45s later: {"count":{"probe_admitted":true,"callers_right_after":[false,false],"caller_after_1.5x_cooldown":true},"rate":{"probe_admitted":true,"callers_right_after":[false,false],"caller_after_1.5x_cooldown":true}}. (Recovery in the rate strategy is by tumbling-window ageing of the failure counters, not by a probe timeout.)
- **duat** PASS: probe admitted and never resumed; {"probe_admitted":true,"callers_right_after":["rejected:circuit","rejected:circuit"],"caller_after_1.5x_cooldown":"ok"}. (half_open_calls has a TTL of cooldownSeconds, so the slot self-heals.)

### S11 A stale result after reopen cannot change breaker state

- **Reqtide** PASS: {"control_no_stale":{"probe":"fail","at_60.999":"rejected:circuit_open","at_61.001":"ok"},"stale_failure":{"probe":"fail","at_60.999":"rejected:circuit_open","at_61.001":"ok"},"stale_success":{"probe":"fail","at_45":"rejected:circuit_open"},"control_valid":true}. Permit lease is 60s so the stale result is rejected by the generation/phase fence, not by lease expiry; control valid: yes.
- **sf/rate-limiter** NOT_APPLICABLE: Library is a rate limiter only: it has no retry, deadline, circuit breaker, bulkhead or event facilities, so this scenario does not apply.
- **sf/http-client** NOT_APPLICABLE: Library is an HTTP client with retry and throttling decorators; it has no circuit breaker, bulkhead, or half-open probing, so this scenario does not apply.
- **caseyamcl retry** NOT_APPLICABLE: Library is a retry middleware only: it has no rate limiter, circuit breaker or bulkhead, so this scenario does not apply.
- **spatie rate** NOT_APPLICABLE: Library only delays requests to a per-second/per-minute limit: it has no retry, deadline, circuit breaker, bulkhead, events or typed rejections, so this scenario does not apply.
- **ganesha** FAIL (missing-capability): {"count":{"control_no_stale":{"probe_admitted":true,"admitted_at_61":false,"admitted_at_62":true},"stale_failure":{"probe_admitted":true,"admitted_at_61":false,"admitted_at_62":false},"stale_success":{"probe_admitted":true,"still_open_at_45":true},"verdict":{"control_ok":true,"stale_failure_ok":false,"stale_success_ok":true}},"rate":{"control_no_stale":{"probe_admitted":true,"admitted_at_61":false,"admitted_at_62":true},"stale_failure":{"probe_admitted":true,"admitted_at_61":false,"admitted_at_62":false},"stale_success":{"probe_admitted":true,"still_open_at_45":true},"verdict":{"control_ok":true,"stale_failure_ok":false,"stale_success_ok":true}}}. Ganesha has no notion of a call/generation: failure() is a bare counter+timestamp update, so a result from before the reopen is indistinguishable from a fresh one. (Clock is whole seconds, so next probe is due strictly after 31+30s.)
- **duat** FAIL (behaviour-defect): {"control_no_stale":{"probe":"fail","at_60.999":"rejected:circuit","at_61.001":"ok"},"stale_failure":{"probe":"fail","at_60.999":"rejected:circuit","at_61.001":"rejected:circuit"},"stale_success":{"probe":"fail","at_45":"rejected:circuit"},"control_valid":true}. A late failure re-enters recordFailure(), re-evaluates the window and calls open() again, rewriting opened_at (the cooldown restarts from the stale result). A late success only increments a bucket.

### S12 Late/non-owner release cannot free someone else's permit

- **Reqtide** PASS: ADAPTATION: limit 1; lease 1.2s with attempt timeout 100ms (the library requires lease > attempt timeout + 1s, so the scenario's 1s lease cannot be configured): {"C":"rejected:concurrency_capacity_exhausted","C_rejected":true,"B_still_running":true,"A_result":"ok","physical_peak_callbacks_running":2}. GRADED on the scenario's observed question (is C admitted while B still runs? no, so A's late release did not free B's permit). The physical peak of 2 is A and B overlapping: the scenario starts B after A's lease expired while A is still running, so any lease-based bulkhead shows it and the literal clause "peak concurrency never exceeds 1" cannot be satisfied.
- **sf/rate-limiter** NOT_APPLICABLE: Library is a rate limiter only: it has no retry, deadline, circuit breaker, bulkhead or event facilities, so this scenario does not apply.
- **sf/http-client** NOT_APPLICABLE: Library is an HTTP client with retry and throttling decorators; it has no circuit breaker, bulkhead, or half-open probing, so this scenario does not apply.
- **caseyamcl retry** NOT_APPLICABLE: Library is a retry middleware only: it has no rate limiter, circuit breaker or bulkhead, so this scenario does not apply.
- **spatie rate** NOT_APPLICABLE: Library only delays requests to a per-second/per-minute limit: it has no retry, deadline, circuit breaker, bulkhead, events or typed rejections, so this scenario does not apply.
- **ganesha** NOT_APPLICABLE: Library is a circuit-breaker state tracker only: it has no retry, rate limiting, deadline, bulkhead or permit lifecycle, so this scenario does not apply.
- **duat** FAIL (behaviour-defect): limit 1, lease 1s: {"peak_concurrency":2,"C":"admitted (running)","B_still_running":true}. BulkheadPolicy releases by increment(-1) on a shared counter with no holder identity, so a late release decrements the counter now owned by B.

### S13 Cancel before dispatch leaves no leaked capacity

- **Reqtide** PASS: bulkhead(1, lease 60s); the caller's own CancellationToken is cancelled from PreparedAttempt::verifyReplay() (after the permit is held, before dispatch): {"disposition":"cancelled","permit_held_at_cancel_instant":true,"dispatched":0,"summary_dispatched":0,"admittedNotDispatched":0,"fresh_caller_after_cancel":"ok","second_caller_while_one_runs":"rejected:concurrency_capacity_exhausted"}
- **sf/rate-limiter** NOT_APPLICABLE: Library has no permits/concurrency capacity and no cancellation signal; a Reservation cannot be cancelled or refunded (LimiterInterface exposes only reserve(), consume(), reset()). Rate-quota accounting is covered by S15.
- **sf/http-client** NOT_APPLICABLE: Library has no concurrency permits; throttling quota is covered by S15.
- **caseyamcl retry** NOT_APPLICABLE: Library is a retry middleware only: it has no rate limiter, circuit breaker or bulkhead, so this scenario does not apply.
- **spatie rate** NOT_APPLICABLE: Library only delays requests to a per-second/per-minute limit: it has no retry, deadline, circuit breaker, bulkhead, events or typed rejections, so this scenario does not apply.
- **ganesha** NOT_APPLICABLE: Library is a circuit-breaker state tracker only: it has no retry, rate limiting, deadline, bulkhead or permit lifecycle, so this scenario does not apply.
- **duat** NOT_EXPRESSIBLE: Library has no cancellation token/signal, so "cancel before dispatch" cannot be expressed. Nearest expressible case (informational): bulkhead(1) outside an OPEN circuit breaker rejects before dispatch (rejected:circuit) and capacity is returned (fresh caller: ok).

### S14 Permit released when the callback throws

- **Reqtide** PASS: after the callback throws, is the original throwable propagated and the next caller admitted? {"Exception":{"propagated_unchanged":true,"next_caller":"ok"},"Error":{"propagated_unchanged":true,"next_caller":"ok"}}
- **sf/rate-limiter** NOT_APPLICABLE: Library is a rate limiter only: it has no retry, deadline, circuit breaker, bulkhead or event facilities, so this scenario does not apply.
- **sf/http-client** NOT_APPLICABLE: Library has no concurrency permits to release.
- **caseyamcl retry** NOT_APPLICABLE: Library is a retry middleware only: it has no rate limiter, circuit breaker or bulkhead, so this scenario does not apply.
- **spatie rate** NOT_APPLICABLE: Library only delays requests to a per-second/per-minute limit: it has no retry, deadline, circuit breaker, bulkhead, events or typed rejections, so this scenario does not apply.
- **ganesha** NOT_APPLICABLE: Library is a circuit-breaker state tracker only: it has no retry, rate limiting, deadline, bulkhead or permit lifecycle, so this scenario does not apply.
- **duat** PASS: after a callback throws, next caller admitted? {"Exception":"ok","Error":"ok"} (BulkheadPolicy releases in finally).

### S15 Rate quota consumed equals requests actually sent

- **Reqtide** FAIL (missing-capability): One GET fails twice (503) then succeeds. Native composition (retry + rateLimit in one pipeline; rate is debited for every dispatch opportunity): consumed 3, sent 3. Informational, user-built nesting of two single-purpose pipelines with the rate limiter OUTSIDE the retry: consumed 1, sent 3 (the same composition-order hazard the competitors show, reachable only by hand). Cancel-before-send (token cancelled after admission and rate debit, before dispatch): consumed 1, sent 0, disposition cancelled, admittedNotDispatched 1 - quota is NOT refunded by design (a transport failure never refunds it, and quota may remain consumed after a cancel before dispatch). Every attempt is charged.
- **sf/rate-limiter** NOT_APPLICABLE: Library is a rate limiter only: it has no retry, deadline, circuit breaker, bulkhead or event facilities, so this scenario does not apply.
- **sf/http-client** FAIL (composition-dependent): quota consumed vs wire requests: {"retry_outer(throttle(wire))":{"consumed":3,"sent":3},"throttle_outer(retry(wire))":{"consumed":1,"sent":3}}. ThrottlingHttpClient reserves one token per request() call it sees, so retries made by an inner RetryableHttpClient are invisible to it. Cancel-before-send sub-case not run: ThrottlingHttpClient calls inner request() before reserving and MockHttpClient has no unsent state to cancel.
- **caseyamcl retry** NOT_APPLICABLE: Library is a retry middleware only: it has no rate limiter, circuit breaker or bulkhead, so this scenario does not apply.
- **spatie rate** FAIL (composition-dependent): rate slots recorded vs wire requests (retry provided by caseyamcl/guzzle_retry_middleware as test fixture): {"retry_outer(limiter(wire))":{"consumed":3,"sent":3},"limiter_outer(retry(wire))":{"consumed":1,"sent":3}}. Cancel-before-send sub-case not run (middleware pushes the timestamp immediately before calling the next handler).
- **ganesha** NOT_APPLICABLE: Library is a circuit-breaker state tracker only: it has no retry, rate limiting, deadline, bulkhead or permit lifecycle, so this scenario does not apply.
- **duat** NOT_APPLICABLE: Library wraps callables with retry/breaker/timeout/bulkhead/fallback; it has no rate limiter (README: "planned") or HTTP awareness, so this scenario does not apply.

### S16 Typed, machine-readable rejection taxonomy

- **Reqtide** PASS: Five causes provoked. Distinct (class, ReasonCode enum) pairs: yes; rate-limit and circuit-open rejections carry a retry-after: yes. Facts: {"rate_limited":{"class":"Enaxon\\Reqtide\\Exception\\AdmissionRejectedException","reason_code":"rate.exhausted","retry_after_seconds":10,"status":"rejected","problem_retryability":"after_wait"},"circuit_open":{"class":"Enaxon\\Reqtide\\Exception\\AdmissionRejectedException","reason_code":"circuit_open","retry_after_seconds":30,"status":"rejected","problem_retryability":"after_wait"},"bulkhead_full":{"class":"Enaxon\\Reqtide\\Exception\\AdmissionRejectedException","reason_code":"concurrency_capacity_exhausted","retry_after_seconds":null,"status":"rejected","problem_retryability":"transient"},"deadline_exceeded":{"class":"Enaxon\\Reqtide\\Exception\\DeadlineExceededException","reason_code":"deadline_exceeded","problem_retryability":"never"},"retries_exhausted":{"detailed_mode":{"disposition":"failed","problem_code":"attempts_exhausted","attempts":3},"simple_mode_exception":"Comparison\\UpstreamFailure","typed_in_simple_mode":false}}. Limitation: retries-exhausted is machine-readable only through executeDetailed() (problem()->code / diagnostics); the throwing execute() returns the original business exception unchanged (by design: business exceptions are never wrapped).
- **sf/rate-limiter** PASS: Only cause produced: rate limit. {"consume_rejected":{"class":"Symfony\\Component\\RateLimiter\\RateLimit","isAccepted":false,"retryAfter":"1700000050"},"ensureAccepted":{"class":"Symfony\\Component\\RateLimiter\\Exception\\RateLimitExceededException","isRateLimitExceeded":true,"getRetryAfter":"1700000050"},"reserve_maxTime":{"class":"Symfony\\Component\\RateLimiter\\Exception\\MaxWaitDurationExceededException","isMaxWait":true,"hasRateLimit":true}}. Library produces no circuit-open/bulkhead/deadline/retries-exhausted causes.
- **sf/http-client** FAIL (missing-capability): After retries are exhausted the final 503 is returned like any non-retried 5xx ({"status_after_exhaustion":503,"exception_on_getContent":"Symfony\\Component\\HttpClient\\Exception\\ServerException","info_retry_count":2}). The only trace is the info key retry_count (an integer; consumers must compare it with their own max_retries to infer exhaustion). No distinct retries-exhausted type; no rate-limit/circuit/bulkhead/deadline causes are produced by this package.
- **caseyamcl retry** FAIL (missing-capability): After exhaustion or a give_up_after_secs stop the final upstream response/exception is passed through unchanged ({"outcome_after_exhaustion":"503","X-Retry-Counter (opt-in expose_retry_header)":"2"}); the middleware defines no exception types of its own and the only machine-readable trace is an opt-in response header. No rate-limit/circuit/bulkhead causes are produced by this package.
- **spatie rate** NOT_APPLICABLE: The middleware never rejects: it sleeps until a slot is free and defines no exception types, so there is no rejection taxonomy to inspect.
- **ganesha** FAIL (missing-capability): Only cause produced: circuit open. {"rejection_class":"Ackintosh\\Ganesha\\Exception\\RejectedException","is_RejectedException":true,"extra_public_methods":["serviceName"],"has_retry_time_accessor":false,"raw_isAvailable_returns":"bool"}. Library produces no rate-limit/bulkhead/deadline/retries-exhausted causes.
- **duat** FAIL (missing-capability): Typed and distinct: yes (CircuitOpenException, BulkheadFullException, RetryExhaustedException, TimeoutExceededException, all extending DuatException). Facts: {"circuit_open":{"class":"Duat\\Exception\\CircuitOpenException","public_fields":["name"],"has_retry_time_field":false},"bulkhead_full":{"class":"Duat\\Exception\\BulkheadFullException","public_fields":["name","maxConcurrent"]},"retries_exhausted":{"class":"Duat\\Exception\\RetryExhaustedException","public_fields":["attempts"],"has_retry_time_field":false},"deadline_exceeded":{"class":"Duat\\Exception\\TimeoutExceededException","public_fields":["name","timeoutSeconds","elapsedSeconds"],"has_retry_time_field":false}}. CircuitOpenException exposes only the circuit name: no retry-at/remaining-cooldown. No rate-limit cause (no limiter). TimeoutExceededException is only thrown after the callable returns and only with throwOnLateSuccess.

### S17 Wall-clock regression safety

- **Reqtide** PASS: Exhaust at T, wall clock set back 3600s (VirtualClock::setWall, monotonic untouched), then 1s steps that advance both domains: {"token_bucket":{"over_admitted_at_jump":false,"recovered_after_elapsed_seconds":10,"interval":10},"fixed_window_aligned":{"over_admitted_at_jump":false,"recovered_after_elapsed_seconds":10,"interval":10},"circuit_breaker":{"over_admitted_at_jump":false,"recovered_after_elapsed_seconds":30,"interval":30}}. The memory backends measure with the monotonic clock; the wall clock is read once to place Aligned window boundaries.
- **sf/rate-limiter** FAIL (wall-clock-dependence): Exhaust at T, clock jumps back 3600s, then 1s steps: {"token_bucket":{"over_admitted_at_jump":false,"recovered_after_elapsed_seconds":3610,"interval":10},"fixed_window":{"over_admitted_at_jump":false,"recovered_after_elapsed_seconds":3610,"interval":10}}
- **sf/http-client** NOT_APPLICABLE: The only wall-clock use in the retry path is parsing an HTTP-date Retry-After; clock regression of limiter state is covered in the symfony/rate-limiter adapter. Not tested separately.
- **caseyamcl retry** NOT_APPLICABLE: Keeps no cross-operation time-based state (no limiter or breaker); per-operation deadline use of time() is not part of this scenario.
- **spatie rate** FAIL (wall-clock-dependence): limit 1/s; request at T, clock jumps back 3600s, next request: {"slept_ms_requested_by_limiter":3601000,"virtual_seconds_until_second_request_reached_wire":3601,"interval_seconds":1} (stored timestamps are in the "future", so the oldest-request age is negative and the computed delay is interval + 3600s).
- **ganesha** FAIL (wall-clock-dependence): open at T, clock back 3600s, then 1s steps: {"count":{"over_admitted_at_jump":false,"recovered_after_elapsed_seconds":3631,"interval":30},"rate":{"over_admitted_at_jump":true,"recovered_after_elapsed_seconds":1,"interval":30}} (Rate strategy additionally keys counters by floor(time/window), so after a regression it reads the older window's counters).
- **duat** FAIL (wall-clock-dependence): circuit opened at T, clock back 3600s, then 1s steps: {"over_admitted_at_jump":false,"recovered_after_elapsed_seconds":3630,"cooldown":30} (opened_at lies in the future, so now - opened_at < cooldown until the clock catches up).

### S18 Throwing observer does not change the business result

- **Reqtide** PASS: RecordingEventSink that throws on every emit (after recording). retry-once-then-succeed and 4-failure circuit trip: {"control_without_throwing_observer":{"retry":{"result":"business-value","disposition":"succeeded","attempts":2,"events_seen_by_sink":4,"accounting_complete":true},"circuit":{"labels":["fail","fail","fail","fail","rejected:circuit_open"],"transition_events_seen":1}},"throwing_observer":{"retry":{"result":"business-value","disposition":"succeeded","attempts":2,"events_seen_by_sink":4,"accounting_complete":false},"circuit":{"labels":["fail","fail","fail","fail","rejected:circuit_open"],"transition_events_seen":1}}}. Business result identical: retry yes, circuit yes. Effect on the caller is limited to summary.accountingComplete=false (a bounded diagnostic), never the result (OperationRun::emit()/complete() catch sink failures).
- **sf/rate-limiter** NOT_APPLICABLE: Library is a rate limiter only: it has no retry, deadline, circuit breaker, bulkhead or event facilities, so this scenario does not apply.
- **sf/http-client** FAIL (behaviour-defect): retry once then 200 with a PSR-3 logger that throws on every call: operation outcome = Symfony\Component\HttpClient\Exception\TransportException (control without logger: 200; expected 200). RetryableHttpClient calls $logger->info() inside the async chunk handler without a try/catch.
- **caseyamcl retry** NOT_APPLICABLE: The only hook is on_retry_callback, which receives the request/options by reference and may mutate them, so it is an interceptor rather than an observer; no event/observer facility to test.
- **spatie rate** NOT_APPLICABLE: Library only delays requests to a per-second/per-minute limit: it has no retry, deadline, circuit breaker, bulkhead, events or typed rejections, so this scenario does not apply.
- **ganesha** FAIL (behaviour-defect): subscriber throws on the "tripped" event; 4th failure() call: {"exception_escaped_from_failure()":"observer failed on tripped","circuit_state_afterwards":"tripped"}. Ganesha::notify() calls subscribers without try/catch (only StorageException is caught around the strategy call), so the caller's own failure handling is interrupted by the observer exception. State is recorded before notification.
- **duat** FAIL (behaviour-defect): retry once then succeed, PSR-14 dispatcher throws on every event: {"result":null,"exception_escaped":"observer failed on Duat\\Event\\RetryAttempted","attempts_made":1,"control_without_observer":"business-value"}. Context::dispatch() has no try/catch; RetryPolicy dispatches inside its catch block.

### S19 Runtime dependency count (transitive)

- **Reqtide** INFO: manifest-derived (packages/core/composer.json "require", not an installed resolution): 0 package dependencies. Source scan of packages/core/src: imports outside Enaxon\Reqtide: (none).
- **sf/rate-limiter** INFO: 2 transitive runtime package(s): symfony/deprecation-contracts, symfony/options-resolver
- **sf/http-client** INFO: 5 transitive runtime package(s): psr/container, psr/log, symfony/deprecation-contracts, symfony/http-client-contracts, symfony/service-contracts
- **caseyamcl retry** INFO: 8 transitive runtime package(s): guzzlehttp/guzzle, guzzlehttp/promises, guzzlehttp/psr7, psr/http-client, psr/http-factory, psr/http-message, symfony/polyfill-php80, symfony/polyfill-php82
- **spatie rate** INFO: 8 transitive runtime package(s): guzzlehttp/guzzle, guzzlehttp/promises, guzzlehttp/psr7, psr/http-client, psr/http-factory, psr/http-message, symfony/polyfill-php80, symfony/polyfill-php82
- **ganesha** INFO: 1 transitive runtime package(s): psr/http-message
- **duat** INFO: 0 transitive runtime package(s): (none)

### S20 PHP floor

- **Reqtide** INFO: manifest-derived: php ">=8.3"
- **sf/rate-limiter** INFO: own php constraint ">=8.4.1" (floor 8.4.1); highest floor in closure: 8.4.1
- **sf/http-client** INFO: own php constraint ">=8.4.1" (floor 8.4.1); highest floor in closure: 8.4.1
- **caseyamcl retry** INFO: own php constraint "^7.4|^8.0" (floor 7.4); highest floor in closure: 7.4
- **spatie rate** INFO: own php constraint "^8.3" (floor 8.3); highest floor in closure: 8.3
- **ganesha** INFO: own php constraint "^8.1" (floor 8.1); highest floor in closure: 8.1
- **duat** INFO: own php constraint ">=8.3" (floor 8.3); highest floor in closure: 8.3

### S21 Static analysis preserves the callback return type (generics)

- **Reqtide** PASS: PHPStan max: $r = $pipeline->execute(fn (AttemptContext $a): int => opaque(), $pipeline->newContext(Duration::seconds(5))) is inferred as "int". The literal-bodied callable `fn (): int => 1` (the form used for the other libraries) is inferred as "1" because PHPStan folds the constant; graded on the opaque-int callable so the expected type `int` is meaningful. 30 @template annotations in packages/core/src (execute() is declared "@template T @param callable(AttemptContext):T $operation @return T"; newContext() returns ExecutionContext<never> so the callable alone fixes T).
- **sf/rate-limiter** NOT_APPLICABLE: Library is a rate limiter only: it has no retry, deadline, circuit breaker, bulkhead or event facilities, so this scenario does not apply.
- **sf/http-client** NOT_APPLICABLE: No public API that executes a user callback; the client returns ResponseInterface.
- **caseyamcl retry** NOT_APPLICABLE: No public API that executes a user callback and returns its value; it is a Guzzle middleware over promises.
- **spatie rate** NOT_APPLICABLE: No public API that executes a user callback and returns its value; it is a Guzzle middleware.
- **ganesha** NOT_APPLICABLE: No public API that executes a user callback; the caller drives isAvailable()/success()/failure() (bool/void), so there is no return type to preserve.
- **duat** FAIL (missing-capability): PHPStan max: $r = Duat::for("x")->retry()->call(fn (): int => 1) is inferred as "mixed"; 0 @template annotations in the library source (call() is declared "@param callable(): mixed $fn" and returns mixed).
