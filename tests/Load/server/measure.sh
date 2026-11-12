#!/usr/bin/env bash
# Runs tests/Load/receive.js against one installation and records what the
# report needs: k6's summary, CPU seconds of PHP-FPM, Nginx and the database
# before and after, k6's own CPU time, vmstat samples, stored rows and logs.
#
#   tests/Load/server/measure.sh OUT_DIR BASE_URL APP_ROOT MODE RATES STAGE_SECONDS [DATABASE_IMAGE]
#
# The website token is read from APP_ROOT/var/load-test-token. Set
# WARMUP_SECONDS to change the warm-up (default 10). Set DRAIN_QUEUE=1 when
# queue consumers run: their CPU is counted apart from PHP-FPM's, and the run
# waits up to five minutes for the queue to empty before counting CPU and
# stored events. Set DATABASE_COUNTERS=1, with DATABASE_IMAGE, to read the
# database's own counters (tests/Load/db_stats.py) just outside the CPU
# window, into db-before.json and db-after.json.
set -euo pipefail
# Logs are not always readable afterwards, so name the failing command in an annotation.
trap 'status=$?; echo "::error title=$(basename "$0")::line ${LINENO}: ${BASH_COMMAND} (exit ${status})"' ERR

out=${1:?output directory}
base_url=${2:?base URL}
app_root=${3:?application root}
mode=${4:?mode}
rates=${5:?rates}
stage_seconds=${6:?stage seconds}
database_image=${7:-}

load_dir=$(cd "$(dirname "$0")/.." && pwd)
mkdir -p "$out"
token=$(cat "$app_root/var/load-test-token")

snapshot() {
  python3 "$load_dir/cpu_snapshot.py" --php php-fpm --web nginx ${DRAIN_QUEUE:+--cli php} ${database_image:+--database-image "$database_image"}
}

counters() {
  if [ -n "${DATABASE_COUNTERS:-}" ] && [ -n "$database_image" ]; then
    python3 "$load_dir/db_stats.py" --image "$database_image" > "$out/db-$1.json"
  fi
}

php "$load_dir/count-events.php" "$app_root" > "$out/before.json"
counters before
snapshot > "$out/cpu-before.json"
vmstat -n -t 1 > "$out/vmstat.log" &
vmstat_pid=$!
ulimit -n "$(ulimit -Hn)"
/usr/bin/time -f '%U %S %e' -o "$out/k6-time.txt" k6 run --quiet \
  -e BASE_URL="$base_url" \
  -e WEBSITE_TOKEN="$token" \
  -e ORIGIN=https://loadtest.example \
  -e MODE="$mode" \
  -e RATES="$rates" \
  -e STAGE_SECONDS="$stage_seconds" \
  -e WARMUP_SECONDS="${WARMUP_SECONDS:-10}" \
  -e SUMMARY_FILE="$out/k6-summary.json" \
  "$load_dir/receive.js"
kill "$vmstat_pid"
if [ -n "${DRAIN_QUEUE:-}" ]; then
  waited=0
  until [ "$(php "$load_dir/queue-depth.php" "$app_root")" = 0 ]; do
    if [ "$waited" -ge 300 ]; then
      echo "::warning title=$(basename "$0")::The queue was not empty after 300 s; events still queued are not counted."
      break
    fi
    sleep 1
    waited=$(( waited + 1 ))
  done
  echo "Queue drained in ${waited} s" > "$out/drain.txt"
fi
snapshot > "$out/cpu-after.json"
if [ -n "${DATABASE_COUNTERS:-}" ]; then
  # PostgreSQL publishes an idle connection's counters within ten seconds.
  sleep 11
fi
counters after
php "$load_dir/count-events.php" "$app_root" > "$out/after.json"

python3 - "$out" <<'PY'
import json, sys
out = sys.argv[1]
before, after = (json.load(open(f"{out}/{name}.json")) for name in ("before", "after"))
modes = set(before["by_mode"]) | set(after["by_mode"])
by_mode = {m: after["by_mode"].get(m, 0) - before["by_mode"].get(m, 0) for m in modes}
size = None if before.get("bytes") is None or after.get("bytes") is None else after["bytes"] - before["bytes"]
json.dump({"total": after["total"] - before["total"], "by_mode": by_mode, "bytes": size}, open(f"{out}/stored.json", "w"))
PY

sudo cat /var/log/php8.3-fpm.log > "$out/php-fpm.log" 2>/dev/null || true
sudo sh -c 'cat /var/log/nginx/aggregate_error*.log' > "$out/nginx-error.log" 2>/dev/null || true
