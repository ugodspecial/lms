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

    for name, kind, detail in failing:
        label = "Test failed" if kind == "failure" else "Test errored"
        print(f"::error title={annotate(label + ' — ' + name)}::{annotate(detail)}")

    return 0


if __name__ == "__main__":
    sys.exit(main())
