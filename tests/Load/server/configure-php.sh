#!/usr/bin/env bash
# Configures PHP-FPM 8.3 for load tests: one static pool of 4 workers per vCPU
# (at least 8), never recycled so CPU accounting stays complete, and the
# production OPcache settings from the deployment guide.
#
#   tests/Load/server/configure-php.sh [--profile DIR]
#
# With --profile, every /api/receive request is profiled with xhprof and its
# call data written to DIR (see tests/Load/profile/prepend.php).
# Prints the number of workers.
set -euo pipefail

profile_dir=''
if [ "${1:-}" = '--profile' ]; then
  profile_dir=${2:?profile directory}
fi

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

if [ -n "$profile_dir" ]; then
  if ! php-fpm8.3 -m | grep -qi '^xhprof$'; then
    sudo apt-get install -y -qq php8.3-xhprof > /dev/null 2>&1 \
      || sudo apt-get install -y -qq php-xhprof > /dev/null 2>&1 \
      || { sudo apt-get install -y -qq php8.3-dev > /dev/null && yes '' | sudo pecl install xhprof > /dev/null \
           && echo 'extension=xhprof.so' | sudo tee /etc/php/8.3/fpm/conf.d/20-xhprof.ini > /dev/null; }
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

sudo php-fpm8.3 -t > /dev/null 2>&1
sudo systemctl restart php8.3-fpm
echo "$workers"
