#!/usr/bin/env bash
# Run the PHP-FPM lane: nginx and PHP-FPM (six static workers) in front of the application in tools/fpm-lane/public, and a
# Redis server, all in Docker (compose profiles "fpm" and "redis"). The driver sends requests through nginx and checks what an
# FPM deployment does: fresh process-local state per request, Redis state shared by requests and workers, a bulkhead under
# concurrent requests, a deadline and a retry (see tools/fpm-lane/drive.php).
#
#   tools/check-fpm.sh                      FPM and CLI client on PHP 8.4
#   PHP_SERVICE=php83 tools/check-fpm.sh    the same on PHP 8.3 (php85 for PHP 8.5)
#   KEEP_SERVERS=1 tools/check-fpm.sh       leave the servers running afterwards
#
# The FPM image has the PHP version of the selected service; the libraries come from the vendor volume of that
# service, which this script fills first. A missing server fails the lane.
#
# Local Docker runs are a developer check, not a supported-platform or production claim. The same script runs in CI on a
# GitHub-hosted Linux runner.
set -u

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root" || exit 2

compose=(docker compose -f tools/docker/compose.yaml --profile redis --profile fpm)
export MSYS_NO_PATHCONV=1

# PHP_SERVICE picks the PHP version of both the FPM server and the CLI client (php83, php84 or php85).
php_service="${PHP_SERVICE:-php84}"
case "$php_service" in
    php83) export FPM_PHP_VERSION=8.3.35 FPM_MINOR=8.3 FPM_VENDOR=83 ;;
    php84) export FPM_PHP_VERSION=8.4.26 FPM_MINOR=8.4 FPM_VENDOR=84 ;;
    php85) export FPM_PHP_VERSION=8.5.11 FPM_MINOR=8.5 FPM_VENDOR=85 ;;
    *) echo "unknown PHP_SERVICE: $php_service (use php83, php84 or php85)" >&2; exit 2 ;;
esac

cleanup() {
    if [ "${KEEP_SERVERS:-0}" != 1 ]; then
        "${compose[@]}" down --remove-orphans >/dev/null 2>&1
    fi
}
trap cleanup EXIT

echo "--- installing the PHP libraries into the vendor volume"
if ! "${compose[@]}" run --rm "$php_service" composer install --no-interaction --no-progress -q; then
    echo "FAIL  composer install" >&2
    exit 1
fi

echo "--- starting Redis, PHP-FPM and nginx"
if ! "${compose[@]}" up -d --build --wait redis82 fpm web; then
    echo "FAIL  the servers did not start" >&2
    "${compose[@]}" logs --tail 40 fpm web >&2
    exit 1
fi

# nginx answers as soon as it runs; wait until the application does.
ready=0
for _ in $(seq 1 30); do
    if "${compose[@]}" run --rm "$php_service" php -r 'exit(@file_get_contents("http://web/info") !== false ? 0 : 1);' >/dev/null 2>&1; then
        ready=1
        break
    fi
    sleep 1
done
if [ "$ready" != 1 ]; then
    echo "FAIL  the application did not answer" >&2
    "${compose[@]}" logs --tail 40 fpm web >&2
    exit 1
fi

if "${compose[@]}" run --rm "$php_service" php tools/fpm-lane/drive.php --base=http://web --redis-host=redis82; then
    echo "PASS  fpm / php $FPM_MINOR"
    exit 0
fi
echo "FAIL  fpm / php $FPM_MINOR" >&2
"${compose[@]}" logs --tail 40 fpm >&2
exit 1
