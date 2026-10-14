#!/usr/bin/env python3
"""Summarize xhprof profiles of /api/receive requests from a load test.

Reads the JSON call graphs written by tests/Load/profile/prepend.php and
reports, per request on average: total PHP wall time, the functions with the
most exclusive (own) time, the Aggregate functions with the most inclusive
time, and the cost of the steps a collection request goes through. With
--github it appends the report to the job summary and emits a notice.

Profiled times are wall time under xhprof, which adds overhead to every
function call; compare them with each other, not with unprofiled timings.
"""

from __future__ import annotations

import argparse
import json
import os
import statistics
from collections import defaultdict
from pathlib import Path

# Steps of a collection request, by the function that covers each one.
STEPS = [
    ("Symfony Runtime and .env loading", "Symfony\\Component\\Runtime\\SymfonyRuntime::__construct"),
    ("Dotenv: read .env files", "Symfony\\Component\\Dotenv\\Dotenv::bootEnv"),
    ("Kernel boot (container)", "Symfony\\Component\\HttpKernel\\Kernel::boot"),
    ("Handle the request", "Symfony\\Component\\HttpKernel\\HttpKernel::handle"),
    ("Routing", "Symfony\\Component\\Routing\\Matcher\\UrlMatcher::match"),
    ("ReceiveController", "App\\Controller\\ReceiveController::__invoke"),
    ("Aggregate YAML settings", "App\\Service\\AggregateConfigLoader::load"),
    ("Website registry YAML", "App\\Service\\WebsiteConfigManager::getWebsites"),
    ("All YAML parsing", "Symfony\\Component\\Yaml\\Yaml::parse"),
    ("Rate limiter", "App\\Security\\IpRateLimiter::allow"),
    ("Database connection", "PDO::__construct"),
    ("Anonymous write (ORM)", "App\\Service\\AnonymousEventRecorder::record"),
    ("Enhanced dispatch (Messenger)", "Symfony\\Component\\Messenger\\MessageBus::dispatch"),
    ("ORM flush", "Doctrine\\ORM\\EntityManager::flush"),
    ("Send the response", "Symfony\\Component\\HttpFoundation\\Response::send"),
    ("Kernel terminate", "Symfony\\Component\\HttpKernel\\Kernel::terminate"),
]


def load_profiles(directory: Path) -> list[dict]:
    profiles = []
    for path in sorted(directory.glob("*.json")):
        try:
            data = json.loads(path.read_text())
        except (OSError, json.JSONDecodeError):
            continue
        if isinstance(data, dict) and "main()" in data:
            profiles.append(data)
    return profiles


def aggregate(profiles: list[dict]) -> tuple[dict, dict, dict, list[float]]:
    inclusive: dict[str, float] = defaultdict(float)
    children: dict[str, float] = defaultdict(float)
    calls: dict[str, float] = defaultdict(float)
    totals = []
    for profile in profiles:
        totals.append(profile["main()"]["wt"] / 1000)
        for edge, values in profile.items():
            if edge == "main()":
                inclusive["main()"] += values["wt"]
                calls["main()"] += values["ct"]
                continue
            parent, _, child = edge.partition("==>")
            # xhprof marks recursive frames as name@N; fold them into the name.
            child = child.split("@")[0]
            parent = parent.split("@")[0]
            if child != parent:
                inclusive[child] += values["wt"]
                children[parent] += values["wt"]
            calls[child] += values["ct"]
    exclusive = {name: inclusive[name] - children.get(name, 0.0) for name in inclusive}
    count = len(profiles)
    scale = lambda table: {name: value / count / 1000 for name, value in table.items()}  # ms per request
    return scale(inclusive), scale(exclusive), {name: value / count for name, value in calls.items()}, totals


def build(args: argparse.Namespace) -> tuple[str, str]:
    profiles = load_profiles(Path(args.directory))
    if not profiles:
        message = f"No profiles found in {args.directory}."
        return f"### Profile: {args.label}\n\n{message}\n", message

    inclusive, exclusive, calls, totals = aggregate(profiles)
    mean_total = statistics.fmean(totals)
    median_total = statistics.median(totals)

    top_exclusive = sorted(((v, k) for k, v in exclusive.items() if k != "main()"), reverse=True)[: args.top]
    top_app = sorted(((v, k) for k, v in inclusive.items() if k.startswith("App\\")), reverse=True)[:12]

    md = [f"### Profile: {args.label}", ""]
    md.append(
        f"{len(profiles):,} requests profiled. PHP wall time per request: {mean_total:.1f} ms on average, "
        f"{median_total:.1f} ms median (xhprof overhead included)."
    )
    md += ["", "| Step | ms per request | Share |", "| --- | ---: | ---: |"]
    plain = [
        f"{len(profiles):,} requests; PHP wall time {mean_total:.1f} ms mean, {median_total:.1f} ms median (with xhprof overhead)",
        "STEPS (inclusive ms per request, share of total)",
    ]
    for label, function in STEPS:
        if function in inclusive:
            value = inclusive[function]
            md.append(f"| {label} | {value:.2f} | {value / mean_total * 100:.0f}% |")
            plain.append(f"  {value:6.2f}  {value / mean_total * 100:3.0f}%  {label}  [{calls.get(function, 0):.1f} calls]")

    md += ["", "Most exclusive (own) time:", "", "| Function | ms per request | Calls | Share |", "| --- | ---: | ---: | ---: |"]
    plain.append("TOP OWN TIME (ms per request, calls, share)")
    for value, name in top_exclusive:
        md.append(f"| `{name}` | {value:.2f} | {calls.get(name, 0):,.1f} | {value / mean_total * 100:.1f}% |")
        plain.append(f"  {value:6.2f}  {calls.get(name, 0):8.1f}  {value / mean_total * 100:4.1f}%  {name}")

    md += ["", "Aggregate code by inclusive time:", "", "| Function | ms per request |", "| --- | ---: |"]
    plain.append("AGGREGATE CODE, INCLUSIVE (ms per request)")
    for value, name in top_app:
        md.append(f"| `{name}` | {value:.2f} |")
        plain.append(f"  {value:6.2f}  {name}")

    return "\n".join(md) + "\n", "\n".join(plain)


def escape(text: str, property_value: bool = False) -> str:
    text = text.replace("%", "%25").replace("\r", "").replace("\n", "%0A")
    return text.replace(":", "%3A").replace(",", "%2C") if property_value else text


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("directory", help="Directory of xhprof JSON profiles")
    parser.add_argument("--label", required=True)
    parser.add_argument("--top", type=int, default=25, help="How many functions to list by own time")
    parser.add_argument("--github", action="store_true")
    args = parser.parse_args()

    markdown, plain = build(args)
    print(markdown)
    if args.github:
        path = os.environ.get("GITHUB_STEP_SUMMARY")
        if path:
            with open(path, "a", encoding="utf-8") as handle:
                handle.write(markdown + "\n")
        print(f"::notice title={escape('Profile: ' + args.label, True)}::{escape(plain)}")


if __name__ == "__main__":
    main()
