#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
#  Run a command and, if it fails, publish the reason as a workflow annotation.
# ─────────────────────────────────────────────────────────────────────────────
#
#  Usage:  bash .github/scripts/annotated.sh "<title>" -- <command> [args...]
#
#  Why this exists: GitHub's job log archive is served from a different host
#  than the REST API, and is not reachable from every environment that needs to
#  read a build result. Step annotations ARE reachable from api.github.com. A
#  failing step that only writes to stdout is therefore invisible to an operator
#  (or an agent) working through the API alone.
#
#  So every step that can fail for a reason worth reading is wrapped in this:
#  full output still goes to the log for a human in the browser, and the tail is
#  duplicated into an annotation for anyone reading the API.
#
#  Annotation text must escape `%` as `%25` and must not contain raw newlines,
#  or GitHub truncates the message at the first one.
#
set -uo pipefail

title="${1:-command failed}"
shift
[ "${1:-}" = "--" ] && shift

echo "\$ $*"
echo "────────────────────────────────────────────────────────────"

output="$("$@" 2>&1)"
code=$?

echo "$output"
echo "────────────────────────────────────────────────────────────"

if [ "$code" -ne 0 ]; then
    detail="$(printf '%s' "$output" \
        | tail -30 \
        | tr '\n\r' '||' \
        | sed -e 's/%/%25/g' \
        | cut -c1-1800)"

    echo "::error title=${title} (exit ${code})::${detail}"
fi

exit "$code"
