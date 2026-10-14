#!/usr/bin/env python3
"""Print the CPU seconds used so far by the web server, PHP and the database.

Run once before and once after a load test; the report subtracts the two to
show how the machine's CPU was split. PHP-FPM workers must not be recycled
during the run (pm.max_requests = 0), since a finished process takes its CPU
time with it.

    python3 tests/Load/cpu_snapshot.py --database-image postgres:16 > cpu-before.json
"""

from __future__ import annotations

import argparse
import json
import os
import subprocess
from pathlib import Path

TICKS = os.sysconf("SC_CLK_TCK")


def process_seconds(prefix: str) -> float:
    """CPU seconds (user + system) of every process whose name starts with prefix."""
    total = 0
    for entry in Path("/proc").iterdir():
        if not entry.name.isdigit():
            continue
        try:
            if not (entry / "comm").read_text().strip().startswith(prefix):
                continue
            fields = (entry / "stat").read_text().rsplit(")", 1)[1].split()
        except OSError:
            continue
        # After the command name: state is field 3, utime 14 and stime 15.
        total += int(fields[11]) + int(fields[12])
    return total / TICKS


def container_seconds(image: str) -> float | None:
    """CPU seconds of the running container started from image, from its cgroup."""
    ids = subprocess.run(["docker", "ps", "-q", "--filter", f"ancestor={image}"], capture_output=True, text=True).stdout.split()
    if not ids:
        return None
    for path, scale in (("/sys/fs/cgroup/cpu.stat", None), ("/sys/fs/cgroup/cpuacct/cpuacct.usage", 1e9)):
        result = subprocess.run(["docker", "exec", ids[0], "cat", path], capture_output=True, text=True)
        if result.returncode != 0:
            continue
        if scale is None:
            for line in result.stdout.splitlines():
                key, _, value = line.partition(" ")
                if key == "usage_usec":
                    return int(value) / 1e6
        else:
            return int(result.stdout.strip()) / scale
    return None


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--php", default="php-fpm", help="Process name prefix of PHP-FPM")
    parser.add_argument("--web", default="nginx", help="Process name prefix of the web server")
    parser.add_argument("--database-image", help="Image of the database container, if any")
    args = parser.parse_args()

    snapshot = {
        "php": process_seconds(args.php),
        "web": process_seconds(args.web),
        "database": container_seconds(args.database_image) if args.database_image else None,
    }
    print(json.dumps(snapshot))


if __name__ == "__main__":
    main()
