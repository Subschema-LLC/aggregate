#!/usr/bin/env python3
"""Run BI-style reads in a loop, as a reporting tool refreshing would, and log each one.

Reads a whole approved view, the way a BI import does, one query after
another for --seconds, through the database container's client. Each line of
the log is the view, the seconds it took and whether it succeeded. The time
includes starting the client in the container, about a tenth of a second.

    python3 tests/Load/bi_loop.py --image postgres:16 --seconds 60 > bi.log
"""

from __future__ import annotations

import argparse
import subprocess
import time

VIEWS = ["bi_anonymous_daily_v1", "bi_anonymous_events_v1", "bi_anonymous_daily_rollup_v1"]


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--image", required=True)
    parser.add_argument("--seconds", type=float, required=True)
    args = parser.parse_args()
    cid = subprocess.run(["docker", "ps", "-q", "--filter", f"ancestor={args.image}"], capture_output=True, text=True, check=True).stdout.split()[0]
    end = time.monotonic() + args.seconds
    index = 0
    while time.monotonic() < end:
        view = VIEWS[index % len(VIEWS)]
        index += 1
        sql = f"SELECT * FROM {view}"
        if args.image.startswith("postgres"):
            command = ["docker", "exec", cid, "psql", "-U", "aggregate", "-d", "aggregate", "-At", "-c", sql]
        else:
            command = ["docker", "exec", cid, "mysql", "-uaggregate", "-pload-test", "-N", "-B", "aggregate", "-e", sql]
        started = time.monotonic()
        result = subprocess.run(command, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, text=True)
        print(f"{view}\t{time.monotonic() - started:.3f}\t{'ok' if result.returncode == 0 else 'failed: ' + result.stderr.strip()[:200]}", flush=True)


if __name__ == "__main__":
    main()
