#!/usr/bin/env bash
# Lint the GitHub workflows with actionlint (pinned Docker image): syntax, expressions, shell steps, action inputs.
# A workflow file with a syntax error is not run at all by GitHub and the run shows only "workflow file issue", so run this
# before every push that touches .github/workflows/.
#
#   tools/check-workflows.sh
set -u

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root" || exit 2
export MSYS_NO_PATHCONV=1

image="rhysd/actionlint:1.7.7@sha256:887a259a5a534f3c4f36cb02dca341673c6089431057242cdc931e9f133147e9"
mount="$(pwd -W 2>/dev/null || pwd)"
if docker run --rm -v "${mount}:/repo:ro" -w /repo "$image" -color; then
    echo "check-workflows: ok"
    exit 0
fi
echo "check-workflows: the workflows have problems" >&2
exit 1
