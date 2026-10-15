# Examples

Eleven small scripts that use the real public API of the core package, with its process-local, in-memory state. Examples 01 to 05 are annotated and check their own behaviour with explicit assertions; 06 to 11 are self-contained and are the source of every code and output block in [`docs/`](../docs/README.md) and in the README. All of them exit non-zero on anything unexpected, so they double as smoke tests.

> **Status: beta (version 0.x).** These examples run from this repository. The Redis and Guzzle packages are separate packages with their own examples; nothing here uses them.

## What you must know before copying anything

- **State is process-local.** The memory backends hold rate-limit, circuit, concurrency, retry-budget and cooldown state in the memory of the PHP process that created them. Nothing is shared with other processes or servers.
- **Under PHP-FPM, construction is per request.** If you build the pipeline and its backends in request code, every request starts with a fresh quota and a closed circuit, and no request sees another's failures. Limits therefore do not add up across requests or workers. Sharing state across requests needs a shared backend, such as the separate Redis package.
- **Running callbacks are not interrupted.** Deadlines are checked between steps (before an attempt, around a delay), not inside your code. The synchronous runtime cannot enforce an attempt timeout; `explain()` says so (`attempt_timeout=unsupported`, `physical_stop=not_guaranteed`).
- **Retries need your explicit statement of safety.** Reqtide cannot know whether repeating your callable is safe. Retrying requires a retry budget and, on every call, a replay-safety assertion: `OperationSafety::replayableRead(ReplaySafetyContract::forReadOnlyCallable(...))`. Calling `forReadOnlyCallable()` IS your assertion that the callable is a repeatable read; it is never inferred or checked. The builder tells you which of these is missing.
- **You decide what is retryable.** `ExceptionClassifier` is a ready-made classifier, but its defaults are conservative: no exception is retried or replaced by a fallback unless you list its class (`retryOn()`, `fallbackOn()`); an unlisted exception still counts as a circuit failure. Example 03 shows the hand-written `OutcomeClassifier` the class replaces.

## The examples

| Script | Shows |
|---|---|
| [`01-quick-start.php`](01-quick-start.php) | **Start here.** `PipelineBuilder` + `ExceptionClassifier`: rate limit, retry with a retry budget, circuit breaker and a deadline around a flaky callable, in about a dozen lines of setup. Real (millisecond) retry delays. |
| [`02-failure-demo.php`](02-failure-demo.php) | The circuit opens after repeated failures; the next call is refused with a typed `ReqtideException` without invoking the callable; the refusal as Problem JSON naming the operation (simple and detailed mode). Rate-limit refusal. |
| [`03-explain-and-detailed.php`](03-explain-and-detailed.php) | **The explicit low-level API** (`PipelineDefinition`, `RuntimeEnvironment`, a hand-written classifier, a hand-built context) plus `explain()` and `executeDetailed()`: disposition, per-attempt records, diagnostics and summary counters. |
| [`04-fallback.php`](04-fallback.php) | Fallbacks. Default scope replaces business failures only; `FallbackScope::IncludeAdmissionRefusals` also replaces a circuit refusal and keeps it as `policyCause`; a failing fallback surfaces as `FallbackFailedException`. |
| [`05-testing-with-virtual-clock.php`](05-testing-with-virtual-clock.php) | `Enaxon\Reqtide\Testing`: `VirtualClock` (`startingAt()`), `VirtualDelayScheduler`, `ControlledRuntimeAdapter`, `RecordingEventSink`, `SeededRandomSource`. Backoff, deadlines, circuit recovery and seeded jitter without sleeping; a replay contract anchored on the virtual clock. |
| [`06-getting-started.php`](06-getting-started.php) | The README quick start and [docs/guides/getting-started.html](../docs/guides/getting-started.html): the smallest useful pipeline, self-contained. |
| [`07-error-handling.php`](07-error-handling.php) | [docs/guides/error-handling.html](../docs/guides/error-handling.html): your exceptions versus Reqtide's refusals, `ReasonCode`, `Problem` JSON, detailed mode, retry-stop reasons, configuration errors. |
| [`08-testing.php`](08-testing.php) | [docs/guides/testing.html](../docs/guides/testing.html): virtual time, scripted attempts and recorded events with plain checks (no test framework). |
| [`09-choosing-policies.php`](09-choosing-policies.php) | [docs/guides/choosing-policies.html](../docs/guides/choosing-policies.html): one small pipeline per protection (rate limit algorithms, bulkhead, circuit windows, Retry-After and cooldown, fallback scopes, deadline). |
| [`10-concepts.php`](10-concepts.php) | [docs/guides/concepts.html](../docs/guides/concepts.html): `explain()`, one operation with several attempts, and the partition a context must match. |
| [`11-rate-limit-charges.php`](11-rate-limit-charges.php) | [docs/guides/rate-limiting.html](../docs/guides/rate-limiting.html): a retry is charged like a first attempt, and a cost above the capacity can never be admitted. |

`support.php` is shared plumbing for examples 01 to 05 (`check()`, `say()`, the two example exception types, and the low-level helpers that only example 03 uses). `autoload.php` only locates Composer's autoloader and is used by every script. Neither is part of the API. Examples 06 to 11 do not use `support.php`; in your own project the `autoload.php` line becomes `require __DIR__ . '/vendor/autoload.php';`.

Examples 05 and 08 use the testing package. In your own project it is a **dev** dependency only; the other examples use `SystemClock` on purpose, because they show production wiring.

## Run

Docker is the supported way (PHP is pinned in `tools/docker/`; Composer's vendor directory is `/opt/reqtide-vendor` inside the container):

```bash
# run all eleven and compare each stdout with examples/expected/*.out
tools/run-examples.sh

# another PHP version
PHP_SERVICE=php84 tools/run-examples.sh

# a single script
docker compose -f tools/docker/compose.yaml run --rm php83 php examples/02-failure-demo.php
```

Without Docker, `composer install` at the repository root and run `php examples/01-quick-start.php`, or `tools/run-examples.sh --local`. `autoload.php` finds the autoloader via `COMPOSER_VENDOR_DIR`, `/opt/reqtide-vendor`, or `vendor/` at the repository root, in that order. On Git Bash for Windows prefix Docker commands with `MSYS_NO_PATHCONV=1`.

`tools/run-examples.sh` exits 0 only if every script exits 0 **and** its output equals the expected file. After an intended change to an example, run `tools/run-examples.sh --update`, review the diff of `examples/expected/` and commit both. The code and output blocks in the README and in `docs/` are verbatim excerpts of these scripts and their expected files: after changing an example, run `php tools/check-docs.php` (in the same container, for example `docker compose -f tools/docker/compose.yaml run --rm php83 php tools/check-docs.php`) and update the documentation if it reports a mismatch.

## Determinism

Output is deterministic because the scripts print only stable facts: counts, dispositions, reason codes, fixed ids. They never print timings, random ids or wait durations. Deadlines are generous (30 s) because with a 1 s deadline the retry in example 01 stopped with `retry_deadline_reached` after the first attempt on Docker Desktop for Windows (sources on a bind mount). The cause was not isolated; slow first-call class loading is the likely one. Output was identical on PHP 8.3, 8.4 and 8.5 in the pinned containers; that is a local run, not a support claim.
