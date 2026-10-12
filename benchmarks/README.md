# Benchmarks (local in-memory slice)

Micro-benchmarks of `CompiledPipeline::execute()` over the process-local memory backends. They produce **raw measurements**. They are not performance targets, budgets, comparisons or claims, and nothing in this repository may cite them as such. Redis is measured by `benchmarks/redis-bench.php` and the Guzzle adapter by `benchmarks/guzzle-bench.php` (raw files and notes are in the technical documents, PERF_BASELINE_REDIS and PERF_BASELINE_GUZZLE).

## What is measured

One sample is the wall time, in nanoseconds, of one logical operation on the **success path**:
build an `ExecutionContext` (including its `Deadline`), then `execute()` a trivial callable. Context construction is inside the timed region because every real caller pays for it.

| Scenario | Policies installed |
|---|---|
| `baseline_context_only` | none: builds the context and calls the callable directly, without a pipeline. The cost floor. |
| `empty_pipeline` | attempt only (no retry, no admission policy) |
| `rate_limit_only` | token bucket (capacity far above what a run consumes) |
| `circuit_only` | circuit breaker, never opens on the success path |
| `concurrency_only` | concurrency limit of 1000 |
| `retry_budget_only` | up to 3 attempts plus retry budget; no retry happens on the success path |
| `cooldown_only` | cooldown policy, never active |
| `combined_all_five` | all of the above together |

Not measured: failure paths, refusals, retry delays, fallbacks, event sinks other than the null sink, multi-partition state, or contention (PHP runs one request per process; there is none to measure locally).

## Method

- Every scenario runs in a **fresh PHP process**, 3 times (`--repeats`), so one noisy process cannot hide in an average.
- Per process: `--warmup` (default 1000) operations are executed and discarded, `gc_collect_cycles()` runs, then `--iterations` (default 5000) operations are timed individually with `hrtime(true)`.
- Raw samples (every nanosecond value) are stored in the JSON together with the derived `min / p50 / p95 / p99 / max / mean / stddev`. Percentiles use the nearest-rank method.
- The cost of timing itself is recorded (`timer_overhead_ns`: back-to-back `hrtime(true)` reads) and is **not** subtracted.
- Memory is `memory_get_usage()` before and after the timed loop (the sample array is preallocated first, so it is not counted), plus the process peak. Peak includes autoloading and the sample array.
- Operations are checked to succeed every time; a run that does not succeed exits non-zero.
- The coverage extension is switched off (`-d pcov.enabled=0`). OPcache is **not** enabled by the stock Docker image for the CLI; the run is recorded as it was. Additional runs can set ini values with `--php-d=...`, which are recorded in the file.

## Machine requirements for results worth keeping

A result is only comparable to another if the following are recorded and similar. `run-all.php` writes most of it into the `environment` block; fill in what a container cannot see (the host) yourself:

- PHP version and SAPI, `opcache.enable_cli`, `opcache.jit`, loaded extensions (Xdebug and a collecting pcov make numbers meaningless).
- CPU model, logical cores and any CPU limit (`cgroup_cpu_max`), RAM, host OS, whether the process runs in a container or VM.
- Power mode: plugged in, performance profile, no thermal throttling. A laptop on battery is not a benchmark machine.
- Nothing else running. Close editors, indexers, browsers and other containers; note the load average (recorded at start and end).
- A fixed library revision: `source_digest_sha1` identifies the exact sources measured.

**Docker Desktop on Windows (WSL2 VM, bind-mounted sources) is noisy.** Expect a long tail (large p99 and max) from VM scheduling and host activity. Treat such numbers as a smoke test of the harness, not as a measurement of the library.

## Run

```bash
# from the repository root
docker compose -f tools/docker/compose.yaml run --rm php84 \
    php -d pcov.enabled=0 benchmarks/run-all.php --out=build/bench/local-php8.4-stock.json

# one scenario, one process, JSON to stdout
docker compose -f tools/docker/compose.yaml run --rm php84 \
    php -d pcov.enabled=0 benchmarks/bench.php --scenario=empty_pipeline --iterations=2000 --warmup=500
```

On Git Bash for Windows, prefix with `MSYS_NO_PATHCONV=1`. `build/` is git-ignored. Keep results that serve as evidence together with the environment block, and add no interpretation beyond the measurements.

## Files

| File | Purpose |
|---|---|
| `scenarios.php` | Scenario definitions (pipelines and per-operation closures). |
| `bench.php` | Runs one scenario in this process and writes its raw samples. |
| `run-all.php` | Starts one fresh process per scenario and repeat, adds environment info, writes one JSON file. |
| `body-digest.php` | Times one body digest of the Guzzle adapter (`BodyIntegrity::Verified`) for 0 B to 16 MiB in memory and temporary-file streams. |
| `redis-cleanup-bench.php` | Times the bounded cleanup paths of the Redis backends (stale queue prefix, mass lease expiry, maintenance, saturated registry) and the ACL preflight, with the server's own slow-log durations. |
