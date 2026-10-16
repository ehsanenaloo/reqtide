#!/usr/bin/env bash
# Run the wire tests: an HTTP server written in Go (packages/guzzle/tests/Wire/server.go) receives requests from the
# Guzzle package and records what arrived. The server shares no code with PHP, Guzzle or this project. The binary is built
# in a pinned Go image, then the PHP suite runs against it on PHP 8.4 (PHP_SERVICE selects another lane). A second run
# repeats the requests through nginx in front of the same server (compose profile "wire-proxy").
#
#   tools/check-wire.sh
#
# Local Docker runs are a developer check, not a supported-platform or production claim. The same script runs in CI.
set -u

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root" || exit 2
export MSYS_NO_PATHCONV=1

image="golang:1.24@sha256:d2d2bc1c84f7e60d7d2438a3836ae7d0c847f4888464e7ec9ba3a1339a1ee804"
php_service="${PHP_SERVICE:-php84}"
mount="$(pwd -W 2>/dev/null || pwd)"
compose=(docker compose -f tools/docker/compose.yaml --profile wire-proxy)

cleanup() {
    "${compose[@]}" down --remove-orphans >/dev/null 2>&1
}
trap cleanup EXIT

mkdir -p build/wire
echo "--- building the wire server with ${image%%@*}"
if ! docker run --rm -v "${mount}:/src" -w /src -e CGO_ENABLED=0 -e GOFLAGS=-trimpath "$image" \
    go build -o build/wire/server packages/guzzle/tests/Wire/server.go; then
    echo "FAIL  the wire server did not build" >&2
    exit 1
fi

echo "--- running the wire tests on ${php_service}"
if ! "${compose[@]}" run --rm \
    -e RT_WIRE_SERVER=/app/build/wire/server -e RT_REQUIRE_WIRE=1 \
    "$php_service" bash -c 'composer install --no-interaction --no-progress -q && composer test:wire -- --filter WireTest'; then
    echo "FAIL  wire / ${php_service}" >&2
    exit 1
fi
echo "PASS  wire / ${php_service}"

echo "--- starting the wire server and nginx"
if ! "${compose[@]}" up -d --wait wire-origin wire-proxy; then
    echo "FAIL  the wire server and nginx did not start" >&2
    "${compose[@]}" logs --tail 40 wire-origin wire-proxy >&2
    exit 1
fi

echo "--- running the wire tests through nginx on ${php_service}"
if "${compose[@]}" run --rm \
    -e RT_WIRE_PROXY=http://wire-proxy -e RT_WIRE_ORIGIN=http://wire-origin:8080 -e RT_REQUIRE_WIRE=1 \
    "$php_service" composer test:wire -- --filter WireProxyTest; then
    echo "PASS  wire through nginx / ${php_service}"
    exit 0
fi
echo "FAIL  wire through nginx / ${php_service}" >&2
"${compose[@]}" logs --tail 40 wire-proxy >&2
exit 1
