#!/usr/bin/env bash
# Runs every packages/redis/examples/NN-*.php and compares its stdout with packages/redis/examples/expected/NN-*.out.
#
#   tools/run-redis-examples.sh              start Redis 7.4.11 in Docker, run with php83 and compare (REDIS_SERVICE=redis82 for 8.2.10)
#   PHP_SERVICE=php84 tools/run-redis-examples.sh
#   tools/run-redis-examples.sh --local      run with the php on PATH against REDIS_HOST (default redis74) and REDIS_PORT
#   tools/run-redis-examples.sh --update     regenerate the expected files (review the diff before keeping it)
#
# Exit status: 0 only if every example exits 0 AND its output equals the expected file.
# Actual outputs are kept in build/redis-examples/ for inspection.
set -u

mode="docker"
update=0
for arg in "$@"; do
    case "$arg" in
        --local) mode="local" ;;
        --inner) mode="inner" ;;
        --update) update=1 ;;
        *) echo "unknown argument: $arg" >&2; exit 2 ;;
    esac
done

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [ "$mode" = "docker" ]; then
    service="${PHP_SERVICE:-php83}"
    server="${REDIS_SERVICE:-redis74}"
    extra=()
    [ "$update" = 1 ] && extra+=(--update)
    # MSYS_NO_PATHCONV stops Git Bash on Windows from rewriting container paths.
    export MSYS_NO_PATHCONV=1
    cd "$root" || exit 2
    compose=(docker compose -f tools/docker/compose.yaml --profile redis)
    trap '"${compose[@]}" down --remove-orphans >/dev/null 2>&1' EXIT
    "${compose[@]}" up -d --wait "$server" || exit 1
    "${compose[@]}" run --rm -e REDIS_HOST="$server" "$service" \
        bash tools/run-redis-examples.sh --inner ${extra[@]+"${extra[@]}"}
    exit $?
fi

cd "$root" || exit 2
mkdir -p build/redis-examples packages/redis/examples/expected
failures=0
count=0

for script in packages/redis/examples/[0-9][0-9]-*.php; do
    name="$(basename "$script" .php)"
    actual="build/redis-examples/$name.out"
    expected="packages/redis/examples/expected/$name.out"
    count=$((count + 1))

    php "$script" >"$actual" 2>"build/redis-examples/$name.err"
    status=$?
    tr -d '\r' <"$actual" >"$actual.tmp" && mv "$actual.tmp" "$actual"

    if [ "$status" -ne 0 ]; then
        echo "FAIL  $name  (exit status $status)"
        sed 's/^/        /' "build/redis-examples/$name.err" | head -20
        failures=$((failures + 1))
        continue
    fi
    if [ "$update" = 1 ]; then
        cp "$actual" "$expected"
        echo "WROTE $name"
        continue
    fi
    if [ ! -f "$expected" ]; then
        echo "FAIL  $name  (no expected file: $expected)"
        failures=$((failures + 1))
        continue
    fi
    if diff -u "$expected" "$actual" >"build/redis-examples/$name.diff"; then
        echo "PASS  $name"
    else
        echo "FAIL  $name  (output differs from $expected)"
        head -30 "build/redis-examples/$name.diff" | sed 's/^/        /'
        failures=$((failures + 1))
    fi
done

if [ "$count" -eq 0 ]; then
    echo "no examples found" >&2
    exit 2
fi
echo "$count example(s), $failures failure(s) (php $(php -r 'echo PHP_VERSION;'))"
[ "$failures" -eq 0 ]
