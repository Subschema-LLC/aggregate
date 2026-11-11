#!/usr/bin/env python3
"""Measure how many small synchronous writes per second this disk completes.

A database commit ends with a write of its log that must reach the disk, so
this caps the commits per second one database can make without group commit.
Writes 8 KiB and waits for fsync, over and over, in DIRECTORY for SECONDS,
and prints the rate as JSON:

    python3 tests/Load/fsync_bench.py "$RUNNER_TEMP" 5 > fsync.json
"""

from __future__ import annotations

import json
import os
import sys
import tempfile
import time


def main() -> None:
    directory = sys.argv[1] if len(sys.argv) > 1 else "."
    seconds = float(sys.argv[2]) if len(sys.argv) > 2 else 5.0
    block = os.urandom(8192)
    count = 0
    with tempfile.NamedTemporaryFile(dir=directory) as handle:
        end = time.monotonic() + seconds
        while time.monotonic() < end:
            os.pwrite(handle.fileno(), block, (count % 128) * 8192)
            os.fsync(handle.fileno())
            count += 1
    print(json.dumps({"syncs_per_second": count / seconds, "directory": directory}))


if __name__ == "__main__":
    main()
