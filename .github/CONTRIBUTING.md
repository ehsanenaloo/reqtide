# Contributing to Reqtide

Thanks for helping. This guide takes you from clone to pull request. Please follow the [Code of Conduct](CODE_OF_CONDUCT.md).

Reqtide is a small project with one maintainer. It is in beta (version 0.x), so the API can change. Bug reports, documentation fixes and small pull requests are welcome.

For anything larger than a small fix, or anything that adds a public class, an option or a dependency, please open an issue first and describe what you want to change. That avoids work on a change that does not fit the project.

## How to report

- **Bug:** use the [bug form](https://github.com/ehsanenaloo/reqtide/issues/new/choose). Give your PHP version, the Reqtide version or commit, a short script that reproduces the problem, what you expected and what happened.
- **Feature:** use the feature form. Say what you are trying to do and what is missing.
- **Question:** use the question form, after you read the [documentation](../docs/README.md).
- **Security problem:** report it privately. See [SECURITY.md](SECURITY.md). Never use a public issue.

Do not paste secrets, tokens or production data into an issue.

## Set up (Docker)

You need Git and Docker. All tools run in containers, so the results do not depend on the PHP on your machine. The containers are defined in `tools/docker/compose.yaml`.

```bash
git clone https://github.com/ehsanenaloo/reqtide.git
cd reqtide
docker compose -f tools/docker/compose.yaml run --rm php83 composer install
docker compose -f tools/docker/compose.yaml run --rm php83 composer check
```

`composer check` validates the three `composer.json` files, checks the coding standard, runs static analysis (PHPStan at its highest level) and runs the tests. It must pass before you open a pull request. CI runs it on PHP 8.3, 8.4 and 8.5. Use `php84` or `php85` instead of `php83` to run it on another version.

Everyday commands. Put `docker compose -f tools/docker/compose.yaml run --rm php83` in front of each one:

| Command | What it does |
|---------|--------------|
| `composer check` | Manifest validation, coding standard, static analysis and tests (what CI runs) |
| `composer test` | The tests that need no external service |
| `composer stan` | Static analysis |
| `composer cs` | Shows coding-standard problems without changing files |
| `composer cs:fix` | Fixes coding-standard problems |
| `composer docs:check` | Checks that the generated documentation files are current and that the code blocks in the docs match the examples |

Run one test file or one test:

```bash
docker compose -f tools/docker/compose.yaml run --rm php83 vendor/bin/phpunit tests/Pipeline
docker compose -f tools/docker/compose.yaml run --rm php83 vendor/bin/phpunit --filter testName
```

## Redis tests

The Redis package has tests that need a real server. Three scripts start Redis 7.4 and 8.2 in Docker and run one test lane against both. Run them from the host, not inside a container:

```bash
bash tools/check-redis.sh
bash tools/check-redis-clock.sh
bash tools/check-redis-tls.sh
```

`composer check` does not need Redis. Run the lanes when you change `packages/redis` or `src/State`.

## Code style

- Style is enforced by [PHP-CS-Fixer](https://cs.symfony.com/) with the rules in `.php-cs-fixer.dist.php`. Run `composer cs:fix` before you commit. CI runs `composer cs` and fails on any difference.
- Every file has `declare(strict_types=1);`. PHPStan must pass at its highest level.
- `.editorconfig` sets UTF-8, LF line endings and 4 spaces (2 for YAML, JSON and Markdown). Use an editor that follows it.
- The core (`src/`) has no runtime dependencies besides PHP. Do not import a framework, an HTTP client or a Redis library there. Adapters live in `packages/redis` and `packages/guzzle`.
- Follow the style of the code around your change.

## Tests, examples and documentation

- Add or update tests for every behavior change. A bug fix starts with a test that fails.
- The code and output blocks in `README.md` and `docs/` come from the scripts in `examples/` and their recorded output in `examples/expected/`. Do not paste a snippet that you have not run. Check the examples and the documentation:

```bash
tools/run-examples.sh        # runs the examples in Docker and compares their output
docker compose -f tools/docker/compose.yaml run --rm php83 composer docs:check
```

- If you change the public API, run `composer docs` to rebuild the generated documentation files. Do not edit those files by hand.
- Add a line under **Unreleased** in `CHANGELOG.md` for any change a user can see. If a change breaks existing code, also describe the steps to migrate in `UPGRADE.md`.
- Write short sentences and plain words. State what was checked and how. Do not make claims about speed, support or availability that you did not measure.

## Commit messages

Use short messages in the imperative mood with a type prefix: `feat:`, `fix:`, `docs:`, `refactor:`, `test:`, `chore:` or `ci:`.

```text
feat: add a sliding-window option to the rate limiter
fix: release the permit when the callback throws
docs: correct the retry example
test: cover the half-open circuit state
```

Keep each commit to one change. Explain the reason in the body when it is not obvious, and reference the issue (`Fixes #123`). Do not commit `vendor/`, `build/` or `composer.lock`.

## What a good pull request has

- One purpose. Branch from `main` and link the related issue.
- `composer check` passes. For Redis changes, the Redis lanes pass too.
- Tests for behavior changes, and updated documentation.
- A line under **Unreleased** in `CHANGELOG.md` for user-visible changes.
- A description that says what changed and why, which checks you ran and which you could not run.
- No new required dependency in the core, and no breaking change unless you call it out.

## License

Participation is governed by the [Code of Conduct](CODE_OF_CONDUCT.md). By contributing, you agree that your contribution is licensed under the [MIT License](../LICENSE).
