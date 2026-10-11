<?php

declare(strict_types=1);

namespace Comparison;

/**
 * Library-independent scenario catalogue. Data only: no library names appear in the scenario text.
 * Time is virtual seconds since an arbitrary epoch. "basis" names the standard or practice that
 * justifies the expected behaviour (named from memory; the URLs were not re-fetched by this harness);
 * "unsure" is non-null where the author is not confident the expectation is universally accepted (reported verbatim in the results).
 */
final class Scenarios
{
    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        return [
            [
                'id' => 'S01', 'domain' => 'retry', 'kind' => 'behaviour',
                'title' => 'Total deadline spans retries and waits',
                'setup' => 'Total deadline D=10s. Every attempt fails instantly (HTTP 503, no Retry-After). Backoff is a constant 4s. The retry COUNT limit is set high (>=10) so that only a time limit can stop the loop.',
                'observed' => 'Start time of every attempt and the time the operation gave up.',
                'expected' => 'No attempt starts at or after t=D, and the operation has returned by t=D (attempts at 0,4,8; the wait that would end at 12 is not started).',
                'basis' => 'gRPC deadlines are absolute and cover the whole call including retries (grpc.io/docs/guides/deadlines; gRPC A6 retry design: maxAttempts and the call deadline both bind). Google SRE book, Handling Overload.',
                'unsure' => null,
            ],
            [
                'id' => 'S02', 'domain' => 'retry', 'kind' => 'behaviour',
                'title' => 'Retry-After larger than remaining deadline',
                'setup' => 'Deadline D=10s. First response is 503 with Retry-After: 30. Count limit is not binding.',
                'observed' => 'Time of any second request and the time the operation returned.',
                'expected' => 'A second request is never sent before t=30 (not early) AND the operation returns by t=D (never blocks past the deadline). Together: exactly one request, returned at <= D (as a failure or a deferral).',
                'basis' => 'RFC 9110 s10.2.3 (Retry-After: time the client should wait before a follow-up request); deadline semantics as in S01. Surveyed libraries wait uncapped, ignore, or retry early.',
                'unsure' => 'RFC 9110 calls the 503 value an "estimate", so "never send before" is a client policy, not a protocol MUST. Retrying early would still be RFC-legal; it is treated as incorrect here because the server asked for a delay.',
            ],
            [
                'id' => 'S03', 'domain' => 'retry', 'kind' => 'behaviour',
                'title' => 'Retry-After honoured as a lower bound',
                'setup' => 'First response 503 with Retry-After: 6; configured client backoff is a constant 2s. Second sub-case (informational): Retry-After: 1 with configured backoff 4s.',
                'observed' => 'Gap between first and second request in each sub-case.',
                'expected' => 'Primary: gap >= 6s when the header exceeds the backoff. Informational: with a header smaller than the configured backoff, a lower-bound reading yields gap >= 4s.',
                'basis' => 'RFC 9110 s10.2.3; client practice (Dapr wanted the larger of the two; an OpenFGA SDK made the server value override).',
                'unsure' => 'Only the primary sub-case decides PASS/FAIL. Whether the header may SHORTEN a configured backoff is contested in practice; the informational sub-case is reported but does not affect the verdict.',
            ],
            [
                'id' => 'S04', 'domain' => 'retry', 'kind' => 'behaviour',
                'title' => 'Retry budget bounds retry storms',
                'setup' => '1000 independent operations all fail with 503. Each operation is allowed up to 3 retries by its own per-operation limit. Delays are zero.',
                'observed' => 'Total requests actually sent; extra = total - 1000 first attempts.',
                'expected' => 'Aggregate extra traffic is bounded to <= 20% of first attempts (SRE guidance is ~10%; 20% allows burst slack), independent of the per-operation limit. Unbounded per-operation retries give +300%.',
                'basis' => 'Google SRE book, Handling Overload (per-client retry budget ~10%; bounds worst case near 1.1x instead of ~3x); AWS Builders Library, Timeouts, retries and backoff with jitter (retry amplification).',
                'unsure' => 'The 20% threshold is this harness\'s choice. A circuit breaker also bounds extra traffic but by stopping primary traffic too; if a library offers both, retry-only and breaker variants are reported separately.',
            ],
            [
                'id' => 'S05', 'domain' => 'retry', 'kind' => 'behaviour',
                'title' => 'Non-idempotent POST is not retried by default',
                'setup' => 'Default retry configuration (only delays zeroed). A POST receives (a) HTTP 500, (b) a transport failure. Informational: (c) 503, (d) 429.',
                'observed' => 'Number of requests sent in each sub-case.',
                'expected' => 'Sub-cases (a) and (b): exactly 1 request (the server may have processed it).',
                'basis' => 'RFC 9110 s9.2.2 (idempotent methods; automatic retry of others is not safe); AWS Builders Library (retry only idempotent operations or use idempotency keys).',
                'unsure' => 'Sub-cases (c)/(d) are informational: 429/503 are often sent before processing and several libraries retry them for any method; the RFC does not declare that safe, but it is common practice. They do not affect the verdict.',
            ],
            [
                'id' => 'S06', 'domain' => 'rate', 'kind' => 'behaviour',
                'title' => 'Token bucket exact boundary and refill remainder',
                'setup' => 'Capacity (burst) 2, refill 1 token per 2s, bucket starts full at t=0. Take 2 tokens at t=0. Probe at t=1.999 and t=2.0. Then jump to t=5.0 and probe; probe at t=5.999 and t=6.0.',
                'observed' => 'Admit/reject decision at each probe instant.',
                'expected' => 't=0 third request rejected. t=1.999 rejected (no early admission). t=2.0 admitted, then rejected again. At t=5.0 exactly one token has accrued (admitted once, then rejected) and the 1.0s remainder toward the next token is preserved: t=5.999 rejected, t=6.0 admitted.',
                'basis' => 'Token bucket definition: admissions in any interval of length T never exceed burst + rate*T; a refill remainder must not be discarded (generic definition, e.g. Wikipedia "Token bucket").',
                'unsure' => null,
            ],
            [
                'id' => 'S07', 'domain' => 'rate', 'kind' => 'behaviour',
                'title' => 'Fixed/sliding window boundary: no early admission',
                'setup' => 'Limit 3 requests per window W (W=10s; W=1s where the library only offers a 1s unit). All 3 are admitted at t0. Probe the 4th request just before, at, and just after t0+W.',
                'observed' => 'Decision (or the instant a delaying limiter lets it through) at t0+W-0.001, t0+W, t0+W+0.001.',
                'expected' => 'The 4th request is not admitted before t0+W and is admitted no later than t0+W+0.001s.',
                'basis' => 'Definition of an N-per-window limit: safety (no early admission) and liveness (admission once the window has elapsed).',
                'unsure' => 'Whether admission occurs at exactly t0+W or one tick later is an open/closed-interval implementation choice; only "not early" and "within 1ms" are required.',
            ],
            [
                'id' => 'S08', 'domain' => 'circuit', 'kind' => 'behaviour',
                'title' => 'Circuit opens exactly at threshold and minimum samples',
                'setup' => 'Minimum samples 4, failure-rate threshold 50% (count threshold 4 where the library only has that). Seq1: four failures. Seq2: success,success,failure,failure (exactly 50% at the 4th sample). Seq3: success,success,success,failure (25%).',
                'observed' => 'After each sequence, whether the next call is admitted; and for Seq1 whether the 4th call was still admitted after 3 failures.',
                'expected' => 'Seq1: calls 1-4 execute, call 5 rejected. Seq2: call 5 rejected. Seq3: call 5 admitted.',
                'basis' => 'Resilience4j CircuitBreaker docs: opens when the failure rate is equal to or greater than the threshold, only after minimumNumberOfCalls.',
                'unsure' => 'Rate semantics differ across libraries (some key counters by time window, count strategies have no rate). Each adapter uses the closest native configuration and states it.',
            ],
            [
                'id' => 'S09', 'domain' => 'circuit', 'kind' => 'behaviour',
                'title' => 'Half-open admits only the configured probes',
                'setup' => 'Circuit open; cooldown elapsed. Five callers arrive before any probe has reported. Probe limit P=2 (P=1 where the library offers no setting).',
                'observed' => 'How many of the five callers were allowed to execute.',
                'expected' => 'Exactly P execute; the rest are rejected.',
                'basis' => 'Resilience4j permittedNumberOfCallsInHalfOpenState; Polly circuit breaker half-open semantics.',
                'unsure' => null,
            ],
            [
                'id' => 'S10', 'domain' => 'circuit', 'kind' => 'behaviour',
                'title' => 'An abandoned probe neither closes the circuit nor wedges it forever',
                'setup' => 'Circuit open, cooldown (30s) elapsed, a probe is admitted and never reports (worker died or was cancelled). Other callers arrive immediately, and again after a further 1.5x cooldown.',
                'observed' => 'Do callers pass freely after the abandonment? Is a replacement probe admitted later?',
                'expected' => 'Not closed right after abandonment (no free passage), and a replacement probe is admitted within 2x cooldown.',
                'basis' => 'Safety (an unreported probe is not evidence of recovery) and liveness (probe leases/timeouts); design principle.',
                'unsure' => 'No external standard specifies abandoned-probe handling; the expectation is a design principle, not a citable rule.',
            ],
            [
                'id' => 'S11', 'domain' => 'circuit', 'kind' => 'behaviour',
                'title' => 'A stale result after reopen cannot change breaker state',
                'setup' => 'Call A starts while closed (t=0) and is slow. The circuit opens at t=0 (4 failures, cooldown 30s). At t=31 a probe fails and the circuit reopens (next probe due t=61). A completes at t=40: variant (i) with a failure, variant (ii) with a success.',
                'observed' => '(i) Is a probe admitted at t=60.999 / t=61.001? (ii) Is the circuit still open at t=45?',
                'expected' => '(i) next probe still due at t=61 (rejected at 60.999, admitted at 61.001). (ii) circuit remains open at t=45.',
                'basis' => 'Late results from a previous circuit generation carry no information about the dependency\'s current state (generation/fencing guard).',
                'unsure' => 'No external standard; Resilience4j/Polly documentation does not address it. Treated as a correctness property the project claims.',
            ],
            [
                'id' => 'S12', 'domain' => 'bulkhead', 'kind' => 'behaviour',
                'title' => 'Late/non-owner release cannot free someone else\'s permit',
                'setup' => 'Concurrency limit 1, lease 1s. Holder A exceeds its lease. B is then admitted (lease expired). A finishes (late release) while B still runs. C arrives while B is running.',
                'observed' => 'Is C admitted while B is still running?',
                'expected' => 'C is rejected; peak concurrency never exceeds 1. A\'s late release must not release B\'s permit.',
                'basis' => 'Fencing-token / ownership principle for leases (Kleppmann, "How to do distributed locking").',
                'unsure' => null,
            ],
            [
                'id' => 'S13', 'domain' => 'bulkhead', 'kind' => 'behaviour',
                'title' => 'Cancel before dispatch leaves no leaked capacity',
                'setup' => 'A caller is admitted (permit taken) and is cancelled by its own cancellation signal before the transport callback is dispatched. Capacity is then probed with a fresh caller.',
                'observed' => 'Is the permit returned?',
                'expected' => 'Capacity is fully returned.',
                'basis' => 'Resource-safety invariant: every acquired permit has exactly one release on all exits, including cancellation.',
                'unsure' => 'Libraries without a cancellation concept are NOT_EXPRESSIBLE, not FAIL.',
            ],
            [
                'id' => 'S14', 'domain' => 'bulkhead', 'kind' => 'behaviour',
                'title' => 'Permit released when the callback throws',
                'setup' => 'Concurrency limit 1. The callback throws an Exception, then (separately) a PHP Error.',
                'observed' => 'After each throw, is a new caller admitted?',
                'expected' => 'Yes for both.',
                'basis' => 'try/finally resource-safety invariant.',
                'unsure' => null,
            ],
            [
                'id' => 'S15', 'domain' => 'rate', 'kind' => 'behaviour',
                'title' => 'Rate quota consumed equals requests actually sent',
                'setup' => 'One logical GET fails twice with 503 then succeeds (3 wire requests). Retry and rate limiting are composed in both possible orders; quota is large enough never to block. Sub-case: a request cancelled before it is sent.',
                'observed' => 'Quota units consumed vs. requests that reached the mock transport, per composition order.',
                'expected' => 'consumed == sent for every composition order: retries count against the quota, and quota is not spent on requests never sent.',
                'basis' => 'A rate limit protects the downstream so it must count wire traffic including retries (AWS Builders Library, retry amplification).',
                'unsure' => 'Many designs deliberately leave composition order to the user. Reported as FAIL with gap "composition-dependent" when one order is correct and the other is not.',
            ],
            [
                'id' => 'S16', 'domain' => 'errors', 'kind' => 'behaviour',
                'title' => 'Typed, machine-readable rejection taxonomy',
                'setup' => 'Provoke every rejection or give-up cause the library itself produces (rate-limited, circuit-open, bulkhead-full, deadline exceeded, retries exhausted).',
                'observed' => 'Per cause: distinguishable by exception class/enum (not message text)? Do rate/circuit rejections carry a machine-readable earliest-retry time or duration?',
                'expected' => 'Every produced cause has its own type, and rate-limit and circuit-open rejections expose a retry-at / retry-after value.',
                'basis' => 'Engineering practice: callers branch on type, not on message text; Retry-After precedent in RFC 9110.',
                'unsure' => 'Judgement-heavy; not a published standard. The "retry-at on circuit-open" clause is the harness author\'s requirement. Facts (type list, fields) are reported so a reader can re-grade.',
            ],
            [
                'id' => 'S17', 'domain' => 'time', 'kind' => 'behaviour',
                'title' => 'Wall-clock regression safety',
                'setup' => 'A time-based limiter/breaker is exhausted at t=T. The wall clock then jumps BACK by 3600s. One full configured interval of elapsed (monotonic) time passes.',
                'observed' => 'Does the component admit again after one interval of elapsed time, or stall until the clock catches up? Does it ever over-admit?',
                'expected' => 'No over-admission, and recovery within <= 2x the configured interval of elapsed time (stall bounded, not ~3600s).',
                'basis' => 'Durations should be measured with a monotonic clock (Linux clock_gettime(2) CLOCK_MONOTONIC is not affected by discontinuous jumps; PHP hrtime()).',
                'unsure' => 'State shared across processes needs a shared (wall) clock, so full immunity is impossible there; only a bounded stall is required. A stall is the safe direction (no over-admission) but is an availability loss.',
            ],
            [
                'id' => 'S18', 'domain' => 'events', 'kind' => 'behaviour',
                'title' => 'Throwing observer does not change the business result',
                'setup' => 'An event sink / logger / subscriber that throws on every event is attached. The operation (retry once then succeed, or a circuit trip) runs.',
                'observed' => 'Is the business result (return value / completion) identical to the run without the observer?',
                'expected' => 'Identical result; observer failure is contained.',
                'basis' => 'OpenTelemetry specification, "Error handling in OpenTelemetry": instrumentation must not throw into application code.',
                'unsure' => 'PSR-14 does not require a dispatcher to swallow listener exceptions, so a library can argue propagation is the dispatcher\'s job. The expectation reflects the telemetry principle, not PSR-14.',
            ],
            [
                'id' => 'S19', 'domain' => 'static', 'kind' => 'measurement',
                'title' => 'Runtime dependency count (transitive)',
                'setup' => 'Transitive closure of "require" (excluding php, ext-*, lib-*) for the package as resolved in this harness\'s composer.lock.',
                'observed' => 'Count and list of packages.',
                'expected' => 'No verdict (measurement). Reqtide Core targets zero runtime dependencies.',
                'basis' => 'composer.lock of the harness; equivalent to the packages reachable by "composer why".',
                'unsure' => 'The count depends on the resolved set; shared transitive packages are counted per package.',
            ],
            [
                'id' => 'S20', 'domain' => 'static', 'kind' => 'measurement',
                'title' => 'PHP floor',
                'setup' => 'The "php" constraint of the pinned release and the "php" constraints in its dependency closure.',
                'observed' => 'Constraint strings from composer metadata.',
                'expected' => 'No verdict (measurement).',
                'basis' => 'composer.lock of the harness.',
                'unsure' => null,
            ],
            [
                'id' => 'S21', 'domain' => 'static', 'kind' => 'behaviour',
                'title' => 'Static analysis preserves the callback return type (generics)',
                'setup' => 'PHPStan analyses $r = <library entry point that executes a callback returning int>; dumpType($r).',
                'observed' => 'The type PHPStan infers for $r.',
                'expected' => 'int (a @template return preserved through the execution API).',
                'basis' => 'PHPStan generics via PHPDoc (@template).',
                'unsure' => null,
            ],
        ];
    }
}
