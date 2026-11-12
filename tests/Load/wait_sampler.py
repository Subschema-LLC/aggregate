#!/usr/bin/env python3
"""Sample what the database's busy connections are doing, once a second, as JSON lines.

For a phase that is not used for the CPU estimate: each sample starts the
database's client in its container. PostgreSQL reports each active
connection's wait event (none means it is on the CPU); MySQL reports each
non-idle connection's state. Run it in the background and stop it after the
phase:

    python3 tests/Load/wait_sampler.py --image postgres:16 > waits.log &
"""

from __future__ import annotations

import argparse
import json
import subprocess
import time

POSTGRES = (
    "SELECT COALESCE(wait_event_type || ':' || wait_event, 'on CPU'), count(*) FROM pg_stat_activity "
    "WHERE datname = 'aggregate' AND state = 'active' AND pid <> pg_backend_pid() GROUP BY 1"
)
MYSQL = (
    "SELECT COALESCE(NULLIF(state, ''), command), COUNT(*) FROM information_schema.processlist "
    "WHERE db = 'aggregate' AND command <> 'Sleep' AND id <> CONNECTION_ID() GROUP BY 1"
)


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--image", required=True)
    parser.add_argument("--seconds", type=float, default=3600)
    args = parser.parse_args()
    cid = subprocess.run(["docker", "ps", "-q", "--filter", f"ancestor={args.image}"], capture_output=True, text=True, check=True).stdout.split()[0]
    if args.image.startswith("postgres"):
        command = ["docker", "exec", cid, "psql", "-U", "aggregate", "-d", "aggregate", "-At", "-F", "\t", "-c", POSTGRES]
    else:
        command = ["docker", "exec", cid, "mysql", "-uroot", "-proot-load", "-N", "-B", "-e", MYSQL]
    end = time.monotonic() + args.seconds
    while time.monotonic() < end:
        started = time.monotonic()
        result = subprocess.run(command, capture_output=True, text=True)
        if result.returncode == 0:
            waits = {}
            for line in result.stdout.splitlines():
                name, _, count = line.rpartition("\t")
                if name:
                    waits[name] = waits.get(name, 0) + int(count or 0)
            print(json.dumps({"waits": waits}), flush=True)
        time.sleep(max(0.0, 1.0 - (time.monotonic() - started)))


if __name__ == "__main__":
    main()
