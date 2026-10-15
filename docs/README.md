# Reqtide PHP documentation

*Applies to version 0.1.0 (beta). Checked 2026-10-15.*

Static HTML guides for Reqtide PHP, in English. The site needs no build step to read, no external services and no JavaScript to be complete. With JavaScript it adds site search, a theme switch, guide filtering and copy buttons. Start at [`index.html`](index.html). Every PHP code block and every output block in the guides is taken verbatim from a script in [`examples/`](https://github.com/ehsanenaloo/reqtide/tree/main/examples) (for the two package guides, from `packages/redis/examples` or `packages/guzzle/examples`) that the project runs and compares with recorded output, and the API reference is generated from the real classes in `src/` and in the two packages.

> **Beta (version 0.x).** The core package keeps its state in one process. Shared state in Redis and a Guzzle integration are separate packages; they were run in Docker and in CI. See [Limits and non-guarantees](guides/limits.html) for what that means.

## Learning path

1. [Install](guides/install.html): install Reqtide PHP from a clone with a Composer path repository, or run the examples in Docker. Packagist is not available yet.
2. [Getting started](guides/getting-started.html): build your first protected call: a retry, a retry budget, a rate limit, a circuit breaker and a total deadline, line by line.
3. [Concepts](guides/concepts.html): the pipeline you build once, the operation and its attempts, the one deadline that covers them, and the partition whose state they share.
4. [Choosing policies](guides/choosing-policies.html): which protection answers which problem, how the classifier decides what counts as a failure, and the sizing rules Reqtide enforces. Start here, then read the guide for each protection.
5. [Error handling](guides/error-handling.html): tell your own failures from Reqtide decisions, branch on ReasonCode and Problem, and read the full account of an operation in detailed mode.
6. [Testing with the virtual clock](guides/testing.html): test retries, backoff, deadlines and circuits without sleeping: a virtual clock, scripted attempts and recorded events.

The protection guides (retry, rate limiting, circuit breaker, bulkhead, fallback) do not depend on each other.

## All guides

**Get started**

- [Install](guides/install.html): Install Reqtide PHP from a clone with a Composer path repository, or run the examples in Docker. Packagist is not available yet.
- [Getting started](guides/getting-started.html): Build your first protected call: a retry, a retry budget, a rate limit, a circuit breaker and a total deadline, line by line.

**Core ideas**

- [Concepts](guides/concepts.html): The pipeline you build once, the operation and its attempts, the one deadline that covers them, and the partition whose state they share.

**Protections**

- [Choosing policies](guides/choosing-policies.html): Which protection answers which problem, how the classifier decides what counts as a failure, and the sizing rules Reqtide enforces. Start here, then read the guide for each protection.
- [Retry and budget](guides/retry-and-budget.html): Retries with backoff and jitter, the safety statement every retry needs, the retry budget that stops a retry storm, and how Retry-After is honored or returned to you.
- [Rate limiting](guides/rate-limiting.html): Token bucket, fixed window and an approximate sliding counter, what a charge means when retries are involved, and which refusal you get when the quota is spent.
- [Circuit breaker](guides/circuit-breaker.html): How the circuit breaker judges a window of completed calls, opens, probes and closes again, what counts as a failure, and what a refusal looks like.
- [Bulkhead and cooldown](guides/bulkhead-and-cooldown.html): Limit the work in flight with a leased bulkhead, and pause a whole partition after a server-stated wait with a shared cooldown. What each refuses and what a lease does not do.
- [Fallback](guides/fallback.html): Attach a fallback to one operation, choose whether it may also replace refusals by the rate limit, circuit breaker, bulkhead or cooldown, and know what it never replaces.

**Errors and tests**

- [Error handling](guides/error-handling.html): Tell your own failures from Reqtide decisions, branch on ReasonCode and Problem, and read the full account of an operation in detailed mode.
- [Testing with the virtual clock](guides/testing.html): Test retries, backoff, deadlines and circuits without sleeping: a virtual clock, scripted attempts and recorded events.

**Packages**

- [Shared state with Redis](guides/redis.html): Share one rate limit, circuit breaker, bulkhead, retry budget and cooldown between PHP processes with the Redis package. Set-up, ACL users, TLS, what each failure means, maintenance, and what a restart loses.
- [Guzzle requests with Reqtide](guides/guzzle.html): Wrap Guzzle requests with retries, deadlines, rate limits and circuit breaking. You state what may be sent twice. Body replay, Retry-After, the shared cooldown, client defaults, connection reuse, promises and limits.

**Reference**

- [API reference](guides/api-reference.html): The public surface you use day to day, with signatures generated from the real classes: PipelineBuilder, CompiledPipeline, ExceptionClassifier, policies, outcomes, events, Problem and the test helpers. The classes of the Redis and Guzzle packages have their own groups.
- [Limits and non-guarantees](guides/limits.html): An honest list of what version 0.x does not do, what Reqtide never promises, what it commits to, and what is only planned.

**Trust and help**

- [Comparison with other libraries](guides/comparison.html): A behavior comparison with six PHP libraries, written and run by the Reqtide author, with every caveat that applies.
- [FAQ](guides/faq.html): Short answers to common questions about install, behavior, errors, testing and contributing.

The wider set of annotated scripts, including the low-level API that the builder wraps, is in [`examples/`](https://github.com/ehsanenaloo/reqtide/tree/main/examples).

## Layout

```
docs/
  index.html          home page
  styles.css          one stylesheet (light and dark theme)
  app.js              theme, search, filter, copy buttons (progressive enhancement)
  search-index.js     search index (generated)
  llms.txt            page index for tools
  .nojekyll           tells GitHub Pages to serve the files as they are
  assets/             logo.svg, favicon.svg, social-preview.svg
  guides/             index.html (guide library) and one <slug>.html per guide
```

## Generated files

The pages are written by hand. Two files are generated; do not edit them by hand:

- `search-index.js` comes from `tools/build-docs-index.php`. It reads the home page and every guide.
- The signature listing in `guides/api-reference.html`, between the `api:begin` and `api:end` markers, comes from `tools/build-docs-api.php`. It reads the real classes in `src/`, `packages/redis/src` and `packages/guzzle/src`.

## Build and check

Run the tools from the repository root. On a machine without PHP, run them through Docker:

```sh
docker compose -f tools/docker/compose.yaml run --rm php83 php tools/build-docs-index.php
docker compose -f tools/docker/compose.yaml run --rm php83 php tools/build-docs-api.php
docker compose -f tools/docker/compose.yaml run --rm php83 php tools/check-docs.php
```

- `build-docs-index.php` and `build-docs-api.php` rewrite their file. With `--check` they only compare and exit with 1 on a difference.
- `check-docs.php` fails when a code or output block is not an excerpt of an executed example, when the API reference no longer matches `src/`, or when the search index is stale. It also runs both `--check` modes.

## Local preview

```sh
php -S 127.0.0.1:8766 -t docs
```

Open http://127.0.0.1:8766/ and stop the server with Ctrl+C. Opening `docs/index.html` directly from disk also works.

## Language and structure

- English only. Guides are not translated.
- Every page has `lang="en"`, a skip link, a `theme-color` meta tag, Open Graph tags and the SVG favicon.
- Every page uses relative links, so the site works from disk and from any address.
- Code blocks show the whole script or a verbatim excerpt of it. The line that loads Composer's autoloader appears as `require __DIR__ . '/vendor/autoload.php';`. In the repository the examples load it through `examples/autoload.php`.
- State is process-local everywhere. Each page says so again where it matters.
- Numbers in examples make a behavior visible. They are not recommendations.
- Links to `examples/` and `src/` are absolute, because those folders are not part of the Composer package.
- `assets/social-preview.svg` is the artwork for the repository social preview. Export it to a 1280x640 PNG to upload it on GitHub.

## Add or change a page

1. Change an example script first and record its output. Then write the page. Take every code and output block from the example.
2. Copy an existing guide in `guides/` as the template. Keep the head, header and sidebar the same. Use a lowercase slug with hyphens.
3. Add the page to the sidebar of every guide, to `guides/index.html`, to the lists above, to the home page where it belongs, and to [`llms.txt`](llms.txt).
4. Put the state the page was checked against and the date on the page. Update both when you re-check it.
5. Regenerate the search index and the API reference, then run the checks above.
6. Re-check [Limits and non-guarantees](guides/limits.html) and [Install](guides/install.html) whenever a package, a backend or a distribution channel is added.

All prose, HTML, CSS and JavaScript in this directory were authored for Reqtide PHP. The mark in `assets/logo.svg` is original. Fonts are the visitor's system fonts; nothing is loaded from other sites.
