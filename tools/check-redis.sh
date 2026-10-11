#!/usr/bin/env bash
# Run the Redis suites against real servers in Docker (compose profile "redis").
#
#   tools/check-redis.sh                    PHP 8.3 against Redis 7.4.11 and 8.2.10
#   PHP_SERVICE=php85 tools/check-redis.sh  another PHP lane (php83, php84, php85)
#   REDIS_SERVICES="redis74" tools/check-redis.sh   only some servers (redis74, redis82)
#   KEEP_SERVERS=1 tools/check-redis.sh     leave the servers running afterwards
#
# For every server: RT_REQUIRE_REDIS=1 (a missing server fails instead of skipping), RT_REDIS_EXPECT_VERSION set to the
# lane's exact version, then `composer test` for the suites that need no server and `composer test:redis` for the
# integration suite. The image digests that actually ran are printed so they can be recorded with the result.
#
# Local Docker runs are a developer check, not a supported-platform or production claim.
set -u

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root" || exit 2

compose=(docker compose -f tools/docker/compose.yaml)
php_service="${PHP_SERVICE:-php83}"
services="${REDIS_SERVICES:-redis74 redis82}"
failures=0

export MSYS_NO_PATHCONV=1

version_of() {
    case "$1" in
        redis74) echo "7.4.11" ;;
        redis82) echo "8.2.10" ;;
        *) echo "unknown redis service: $1" >&2; exit 2 ;;
    esac
}

cleanup() {
    if [ "${KEEP_SERVERS:-0}" != 1 ]; then
        "${compose[@]}" --profile redis down --remove-orphans >/dev/null 2>&1
    fi
}
trap cleanup EXIT

echo "--- starting $services"
# shellcheck disable=SC2086
if ! "${compose[@]}" --profile redis up -d --wait $services; then
    echo "FAIL  the Redis services did not become healthy" >&2
    exit 1
fi

for service in $services; do
    version="$(version_of "$service")"
    image="$("${compose[@]}" --profile redis images "$service" --format json 2>/dev/null | head -c 400)"
    echo "--- $service (expects Redis $version) with $php_service"
    [ -n "$image" ] && echo "image: $image"
    if "${compose[@]}" --profile redis run --rm \
        -e RT_REDIS_HOST="$service" -e RT_REDIS_PORT=6379 -e RT_REQUIRE_REDIS=1 -e RT_REDIS_EXPECT_VERSION="$version" \
        "$php_service" bash -c 'composer install --no-interaction --no-progress -q && composer test && composer test:redis'; then
        echo "PASS  $service / $php_service"
    else
        echo "FAIL  $service / $php_service"
        failures=$((failures + 1))
    fi
done

if [ "$failures" -ne 0 ]; then
    echo "check:redis: $failures failing lane(s)" >&2
    exit 1
fi
echo "check:redis: all lanes passed"
