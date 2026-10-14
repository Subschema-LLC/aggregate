#!/usr/bin/env bash
# Sends one anonymous event and fails unless the installation records it.
#
#   tests/Load/server/smoke.sh BASE_URL WEBSITE_TOKEN
set -euo pipefail
# Logs are not always readable afterwards, so name the failing command in an annotation.
trap 'status=$?; echo "::error title=$(basename "$0")::line ${LINENO}: ${BASH_COMMAND} (exit ${status})"' ERR

base_url=${1:?base URL}
token=${2:?website token}

body=$(curl -sS -X POST "${base_url}/api/receive" \
  -H 'Content-Type: application/json' -H 'Origin: https://loadtest.example' -H 'X-Forwarded-For: 198.19.255.254' \
  -d "{\"websiteToken\":\"${token}\",\"eventName\":\"view\",\"pagePath\":\"/\",\"consentState\":\"denied\"}")
echo "$body"
if ! echo "$body" | grep -q '"recorded"'; then
  sudo tail -n 50 /var/log/php8.3-fpm.log || true
  sudo sh -c 'tail -n 50 /var/log/nginx/aggregate_error*.log' || true
  echo "::error title=Smoke test::${base_url}/api/receive did not record the event: $body"
  exit 1
fi
