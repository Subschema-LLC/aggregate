#!/usr/bin/env python3
"""Estimate how many application servers one database could serve, from a one-machine run.

Phase 3 of the scaling roadmap starts ahead of phase 2 only where the shared
database has about twice the headroom one application server needs. This
report estimates that headroom from what the database spent per event during
a step test on one machine, where Aggregate, the database and k6 share the
CPU and the disk. It is an estimate, not a measurement with two servers:

  application server rate  = vCPUs / (PHP + Nginx + queue consumer CPU per event):
                             the events per second one server of this size could
                             take with the database and k6 elsewhere
  CPU headroom             = vCPUs / (that rate x database CPU per event):
                             application servers a database server of the same
                             size could keep up with
  disk headroom            = log syncs per second this disk can do / (that rate
                             x log syncs per event, binary log included); group
                             commit merges syncs under more load, so this is a
                             floor

The lower of the two is the headroom. Connections are reported beside it,
not in it: max_connections is a setting, raised or pooled, not hardware. A
second pair of phases at half the measured capacity, without and then with a
loop of BI reads, shows what reporting on the same database does to
collection.

    python3 tests/Load/headroom.py --github --label "PostgreSQL 16, enhanced, doctrine://" ...
"""

from __future__ import annotations

import argparse
import json
import math
import os
import statistics
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from report import annotation_escape, property_escape, stage_rows  # noqa: E402

GATE = 2.0


def load(path: str | None) -> dict:
    return json.loads(Path(path).read_text()) if path and Path(path).is_file() else {}


def delta(before: dict, after: dict, key: str) -> float | None:
    if before.get(key) is None or after.get(key) is None:
        return None
    return after[key] - before[key]


def fmt(value: float | None, places: int) -> str:
    return "n/a" if value is None else f"{value:,.{places}f}"


def phase(directory: str | None) -> dict | None:
    """The one stage of a fixed-rate measure.sh run: its p95, failures and rate."""
    summary = load(str(Path(directory) / "k6-summary.json")) if directory else {}
    rows = stage_rows(summary) if "aggregate_load_test" in summary else []
    return rows[-1] if rows else None


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--label", required=True)
    parser.add_argument("--server", default="")
    parser.add_argument("--cores", type=int, required=True)
    parser.add_argument("--fpm-workers", type=int, required=True)
    parser.add_argument("--consumers", type=int, default=0)
    parser.add_argument("--persistent", action="store_true", help="Each PHP-FPM worker keeps its connection open")
    parser.add_argument("--rows", type=int, default=0, help="Rows preloaded into events")
    parser.add_argument("--step", required=True, help="measure.sh output directory of the step test, run with DATABASE_COUNTERS=1")
    parser.add_argument("--load-json", required=True, help="report.py --json-out of the step test")
    parser.add_argument("--connections", help="Open connections sampled during the step test, one JSON object per line")
    parser.add_argument("--fsync", help="fsync_bench.py output")
    parser.add_argument("--quiet", help="measure.sh output directory, half capacity, no BI reads")
    parser.add_argument("--busy", help="measure.sh output directory, half capacity, with BI reads")
    parser.add_argument("--bi-log", help="bi_loop.py output")
    parser.add_argument("--json-out")
    parser.add_argument("--github", action="store_true")
    args = parser.parse_args()

    step = Path(args.step)
    events = load(str(step / "stored.json")).get("total", 0)
    cpu_before, cpu_after = load(str(step / "cpu-before.json")), load(str(step / "cpu-after.json"))
    db_before, db_after = load(str(step / "db-before.json")), load(str(step / "db-after.json"))
    capacity = load(args.load_json).get("capacity_rps")
    if events <= 0:
        raise SystemExit("No events were stored during the step test.")

    app_seconds = sum(delta(cpu_before, cpu_after, key) or 0.0 for key in ("php", "web", "cli"))
    db_seconds = delta(cpu_before, cpu_after, "database") or 0.0
    app_ms, db_ms = app_seconds * 1000 / events, db_seconds * 1000 / events
    server_rate = args.cores * 1000 / app_ms if app_ms > 0 else None

    per_event = {}
    for key in ("transactions", "log_syncs", "binlog_syncs", "log_bytes", "rows_inserted", "rows_updated", "rows_deleted", "queue_writes", "queue_reads", "blocks_read"):
        change = delta(db_before, db_after, key)
        per_event[key] = change / events if change is not None else None
    syncs = (per_event["log_syncs"] or 0.0) + (per_event["binlog_syncs"] or 0.0)
    sync_ms = delta(db_before, db_after, "log_sync_ms")
    sync_wait_ms = sync_ms / (delta(db_before, db_after, "log_syncs") or 1) if sync_ms else None

    samples = [json.loads(line).get("connections", 0) for line in Path(args.connections).read_text().splitlines() if line.strip()] if args.connections and Path(args.connections).is_file() else []
    peak = max(samples) if samples else None
    max_connections = db_after.get("max_connections")
    per_server = args.fpm_workers + args.consumers if args.persistent else (peak or args.fpm_workers + args.consumers)
    fsync_rate = load(args.fsync).get("syncs_per_second")

    headroom = {}
    if server_rate and db_ms > 0:
        headroom["CPU"] = args.cores / (server_rate * db_ms / 1000)
    if server_rate and fsync_rate and syncs > 0:
        headroom["disk"] = fsync_rate / (server_rate * syncs)
    limit = min(headroom, key=headroom.get) if headroom else None
    overall = headroom[limit] if limit else None
    verdict = (f"about {overall:.1f}x, limited by {limit}: " + ("phase 3 can go ahead here" if overall >= GATE else "under 2x, so phase 3 waits for phase 2's batching here")) if overall else "not enough data for an estimate"
    servers_by_connections = max_connections / per_server if max_connections and per_server else None
    connection_note = None
    if servers_by_connections is not None and overall and servers_by_connections < min(overall, 2 * GATE):
        needed = math.ceil(per_server * math.floor(min(overall, 2 * GATE)))
        connection_note = (f"Connections run out first: {max_connections:.0f} allowed and {per_server} per server is {servers_by_connections:.1f} servers. "
                           f"Raise max_connections to at least {needed}, or pool connections, before adding servers.")

    quiet, busy = phase(args.quiet), phase(args.busy)
    quiet_p95, busy_p95 = (row["p95_ms"] if row else None for row in (quiet, busy))
    bi = []
    if args.bi_log and Path(args.bi_log).is_file():
        for line in Path(args.bi_log).read_text().splitlines():
            parts = line.split("\t")
            if len(parts) == 3:
                bi.append((parts[0], float(parts[1]), parts[2]))
    bi_ok = [seconds for _, seconds, status in bi if status == "ok"]
    bi_failed = [status.removeprefix("failed: ") for _, _, status in bi if status != "ok"]

    lines = [f"### Database headroom: {args.label}", ""]
    if args.server:
        lines += [args.server + (f"; events table preloaded with {args.rows:,} rows" if args.rows else ""), ""]
    lines += [
        f"**Estimated headroom: {verdict}.** One machine ran Aggregate, the database and k6 together, so this is an estimate from per-event costs, not a test with two application servers.",
        "",
        "| Measure | Value |",
        "| --- | ---: |",
        f"| Events stored in the step test | {events:,} |",
        f"| Sustained capacity on this machine | {capacity if capacity is not None else 'none'} requests/s |",
        f"| Application CPU per event (PHP-FPM, Nginx, queue consumers) | {app_ms:.2f} ms |",
        f"| Database CPU per event | {db_ms:.2f} ms |",
        f"| One application server of {args.cores} vCPUs, database elsewhere | {server_rate:,.0f} events/s |" if server_rate else "| One application server, database elsewhere | n/a |",
        f"| Transactions per event, reads included | {fmt(per_event['transactions'], 2)} |",
        f"| Log syncs per event | {fmt(per_event['log_syncs'], 3)}" + (f" ({sync_wait_ms:.2f} ms each)" if sync_wait_ms else "") + " |",
        f"| Binary log syncs per event | {fmt(per_event['binlog_syncs'], 3)} |" if db_after.get("binlog") else None,
        f"| Log bytes per event | {fmt(per_event['log_bytes'], 0)} |",
        f"| Queue table row changes per event | {fmt(per_event['queue_writes'], 2)} |",
        f"| Queue table reads per event | {fmt(per_event['queue_reads'], 2)} |",
        f"| Blocks read from disk per event | {fmt(per_event['blocks_read'], 2)} |",
        f"| This disk's log syncs per second | {fsync_rate:,.0f} |" if fsync_rate else "| This disk's log syncs per second | n/a |",
        f"| Connections: peak during the step test / one server holds / database maximum | {peak if peak is not None else 'n/a'} / {per_server} / {f'{max_connections:.0f}' if max_connections else 'n/a'} |",
    ]
    lines = [line for line in lines if line is not None]
    for name, value in headroom.items():
        lines.append(f"| Headroom by {name} | {value:.1f}x |")
    if servers_by_connections is not None:
        lines.append(f"| Servers the connection limit allows (a setting) | {servers_by_connections:.1f} |")
    if connection_note:
        lines += ["", connection_note]
    if quiet and busy and quiet_p95 and busy_p95 is not None:
        lines += ["", f"At {quiet['target_rps']} requests/s, about half the capacity, collection p95 was {quiet_p95:.0f} ms without BI reads"
                  f" and {busy_p95:.0f} ms with them ({(busy_p95 / quiet_p95 - 1) * 100:+.0f}%); failed requests"
                  f" {quiet['failure_rate'] * 100:.1f}% and {busy['failure_rate'] * 100:.1f}%."]
    if bi:
        text = (f"BI reads during that phase: {len(bi)}, median {statistics.median(bi_ok):.1f} s, longest {max(bi_ok):.1f} s"
                if bi_ok else f"BI reads during that phase: {len(bi)}, none succeeded")
        lines.append(text + (f"; {len(bi_failed)} failed, first: {bi_failed[0]}" if bi_failed else "") + ".")
    index_before, index_after = db_before.get("index_scans", {}), db_after.get("index_scans", {})
    index_reads = {name: index_after[name] - index_before.get(name, 0) for name in sorted(index_after)}
    if any(index_reads.values()):
        lines += ["", "Reads of each events index during the step test: " + ", ".join(
            f"{name} {reads:,.0f}" for name, reads in index_reads.items() if reads)]
    elif index_reads:
        lines += ["", "Collection read no events index during the step test; it only inserted."]
    report = "\n".join(lines) + "\n"

    result = {
        "label": args.label, "events": events, "capacity_rps": capacity, "rows": args.rows,
        "app_cpu_ms_per_event": app_ms, "db_cpu_ms_per_event": db_ms, "server_rate": server_rate,
        "per_event": per_event, "index_reads": index_reads, "log_sync_ms_each": sync_wait_ms, "fsync_per_second": fsync_rate,
        "connections": {"peak": peak, "per_server": per_server, "max": max_connections, "servers": servers_by_connections},
        "headroom": headroom, "limit": limit, "overall": overall, "passes_gate": bool(overall and overall >= GATE),
        "p95_ms": {"without_bi": quiet_p95, "with_bi": busy_p95},
        "failure_rate": {"without_bi": quiet["failure_rate"] if quiet else None, "with_bi": busy["failure_rate"] if busy else None},
        "bi_reads": {"count": len(bi), "median_s": statistics.median(bi_ok) if bi_ok else None, "max_s": max(bi_ok) if bi_ok else None, "failed": len(bi_failed)},
    }
    if args.json_out:
        Path(args.json_out).write_text(json.dumps(result, indent=2))
    print(report)
    if args.github:
        summary = os.environ.get("GITHUB_STEP_SUMMARY")
        if summary:
            with open(summary, "a", encoding="utf-8") as handle:
                handle.write(report)
        print(f"::notice title={property_escape('Database headroom: ' + args.label)}::{annotation_escape('Estimated headroom ' + verdict + '.')}")


if __name__ == "__main__":
    main()
