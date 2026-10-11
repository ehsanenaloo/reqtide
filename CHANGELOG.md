# Changelog

All notable changes to Reqtide PHP are listed here.

The format follows [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/). The project intends to follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html) once a first release exists.

## [Unreleased]

Nothing has been released. There is no tagged version and the package is not on Packagist, so everything below is unreleased. While the project is alpha, any public name or behavior may change without notice.

### Added

- A resilience pipeline for PHP callables, built with `PipelineBuilder`:
  - One total deadline that spans attempts, retry delays and fallback. A running callback is never interrupted.
  - Retry with constant or exponential backoff, optional jitter and `Retry-After` as a lower bound.
  - A retry budget that caps extra traffic across operations. Retrying needs the budget, a retryable exception class and your statement that repeating the call is safe.
  - Rate limiting with a token bucket, a fixed window (aligned or first hit) or an approximate sliding counter.
  - A circuit breaker with a count or time window, a minimum number of samples and half-open probes.
  - A bulkhead (concurrency limit with leases) and a shared cooldown.
  - Opt-in fallbacks, for failures and optionally for refusals.
- Process-local, in-memory state for rate limit, circuit, concurrency, retry budget and cooldown, with bounded size.
- Typed results: the `ReasonCode` registry, a redacted `Problem` value shaped after RFC 9457 and serializable to JSON, and `ProblemCarrier` on every policy exception. Your own exceptions come out unwrapped.
- Typed events, `explain()` and detailed results with per-attempt records (`executeDetailed()`).
- `ExceptionClassifier`, a conservative ready-made classifier: nothing is retried or replaced by a fallback unless you list its class.
- Test helpers in the same package, namespace `Enaxon\Reqtide\Testing`: a virtual clock, a delay scheduler that advances the clock instead of sleeping, scripted attempts, seeded randomness and an event recorder.
- Documentation in `docs/`: install, getting started, choosing policies, error handling, testing, limits, a self-run comparison with six libraries, and a FAQ. Every code and output block is an excerpt of an example that is run and compared with recorded output.
- Runnable examples 01 to 11 in `examples/` with recorded output, and a script to run them.
- A short description for language-model readers in `docs/llms.txt`.
- Two separate packages in the development repository, not part of the archive of `enaxon/reqtide`:
  - `enaxon/reqtide-redis`: rate limit, circuit breaker, bulkhead, retry budget and cooldown state in Redis, shared by several processes.
  - `enaxon/reqtide-guzzle`: retries, deadlines, rate limits and circuit breaking around Guzzle requests.
  - Neither is released or on Packagist. Both were run only in local Docker: PHP 8.3 to 8.5 on the command line, Redis 7.4.11 and 8.2.10, Guzzle 7.15.5. PHP-FPM, Redis Sentinel, Redis Cluster, Valkey and PSR-18 are not supported.

### Changed

- The Composer vendor is `enaxon` (packages `enaxon/reqtide`, `enaxon/reqtide-redis` and `enaxon/reqtide-guzzle`) and the PHP namespace is `Enaxon\Reqtide`. The error type is the tag URI `tag:enaloo.com,2026:reqtide:problem:<code>`. Nothing was released under earlier names.
- The core package `enaxon/reqtide` has its manifest at the repository root. The test helpers ship inside it. Tests, tools, examples and the repository's own planning files are left out of the distribution archive, which holds `src/`, `docs/`, `composer.json`, `README.md`, `LICENSE` and `CHANGELOG.md`.
- The community files (contributing guide, code of conduct, security policy and support notes) moved to `.github/`.
- The README is shorter and links to the new documentation pages. The comparison table moved to `docs/guides/comparison.html`.

### Security

- No security-relevant change. See the [security policy](https://github.com/ehsanenaloo/reqtide/security/policy) for how to report a vulnerability privately.
