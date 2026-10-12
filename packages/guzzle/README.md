# Reqtide Guzzle

**Retries, deadlines, rate limits and circuit breaking around Guzzle requests, with explicit rules for what may be sent twice.**

This package is the Guzzle integration of [Reqtide PHP](https://github.com/ehsanenaloo/reqtide). It is not released and not on Packagist. It works in the development repository, with process-local state or with the Redis backends of `enaxon/reqtide-redis` passed in (`ReqtideClient::withBackends`). Everything below was run only in local Docker tests.

## What exists today

| Item | State |
| --- | --- |
| `ReqtideClient`: `send`, `sendDetailed`, `sendAsync`, `explain`, `uri` | Works |
| Retry rules for reads and for mutations that you declare repeatable | Works |
| `Retry-After` (seconds and HTTP dates) and a shared cooldown | Works |
| Shared limits, circuit and cooldown across processes | Works with the Redis backends (tested with up to 6 processes) |
| Guzzle 7.15.5, PHP 8.3 to 8.5 command line, cURL handlers | Tested locally |
| PHP-FPM, Guzzle 8, the stream handler, PSR-18 | Not supported |

## Use

```php
$client = ReqtideClient::inMemory(
    PipelineBuilder::profile('orders-api', 'v1')
        ->partition('shop', 'prod', 'orders-api')
        ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::TransportEnforced)
        ->retry(3, BackoffMode::Exponential, Duration::milliseconds(100), Duration::seconds(1), JitterMode::Full)
        ->retryBudget(10, 10, Duration::seconds(60), Duration::seconds(12)),
    new GuzzleConfiguration(baseUri: 'https://api.example.com'),
);

$operation = HttpOperation::read(
    new Request('GET', $client->uri('/orders/42')),
    'orders.read',
    Duration::seconds(60),                                   // how long your "this is safe to repeat" statement holds
    HttpOutcomeClassifier::starter()->retryableOutcomeCodes(),
);
$response = $client->send($operation, Duration::seconds(10)); // 10 s for the whole operation, waits between attempts included
```

The examples in `examples/` run against a local test server and print what the server received. Run them with `tools/run-guzzle-examples.sh` in the development repository.

## Rules

- **What may be sent twice is your statement, not a guess.** `HttpOperation::read()` is for GET and HEAD. `HttpOperation::idempotentMutation()` is for a POST, PUT, PATCH or DELETE that the server deduplicates by an identity such as an `Idempotency-Key`; the key is checked before each attempt, and the body is checked by SHA-256 digest (`BodyIntegrity::Verified`, the default) so one key never carries two payloads; pass `BodyIntegrity::CallerAsserted` for a body that is too large or cannot be read twice. `HttpOperation::single()` is sent once. Core refuses a `single()` operation in a pipeline that retries more than once, before anything is sent.
- **A body must be replayable.** A seekable body is rewound and checked before each attempt. A body that is not seekable needs a body factory. Anything else is refused before the first request.
- **A 4xx or 5xx status is a response, not an exception.** After the last attempt you get the last response. A transport failure (refused connection, timeout) is the original Guzzle exception. `http_errors` and `allow_redirects` are forced off for every transfer and cannot be set as client defaults.
- **Only some client defaults are accepted.** `GuzzleConfiguration::$clientDefaults` takes `headers` (they only fill in headers a request does not have), `auth`, `proxy`, `verify`, `cert`, `ssl_key`, `crypto_method`, `force_ip_resolve`, `version` and `decode_content`. Any other option, for example `delay`, `query`, `body`, `json`, `sink`, `curl`, `cookies`, `on_headers`, `on_stats`, `timeout`, `base_uri` or `handler`, is refused with an `InvalidConfigurationException` that names it. A client default applies to every transfer outside the replay checks and outside the deadline: a default `delay` would sleep beyond the deadline, and a default `curl` could replace the timeouts and the connection rules below. Put per-request values in the request.
- **A request you did not declare repeatable never reuses a connection.** libcurl sends a request again on a new connection, whatever its method, when a reused keep-alive connection gives no bytes back (`Curl_retry_request`). Reqtide would not see that second dispatch. An operation built with `HttpOperation::single()` therefore sets `CURLOPT_FRESH_CONNECT` and `CURLOPT_FORBID_REUSE`, and the option cannot be overridden. Reads and mutations the server deduplicates by an identity may reuse connections, because a repeat of them is safe by your own statement. The cost for `single()` is a new TCP and TLS handshake. Residual risk: a retry that your own proxy or load balancer makes behind the connection is not visible here.
- **Empty `Expect` header.** A request with a body of known size gets an empty `Expect` header unless it already has one, so cURL does not wait for `100 Continue`.
- **Retryable outcomes are yours to name.** The `$retryableOutcomeCodes` argument of `read()` and `idempotentMutation()` defaults to an empty list. With the default, a pipeline that retries never retries and only reports the reason `outcome_not_retryable`. Pass `HttpOutcomeClassifier::starter()->retryableOutcomeCodes()` or your own list.
- **The base URI is not a security boundary.** `ReqtideClient::uri()` resolves a reference as RFC 3986 says: an absolute URI or one that starts with `//` replaces the base host, and `orders` against `https://h/v1` gives `https://h/orders`. A malformed reference throws `InvalidConfigurationException`. Reqtide does not check where a request goes.
- **No cap on response size.** The body is not limited; a hostile or faulty server can fill temporary storage within the timeout window. Use a `sink` or limits at your own layer.
- **Redirects are not followed.** A 3xx is a response; its target is never requested.
- **Timeouts.** The attempt timeout is the smaller of your attempt timeout and the time left in the operation. It is given to cURL as `timeout` and `connect_timeout`. `read_timeout` is never used, because the cURL handlers ignore it.
- **`Retry-After`.** Read for 429 and 503 (your classifier can change that), as seconds or as an HTTP date. A value that cannot be read, or one that is too large, starts no cooldown. With a `cooldown` policy, the cooldown stops further calls from the same partition without a request.
- **Handlers.** The client builds and owns its cURL handlers (`CurlHandler` for `send`, `CurlMultiHandler` for `sendAsync`). You cannot pass a handler.
- **Promises.** `sendAsync` returns a promise that settles only after the whole operation has ended. Guzzle has no event loop, so the operation runs when you call `wait()`, one operation at a time. While it waits, the driver runs the process-global Guzzle promise task queue, so unrelated promise callbacks of your program also run inside the deadline loop. There is no claim of concurrent operations, of detached completion or of a guaranteed stop of a running transfer. Cancelling asks the transport to stop; it does not prove it stopped.
- **Secrets.** Events, summaries, diagnostics and problems carry descriptor fields only (the method, an origin fingerprint, your operation group and a fingerprint of the Authorization and Proxy-Authorization headers of the request you give, keyed with your credential key and resolved against the client defaults `headers`, `auth`, `proxy`, `cert` and `ssl_key`, the request URI and any `credentialHeaders` you configure, so it covers what is really sent), never a URL, a query, a header or a body. Random secrets placed in all of them were searched for in everything Reqtide produces, and in Redis keys and values, without a hit. The exception that Guzzle throws for a transport failure holds the full URL: it is your result and is returned as it is. Stack traces can hold arguments; set `zend.exception_ignore_args=On` in production.
- **Shared state.** `ReqtideClient::inMemory()` keeps limits in the process, so under PHP-FPM every request starts fresh. `ReqtideClient::withBackends()` takes backends such as the Redis ones; see the Redis package for its rules.

## Cost

Measured on a laptop under Docker Desktop (large run-to-run noise, no claim): a call through the client with the in-memory state added about 30 to 130 microseconds to a bare Guzzle call with one policy set, and about 135 to 235 microseconds with five. The first request of a process cost 14 ms for bare Guzzle and 26 to 35 ms for the client when the sources were on a local disk; with the sources on a slow bind mount the first request took 0.6 to 1 second, because the client loads about 100 to 150 more files than bare Guzzle. Use an optimized autoloader and OPcache in production.

## Not planned for the first release

Guzzle 8, the stream handler, streaming bodies, protection against requests to internal addresses (that belongs in your network egress controls: Reqtide does not check where a request goes), and a PSR-18 adapter.

## License

[MIT](LICENSE).
