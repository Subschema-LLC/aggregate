#!/usr/bin/env bash
# Runs tests/Load/receive.js against one installation and records what the
# report needs: k6's summary, CPU seconds of PHP-FPM, Nginx and the database
# before and after, k6's own CPU time, vmstat samples, stored rows and logs.
#
#   tests/Load/server/measure.sh OUT_DIR BASE_URL APP_ROOT MODE RATES STAGE_SECONDS [DATABASE_IMAGE]
#
# The website token is read from APP_ROOT/var/load-test-token. Set
# WARMUP_SECONDS to change the warm-up (default 10).
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
  python3 "$load_dir/cpu_snapshot.py" --php php-fpm --web nginx ${database_image:+--database-image "$database_image"}
}

php "$load_dir/count-events.php" "$app_root" > "$out/before.json"
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
snapshot > "$out/cpu-after.json"
php "$load_dir/count-events.php" "$app_root" > "$out/after.json"

python3 - "$out" <<'PY'
import json, sys
out = sys.argv[1]
before, after = (json.load(open(f"{out}/{name}.json")) for name in ("before", "after"))
modes = set(before["by_mode"]) | set(after["by_mode"])
by_mode = {m: after["by_mode"].get(m, 0) - before["by_mode"].get(m, 0) for m in modes}
json.dump({"total": after["total"] - before["total"], "by_mode": by_mode}, open(f"{out}/stored.json", "w"))
PY

sudo cat /var/log/php8.3-fpm.log > "$out/php-fpm.log" 2>/dev/null || true
sudo sh -c 'cat /var/log/nginx/aggregate_error*.log' > "$out/nginx-error.log" 2>/dev/null || true
