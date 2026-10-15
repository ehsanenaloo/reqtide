#!/usr/bin/env bash
# Checks tools/select-baseline.sh against fixed tag sets.
set -u
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
failures=0

expect() {
    local tag="$1" expected="$2" tags="$3" actual
    actual="$(printf '%s\n' "${tags}" | TAGS_FROM_STDIN=1 bash "${root}/tools/select-baseline.sh" "${tag}" 2>&1)"
    if [ "${actual}" = "${expected}" ]; then
        echo "PASS  ${tag} -> ${expected:-(none)}"
    else
        echo "FAIL  ${tag}: expected '${expected}', got '${actual}'" >&2
        failures=$((failures + 1))
    fi
}

set_a=$'v1.4.0\nv1.4.1\nv1.5.0\nv2.0.0\nv1.5.0-rc1\nv2.0.0-rc1\nv0.9.0'
expect v1.4.1 v1.4.0 "${set_a}"
expect v1.4.0 "" "${set_a}"
expect v1.5.0 v1.4.1 "${set_a}"
expect v1.5.1 v1.5.0 "${set_a}"
expect v2.0.0 "" "${set_a}"
expect v2.0.1 v2.0.0 "${set_a}"
expect v1.6.0-rc1 v1.5.0 "${set_a}"
expect v0.9.1 v0.9.0 "${set_a}"
expect v0.1.0 "" ""
expect v1.10.0 v1.5.0 "${set_a}"$'\nv1.10.0'
expect v1.9.0 v1.5.0 $'v1.5.0\nv1.10.0'

if ! printf '' | TAGS_FROM_STDIN=1 bash "${root}/tools/select-baseline.sh" not-a-tag >/dev/null 2>&1; then
    echo "PASS  a malformed tag is refused"
else
    echo "FAIL  a malformed tag was accepted" >&2
    failures=$((failures + 1))
fi

[ "${failures}" -eq 0 ] && echo "test-select-baseline: ok" && exit 0
echo "test-select-baseline: ${failures} failure(s)" >&2
exit 1
