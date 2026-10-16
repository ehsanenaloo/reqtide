<div align="center">

**English** | [فارسی](.github/README.fa.md)

<a href="https://enaloo.com/apps/reqtide/docs/"><img src=".github/assets/logo.svg" width="112" height="112" alt="Reqtide logo"></a>

# Reqtide PHP

**Retries, rate limits, a circuit breaker and a deadline in one pipeline. Testable under virtual time.**

A resilience toolkit for PHP code that calls unreliable APIs.
No runtime dependencies in the core package. Beta: version 0.x, the API can change between minor versions.

[![User guide](https://img.shields.io/badge/User%20guide-Open%20the%20docs-0f766e?logo=readthedocs&logoColor=white&style=for-the-badge)](https://enaloo.com/apps/reqtide/docs/)
[![Examples](https://img.shields.io/badge/Examples-Run%20in%20Docker-2496ED?logo=docker&logoColor=white&style=for-the-badge)](examples)

[![License: MIT](https://img.shields.io/badge/license-MIT-green)](LICENSE)
![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4)
![Core runtime dependencies: none](https://img.shields.io/badge/core%20runtime%20dependencies-none-lightgrey)
![Status: beta](https://img.shields.io/badge/status-beta-yellow)
[![Docs](https://img.shields.io/badge/docs-guides-0f766e)](https://enaloo.com/apps/reqtide/docs/)

</div>

## Why this exists

A retry loop here, a counter there, a flag that says "the service is down". These pile up in application code, and they disagree about who stops first. Testing them means sleeping.

Reqtide puts these decisions in one pipeline with a fixed order. You set a total deadline, retries with a budget, a rate limit, a circuit breaker, a bulkhead and, if you want one, a fallback. Failures come back as typed results you can branch on. Every number is yours, because Reqtide ships no defaults. Time is injected, so a test of exponential backoff runs in microseconds.

It wraps a callable. It is not an HTTP client, a queue or edge protection. In the core package the state lives in the memory of one PHP process (see [Limits and non-guarantees](https://enaloo.com/apps/reqtide/docs/limits/)).

---

## Install

| Route | Status |
| --- | --- |
| Packagist (`composer require enaxon/reqtide`) | Available from 0.1.0 |
| PHP 8.3, 8.4, 8.5 | Tested on the command line |
| PHP-FPM | Three lanes in CI (PHP 8.3, 8.4 and 8.5), each with nginx, six workers and Redis 8.2.10. Apache and other web servers: not run |

```shell
composer require enaxon/reqtide
composer require enaxon/reqtide-redis   # optional: state shared by processes
composer require enaxon/reqtide-guzzle  # optional: Guzzle integration
```

The three packages are released together at one version. Each adapter needs exactly the same version of the core. To run the examples without PHP on your machine, clone the repository and use Docker: see [Install](https://enaloo.com/apps/reqtide/docs/install/).

Save this as `demo.php` in a project with the core package installed. It is [`examples/06-getting-started.php`](examples/06-getting-started.php).

```php
<?php

declare(strict_types=1);

namespace Reqtide\Examples\GettingStarted;

use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Execution\OperationSafety;
use Enaxon\Reqtide\Execution\ReplaySafetyContract;
use Enaxon\Reqtide\Outcome\ExceptionClassifier;
use Enaxon\Reqtide\Pipeline\PipelineBuilder;
use Enaxon\Reqtide\Policy\AttemptTimeoutRequirement;
use Enaxon\Reqtide\Policy\BackoffMode;
use Enaxon\Reqtide\Policy\JitterMode;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\Ratio;

require __DIR__ . '/vendor/autoload.php';

/** The failure we treat as transient. Anything else is a plain failure and is never retried. */
final class UpstreamTimeout extends \RuntimeException {}

// Build the pipeline once and reuse it. Every number is yours: Reqtide has no defaults.
$pipeline = PipelineBuilder::profile('payments', 'v1')
    ->partition('shop', 'prod', 'payments-api')
    // One attempt may take at most 2 s (checked by you, not enforced inside your code: "Cooperative").
    ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::Cooperative)
    // At most 3 attempts in total (the first call counts), exponential backoff from 10 ms up to 100 ms.
    ->retry(3, BackoffMode::Exponential, Duration::milliseconds(10), Duration::milliseconds(100), JitterMode::Full)
    // Retries draw from a budget so a failing dependency is not hammered: 10 tokens, 10 more per minute.
    ->retryBudget(10, 10, Duration::seconds(60), Duration::seconds(10))
    // Token bucket: 5 tokens, refilled 5 per second. Every dispatch, retries included, costs 1 token.
    ->rateLimit(PipelineBuilder::tokenBucket(5, 5, Duration::seconds(1)))
    // Judge the last 10 completed calls; from 4 samples on, open at >= 1/2 failures, for 30 s, then allow 1 probe.
    ->circuitBreaker(PipelineBuilder::lastCalls(10), 4, Ratio::of(1, 2), Duration::seconds(30), 1, 1, Duration::seconds(10))
    // Only UpstreamTimeout may be retried.
    ->compileInMemory(ExceptionClassifier::create()->retryOn(UpstreamTimeout::class));

// The protected call. Here it fails twice with a timeout and then succeeds.
$calls = 0;
$callApi = static function (AttemptContext $attempt) use (&$calls): string {
    ++$calls;
    if ($calls < 3) {
        throw new UpstreamTimeout('upstream timed out');
    }

    return "ok on attempt {$attempt->dispatchedAttemptNumber}";
};

// One context per operation. Its deadline covers all attempts and waits together. forReadOnlyCallable() is YOUR
// statement that running this callable again is safe; Reqtide cannot check it, and refuses to retry without it.
$context = $pipeline->newContext(
    Duration::seconds(30),
    OperationSafety::replayableRead(ReplaySafetyContract::forReadOnlyCallable('get-payment', Duration::seconds(3600))),
);

echo $pipeline->execute($callApi, $context), "\n";
echo "invocations: {$calls}\n";
```

Output:

```text-output
ok on attempt 3
invocations: 3
```

`forReadOnlyCallable()` is your statement that running the callable again is safe. Reqtide never guesses, and does not retry without it. [Getting started](https://enaloo.com/apps/reqtide/docs/getting-started/) explains each line.

---

## What is inside

| Capability | What you get |
| --- | --- |
| **Total deadline** | One deadline across attempts, delays and fallback. A retry that cannot fit is not started. |
| **Retry** | Constant or exponential backoff, optional jitter, `Retry-After` as a lower bound, and a retry budget that caps extra traffic. |
| **Rate limit** | Token bucket, fixed window or approximate sliding counter, in integer arithmetic. |
| **Circuit breaker** | Count or time window, minimum samples, half-open probes. |
| **Bulkhead, cooldown** | Concurrency limit with leases; shared cooldown after `Retry-After`. |
| **Fallback** | Opt in per operation, for failures and optionally for refusals. |
| **Typed errors and events** | `ReasonCode`, redacted `Problem` (RFC 9457 shape), typed events, `explain()`. |
| **Test helpers** | Virtual clock, scripted attempts, seeded randomness, event recorder. Same package. |

This is the core package. It runs in memory and is tested. That is not a production claim.

Three Composer packages, one version:

| Package | What it does | State |
| --- | --- | --- |
| `enaxon/reqtide` | The pipeline, the policies, the typed results and the test helpers. No runtime dependencies. | Beta |
| [`enaxon/reqtide-redis`](packages/redis/README.md) | Rate limit, circuit breaker, bulkhead, retry budget and cooldown shared by processes, in Redis 7.0 or newer. | Beta. Tested on the PHP 8.3 to 8.5 CLI and in PHP-FPM lanes. Not supported: Sentinel, Cluster, Valkey |
| [`enaxon/reqtide-guzzle`](packages/guzzle/README.md) | `ReqtideClient`: the pipeline around Guzzle 7 requests, with explicit rules for what may be sent twice. | Beta. Tested on the PHP 8.3 to 8.5 CLI with cURL handlers. Not tested under PHP-FPM. Not supported: PSR-18 |

---

## What you can do

Every block below comes from a script in the repository, with its recorded output.

### Stop calling a broken service

Four calls fail, then the circuit opens. The fifth call is refused without running your code, with a typed exception that carries a machine-readable reason (from [`examples/02-failure-demo.php`](examples/02-failure-demo.php)).

```php
// Judge the last 4 completed calls, but only once 4 samples exist; open when at least half of them failed, stay
// open for 30 s. The permit lease (10 s) must exceed the attempt budget plus 1 s. The default classifier counts
// every exception as a circuit failure and retries none.
$pipeline = $base()
    ->circuitBreaker(PipelineBuilder::lastCalls(4), 4, Ratio::of(1, 2), Duration::seconds(30), 1, 1, Duration::seconds(10))
    ->compileInMemory(ExceptionClassifier::create());

$invocations = 0;
$broken = static function (AttemptContext $attempt) use (&$invocations): string {
    ++$invocations;

    return upstreamIsDown();
};

say('-- circuit breaker');
for ($i = 1; $i <= 4; ++$i) {
    try {
        $pipeline->execute($broken, $context($pipeline));
        check(false, 'a broken callable must not succeed');
    } catch (PermanentError $e) {
        // Closed circuit: your exception comes through untouched.
        say("call {$i}: PermanentError ({$e->getMessage()})");
    }
}
check($invocations === 4, 'the callable ran for each of the four closed-circuit calls');
```

```text-output
-- circuit breaker
call 1: PermanentError (upstream is down)
call 2: PermanentError (upstream is down)
call 3: PermanentError (upstream is down)
call 4: PermanentError (upstream is down)
call 5: Enaxon\Reqtide\Exception\AdmissionRejectedException
```

### Test without sleeping

The core package has a virtual clock, scripted attempts and an event recorder. Backoff of 100 ms and 200 ms takes no real time (from [`examples/08-testing.php`](examples/08-testing.php)).

```php
echo "-- backoff without sleeping\n";
[$pipeline, $clock, $scheduler, $runtime, $events] = rig(false);
$runtime->enqueue(
    DispatchScript::throwing(new UpstreamTimeout('503')),
    DispatchScript::throwing(new UpstreamTimeout('503')),
    DispatchScript::returning('ok'),
);
$start = $clock->peekMonotonic();
$result = $pipeline->execute($unused, context($pipeline, $clock));
$delays = array_map(static fn(Duration $d): int => intdiv($d->nanoseconds, 1_000_000), $scheduler->requestedDelays());

expect($result === 'ok', 'the third dispatch succeeds');
expect($runtime->dispatchCount() === 3, 'exactly three dispatches');
expect($delays === [100, 200], 'exponential backoff waited 100 ms, then 200 ms');
```

```text-output
-- backoff without sleeping
ok: the third dispatch succeeds
ok: exactly three dispatches
ok: exponential backoff waited 100 ms, then 200 ms
```

### Share limits between processes

With `enaxon/reqtide-redis`, two pipelines built separately draw from one token bucket. Here three tokens serve five calls, whoever asks (from [`packages/redis/examples/01-shared-rate-limit.php`](packages/redis/examples/01-shared-rate-limit.php); `tools/run-redis-examples.sh` starts a Redis server in Docker).

```PHP
/** What each "process" does: build the same pipeline on the Redis environment. */
$build = static function () use ($registry) {
    $builder = PipelineBuilder::profile('shared-limit', 'v1')
        ->partition('example', 'local', 'reports-api')
        ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::Cooperative)
        // Three tokens, one refilled per hour: three calls for the life of this script, across both pipelines.
        ->rateLimit(PipelineBuilder::tokenBucket(3, 1, Duration::seconds(3600)));

    return $builder->compile(RedisEnvironment::for($builder->definition(), ExceptionClassifier::create(), connection(), $registry));
};

$pipelines = ['A' => $build(), 'B' => $build()];
$sequence = ['A', 'B', 'A', 'B', 'A'];
```

```console
call 1 via A: served
call 2 via B: served
call 3 via A: served
call 4 via B: refused (rate.exhausted)
call 5 via A: refused (rate.exhausted)
served by A: 2, served by B: 1
```

If Redis is down, the call does not run and you get a `StateUnavailableException`.

### Protect Guzzle requests

With `enaxon/reqtide-guzzle`, you declare which requests may be sent twice. A 503 is a response: after the last attempt you get it back. The server here is the local test server of the repository (from [`packages/guzzle/examples/01-read-with-retry.php`](packages/guzzle/examples/01-read-with-retry.php)).

```PHP
$client = ReqtideClient::inMemory(
    PipelineBuilder::profile('orders-api', 'v1')
        ->partition('shop', 'prod', 'orders-api')
        // The transport enforces this timeout on every attempt.
        ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::TransportEnforced)
        // Up to three attempts, 20 ms apart, and a budget so that a failing server is not hammered.
        ->retry(3, BackoffMode::Constant, Duration::milliseconds(20), Duration::seconds(1), JitterMode::None)
        ->retryBudget(10, 10, Duration::seconds(60), Duration::seconds(12)),
);

$read = static fn(string $path): HttpOperation => HttpOperation::read(
    new Request('GET', $server->baseUri . $path),
    'orders.read',
    Duration::seconds(60),
    HttpOutcomeClassifier::starter()->retryableOutcomeCodes(),
);

$response = $client->send($read('/status/503'), Duration::seconds(10));
echo 'failing server: status ', $response->getStatusCode(), ', requests at the server: ', $server->count('/status/503'), "\n";
```

```console
failing server: status 503, requests at the server: 3
```

---

## A full guide

The guide is on [enaloo.com](https://enaloo.com/apps/reqtide/docs/): 16 pages, with search and light and dark themes.

| Topic | Read |
|---|---|
| Start | [Install](https://enaloo.com/apps/reqtide/docs/install/) · [Getting started](https://enaloo.com/apps/reqtide/docs/getting-started/) |
| Core ideas | [Concepts](https://enaloo.com/apps/reqtide/docs/concepts/) · [Choosing policies](https://enaloo.com/apps/reqtide/docs/choosing-policies/) |
| Protections | [Retry](https://enaloo.com/apps/reqtide/docs/retry-and-budget/) · [Rate limit](https://enaloo.com/apps/reqtide/docs/rate-limiting/) · [Circuit breaker](https://enaloo.com/apps/reqtide/docs/circuit-breaker/) · [Bulkhead and cooldown](https://enaloo.com/apps/reqtide/docs/bulkhead-and-cooldown/) · [Fallback](https://enaloo.com/apps/reqtide/docs/fallback/) |
| Errors and tests | [Error handling](https://enaloo.com/apps/reqtide/docs/error-handling/) · [Testing](https://enaloo.com/apps/reqtide/docs/testing/) |
| Reference | [API reference](src) · [Limits](https://enaloo.com/apps/reqtide/docs/limits/) · [Comparison](https://enaloo.com/apps/reqtide/docs/comparison/) · [FAQ](https://enaloo.com/apps/reqtide/docs/faq/) |

---

## When something goes wrong

Your own exceptions come out unwrapped. A decision of Reqtide, such as a refusal, is a typed exception with a stable reason code and a `Problem` you can log or return. (from [`examples/07-error-handling.php`](examples/07-error-handling.php)).

```php
// 3. Your own exceptions are never wrapped. They come out exactly as your callable threw them.
$failing = $base()->compileInMemory(ExceptionClassifier::create());
try {
    $failing->execute(static fn(): string => throw new UpstreamTimeout('upstream timed out'), $failing->newContext(Duration::seconds(30)));
} catch (UpstreamTimeout $e) {
    echo 'business exception: ', $e::class, ' (', $e->getMessage(), ")\n";
}
```

```text-output
business exception: Reqtide\Examples\ErrorHandling\UpstreamTimeout (upstream timed out)
```

Branch on `ReasonCode` and `retryability`, not on message text. See [Error handling](https://enaloo.com/apps/reqtide/docs/error-handling/).

---

## What we checked

These are local runs in Docker containers, on the command line.

- **Core.** The unit tests, static analysis at the highest PHPStan level and the coding standard pass on PHP 8.3, 8.4 and 8.5.
- **Examples and docs.** Every example runs, and its output is compared with a recorded file. Every code and output block in this README is checked against those files.
- **Redis package.** The suites run against Redis 7.4.11 and 8.2.10, with TLS and with a controllable server clock.
- **Guzzle package.** Guzzle 7.15.5 with the cURL handlers. Cross-process and fault scenarios are judged by the request counts a test server sees. Random secrets were searched for in everything the package produces, with no hit.

### Good to know

- Version 0.x is beta. The API can change between minor versions, and every break is listed in the changelog. CI runs on every push (GitHub Actions: PHP 8.3, 8.4 and 8.5, the Redis suites, the PHP-FPM lane, coverage and mutation testing); the results are on the Actions tab of the repository.
- PHP-FPM has three CI lanes (PHP 8.3, 8.4 and 8.5, each with nginx, six workers and Redis 8.2.10). Not tested: the Guzzle client under FPM, Apache and other web servers. Not supported: Redis Sentinel, Redis Cluster, Valkey, Guzzle 8 and PSR-18.
- The core package keeps its state in one process. Under PHP-FPM every request starts fresh.
- A running callback is not interrupted. The deadline is checked between steps.
- There is no exactly-once execution, and a rate unit is not given back when a call is cancelled before dispatch.
- The project has not had an independent security review.

The full list is in [Limits and non-guarantees](https://enaloo.com/apps/reqtide/docs/limits/).

---

## Requirements

- PHP **8.3** or newer, 64-bit (tested on 8.3, 8.4 and 8.5, command line only). Composer 2.
- The core package has no required Composer packages.
- `enaxon/reqtide-redis` needs `predis/predis` 3.7, `ext-openssl` and a Redis server 7.0 or newer with `maxmemory-policy noeviction`.
- `enaxon/reqtide-guzzle` needs `guzzlehttp/guzzle` 7.15.5 or newer 7.x, and `ext-curl`.

---

## FAQ

<details>
<summary><b>Is it on Packagist?</b></summary>

Yes: `composer require enaxon/reqtide`. See [Install](https://enaloo.com/apps/reqtide/docs/install/).
</details>

<details>
<summary><b>Does it retry a POST?</b></summary>

Only if you say repeating the call is safe, a retry budget exists and the exception class is listed.
</details>

---

## Contributing

Bug reports, documentation fixes and small pull requests are welcome. Read [CONTRIBUTING.md](.github/CONTRIBUTING.md) first. It explains the Docker workflow and the coding standard.

- **Report a bug.** Use the bug form and add a short script that shows it.
- **Ask for help.** See [SUPPORT.md](.github/SUPPORT.md).

Report vulnerabilities privately: [SECURITY.md](.github/SECURITY.md). Please follow the [Code of Conduct](.github/CODE_OF_CONDUCT.md).

### Contributors

Thanks to everyone who has helped. Your name appears here after your first merged contribution.

Recent changes are in [CHANGELOG.md](CHANGELOG.md).

---

## License

[MIT](LICENSE). Copyright 2026 Ehsan Enaloo.

## About

Made by [Ehsan Enaloo](https://github.com/ehsanenaloo). Reqtide PHP is a resilience toolkit for PHP: deadlines, retries, rate limits, circuit breakers and bulkheads, with a Redis backend and a Guzzle integration.

Topics: `php` `resilience` `retry` `rate-limit` `circuit-breaker` `bulkhead` `guzzle` `redis` `api` `http`
