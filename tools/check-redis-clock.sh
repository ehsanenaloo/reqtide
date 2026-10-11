#!/usr/bin/env bash
# Run the contract suites against real Redis servers whose clock the tests control (compose profile "redis-time").
#
#   tools/check-redis-clock.sh                    PHP 8.3 against Redis 7.4.11 and 8.2.10 under libfaketime
#   PHP_SERVICE=php85 tools/check-redis-clock.sh  another PHP lane (php83, php84, php85)
#   REDIS_SERVICES="redis74-ft" tools/check-redis-clock.sh   only some servers (redis74-ft, redis82-ft)
#   KEEP_SERVERS=1 tools/check-redis-clock.sh     leave the last server running afterwards
#
# The servers run one at a time because they read the same clock file (the shared volume mounted at /ft in the PHP
# containers). The production scripts run unchanged; only the server clock is under test control. For every server:
# RT_REQUIRE_REDIS=1 (a missing server fails instead of skipping), RT_FAKETIME_FILE=/ft/time, then `composer test:redis-clock`.
#
# Local Docker runs are a developer check, not a supported-platform or production claim.
set -u

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root" || exit 2

compose=(docker compose -f tools/docker/compose.yaml --profile redis-time)
php_service="${PHP_SERVICE:-php83}"
services="${REDIS_SERVICES:-redis74-ft redis82-ft}"
failures=0

export MSYS_NO_PATHCONV=1

version_of() {
    case "$1" in
        redis74-ft) echo "7.4.11" ;;
        redis82-ft) echo "8.2.10" ;;
        *) echo "unknown redis service: $1" >&2; exit 2 ;;
    esac
}

cleanup() {
    if [ "${KEEP_SERVERS:-0}" != 1 ]; then
        "${compose[@]}" down --remove-orphans >/dev/null 2>&1
    fi
}
trap cleanup EXIT

for service in $services; do
    version="$(version_of "$service")"
    echo "--- starting $service (Redis $version under libfaketime) with $php_service"
    if ! "${compose[@]}" up -d --build --wait "$service"; then
        echo "FAIL  $service did not become healthy" >&2
        failures=$((failures + 1))
        continue
    fi
    if "${compose[@]}" run --rm \
        -e RT_REDIS_HOST="$service" -e RT_REDIS_PORT=6379 -e RT_REQUIRE_REDIS=1 -e RT_REDIS_EXPECT_VERSION="$version" \
        -e RT_FAKETIME_FILE=/ft/time \
        "$php_service" bash -c 'composer install --no-interaction --no-progress -q && composer test:redis-clock'; then
        echo "PASS  $service / $php_service"
    else
        echo "FAIL  $service / $php_service"
        failures=$((failures + 1))
    fi
    "${compose[@]}" stop "$service" >/dev/null 2>&1
done

if [ "$failures" -ne 0 ]; then
    echo "check:redis-clock: $failures failing lane(s)" >&2
    exit 1
fi
echo "check:redis-clock: all lanes passed"
