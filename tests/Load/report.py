#!/usr/bin/env python3
"""Summarize a k6 run of tests/Load/receive.js, stage by stage.

Reads the k6 JSON summary, optional `vmstat -n -t 1` output recorded on the
server during the run, the stored-row count and the PHP log, then prints a
Markdown report. With --github it also appends the report to the job summary
and emits one notice annotation holding the stage table, so results can be
read from the checks API without downloading logs.

A stage counts as sustained when the server delivered at least 95% of the
target rate, fewer than 1% of requests failed to store an event, and the 95th
percentile response time stayed under 500 ms. The sustained capacity is the
highest stage reached before the first stage that is not sustained.
"""

from __future__ import annotations

import argparse
import json
import os
import re
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path

SUSTAINED_SHARE = 0.95
MAX_FAILURE_RATE = 0.01
MAX_P95_MS = 500.0


def metric(summary: dict, name: str) -> dict:
    return summary.get("metrics", {}).get(name, {}).get("values", {})


def stage_rows(summary: dict) -> list[dict]:
    meta = summary["aggregate_load_test"]
    seconds = meta["stage_seconds"]
    rows = []
    for stage in meta["stages"]:
        name = stage["name"]
        requests = metric(summary, f"http_reqs{{scenario:{name}}}").get("count", 0)
        duration = metric(summary, f"http_req_duration{{scenario:{name}}}")
        checks = metric(summary, f"checks{{scenario:{name}}}")
        dropped = metric(summary, f"dropped_iterations{{scenario:{name}}}").get("count", 0)
        passes = checks.get("passes", 0)
        fails = checks.get("fails", 0)
        attempted = passes + fails
        rows.append({
            "name": name,
            "index": stage["index"],
            "target_rps": stage["rate"],
            "achieved_rps": passes / seconds if seconds else 0.0,
            "requests": requests,
            "stored": passes,
            "failure_rate": (fails / attempted) if attempted else 0.0,
            "dropped": dropped,
            "p50_ms": duration.get("med"),
            "p95_ms": duration.get("p(95)"),
            "p99_ms": duration.get("p(99)"),
            "max_ms": duration.get("max"),
        })
    for row in rows:
        row["sustained"] = (
            row["achieved_rps"] >= SUSTAINED_SHARE * row["target_rps"]
            and row["failure_rate"] < MAX_FAILURE_RATE
            and row["p95_ms"] is not None
            and row["p95_ms"] < MAX_P95_MS
        )
    return rows


def parse_vmstat(path: Path | None) -> list[tuple[float, dict]]:
    """Returns (epoch seconds, columns) for each sample; the runner clock is UTC."""
    if path is None or not path.is_file():
        return []
    header: list[str] = []
    samples = []
    for line in path.read_text(errors="replace").splitlines():
        parts = line.split()
        if not parts:
            continue
        if parts[0] == "r":
            header = parts
            continue
        if not header or not parts[0].isdigit():
            continue
        try:
            stamp = datetime.strptime(" ".join(parts[-2:]), "%Y-%m-%d %H:%M:%S").replace(tzinfo=timezone.utc)
        except ValueError:
            continue
        values = dict(zip(header, (float(p) for p in parts[: len(header) - 1] if re.fullmatch(r"-?\d+(\.\d+)?", p))))
        samples.append((stamp.timestamp(), values))
    return samples


def add_cpu(rows: list[dict], summary: dict, samples: list[tuple[float, dict]]) -> None:
    if not samples:
        return
    meta = summary["aggregate_load_test"]
    duration_s = summary.get("state", {}).get("testRunDurationMs", 0) / 1000
    start = meta["ended_at_ms"] / 1000 - duration_s
    period = meta["stage_seconds"] + meta["gap_seconds"]
    for row in rows:
        # Skip the first seconds of each stage while the rate ramps in.
        begin = start + row["index"] * period + 3
        end = start + row["index"] * period + meta["stage_seconds"]
        window = [values for stamp, values in samples if begin <= stamp <= end]
        if not window:
            continue
        busy = [values.get("us", 0) + values.get("sy", 0) for values in window]
        row["cpu_busy_pct"] = sum(busy) / len(busy)
        row["cpu_wait_pct"] = sum(values.get("wa", 0) for values in window) / len(window)
        row["run_queue"] = max(values.get("r", 0) for values in window)


def log_problems(path: Path | None, limit: int = 3) -> list[tuple[int, str]]:
    if path is None or not path.is_file():
        return []
    messages: Counter[str] = Counter()
    for line in path.read_text(errors="replace").splitlines():
        if "ERROR" not in line and "CRITICAL" not in line and "Fatal" not in line:
            continue
        text = re.sub(r"^\[[^\]]*\]\s*", "", line)
        text = re.sub(r"\d{4}-\d\d-\d\dT[\d:.+-]+", "<time>", text)
        text = re.sub(r"\b\d+\b", "N", text)
        messages[text[:240]] += 1
    return messages.most_common(limit)


def fmt_ms(value: float | None) -> str:
    if value is None:
        return "-"
    return f"{value:,.0f}" if value >= 10 else f"{value:.1f}"


def build_report(args: argparse.Namespace) -> tuple[str, str, dict]:
    summary = json.loads(Path(args.summary).read_text())
    meta = summary["aggregate_load_test"]
    rows = stage_rows(summary)
    add_cpu(rows, summary, parse_vmstat(Path(args.vmstat) if args.vmstat else None))

    capacity = None
    for row in rows:
        if not row["sustained"]:
            break
        capacity = row

    stored_total = sum(row["stored"] for row in rows)
    stored_db = None
    if args.stored and Path(args.stored).is_file():
        stored_db = json.loads(Path(args.stored).read_text())
    statuses = {
        name.removeprefix("status_"): int(values.get("values", {}).get("count", 0))
        for name, values in summary.get("metrics", {}).items()
        if name.startswith("status_") and values.get("values", {}).get("count", 0)
    }
    problems = log_problems(Path(args.php_log) if args.php_log else None)

    header = "| Target rps | Achieved rps | Failed | Dropped | p50 ms | p95 ms | p99 ms | CPU busy | Sustained |"
    lines = [header, "| ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | :---: |"]
    for row in rows:
        cpu = f"{row['cpu_busy_pct']:.0f}%" if "cpu_busy_pct" in row else "-"
        lines.append(
            f"| {row['target_rps']} | {row['achieved_rps']:,.0f} | {row['failure_rate'] * 100:.1f}% | {row['dropped']:,} "
            f"| {fmt_ms(row['p50_ms'])} | {fmt_ms(row['p95_ms'])} | {fmt_ms(row['p99_ms'])} | {cpu} | {'yes' if row['sustained'] else 'no'} |"
        )

    if capacity:
        verdict = (
            f"Sustained capacity: **{capacity['target_rps']} requests per second** "
            f"(about {capacity['target_rps'] * 60:,} per minute), the highest stage before the first one that missed "
            f"95% of its target rate, failed 1% or more, or had a p95 of 500 ms or more."
        )
    else:
        verdict = "No stage was sustained; even the first stage missed its target rate, failed 1% or more, or had a p95 of 500 ms or more."

    context = [
        f"- Setup: {args.label}",
        f"- Mode: {meta['mode']}; {len(rows)} stages of {meta['stage_seconds']} s; "
        f"{meta['visitors']:,} simulated client addresses" + ("" if meta["spoof_clients"] else " (disabled: one address)"),
    ]
    if args.server:
        context.append(f"- Server: {args.server}")
    if stored_db is not None:
        context.append(
            f"- Stored rows: {stored_db.get('total', 0):,} in the database for {stored_total:,} requests reported as stored "
            f"({', '.join(f'{k}: {v:,}' for k, v in sorted(stored_db.get('by_mode', {}).items()))})"
        )
    if statuses:
        context.append("- Responses: " + ", ".join(f"{name}: {count:,}" for name, count in sorted(statuses.items())))
    for count, message in [(c, m) for m, c in problems]:
        context.append(f"- PHP log ({count:,}×): `{message}`")

    markdown = "\n".join([f"### Load test: {args.label}", "", verdict, "", *lines, "", *context, ""])

    plain_lines = [verdict.replace("**", "")]
    plain_lines.append("target  achieved  failed  dropped  p50ms  p95ms  p99ms  cpu  ok")
    for row in rows:
        cpu = f"{row['cpu_busy_pct']:.0f}%" if "cpu_busy_pct" in row else "-"
        plain_lines.append(
            f"{row['target_rps']:>6}  {row['achieved_rps']:>8.0f}  {row['failure_rate'] * 100:>5.1f}%  {row['dropped']:>7}  "
            f"{fmt_ms(row['p50_ms']):>5}  {fmt_ms(row['p95_ms']):>5}  {fmt_ms(row['p99_ms']):>5}  {cpu:>4}  {'yes' if row['sustained'] else 'no'}"
        )
    plain_lines.extend(line.removeprefix("- ") for line in context)
    plain = "\n".join(plain_lines)

    result = {
        "label": args.label,
        "mode": meta["mode"],
        "capacity_rps": capacity["target_rps"] if capacity else None,
        "stages": rows,
        "stored_in_database": stored_db,
        "statuses": statuses,
    }
    return markdown, plain, result


def annotation_escape(text: str) -> str:
    return text.replace("%", "%25").replace("\r", "").replace("\n", "%0A")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--summary", required=True, help="k6 JSON summary from receive.js")
    parser.add_argument("--label", required=True, help="Short description of the setup")
    parser.add_argument("--server", help="Server description, such as CPUs, memory and PHP workers")
    parser.add_argument("--vmstat", help="Output of `vmstat -n -t 1` recorded during the run")
    parser.add_argument("--stored", help="JSON with the stored row counts")
    parser.add_argument("--php-log", help="PHP-FPM log to scan for errors")
    parser.add_argument("--json-out", help="Write the per-stage results as JSON here")
    parser.add_argument("--github", action="store_true", help="Append to the job summary and emit a notice")
    args = parser.parse_args()

    markdown, plain, result = build_report(args)
    print(markdown)
    if args.json_out:
        Path(args.json_out).write_text(json.dumps(result, indent=2))
    if args.github:
        summary_path = os.environ.get("GITHUB_STEP_SUMMARY")
        if summary_path:
            with open(summary_path, "a", encoding="utf-8") as handle:
                handle.write(markdown + "\n")
        print(f"::notice title=Load test: {annotation_escape(args.label)}::{annotation_escape(plain)}")


if __name__ == "__main__":
    main()
