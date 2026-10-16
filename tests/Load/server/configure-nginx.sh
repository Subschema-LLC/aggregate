#!/usr/bin/env bash
# Serves one or more load-test installations with the documented Nginx site
# configuration (docs/nginx/aggregate-analytics.conf), one port each.
#
#   tests/Load/server/configure-nginx.sh 80:/srv/aggregate [8082:/srv/head ...]
set -euo pipefail
# Logs are not always readable afterwards, so name the failing command in an annotation.
trap 'status=$?; echo "::error title=$(basename "$0")::line ${LINENO}: ${BASH_COMMAND} (exit ${status})"' ERR

repo_root=$(cd "$(dirname "$0")/../../.." && pwd)
template="$repo_root/docs/nginx/aggregate-analytics.conf"
test "$#" -ge 1

sudo rm -f /etc/nginx/sites-enabled/default /etc/nginx/conf.d/aggregate*.conf
first=1
for site in "$@"; do
  port=${site%%:*}
  root=${site#*:}
  default=''
  if [ "$first" = 1 ]; then default=' default_server'; fi
  # The access-log map is declared once, in the first site.
  sed -e "s#root /var/www/aggregate-sy/public;#root ${root}/public;#" \
      -e 's#php8.2-fpm.sock#php8.3-fpm.sock#g' \
      -e "s#listen 80;#listen ${port}${default};#" \
      -e 's#server_name analytics.example.com;#server_name _;#' \
      -e "s#aggregate_error\.log#aggregate_error_${port}.log#" \
      -e "s#aggregate_access\.log#aggregate_access_${port}.log#" \
      "$template" > "/tmp/aggregate-${port}.conf"
  if [ "$first" = 0 ]; then
    # Drop the map block from later sites; nginx allows one per variable.
    python3 - "/tmp/aggregate-${port}.conf" <<'PY'
import re, sys
path = sys.argv[1]
text = open(path).read()
open(path, 'w').write(re.sub(r'map \$request_uri \$aggregate_access_loggable \{.*?\n\}\n', '', text, count=1, flags=re.S))
PY
  fi
  sudo install -m 0644 "/tmp/aggregate-${port}.conf" "/etc/nginx/conf.d/aggregate-${port}.conf"
  first=0
done

sudo sed -i -e 's/worker_connections [0-9]*;/worker_connections 8192;/' /etc/nginx/nginx.conf
grep -q worker_rlimit_nofile /etc/nginx/nginx.conf || sudo sed -i '1i worker_rlimit_nofile 32768;' /etc/nginx/nginx.conf
if ! test_output=$(sudo nginx -t 2>&1); then
  echo "::error title=Nginx configuration::$(echo "$test_output" | sed ':a;N;$!ba;s/%/%25/g;s/\n/%0A/g')"
  exit 1
fi
sudo systemctl restart nginx
