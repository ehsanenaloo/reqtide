#!/usr/bin/env bash
# Check the top-level entries of a release archive against the list the package may ship. Used by the release workflow.
#
#   tools/check-archive.sh dist/reqtide-v0.1.0.tar.gz     a tar.gz made by `git archive --prefix=NAME/`
#   git archive HEAD | tools/check-archive.sh -            the same from a pipe (no prefix is assumed to be there)
#
# Allowed: CHANGELOG.md, LICENSE, NOTICE, README.md, composer.json, docs, src. Anything else, or a missing required
# entry (composer.json, src, LICENSE, README.md), fails. Prints each unexpected entry.
set -u

allowed="CHANGELOG.md LICENSE NOTICE README.md composer.json docs src"
required="LICENSE README.md composer.json src"
source="${1:--}"

if [ "$source" = "-" ]; then
    listing="$(tar -tf - 2>/dev/null)"
else
    listing="$(tar -tzf "$source" 2>/dev/null)"
fi
if [ -z "$listing" ]; then
    echo "check-archive: the archive is empty or unreadable" >&2
    exit 2
fi

# The first path segment is the prefix (NAME/) when there is one; the top-level entries are the segments below it.
first="$(printf '%s\n' "$listing" | head -n 1)"
if [ "${source}" != "-" ] && [[ "$first" == */ ]] && [[ "$first" != *"/"*"/"* ]]; then
    entries="$(printf '%s\n' "$listing" | sed -e '1d' -e 's|^[^/]*/||' | cut -d/ -f1 | sort -u | sed '/^$/d')"
else
    entries="$(printf '%s\n' "$listing" | cut -d/ -f1 | sort -u | sed '/^$/d')"
fi

status=0
for entry in $entries; do
    case " $allowed " in
        *" $entry "*) ;;
        *) echo "check-archive: unexpected top-level entry: $entry" >&2; status=1 ;;
    esac
done
for entry in $required; do
    case " $(printf '%s ' $entries) " in
        *" $entry "*) ;;
        *) echo "check-archive: missing required entry: $entry" >&2; status=1 ;;
    esac
done
[ "$status" -eq 0 ] && echo "check-archive: ok ($(printf '%s ' $entries))"
exit "$status"
