#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
#  Run a command and, if it fails, publish the reason as a workflow annotation.
# ─────────────────────────────────────────────────────────────────────────────
#
#  Usage:  bash .github/scripts/annotated.sh "<title>" -- <command> [args...]
#
#  Why this exists: GitHub serves the job log archive from a different host than
#  the REST API, and that host is not reachable from every environment that needs
#  to read a build result. Step annotations ARE reachable from api.github.com. A
#  failing step that only writes to stdout is therefore invisible to an operator
#  (or an agent) working through the API alone.
#
#  So every step that can fail for a reason worth reading is wrapped in this: the
#  full output still goes to the log for a human in the browser, and a condensed
#  copy goes into an annotation for anyone reading the API.
#
#  For long output the HEAD is more useful than the tail — tools like Pint and
#  PHPStan report per file in order, and the first entries are the ones to act on.
#  Both ends are kept, with an explicit marker for what was dropped.
#
#  Annotation text must escape `%` as `%25` and must not contain raw newlines, or
#  GitHub truncates the message at the first one.
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
    lines="$(printf '%s' "$output" | wc -l | tr -d ' ')"

    if [ "$lines" -le 280 ]; then
        excerpt="$output"
    else
        head_part="$(printf '%s' "$output" | head -200)"
        tail_part="$(printf '%s' "$output" | tail -80)"
        dropped=$((lines - 280))
        excerpt="${head_part}
...[${dropped} lines omitted]...
${tail_part}"
    fi

    # ANSI escapes make an annotation unreadable; strip them before escaping.
    detail="$(printf '%s' "$excerpt" \
        | sed -e 's/\x1b\[[0-9;]*[a-zA-Z]//g' \
        | tr '\n\r' '||' \
        | sed -e 's/%/%25/g' \
        | cut -c1-20000)"

    echo "::error title=${title} (exit ${code})::${detail}"
fi

exit "$code"
