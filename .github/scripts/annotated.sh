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
#
#  One annotation is not enough for long output. A 20 000-character `::error::`
#  line was published and then arrived through the API with an empty message,
#  while a 900-character one arrived intact: a failing step reported nothing at
#  all about why. So the flattened output is cut into chunks small enough to
#  survive, published head-first, each labelled with its position, and the total
#  size is stated when the set is truncated. Ten chunks is the budget GitHub
#  allows per check run; using more than that would cost the annotations other
#  steps need.
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
    chunk_size=1700
    max_chunks=9

    # Flatten first, then cut: `cut -c` works per line, which would slice every
    # line of the output instead of walking through it.
    flat="$(printf '%s' "$output" \
        | sed -e 's/\x1b\[[0-9;]*[a-zA-Z]//g' \
        | tr '\n\r' '||' \
        | sed -e 's/%/%25/g')"

    total="${#flat}"
    shown=$(( max_chunks * chunk_size ))
    [ "$total" -lt "$shown" ] && shown="$total"

    part=1
    while [ "$part" -le "$max_chunks" ]; do
        offset=$(( (part - 1) * chunk_size ))
        [ "$offset" -ge "$total" ] && break

        detail="${flat:$offset:$chunk_size}"
        echo "::error title=${title} (exit ${code}, part ${part} of ${max_chunks}, ${total} chars total)::${detail}"

        part=$(( part + 1 ))
    done

    if [ "$total" -gt "$shown" ]; then
        dropped=$(( total - shown ))
        echo "::error title=${title} (output truncated)::${dropped} more characters are in the job log; the first ${shown} are in the annotations above."
    fi
fi

exit "$code"
