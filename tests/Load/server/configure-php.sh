#!/usr/bin/env bash
# Configures PHP-FPM 8.3 for load tests: one static pool of 4 workers per vCPU
# (at least 8), never recycled so CPU accounting stays complete, and the
# production OPcache settings from the deployment guide.
#
#   tests/Load/server/configure-php.sh [--profile DIR] [--preload APP_ROOT]
#
# With --profile, every /api/receive request is profiled with xhprof and its
# call data written to DIR (see tests/Load/profile/prepend.php). With
# --preload, OPcache preloads APP_ROOT/config/preload.php, Symfony's list of
# the classes the application uses, when PHP-FPM starts.
# Exports FPM_WORKERS to GitHub Actions when run there.
set -euo pipefail
# Logs are not always readable afterwards, so name the failing command in an annotation.
trap 'status=$?; echo "::error title=$(basename "$0")::line ${LINENO}: ${BASH_COMMAND} (exit ${status})"' ERR

profile_dir=''
preload_root=''
while [ "$#" -gt 0 ]; do
  case "$1" in
    --profile) profile_dir=${2:?profile directory}; shift 2 ;;
    --preload) preload_root=${2:?application root}; shift 2 ;;
    *) echo "Unknown option $1" >&2; exit 2 ;;
  esac
done

workers=$(( $(nproc) * 4 ))
if [ "$workers" -lt 8 ]; then workers=8; fi

sudo tee /etc/php/8.3/fpm/pool.d/www.conf > /dev/null <<EOF
[www]
user = $(id -un)
group = $(id -gn)
listen = /run/php/php8.3-fpm.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
listen.backlog = 4096
pm = static
pm.max_children = ${workers}
pm.max_requests = 0
catch_workers_output = yes
decorate_workers_output = no
EOF

sudo tee /etc/php/8.3/fpm/conf.d/99-load-test.ini > /dev/null <<'EOF'
opcache.enable=1
opcache.memory_consumption=256
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0
realpath_cache_size=4096K
realpath_cache_ttl=600
memory_limit=256M
EOF

if [ -n "$preload_root" ]; then
  test -f "$preload_root/config/preload.php"
  sudo tee /etc/php/8.3/fpm/conf.d/99-load-test-preload.ini > /dev/null <<EOF
opcache.preload=${preload_root}/config/preload.php
opcache.preload_user=$(id -un)
EOF
else
  sudo rm -f /etc/php/8.3/fpm/conf.d/99-load-test-preload.ini
fi

if [ -n "$profile_dir" ]; then
  if ! php-fpm8.3 -m | grep -qi '^xhprof$'; then
    log=/tmp/xhprof-install.log
    if ! { sudo apt-get install -y -qq php8.3-xhprof > "$log" 2>&1 \
        || sudo apt-get install -y -qq php-xhprof >> "$log" 2>&1 \
        || { sudo apt-get install -y -qq php8.3-dev >> "$log" 2>&1 \
             && sudo pecl install xhprof < /dev/null >> "$log" 2>&1 \
             && echo 'extension=xhprof.so' | sudo tee /etc/php/8.3/mods-available/xhprof.ini > /dev/null \
             && sudo phpenmod -v 8.3 xhprof; }; }; then
      echo "::error title=xhprof::$(tail -n 15 "$log" | sed ':a;N;$!ba;s/%/%25/g;s/\n/%0A/g')"
      exit 1
    fi
  fi
  sudo mkdir -p "$profile_dir"
  sudo chown "$(id -un):$(id -gn)" "$profile_dir"
  script_dir=$(cd "$(dirname "$0")/.." && pwd)
  sudo install -m 0644 "$script_dir/profile/prepend.php" /etc/php/8.3/fpm/load-test-prepend.php
  echo 'auto_prepend_file=/etc/php/8.3/fpm/load-test-prepend.php' | sudo tee /etc/php/8.3/fpm/conf.d/99-load-test-profile.ini > /dev/null
  # The prepended script reads its output directory from the pool environment.
  echo "env[LOAD_TEST_PROFILE_DIR] = ${profile_dir}" | sudo tee -a /etc/php/8.3/fpm/pool.d/www.conf > /dev/null
  php-fpm8.3 -m | grep -qi '^xhprof$' || { echo 'xhprof is not available for PHP-FPM' >&2; exit 1; }
else
  sudo rm -f /etc/php/8.3/fpm/conf.d/99-load-test-profile.ini
fi

if ! test_output=$(sudo php-fpm8.3 -t 2>&1); then
  echo "::error title=PHP-FPM configuration::$(echo "$test_output" | sed ':a;N;$!ba;s/%/%25/g;s/\n/%0A/g')"
  exit 1
fi
sudo systemctl restart php8.3-fpm
if [ -n "${GITHUB_ENV:-}" ]; then echo "FPM_WORKERS=$workers" >> "$GITHUB_ENV"; fi
echo "PHP-FPM: ${workers} workers${profile_dir:+, profiling to $profile_dir}${preload_root:+, preloading $preload_root}"
