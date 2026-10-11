#!/usr/bin/env bash
# Runs every examples/NN-*.php and compares its stdout with examples/expected/NN-*.out.
#
#   tools/run-examples.sh              run in Docker (php83 by default) and compare
#   PHP_SERVICE=php84 tools/run-examples.sh
#   tools/run-examples.sh --local      run with the php on PATH (no Docker)
#   tools/run-examples.sh --update     regenerate the expected files (review the diff before keeping it)
#
# Exit status: 0 only if every example exits 0 AND its output equals the expected file.
# Actual outputs are kept in build/examples/ for inspection.
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
    extra=()
    [ "$update" = 1 ] && extra+=(--update)
    # MSYS_NO_PATHCONV stops Git Bash on Windows from rewriting container paths.
    cd "$root" || exit 2
    MSYS_NO_PATHCONV=1 docker compose -f tools/docker/compose.yaml run --rm "$service" \
        bash tools/run-examples.sh --inner ${extra[@]+"${extra[@]}"}
    exit $?
fi

cd "$root" || exit 2
mkdir -p build/examples examples/expected
failures=0
count=0

for script in examples/[0-9][0-9]-*.php; do
    name="$(basename "$script" .php)"
    actual="build/examples/$name.out"
    expected="examples/expected/$name.out"
    count=$((count + 1))

    php "$script" >"$actual" 2>"build/examples/$name.err"
    status=$?
    tr -d '\r' <"$actual" >"$actual.tmp" && mv "$actual.tmp" "$actual"

    if [ "$status" -ne 0 ]; then
        echo "FAIL  $name  (exit status $status)"
        sed 's/^/        /' "build/examples/$name.err" | head -20
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
    if diff -u "$expected" "$actual" >"build/examples/$name.diff"; then
        echo "PASS  $name"
    else
        echo "FAIL  $name  (output differs from $expected)"
        head -30 "build/examples/$name.diff" | sed 's/^/        /'
        failures=$((failures + 1))
    fi
done

if [ "$count" -eq 0 ]; then
    echo "no examples found" >&2
    exit 2
fi
echo "$count example(s), $failures failure(s) (php $(php -r 'echo PHP_VERSION;'))"
[ "$failures" -eq 0 ]
