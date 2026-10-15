#!/usr/bin/env bash
# Prints the release that the API of a new tag is compared with, or nothing when there is none.
#
#   tools/select-baseline.sh v1.4.1
#
# The baseline is the highest stable release (vX.Y.Z, no pre-release suffix) of the same major version that is lower than the
# core version of the tag. So a maintenance patch of an older line is compared with its own line, a pre-release is compared
# with the last stable release below it, and the first release of a major version has no baseline (breaking changes are
# expected there). Tags come from `git tag` or, when TAGS_FROM_STDIN=1, from standard input (used by the test).
set -euo pipefail

tag="${1:-}"
if [[ ! "${tag}" =~ ^v([0-9]+)\.([0-9]+)\.([0-9]+)(-[0-9A-Za-z.-]+)?$ ]]; then
    echo "select-baseline: '${tag}' is not a version tag (vMAJOR.MINOR.PATCH[-suffix])" >&2
    exit 2
fi
major="${BASH_REMATCH[1]}"
core="v${major}.${BASH_REMATCH[2]}.${BASH_REMATCH[3]}"

if [ "${TAGS_FROM_STDIN:-0}" = 1 ]; then
    tags="$(cat)"
else
    tags="$(git tag --list 'v*')"
fi

{ printf '%s\n' "${tags}" | grep -E "^v${major}\.[0-9]+\.[0-9]+$" || true; echo "${core}"; } \
    | sort -uV \
    | awk -v core="${core}" '$0 == core { exit } { last = $0 } END { if (last != "") print last }'
