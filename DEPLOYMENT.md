# Deployment Guide

This guide covers installing a release ZIP on a web host, and Docker and native (non-Docker) deployment methods for Aggregate Analytics.

Complete first-time setup behind a local connection, VPN, or web-server access
restriction before exposing the application publicly. Prefer
`php bin/console app:install` to create the initial administrator. It asks for
the name and password, or takes them from `--username` and `--password` or the
`ADMIN_USERNAME` and `ADMIN_PASSWORD` environment variables for an unattended
install; an environment variable keeps the password out of the shell history.
The web installer (`/install`) and the release-ZIP
[setup page](docs/RELEASES.md#install-or-deploy-a-verified-package) both require
the one-time code from `SETUP-CODE.txt` in the application directory (created
automatically on first visit when not yet installed), plus CSRF protection, so a
visitor who cannot read the server's files cannot configure the database or
create the first account. Keep `/install` and `/install/execute` restricted
until setup completes, and delete or complete setup promptly. Headless
deployments should disable the dashboard as described in the
[configuration guide](docs/CONFIGURATION.md).

## Table of Contents

- [Release ZIP on a Web Host](#release-zip-on-a-web-host)
- [Docker Deployment](#docker-deployment)
- [Native Deployment](#native-deployment)
- [Web Server Configuration](#web-server-configuration)
- [Worker Process Setup](#worker-process-setup)
- [Production Considerations](#production-considerations)
  - [Scheduled tasks](#scheduled-tasks)
  - [Updates](#updates)
- [Troubleshooting](#troubleshooting)

---

## Release ZIP on a Web Host

A signed release ZIP contains the production dependencies and compiled assets,
so the server needs only PHP 8.2+ and a database: no terminal, Git, Composer or
Node. These steps work with any hosting panel or web server that runs PHP.

1. **Prepare the site.** Create the site in your hosting panel, choose PHP 8.2 or
   newer for it, and create an empty MySQL 8.0+, MariaDB 10.6+, PostgreSQL 13+ or
   [SQL Server 2017+](docs/DATABASE.md#microsoft-sql-server) database with a user
   that has full access to it. SQLite needs no database
   server and suits trying Aggregate out or small sites, but BI tools read the
   whole file, so they cannot be limited to the reporting views.
2. **Upload the release.** Download `aggregate-VERSION.zip` from the **Assets** of
   the [latest release](https://github.com/Subschema-LLC/aggregate/releases/latest),
   not the **Source code** archives, which lack the dependencies. Upload and
   extract it with the site's file manager, so that the files belong to the
   account PHP runs as.
3. **Set the document root** to the extracted `public` folder. Everything else in
   the folder holds configuration and data and must not be reachable from the web.
4. **Open the site.** The [setup page](docs/RELEASES.md#install-or-deploy-a-verified-package)
   checks the server, asks for the one-time code in `SETUP-CODE.txt` (next to
   `README.md` in the extracted folder), tests the database connection and writes
   `.env.local`. Then create the administrator account on `/install`.
5. **Add one scheduled task** that runs `bin/console app:scheduler:run` every
   five minutes, as the user PHP runs as. It runs maintenance and the optional
   syncs. **Administration → Scheduled tasks** shows the exact settings for
   your server and whether the task runs; see [Scheduled tasks](docs/SCHEDULED-TASKS.md).

The web server must pass requests for addresses that are not files, and for
`/aggregate.js`, to `public/index.php`. Apache does this through `public/.htaccess`
when overrides are allowed; for nginx, use the rules under
[Web Server Configuration](#web-server-configuration). The setup page checks both.
Events need no background worker in the default `sync://` mode. Later updates are installed from the dashboard **Updates** page; see the
[update guide](docs/UPDATES.md).

### Hosting panel guides

Step-by-step guides with each panel's own menu names and fixes:

| Hosting panel | Guide |
| --- | --- |
| Plesk | [Plesk deployment](PLESK-DEPLOYMENT.md#install-from-a-release-zip-no-ssh) |
| cPanel | Planned; see the [roadmap](ROADMAP.md#after-beta-feedback) |

The setup page itself names no hosting panel, so these guides are where
panel-specific steps belong.

---

## Docker Deployment

> **Development-only configuration:** The checked-in `compose.yaml` is for local development (`APP_ENV=dev`, `APP_DEBUG=1`, mounted working tree). Database ports are bound to `127.0.0.1` and images are pinned by digest. The database passwords default to the development values in `.env.dev`; set `MYSQL_PASSWORD`, `POSTGRES_PASSWORD` or `MARIADB_PASSWORD` (and the matching root password) in the environment to use your own. For production, use a [release ZIP](#release-zip-on-a-web-host), [native deployment](#native-deployment), or a dedicated production container image with `APP_ENV=prod` and `APP_DEBUG=0`.

### Quick Start

```bash
# Clone repository
git clone https://github.com/Subschema-LLC/aggregate.git aggregate-sy
cd aggregate-sy

# Copy app configuration (privacy controls, rate limit, JS namespace)
cp config/aggregate.yaml.example config/aggregate.yaml
nano config/aggregate.yaml

# Start services
make start-mysql

# Run migrations
make migrate-mysql
```

Optional dashboard setup: open `http://localhost/install` to create an admin user. After install, admins can add users and reset passwords from **Administration → Users**, or via CLI with `php bin/console app:user:create` and `php bin/console app:user:reset-password`.

If you use generic profile commands (`make start DOCKER_PROFILE=...`), set `DOCKER_DATABASE_URL` to a matching DSN.
Wrapper targets (`make start-postgres`, `make start-mariadb`) set sensible defaults automatically.

To enable optional coarse geography in Docker, obtain and update a GeoLite2-Country-compatible MMDB yourself and mount it read-only into the `php` service, for example in `compose.override.yaml`:

```yaml
services:
  php:
    volumes:
      - /srv/geoip/GeoLite2-Country.mmdb:/var/lib/GeoIP/GeoLite2-Country.mmdb:ro
```

Then set `anonymous_geo_enabled: true` and `anonymous_geo_database_path: /var/lib/GeoIP/GeoLite2-Country.mmdb` in `config/aggregate.yaml`. Aggregate does not download the database or use a hosted lookup API. A missing/unreadable database leaves geography empty and does not stop ingestion.

### Docker Architecture

- **php**: FrankenPHP web server
- **worker**: Background job processor
- **database**: selected profile (`mysql`, `postgres`, or `mariadb`)
- **asset-compile**: Frontend asset compilation

### Docker Commands

All `make` commands work automatically in Docker mode:
- `make start-mysql` - Start containers with MySQL profile
- `make start-postgres` - Start containers with PostgreSQL profile
- `make start-mariadb` - Start containers with MariaDB profile
- `make stop` - Stop containers
- `make logs` - View logs
- `make migrate-mysql` - Run migrations with MySQL profile
- `make migrate-postgres` - Run migrations with PostgreSQL profile
- `make migrate-mariadb` - Run migrations with MariaDB profile
- `make create-website` - Create tracking website

---

## Native Deployment

Native deployment runs directly on your server without Docker. Ideal for shared hosting, VPS, or when you want full control.

### Requirements

- PHP 8.2+ with extensions: `ctype`, `iconv`, `pdo`, `mbstring`, `xml`, `curl`, `intl`
- PostgreSQL 13+, MySQL 8.0+, MariaDB 10.6+, Microsoft SQL Server 2017+, or SQLite 3.25+
- Composer
- Web server (Nginx, Apache, or FrankenPHP)
- Process supervisor (systemd, Supervisor, or cron)

### Quick Install

```bash
# Clone repository
git clone https://github.com/Subschema-LLC/aggregate.git aggregate-sy
cd aggregate-sy

# Run interactive installer
chmod +x install.sh
./install.sh
```

The installer will:
1. Check PHP version and extensions
2. Install Composer dependencies
3. Generate secure secrets
4. Configure database
5. Run migrations
6. Configure ingestion mode (`sync://` quick mode or async queue mode)
7. Create first website

### Manual Installation

When upgrading to Doctrine DBAL 4, first review the
[database version hint changes](docs/DATABASE.md#upgrade-to-doctrine-dbal-4).
MySQL and MariaDB need full version hints in the active `DATABASE_URL`.

```bash
# 1. Install dependencies
composer install --no-dev --optimize-autoloader

# 2. Create .env in project root (never committed to git)
#    This is your server's environment file.
cat > .env <<'EOF'
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=change-me-use-openssl-rand-hex-32
DATABASE_URL="mysql://user:pass@localhost:3306/dbname?serverVersion=8.0.0"
MESSENGER_TRANSPORT_DSN=sync://   # default quick mode (no worker required)
MAILER_DSN=null://null
EOF

# Async scale-up mode (worker required):
# MESSENGER_TRANSPORT_DSN=doctrine://default

# 3. Copy app configuration
cp config/aggregate.yaml.example config/aggregate.yaml
# Review rate_limit_per_minute, app_host, js_namespace, dashboard_enabled,
# anonymous_tracking_enabled, anonymous_excluded_paths, and the optional
# anonymous_geo_* coarse-geography controls
# Optional API-only mode: set DASHBOARD_ENABLED=0 in .env and run php bin/console cache:clear

# 4. Run migrations
php bin/console doctrine:migrations:migrate -n
php bin/console app:analytics:glossary:sync

# 5. Compile assets
php bin/console asset-map:compile

# 6. Give the PHP process access to runtime data, not application code
chown -R www-data:www-data var/
find var -type d -exec chmod u+rwx,g-w,o-rwx {} +
# Or grant ACL access for the PHP user and your deployment user:
# setfacl -dR -m u:www-data:rwX -m u:"$(whoami)":rwX var
# setfacl -R -m u:www-data:rwX -m u:"$(whoami)":rwX var
# Keep public/ and application code owned by the deployment user.
# See Production Considerations for writable admin configuration and uploads.

# 7. Optional: open https://your-domain.com/install to create
#    a dashboard admin user and update dashboard settings.
#    After install, manage additional users/passwords in Administration -> Users,
#    or via CLI with app:user:create and app:user:reset-password.
#    CLI alternative: php bin/console app:install

# 8. Schedule maintenance and the optional syncs: one cron line for www-data
#    (sudo crontab -u www-data -e). app:scheduler:status shows whether it runs.
#    */5 * * * * cd /var/www/aggregate && php bin/console app:scheduler:run --no-interaction
```

For optional coarse geography on a native deployment, place a current GeoLite2-Country-compatible MMDB on the local filesystem outside `public/`, make it read-only and readable by the PHP ingestion process, then configure its absolute path. Direct UNC/network-share and Windows device paths are rejected. Do not give the application write access to the database file.

When a reverse proxy sits in front of PHP, list only its exact IP addresses or narrow CIDRs in the comma-separated `TRUSTED_PROXIES` environment variable, for example `TRUSTED_PROXIES=127.0.0.1,10.20.30.0/24`. The proxy must strip or overwrite client-supplied `X-Forwarded-*` headers before setting its own. Leave the variable blank for direct deployments. Never use `0.0.0.0/0`, `::/0`, or the Symfony `REMOTE_ADDR` shortcut: an overly broad or unsanitized trust boundary lets clients spoof forwarding headers. Without correct proxy trust, coarse geography will normally describe the proxy or remain empty rather than the visitor.

---

## Web Server Configuration

### Nginx

**1. Copy configuration:**
```bash
sudo cp docs/nginx/aggregate-analytics.conf /etc/nginx/sites-available/analytics
sudo ln -s /etc/nginx/sites-available/analytics /etc/nginx/sites-enabled/
```

**2. Edit configuration:**
```nginx
server {
    listen 80;
    server_name analytics.example.com;  # Change this
    root /var/www/aggregate-sy/public;  # Change this

    # ... rest of config
}
```

**3. Test and reload:**
```bash
sudo nginx -t
sudo systemctl reload nginx
```

**Important settings:**
- `server_name`: Your domain
- `root`: Path to `public/` directory
- `fastcgi_pass`: PHP-FPM socket (usually `/var/run/php/php8.2-fpm.sock`), in each location that sets it
- Compression: the file compresses scripts and styles with gzip, a [page speed saver](docs/PAGE-SPEED.md#turn-on-compression); with the `ngx_brotli` module, uncomment its Brotli lines

### Apache

**1. Copy configuration:**
```bash
sudo cp docs/apache/aggregate-analytics.conf /etc/apache2/sites-available/analytics.conf
sudo a2ensite analytics
sudo a2enmod rewrite headers filter deflate brotli
```

`public/.htaccess` compresses scripts and styles with Brotli, or gzip for
browsers without it, when these modules are enabled: a
[page speed saver](docs/PAGE-SPEED.md#turn-on-compression).

**2. Edit configuration:**
```apache
<VirtualHost *:80>
    ServerName analytics.example.com    # Change this
    DocumentRoot /var/www/aggregate-sy/public  # Change this

    # ... rest of config
</VirtualHost>
```

**3. Reload:**
```bash
sudo systemctl reload apache2
```

### FrankenPHP (Standalone)

For native FrankenPHP without Docker:

```bash
# Download
curl -L https://github.com/dunglas/frankenphp/releases/latest/download/frankenphp-linux-x86_64 -o frankenphp
chmod +x frankenphp

# Run
./frankenphp php-server --root public/
```

`php-server` does not compress responses. The Docker image's
[`frankenphp/Caddyfile`](frankenphp/Caddyfile) compresses scripts and styles, a
[page speed saver](docs/PAGE-SPEED.md#turn-on-compression); to use it here,
change its `root` to your `public/` directory and run
`./frankenphp run --config frankenphp/Caddyfile`.

---

## Worker Process Setup

The worker process handles background analytics event processing.
It is required only when `MESSENGER_TRANSPORT_DSN` is async, such as `doctrine://default`.
If you use `MESSENGER_TRANSPORT_DSN=sync://`, no worker is needed.

### Option 1: systemd (Recommended)

**1. Copy service file:**
```bash
sudo cp docs/systemd/aggregate-worker.service /etc/systemd/system/
```

**2. Edit service file:**
```ini
[Service]
User=www-data              # Change if needed
WorkingDirectory=/var/www/aggregate-sy  # Change to your path
ExecStart=/usr/bin/php /var/www/aggregate-sy/bin/console messenger:consume async --time-limit=3600
```

**3. Enable and start:**
```bash
sudo systemctl daemon-reload
sudo systemctl enable aggregate-worker
sudo systemctl start aggregate-worker
sudo systemctl status aggregate-worker
```

**Manage service:**
```bash
sudo systemctl stop aggregate-worker
sudo systemctl restart aggregate-worker
sudo journalctl -u aggregate-worker -f  # View logs
```

### Option 2: Supervisor

**1. Install Supervisor:**
```bash
# Debian/Ubuntu
sudo apt-get install supervisor

# CentOS/RHEL
sudo yum install supervisor
```

**2. Copy configuration:**
```bash
sudo cp docs/supervisor/aggregate-worker.conf /etc/supervisor/conf.d/
```

**3. Edit configuration:**
```ini
[program:aggregate-worker]
command=/usr/bin/php /var/www/aggregate-sy/bin/console messenger:consume async
user=www-data
directory=/var/www/aggregate-sy
```

**4. Reload Supervisor:**
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start aggregate-worker:*
```

**Manage workers:**
```bash
sudo supervisorctl status
sudo supervisorctl stop aggregate-worker:*
sudo supervisorctl restart aggregate-worker:*
sudo supervisorctl tail -f aggregate-worker
```

### Option 3: Cron (Simple but Less Reliable)

Add to crontab (`crontab -e`):

```cron
* * * * * cd /var/www/aggregate-sy && php bin/console messenger:consume async --time-limit=60 >> var/log/worker.log 2>&1
```

**Note:** This runs every minute for 60 seconds, which may lead to overlapping processes or gaps in processing.

---

## Production Considerations

### Security

**1. Use HTTPS:**
Configure SSL/TLS certificates (Let's Encrypt recommended):
```bash
# For Nginx with Certbot
sudo apt-get install certbot python3-certbot-nginx
sudo certbot --nginx -d analytics.example.com
```

**2. Secure secrets:**
- Never commit `.env` (server-specific env file) to version control — it is gitignored by default
- Keep `config/aggregate.yaml`, environment-specific `config/aggregate_*.yaml`, `config/websites.yaml`, `config/website-scopes.yaml`, `config/website-scopes/` and `config/tag-manager/sites/` private; commit only sanitized example files
- Use a unique, strong `APP_SECRET` in the server environment
- Optionally set `AGGREGATE_SECRETS_KEY` (at least 32 characters, e.g. `openssl rand -hex 32`) in the server environment or `.env.local` to encrypt credential files under `config/secrets/` (`email.yaml`, `bigquery-service-account.json`, `bigquery-google-sign-in.json`, `mcp-oauth.yaml`) at rest. Keep a copy of the key outside the application folder: without it, encrypted files cannot be read until the key is set again, or the files are deleted and the credentials entered again. Files written before the key was set stay readable and are encrypted the next time they are saved
- Review anonymous path exclusions and set both BI minimum-cell thresholds before collection (`anonymous_min_cell_count`, default `5`; `anonymous_geo_min_cell_count`, default `25`), on the BI disclosure page or in YAML followed by `php bin/console app:analytics:bi-views:regenerate`
- Review every code in `config/goals.yaml`; keep values fixed and non-identifying, and set `anonymous: false` where enhanced consent is appropriate
- Leave coarse geography disabled unless its purpose, legal basis, notice, traffic volume, and local MMDB lifecycle have been reviewed
- Rotate secrets periodically

**3. Database access:**
- Use strong database passwords
- Restrict database access to localhost if possible
- On PostgreSQL/MySQL/MariaDB/SQL Server, grant routine BI users access only to the approved `bi_anonymous_events_v1`, `bi_anonymous_daily_v1`, `bi_anonymous_daily_rollup_v1`, `bi_anonymous_coverage_v1`, `bi_anonymous_goals_v1`, and, when needed, `bi_anonymous_geo_events_v1` views—not the private raw `events` table
- SQLite cannot enforce view-only grants; never distribute its database file to routine BI users—export approved view results or use a server database for direct BI access
- Regular backups

**4. File permissions:**
Keep application code, `vendor/`, and `public/` owned by the deployment user and
read-only to PHP. Give PHP write access to `var/` for cache, logs, sessions, and
other runtime state. Do not recursively make `public/` writable: it contains the
executable front controller and browser assets. Preserve executable permissions
on CLI scripts rather than applying one mode to every file in the checkout.

Admin settings also require narrowly scoped write access to the active aggregate
YAML file, `config/websites.yaml`, `config/website-scopes.yaml` and
`config/website-scopes/` for website groups, and `config/tag-manager/sites/` when
per-site tag/CMP editing is enabled. Pre-create these paths as the deployment user, grant
the PHP group only the access the enabled admin actions need, and
keep secrets readable only by the operator and necessary processes. Custom logo
uploads are stored under `var/branding/`, outside the web root. Headless operators
can maintain configuration through YAML/CLI without granting PHP write access to
the configuration directory. Do not change ownership of the whole checkout.

**5. Protect administrator access:**

Login and logout enforce CSRF protection. Login throttling allows five failed
attempts per username/IP pair per minute and an additional limit of 25 attempts
per IP per minute. These limits use Symfony's local rate-limiter cache by default;
they reset when it is cleared and are not a shared limit across multiple app
instances. Configure shared limiter storage for a multi-instance deployment and
apply web-server or proxy rate limits for broader abuse protection. Trust only
the actual proxy addresses so clients cannot choose the IP used by these limits.

Keep the admin interface on HTTPS, use unique administrator passwords, and limit
access through the network where practical. A missing users table permits fresh
setup; database connectivity/permission errors and malformed application
configuration now stop the installation check rather than reopening setup.
Browser setup errors omit internal diagnostics. For migration failures, run
`php bin/console doctrine:migrations:migrate` from the server; after successful
migrations, run `php bin/console app:analytics:glossary:sync`, then use
`php bin/console app:install` to create the administrator.

### Performance

**How much traffic one server handles.** Every page view and every tracked
event is one hit. With MySQL, MariaDB or PostgreSQL on the same server:

| Server | As installed | With preloading (item 3) |
| --- | ---: | ---: |
| 2 vCPU | about 1.2 million hits a day | about 1.5 million hits a day |
| 4 vCPU | about 2.4 million hits a day | about 3 million hits a day |
| 8 vCPU | about 4.8 million hits a day | about 6 million hits a day |

That assumes ordinary daily traffic, where the busiest minutes bring about
five times the day's average; halve it if visitors arrive in bursts, such as
after a newsletter or a campaign. Room for maintenance, the dashboard and syncs
is already left out. Plan disk space for about 0.3–0.6 GB per million hits
before archiving. The limit is the server's CPU, mostly PHP. See
[load testing and capacity](docs/LOAD-TESTING.md#at-a-glance-hits-per-day) for
how this was measured, the figures per second and how to test your own server.

**1. Use production environment:**
```bash
# In .env (your server's environment file)
APP_ENV=prod
APP_DEBUG=0
```

**2. Enable OPcache:**
```ini
; php.ini
opcache.enable=1
opcache.memory_consumption=256
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0  ; Disable for production
```

**3. Preload classes (optional):**
PHP-FPM can load the classes Symfony lists in `config/preload.php` once at
start-up instead of on every request:
```ini
; php.ini, or the PHP-FPM pool's php.ini
opcache.preload=/var/www/aggregate-sy/config/preload.php
opcache.preload_user=www-data   ; the PHP-FPM pool's user
```
Preloaded classes stay in memory until PHP-FPM reloads, so reload it
(`sudo systemctl reload php8.3-fpm`) after every update, including updates from
the dashboard's **Install update** button or `app:updates:apply`, which cannot
reload it for you. A reload preloads again; a restart is not needed. Leave
preloading off if your deployment cannot reload PHP-FPM.

**Reload PHP-FPM without dropping requests:** by default a PHP-FPM reload stops
busy workers at once, which failed about 0.1% of collection requests in the
[load test](docs/LOAD-TESTING.md#clearing-the-cache-under-traffic). Let them
finish instead:
```ini
; php-fpm.conf, [global] section (on Ubuntu: /etc/php/8.3/fpm/php-fpm.conf)
process_control_timeout = 10s
```
With it, reloads during traffic failed no requests. Prefer a reload to a
restart, which always dropped some.

**4. Use PostgreSQL or MySQL:**
SQLite is fine for small deployments, but use PostgreSQL or MySQL for production.
With PostgreSQL, keep connections open between requests with
[persistent connections or PgBouncer](docs/DATABASE.md#performance-tuning);
opening a new connection for every request is its largest cost. The setup page
turns persistent connections on for PostgreSQL by default.

**5. Queue enhanced events:**
By default (`sync://`) each enhanced event is saved during the request. To save
them in the background instead, use the included Doctrine transport and run a
[worker](#worker-process-setup):
```bash
# In .env.local
MESSENGER_TRANSPORT_DSN=doctrine://default
```
Only this transport is included. Redis and RabbitMQ (AMQP) would need the
`symfony/redis-messenger` or `symfony/amqp-messenger` package, which release
ZIPs do not contain, and an update replaces `vendor/`, so a package added by
hand disappears with the next update.

**6. Scale workers:**
Run multiple worker processes (with Supervisor or systemd):
```ini
; Supervisor: set numprocs
numprocs=4

; systemd: create multiple service instances
sudo systemctl start aggregate-worker@1
sudo systemctl start aggregate-worker@2
```

### Monitoring

**1. Health check:**
```bash
curl https://analytics.example.com/api/health
```

**2. Worker monitoring:**
```bash
# systemd
sudo systemctl status aggregate-worker

# Supervisor
sudo supervisorctl status

# Check message queue
php bin/console messenger:stats
```

**3. Logs:**
```bash
# Application logs (prod uses php://stderr by default, so they appear in the
# web server or PHP-FPM error log). Paths depend on your server, for example:
tail -f /var/log/nginx/error.log        # Nginx
tail -f /var/log/apache2/error.log      # Apache (Debian/Ubuntu)
# Hosting panels keep per-site logs; see your panel's documentation.

# Worker logs
tail -f var/log/worker.log

# Web server logs
tail -f /var/log/nginx/aggregate_error.log
```

**4. Database monitoring:**
```sql
-- Use the grouped/suppressed BI contract for anonymous-mode monitoring
SELECT * FROM bi_anonymous_events_v1 ORDER BY event_hour DESC;

-- Use the completed-day, suppressed conversion contract
SELECT * FROM bi_anonymous_goals_v1 ORDER BY event_day DESC, goal_event;

-- Use the lower-dimensional, daily suppressed geography contract
SELECT * FROM bi_anonymous_geo_events_v1 ORDER BY event_day DESC;

-- MySQL/MariaDB example: recent enhanced events (private table)
SELECT COUNT(*) FROM events
WHERE privacy_mode = 'enhanced' AND created_at > NOW() - INTERVAL 1 HOUR;

-- Website registration is stored in config/websites.yaml, not a database table.
```

### Backup

**1. Database backups:**
```bash
# PostgreSQL
pg_dump -U dbuser dbname > backup_$(date +%Y%m%d).sql

# MySQL
mysqldump -u dbuser -p dbname > backup_$(date +%Y%m%d).sql
```

**2. Application backups:**
Include all active environment files, website registrations, website groups and
their settings (`config/website-scopes.yaml` and `config/website-scopes/`), per-site
tag/CMP YAML under `config/tag-manager/sites/`, credentials under `config/secrets/` (such
as a BigQuery key), and branding uploads. Preserve these operator files when
replacing application code or deploying a release ZIP.

```bash
# Backup config (include deployment-specific settings — keep secure!)
tar --ignore-failed-read -czf config_backup_$(date +%Y%m%d).tar.gz .env .env.local config/aggregate*.yaml config/websites.yaml config/website-scopes.yaml config/website-scopes config/*.local.yaml config/tag-manager/sites config/secrets

# Full backup (exclude vendor and cache)
tar -czf app_backup_$(date +%Y%m%d).tar.gz \
    --exclude='var/cache' \
    --exclude='var/log' \
    --exclude='vendor' \
    .
```

**3. Automated backups:**
Add to crontab:
```cron
# Daily database backup at 2 AM
0 2 * * * /path/to/backup-script.sh
```

### Scheduled tasks

One command runs every background job that is due, never from web requests:
daily maintenance (archiving, retention and purging of old task and audit
records, which is on by default), BigQuery sync and the reporting database
sync. Run it every five minutes as the user that runs Aggregate:

```cron
*/5 * * * * cd /var/www/aggregate && php bin/console app:scheduler:run --no-interaction
```

It prints nothing when nothing was due. **Administration → Scheduled tasks** and
`php bin/console app:scheduler:status` show whether it runs, each job's last and
next run, and the exact line for this server. A [systemd timer](docs/systemd/aggregate-scheduler.timer)
works too. See [Scheduled tasks](docs/SCHEDULED-TASKS.md) for Plesk, systemd and
troubleshooting. Separate lines for `app:analytics:maintain` or the sync
commands from older guides keep working, but this one line replaces them.

### Updates

Aggregate updates itself in place from signed **release ZIPs** (recommended) or a
**Git clone** of the repository, or reports on code you **deploy another way**,
such as a hosting panel's Git deployment or CI/CD. Choose the method on the
dashboard **Updates** page, with `php bin/console app:updates:method`, or with
`updates_method` in `config/aggregate.yaml`. The [update guide](docs/UPDATES.md)
explains each method, the system check, recovery, the full command reference and
troubleshooting.

```bash
php bin/console app:updates:check --refresh      # see what is available
php bin/console app:updates:apply --preflight    # system check, changes nothing
php bin/console app:updates:apply                # SQLite: snapshots the database automatically
php bin/console app:updates:apply --database-backup-confirmed   # PostgreSQL, MySQL, MariaDB, SQL Server
```

- Run commands as the user that owns the application files. The dashboard's
  **Install update** button runs the same command in the background.
- While files change, every request, including `/api/receive`, gets a 503
  maintenance page, so events sent during the update are not recorded.
- Your configuration and data are [never replaced](docs/UPDATES.md#what-an-update-keeps):
  `.env.local`, `config/aggregate*.yaml`, `config/websites.yaml`,
  `config/website-scopes.yaml` and `config/website-scopes/`,
  `config/tag-manager/sites/`, `config/*.local.yaml`, `config/secrets/` and `var/`.
- After an update, reload PHP if OPcache does not revalidate file timestamps
  (`opcache.validate_timestamps=0`), and restart workers if your process manager
  does not restart them after the stop signal. For Docker images, rebuild and
  redeploy the image instead.
- After every migration run, `php bin/console app:analytics:glossary:sync`
  publishes the declared [BI glossary](docs/BI-GLOSSARY.md#view-only-grants); the
  updater runs it for you.

Installations whose code predates `app:updates:apply`, and code a deployment tool
updates, use the manual steps below.

#### Manual update steps

Use these steps when a deployment tool updates the files, or for an installation whose code predates `app:updates:apply`.
With code deployed another way, `php bin/console app:updates:deployed` runs steps 4 to 7 for you; see
[after each deployment](docs/UPDATES.md#after-each-deployment).

Checkouts made before the repository moved need its address updated once:

```bash
git remote set-url origin https://github.com/Subschema-LLC/aggregate.git
```

If the installed version predates `app:updates:pull`, use `git pull --ff-only` for
step 3 of that first upgrade.

For upgrades that include the `Version20260724*` privacy migrations, pause `/api/receive` and stop all async workers before the steps below. The migrations permanently remove daily IP hashes, legacy non-granted event rows, and matching Doctrine-queue tracker envelopes. Inspect failed, external, and encoded/base64 queue transports separately before resuming ingestion.

```bash
# 1. Backup first!

# 2. Stop async workers (skip this command when using sync://) and pause the collection endpoint at the proxy
sudo systemctl stop aggregate-worker

# 3. Pull source code with a clean, fast-forward update
php bin/console app:updates:pull

# 4. Install dependencies
composer install --no-dev --optimize-autoloader

# 5. Run migrations
php bin/console doctrine:migrations:migrate -n
php bin/console app:analytics:glossary:sync

# 6. Clear cache
php bin/console cache:clear --env=prod

# 7. Compile assets
php bin/console asset-map:compile

# 8. Restart the worker when using async mode
sudo systemctl restart aggregate-worker
```

Reload PHP/OPcache as required by your host (for PHP-FPM, a graceful
`sudo systemctl reload php8.3-fpm`; see [Performance](#performance)), verify `/api/health`, then resume
the collection endpoint. In Docker, run PHP/Composer commands in the application
container and Git commands as the owner of the mounted checkout; use the
equivalent Compose worker restart.

---

## Troubleshooting

### Worker not processing events

```bash
# Check worker is running
sudo systemctl status aggregate-worker  # systemd
sudo supervisorctl status               # Supervisor

# Check message queue
php bin/console messenger:stats

# Manually consume messages
php bin/console messenger:consume async -vv
```

### Permission errors

```bash
chown -R www-data:www-data var/
find var -type d -exec chmod u+rwx,g-w,o-rwx {} +
```

### Database connection errors

- Verify `DATABASE_URL` in your `.env` file
- Check database is running
- Test connection: `php bin/console doctrine:query:sql "SELECT 1"`
- Note: Special characters in passwords must be URL-encoded (`%` → `%25`, `@` → `%40`)

### 500 errors

```bash
# Check the web server or PHP-FPM error log (paths depend on your server)
tail -f /var/log/nginx/error.log
tail -f /var/log/apache2/error.log

# Check web server logs
tail -f /var/log/nginx/error.log

# Clear cache
php bin/console cache:clear
```

---

## Support

- Documentation: See `README.md` for full documentation
- Configuration examples: See `docs/` directory
- Issues: GitHub issues page
