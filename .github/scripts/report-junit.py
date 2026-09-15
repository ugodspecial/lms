#!/usr/bin/env python3
"""Turn a PHPUnit JUnit log into one GitHub annotation per failing test.

A failing assertSee or assertDontSee prints the entire rendered page into its
message, so a single test can consume the whole annotation budget for the step
and hide every other failure behind it. That is exactly what happened here: the
first failure was an HTML dump and the remaining six were invisible.

The JUnit log has one structured record per test, so all of them can be reported
— the test name, plus the head and tail of the message, because PHPUnit puts the
needle it could not find at the very end ("... does not contain "Identity &
Access".").

There is a second, quieter way to lose a failure: GitHub keeps at most ten error
annotations per check run and keeps the first ten. With eleven failures the last
one simply did not exist for anyone reading the API, and the build reported ten
of eleven. So the names of every failing test go out first, in one compact
annotation, and the per-test details follow until the budget runs out. What
failed is never the thing that goes missing; only the detail can be, and this
says so out loud when it is.

Usage: python3 .github/scripts/report-junit.py junit.xml

Always exits 0. This is a reporting step, not a gate: the PHPUnit step already
decides whether the build passes, and a reporter that can itself fail the build
would obscure the result it exists to explain.
"""

import sys
import xml.etree.ElementTree as ET

HEAD = 200
TAIL = 500
CAP = 900

# One annotation carries every failing test name, the rest carry detail. Ten is
# GitHub's per-check-run cap, so nine details plus the summary is the most that
# can be published without the summary being the thing that gets dropped.
SUMMARY_CAP = 1700
DETAILS_SHOWN = 9


def clip(text: str) -> str:
    """Collapse whitespace and keep both ends of a long message."""
    text = " ".join((text or "").split())
    if len(text) <= CAP:
        return text
    return (
        f"{text[:HEAD]} ...[{len(text) - HEAD - TAIL} chars omitted]... {text[-TAIL:]}"
    )


def annotate(text: str) -> str:
    """GitHub truncates an annotation at the first raw newline and reads % literally."""
    return text.replace("%", "%25").replace("\n", " ").replace("\r", " ")


def short_name(name: str) -> str:
    """`Tests\\Feature\\Administration\\FilePolicyTest::test_x` -> `FilePolicyTest::test_x`.

    The namespace is the same for every test in a suite, so it costs budget and
    says nothing. The class and method are what someone needs to find the file.
    """
    left, sep, right = name.partition("::")
    if not sep:
        return name
    return f"{left.rsplit(chr(92), 1)[-1]}{sep}{right}"


def main() -> int:
    path = sys.argv[1] if len(sys.argv) > 1 else "junit.xml"

    try:
        root = ET.parse(path).getroot()
    except (OSError, ET.ParseError) as exc:
        print(f"::warning title=Test report unavailable::Could not read {path}: {annotate(str(exc))}")
        return 0

    failing = []
    total = 0

    for case in root.iter("testcase"):
        total += 1
        for kind in ("failure", "error"):
            node = case.find(kind)
            if node is None:
                continue

            name = f"{case.get('class', '')}::{case.get('name', '')}"
            message = (node.get("message") or "").strip()
            body = (node.text or "").strip()

            # The message attribute carries the assertion; the body carries the
            # stack trace. Prefer the assertion, but keep the first frame of the
            # trace when the message alone says nothing actionable.
            detail = clip(message) if message else clip(body)

            failing.append((name, kind, detail))
            break

    print(f"JUnit log: {total} tests, {len(failing)} failing.")

    if failing:
        names = ", ".join(short_name(name) for name, _, _ in failing)
        if len(names) > SUMMARY_CAP:
            names = f"{names[: SUMMARY_CAP - 30]} ...[{len(names)} chars]"
        print(f"::error title={annotate(f'Failing tests ({len(failing)})')}::{annotate(names)}")

    for name, kind, detail in failing[:DETAILS_SHOWN]:
        label = "Test failed" if kind == "failure" else "Test errored"
        print(f"::error title={annotate(label + ' — ' + name)}::{annotate(detail)}")

    hidden = len(failing) - DETAILS_SHOWN
    if hidden > 0:
        reason = (
            f"{hidden} further failing tests have no detail annotation: GitHub "
            f"keeps ten per check run. Every name is in the first annotation, and "
            f"the full messages are in the job log."
        )
        print(f"::error title={annotate(f'{hidden} failures without detail here')}::{annotate(reason)}")

    return 0


if __name__ == "__main__":
    sys.exit(main())
