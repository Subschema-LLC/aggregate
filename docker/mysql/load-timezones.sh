#!/bin/bash
# Load MySQL/MariaDB time zone tables so CONVERT_TZ can use names such as
# America/Chicago. Safe to run more than once. Warnings about files in
# zoneinfo that are not time zones are normal.
set -eu

zoneinfo=/usr/share/zoneinfo
if [ ! -d "$zoneinfo" ]; then
  echo "No zoneinfo directory at $zoneinfo." >&2
  exit 1
fi

if command -v mariadb-tzinfo-to-sql >/dev/null 2>&1; then
  loader=$(command -v mariadb-tzinfo-to-sql)
elif command -v mysql_tzinfo_to_sql >/dev/null 2>&1; then
  loader=$(command -v mysql_tzinfo_to_sql)
else
  echo "mysql_tzinfo_to_sql or mariadb-tzinfo-to-sql was not found." >&2
  exit 1
fi

if command -v mariadb >/dev/null 2>&1; then
  client=$(command -v mariadb)
elif command -v mysql >/dev/null 2>&1; then
  client=$(command -v mysql)
else
  echo "The mariadb or mysql client was not found." >&2
  exit 1
fi

password="${MARIADB_ROOT_PASSWORD:-${MYSQL_ROOT_PASSWORD:-}}"
defaults=""
client_args=()
cleanup() {
  if [ -n "$defaults" ]; then
    rm -f "$defaults"
  fi
  if [ -n "${sql_file:-}" ]; then
    rm -f "$sql_file"
  fi
}
trap cleanup EXIT

if [ -n "$password" ]; then
  defaults=$(mktemp)
  chmod 600 "$defaults"
  escaped=$(printf '%s' "$password" | sed 's/\\/\\\\/g; s/"/\\"/g')
  {
    printf '%s\n' '[client]' 'user=root'
    printf 'password="%s"\n' "$escaped"
  } > "$defaults"
  client_args=(--defaults-extra-file="$defaults" --protocol=socket)
else
  client_args=(--protocol=socket -u root)
fi

probe=$("$client" "${client_args[@]}" -Nse "SELECT CONVERT_TZ('2024-07-15 12:00:00','UTC','America/Chicago')" || true)
if [ -n "$probe" ] && [ "$probe" != "NULL" ]; then
  echo "Time zone tables already convert America/Chicago ($probe)."
  echo "Then run: php bin/console app:analytics:bi-views:regenerate"
  exit 0
fi

count=$("$client" "${client_args[@]}" -Nse "SELECT COUNT(*) FROM mysql.time_zone_name" || true)
if [ -n "$count" ] && [ "$count" != "0" ]; then
  "$client" "${client_args[@]}" mysql <<'SQL'
SET FOREIGN_KEY_CHECKS=0;
TRUNCATE TABLE time_zone_transition;
TRUNCATE TABLE time_zone_transition_type;
TRUNCATE TABLE time_zone_name;
TRUNCATE TABLE time_zone;
SET FOREIGN_KEY_CHECKS=1;
SQL
fi

sql_file=$(mktemp)
loader_status=0
"$loader" "$zoneinfo" > "$sql_file" || loader_status=$?
if [ ! -s "$sql_file" ]; then
  echo "Could not read time zone data from $zoneinfo (loader exit $loader_status)." >&2
  exit 1
fi
if [ "$loader_status" -ne 0 ]; then
  echo "The time zone loader exited $loader_status after writing SQL. Warnings about files that are not time zones are normal." >&2
fi
"$client" "${client_args[@]}" mysql < "$sql_file"

after=$("$client" "${client_args[@]}" -Nse "SELECT CONVERT_TZ('2024-07-15 12:00:00','UTC','America/Chicago')" || true)
if [ -z "$after" ] || [ "$after" = "NULL" ]; then
  echo "Tables were loaded, but the server still cannot convert America/Chicago. Restart the database server, then run this again." >&2
  exit 1
fi

echo "Loaded MySQL time zone tables. America/Chicago converts to $after."
echo "Then run: php bin/console app:analytics:bi-views:regenerate"
