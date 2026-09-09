#!/usr/bin/env python3
"""
Reads the [Unreleased] section of CHANGELOG.md, derives the SemVer bump
from it, computes the next version from the latest git tag and writes the
CHANGELOG forward.

Rules (Keep a Changelog):
  major  -> "### Removed", "BREAKING" or the marker [major]
  minor  -> "### Added", "### Changed" or the marker [minor]
  patch  -> anything else (Fixed, Security, Deprecated) or the marker [patch]

An explicit marker in the Unreleased heading always wins, for example:
  ## [Unreleased] [minor]

Usage:
  release.py            -> prints NEW_VERSION and BUMP as env lines on stdout
  release.py --check    -> only checks that an Unreleased entry exists
Exit code 78 means there is nothing to release.
"""
import datetime
import re
import subprocess
import sys

CHANGELOG = "CHANGELOG.md"


def read_unreleased(text):
    m = re.search(
        r"^##\s*\[?Unreleased\]?.*$", text, re.IGNORECASE | re.MULTILINE
    )
    if not m:
        return None, None, None
    start = m.start()
    heading_end = m.end()
    nxt = re.search(r"^##\s", text[heading_end:], re.MULTILINE)
    end = heading_end + nxt.start() if nxt else len(text)
    return m.group(0), text[heading_end:end], (start, heading_end, end)


def detect_bump(heading, body):
    blob = (heading + "\n" + body).lower()
    for marker in ("major", "minor", "patch"):
        if f"[{marker}]" in blob and "unreleased" not in f"[{marker}]":
            return marker
    if "breaking" in blob or re.search(r"^###\s*removed", body, re.I | re.M):
        return "major"
    if re.search(r"^###\s*(added|changed)", body, re.I | re.M):
        return "minor"
    return "patch"


def last_version():
    try:
        out = subprocess.run(
            ["git", "tag", "--list", "v[0-9]*", "--sort=-v:refname"],
            capture_output=True, text=True, check=True,
        ).stdout.split()
    except subprocess.CalledProcessError:
        out = []
    for tag in out:
        m = re.fullmatch(r"v(\d+)\.(\d+)\.(\d+)", tag)
        if m:
            return tuple(int(x) for x in m.groups())
    return (0, 0, 0)


def bump(version, kind):
    major, minor, patch = version
    if kind == "major":
        return (major + 1, 0, 0)
    if kind == "minor":
        return (major, minor + 1, 0)
    return (major, minor, patch + 1)


def main():
    check_only = "--check" in sys.argv
    try:
        text = open(CHANGELOG, encoding="utf-8").read()
    except FileNotFoundError:
        print(f"{CHANGELOG} is missing.", file=sys.stderr)
        sys.exit(1)

    heading, body, pos = read_unreleased(text)
    if heading is None:
        print("No [Unreleased] section found in CHANGELOG.md.",
              file=sys.stderr)
        sys.exit(1)

    entries = [ln for ln in body.splitlines()
               if ln.strip().startswith(("-", "*"))]
    if not entries:
        if check_only:
            print("CHANGELOG: [Unreleased] is empty - add what changed.", file=sys.stderr)
            sys.exit(1)
        sys.exit(78)

    kind = detect_bump(heading, body)
    new = bump(last_version(), kind)
    tag = "v%d.%d.%d" % new

    if check_only:
        print(f"OK - {len(entries)} entry/entries, next version would be {tag} ({kind}).")
        return

    today = datetime.date.today().isoformat()
    start, heading_end, end = pos
    released = f"## [{tag.lstrip('v')}] - {today}"
    new_text = (
        text[:start]
        + "## [Unreleased]\n\n"
        + released + "\n"
        + text[heading_end:end].lstrip("\n").rstrip() + "\n\n"
        + text[end:]
    )
    open(CHANGELOG, "w", encoding="utf-8").write(new_text)

    print(f"NEW_VERSION={tag}")
    print(f"BUMP={kind}")


if __name__ == "__main__":
    main()
