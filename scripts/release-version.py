#!/usr/bin/env python3
"""Check or propose release tags in the vYYYY.MM.NN calendar format.

SPDX-License-Identifier: AGPL-3.0-only
NN is the release's number within that month, starting at 01; it is not the day.

  python3 scripts/release-version.py next            # next tag for the current UTC month
  python3 scripts/release-version.py next --month 2026.10
  python3 scripts/release-version.py check v2026.09.01
"""

import argparse
import datetime as dt
import importlib.util
from pathlib import Path
import re
import subprocess
import sys

_SPEC = importlib.util.spec_from_file_location("build_release", Path(__file__).resolve().parent / "build-release.py")
_BUILDER = importlib.util.module_from_spec(_SPEC)
_SPEC.loader.exec_module(_BUILDER)
CALENDAR_VERSION = _BUILDER.CALENDAR_VERSION
validate_version = _BUILDER.validate_version


def next_version(tags, month):
    """Next YYYY.MM.NN for month "YYYY.MM" given existing tag names."""
    if not re.fullmatch(r"20[0-9]{2}\.(0[1-9]|1[0-2])", month):
        raise ValueError("Month must be YYYY.MM, for example 2026.09.")
    used = [int(match.group(3)) for tag in tags if (match := re.fullmatch("v" + CALENDAR_VERSION, tag.strip())) and f"{match.group(1)}.{match.group(2)}" == month]
    index = max(used, default=0) + 1
    if index > 99:
        raise ValueError(f"{month} already has 99 releases.")
    return f"{month}.{index:02d}"


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    commands = parser.add_subparsers(dest="command", required=True)
    proposal = commands.add_parser("next", help="print the next unused tag for a month (default: the current UTC month)")
    proposal.add_argument("--month", help="YYYY.MM")
    check = commands.add_parser("check", help="exit 1 unless the tag uses the calendar format")
    check.add_argument("tag")
    args = parser.parse_args()
    try:
        if args.command == "check":
            if not args.tag.startswith("v"):
                raise ValueError("Release tags start with v, for example v2026.09.01.")
            validate_version(args.tag)
            print(args.tag)
            return
        month = args.month or dt.datetime.now(dt.timezone.utc).strftime("%Y.%m")
        tags = subprocess.run(["git", "tag", "--list", "v*"], check=True, capture_output=True, text=True).stdout.splitlines()
        print("v" + next_version(tags, month))
    except (ValueError, subprocess.CalledProcessError) as error:
        parser.exit(1, f"{error}\n")


if __name__ == "__main__":
    sys.exit(main())
