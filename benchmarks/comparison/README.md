# Competitor conformance harness

Dev-only. Not shipped, not part of the repository autoload, never required by `enaxon/reqtide`. It has its own
`composer.json`/`composer.lock`; its vendor tree is installed outside the repository root vendor
(`build/comparison/vendor`, git-ignored).

It is a **behavioural** harness (21 library-independent scenarios), not a performance benchmark: no latency or
throughput number is produced. A PASS or FAIL only says what the library did in this harness, in one process, on
virtual time.

## Run (reproducible)

Needs PHP >= 8.4.1 (`symfony/rate-limiter` 8.1 and `symfony/http-client` 8.1 require it), so use the `php84` or
`php85` service. `php83` cannot install the dependencies. Run from the repository root; on Git Bash on Windows keep
`MSYS_NO_PATHCONV=1`.

```sh
# 1. once per PHP service: install the pinned dependencies from composer.lock into build/comparison/vendor
MSYS_NO_PATHCONV=1 docker compose -f tools/docker/compose.yaml run --rm \
  -e COMPOSER_VENDOR_DIR=/app/build/comparison/vendor php84 composer install -d benchmarks/comparison

# 2. run all scenarios on PHP 8.4
MSYS_NO_PATHCONV=1 docker compose -f tools/docker/compose.yaml run --rm php84 php benchmarks/comparison/run.php

# 3. same on PHP 8.5 (the vendor tree is shared; reinstall only if composer reports a platform mismatch)
MSYS_NO_PATHCONV=1 docker compose -f tools/docker/compose.yaml run --rm php85 php benchmarks/comparison/run.php

# options: --only=S01,S08   --out=<dir>   (default out dir: benchmarks/comparison/results)
# environment: COMPARISON_VENDOR_DIR=<path> overrides build/comparison/vendor
```

Outputs: `results/latest.json` (raw observations plus library metadata) and `results/latest.md` (matrix and
observations). Both are overwritten by each run, so pass `--out=` when
comparing PHP versions. Exit code 2 means an adapter crashed (reported as ERROR, never converted into a verdict); exit
code 3 means the vendor tree is missing.

Seven adapters run: Reqtide (this repository's working tree), symfony/rate-limiter, symfony/http-client,
caseyamcl/guzzle_retry_middleware, spatie rate limiter, ganesha and duat. Exact versions are in
`composer.lock`; cite a row only together with its version.

## Layout

- `src/Scenarios.php` library-independent scenario data (setup, observed, expected, basis, unsure).
- `src/VirtualTime.php`, `src/shadows.php` virtual clock; function shadows redirect unqualified `time()`,
  `microtime()`, `usleep()` inside the namespaces of the libraries under test. Library code is unmodified.
- `src/ReqtideRig.php` builds the Reqtide `RuntimeEnvironment` on `src/Testing` virtual time (no shadowing:
  Reqtide reads time only through its Clock and DelayScheduler seams).
- `adapters/*Adapter.php` one adapter per library; a scenario is a method `sNN()` that runs the real library.
  Missing method means NOT_APPLICABLE with the adapter's scope text.
- `adapters/ReqtideAdapter.php` drives `enaxon/reqtide` (`src/`) through its public API only (`PipelineBuilder`,
  `PipelineDefinition::compile()`, `CompiledPipeline::execute()/executeDetailed()`).

## Rules for adapters (including Reqtide)

Shared expectations are never adjusted; default configuration unless a scenario names a parameter; virtual time;
report FAIL honestly; every parameter chosen by the adapter that can influence a verdict is flagged "ADAPTATION" in the
observation; options not enabled are listed in `describe()['not_enabled']`.

## Fairness caveats in brief

- The same author wrote the scenarios and the adapters, and the scenarios derive from this project's own research, so
  they favour properties Reqtide claims. Neutral wording and published `basis`/`unsure` fields mitigate this; they do
  not remove it.
- Reqtide has no numeric defaults, so retry budget size, leases and attempt timeout were chosen by the adapter.
  Six Reqtide cells may advantage Reqtide (S04, S05, S10, S12, S16, S17).
- Only process-local backends and virtual time were exercised. No Redis, no real transport, no real concurrency.
  Reqtide has no shared backend yet, so Redis-capable competitors are only compared on their in-memory path.

## Verification of these instructions

The commands above were run on 2026-10-09 with the `php84` (8.4.26) and `php85` (8.5.11) services: both exit 0 with
"21 scenarios x 7 adapters, 0 harness errors", and the verdict matrices (section "Matrix" of `latest.md`) are identical
to each other and to the committed `results/latest.md`. The `php83` service was not tried because the dependency
constraints exclude it. This is a local Docker check, not a supported-platform claim.
