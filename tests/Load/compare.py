#!/usr/bin/env python3
"""Compare two installations measured on the same machine, phase by phase.

Each phase directory holds what tests/Load/server/measure.sh records for one
run at a fixed rate. Phases alternate between the base and the changed
installation (base, head, head, base), so drift in the shared machine affects
both sides alike. The report gives server CPU per stored event (PHP,
database and Nginx), median and p95 response times, and the change from base
to head. With --github it appends to the job summary and emits a notice.

    python3 tests/Load/compare.py --label "MySQL 8.0, anonymous" \\
        base=phases/1-base head=phases/2-head head=phases/3-head base=phases/4-base
"""

from __future__ import annotations

import argparse
import json
import os
import statistics
from pathlib import Path


def phase(directory: Path) -> dict:
    summary = json.loads((directory / "k6-summary.json").read_text())
    before = json.loads((directory / "cpu-before.json").read_text())
    after = json.loads((directory / "cpu-after.json").read_text())
    stored = json.loads((directory / "stored.json").read_text())["total"]
    meta = summary["aggregate_load_test"]
    stage = meta["stages"][0]["name"]
    duration = summary["metrics"].get(f"http_req_duration{{scenario:{stage}}}", {}).get("values", {})
    checks = summary["metrics"].get(f"checks{{scenario:{stage}}}", {}).get("values", {})
    used = {
        key: after[key] - before[key]
        for key in ("php", "database", "web")
        if after.get(key) is not None and before.get(key) is not None
    }
    per_event = {key: seconds * 1000 / stored for key, seconds in used.items()} if stored else {}
    return {
        "rate": meta["rates"][0],
        "stored": stored,
        "failures": checks.get("fails", 0),
        "achieved_rps": checks.get("passes", 0) / meta["stage_seconds"],
        "p50_ms": duration.get("med"),
        "p95_ms": duration.get("p(95)"),
        "cpu_ms": per_event,
        "server_cpu_ms": sum(per_event.values()),
    }


def change(base: float, head: float) -> str:
    if not base:
        return "-"
    delta = (head - base) / base * 100
    return f"{delta:+.0f}%"


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--label", required=True)
    parser.add_argument("--server", help="Machine description")
    parser.add_argument("--github", action="store_true")
    parser.add_argument("--json-out")
    parser.add_argument("phases", nargs="+", help="side=directory, side being base or head")
    args = parser.parse_args()

    rows = []
    for item in args.phases:
        side, _, directory = item.partition("=")
        rows.append({"side": side, "dir": directory, **phase(Path(directory))})

    sides = {}
    for side in ("base", "head"):
        mine = [row for row in rows if row["side"] == side]
        keys = sorted({key for row in mine for key in row["cpu_ms"]})
        sides[side] = {
            "server_cpu_ms": statistics.fmean(row["server_cpu_ms"] for row in mine),
            "cpu_ms": {key: statistics.fmean(row["cpu_ms"].get(key, 0) for row in mine) for key in keys},
            "p50_ms": statistics.fmean(row["p50_ms"] for row in mine),
            "p95_ms": statistics.fmean(row["p95_ms"] for row in mine),
            "failures": sum(row["failures"] for row in mine),
        }
    base, head = sides["base"], sides["head"]
    labels = {"php": "PHP", "database": "database", "web": "Nginx"}

    headline = (
        f"Server CPU per event: base {base['server_cpu_ms']:.1f} ms, head {head['server_cpu_ms']:.1f} ms "
        f"({change(base['server_cpu_ms'], head['server_cpu_ms'])}); "
        f"median response {base['p50_ms']:.1f} -> {head['p50_ms']:.1f} ms ({change(base['p50_ms'], head['p50_ms'])}), "
        f"p95 {base['p95_ms']:.1f} -> {head['p95_ms']:.1f} ms ({change(base['p95_ms'], head['p95_ms'])})"
    )
    parts = ", ".join(
        f"{labels[key]} {base['cpu_ms'].get(key, 0):.1f} -> {head['cpu_ms'].get(key, 0):.1f} ms "
        f"({change(base['cpu_ms'].get(key, 0), head['cpu_ms'].get(key, 0))})"
        for key in ("php", "database", "web")
        if key in base["cpu_ms"] or key in head["cpu_ms"]
    )

    table = ["| Phase | Side | Rate | Stored | Failed | PHP ms/event | DB ms/event | Server ms/event | p50 ms | p95 ms |",
             "| ---: | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |"]
    plain = [headline, f"By component: {parts}", "phase side  rate stored failed  php_ms  db_ms  server_ms  p50  p95"]
    for number, row in enumerate(rows, 1):
        db = row["cpu_ms"].get("database")
        table.append(
            f"| {number} | {row['side']} | {row['rate']} | {row['stored']:,} | {row['failures']:,} | "
            f"{row['cpu_ms'].get('php', 0):.1f} | {'-' if db is None else f'{db:.1f}'} | {row['server_cpu_ms']:.1f} | "
            f"{row['p50_ms']:.1f} | {row['p95_ms']:.1f} |"
        )
        plain.append(
            f"{number:>5} {row['side']:<5} {row['rate']:>4} {row['stored']:>6} {row['failures']:>6}  "
            f"{row['cpu_ms'].get('php', 0):6.1f}  {'-' if db is None else f'{db:5.1f}':>5}  {row['server_cpu_ms']:9.1f}  "
            f"{row['p50_ms']:5.1f} {row['p95_ms']:5.1f}"
        )
    if args.server:
        plain.append(f"Server: {args.server}")

    markdown = "\n".join([f"### Comparison: {args.label}", "", headline + ".", "", f"By component: {parts}.", "", *table, ""]
                         + ([f"Server: {args.server}", ""] if args.server else []))
    print(markdown)
    if args.json_out:
        Path(args.json_out).write_text(json.dumps({"label": args.label, "phases": rows, "sides": sides}, indent=2))
    if args.github:
        path = os.environ.get("GITHUB_STEP_SUMMARY")
        if path:
            with open(path, "a", encoding="utf-8") as handle:
                handle.write(markdown + "\n")
        escape = lambda text: text.replace("%", "%25").replace("\r", "").replace("\n", "%0A")
        title = escape("Comparison: " + args.label).replace(":", "%3A").replace(",", "%2C")
        print(f"::notice title={title}::{escape(chr(10).join(plain))}")


if __name__ == "__main__":
    main()
