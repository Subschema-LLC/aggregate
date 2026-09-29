# Deployment Guide

This guide covers both Docker and native (non-Docker) deployment methods for Aggregate Analytics.

Complete first-time setup behind a local connection, VPN, or web-server access
restriction before exposing the application publicly. Prefer
`php bin/console app:install` to create the initial administrator. The web
installer is a first-run bootstrap flow: its CSRF protection prevents forged
browser submissions, but anyone who can reach an uninitialized installation can
open the form and create the first account. Keep `/install` and `/install/execute`
restricted until setup completes. Headless deployments should disable the
dashboard as described in the [configuration guide](docs/CONFIGURATION.md).

## Table of Contents

- [Docker Deployment](#docker-deployment)
- [Native Deployment](#native-deployment)
- [Web Server Configuration](#web-server-configuration)
- [Worker Process Setup](#worker-process-setup)
- [Production Considerations](#production-considerations)

---

## Docker Deployment

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

Optional dashboard setup: open `http://localhost/install` to create an admin user. After install, admins can add users and reset passwords from **Administration → Users**.

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
chmod -R 775 var/
chown -R www-data:www-data var/
# Keep public/ and application code owned by the deployment user.
# See Production Considerations for writable admin configuration and uploads.

# 7. Optional: open https://your-domain.com/install to create
#    a dashboard admin user and update dashboard settings.
#    After install, manage additional users/passwords in Administration -> Users.
#    CLI alternative: php bin/console app:install
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
- `fastcgi_pass`: PHP-FPM socket (usually `/var/run/php/php8.2-fpm.sock`)

### Apache

**1. Copy configuration:**
```bash
sudo cp docs/apache/aggregate-analytics.conf /etc/apache2/sites-available/analytics.conf
sudo a2ensite analytics
sudo a2enmod rewrite headers
```

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

---

## Worker Process Setup

The worker process handles background analytics event processing.
It is required only when `MESSENGER_TRANSPORT_DSN` is async (for example `doctrine://default`, AMQP, Redis).
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
- Keep `config/aggregate.yaml`, environment-specific `config/aggregate_*.yaml`, `config/websites.yaml`, and `config/tag-manager/sites/` private; commit only sanitized example files
- Use a unique, strong `APP_SECRET` in the server environment
- Review anonymous path exclusions and configure both database-backed BI minimum-cell thresholds in the admin dashboard before collection (`5` hourly; `25` daily geography defaults)
- Review every code in `config/goals.yaml`; keep values fixed and non-identifying, and set `anonymous: false` where enhanced consent is appropriate
- Leave coarse geography disabled unless its purpose, legal basis, notice, traffic volume, and local MMDB lifecycle have been reviewed
- Rotate secrets periodically

**3. Database access:**
- Use strong database passwords
- Restrict database access to localhost if possible
- On PostgreSQL/MySQL/MariaDB/SQL Server, grant routine BI users access only to the approved `bi_anonymous_events_v1`, `bi_anonymous_goals_v1`, and, when needed, `bi_anonymous_geo_events_v1` views—not the private raw `events` table
- SQLite cannot enforce view-only grants; never distribute its database file to routine BI users—export approved view results or use a server database for direct BI access
- Regular backups

**4. File permissions:**
Keep application code, `vendor/`, and `public/` owned by the deployment user and
read-only to PHP. Give PHP write access to `var/` for cache, logs, sessions, and
other runtime state. Do not recursively make `public/` writable: it contains the
executable front controller and browser assets. Preserve executable permissions
on CLI scripts rather than applying one mode to every file in the checkout.

Admin settings also require narrowly scoped write access to the active aggregate
YAML file, `config/websites.yaml`, and `config/tag-manager/sites/` when per-site
tag/CMP editing is enabled. Pre-create these paths as the deployment user, grant
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

**3. Use PostgreSQL or MySQL:**
SQLite is fine for small deployments, but use PostgreSQL or MySQL for production.

**4. Use Redis or RabbitMQ for messaging:**
```bash
# In .env
MESSENGER_TRANSPORT_DSN="amqp://user:pass@rabbitmq:5672/%2f/messages"
# or
MESSENGER_TRANSPORT_DSN="redis://localhost:6379/messages"
```

**5. Scale workers:**
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
Include all active environment files, website registrations, per-site tag/CMP
YAML under `config/tag-manager/sites/`, and branding uploads. Preserve these
operator files when replacing application code or deploying a release ZIP.

```bash
# Backup config (include deployment-specific settings — keep secure!)
tar --ignore-failed-read -czf config_backup_$(date +%Y%m%d).tar.gz .env .env.local config/aggregate*.yaml config/websites.yaml config/*.local.yaml config/tag-manager/sites

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

### Updates

Aggregate updates itself in place with one of two methods. An administrator
chooses the method on the dashboard **Updates** page, with
`php bin/console app:updates:method`, or with `updates_method` in
`config/aggregate.yaml`; the page then shows only that method. The
[update guide](docs/UPDATES.md) compares them in detail and covers setting up a Git
clone, switching methods and troubleshooting.

- **Release ZIP** (`updates_method: release`, recommended) — installs signed
  releases published on GitHub. **Download from GitHub** fetches the newest
  stable release for `updates_branch`; **Upload a release ZIP** installs the ZIP,
  `aggregate-release.json` and `aggregate-release.json.sig` you downloaded, for
  servers that cannot reach GitHub. The signature is verified with the
  installation's trusted key before anything changes, and the server needs no Git,
  Composer or Node. Works for any directory without `.git`, including files copied
  by a deployment tool; an installation without `release.json` is offered the
  latest release, and installing it records the version.
- **From the repository** (`updates_method: repository`, advanced) — for a Git
  clone of the repository in the application directory. The update fast-forwards
  the checkout (the same rules as `app:updates:pull` below), runs `composer install`
  when `composer.lock` or `importmap.php` changed, and compiles dashboard assets. It
  installs whatever is on the branch, which is not a signed release, and needs Git
  and Composer on the server.

Until a method is chosen, the dashboard does not install updates and the command
line uses the method that fits the directory (a `.git` folder means repository).
A chosen method that does not fit the directory stops updates, and the system check
says what to change. Only one mechanism should write the application files: if
another tool also deploys them, stop its automatic deployments before using the
updater, or keep using that tool with the [manual steps](#manual-update-steps).

From the command line, run as the user that owns the application files. The
commands work with the dashboard disabled:

```bash
php bin/console app:updates:method               # show the method; add release or repository to choose
php bin/console app:updates:check --refresh      # see what is available
php bin/console app:updates:apply --preflight    # system check, changes nothing
php bin/console app:updates:apply                # SQLite: snapshots the database automatically
php bin/console app:updates:apply --database-backup-confirmed   # PostgreSQL, MySQL, MariaDB, SQL Server
php bin/console app:updates:apply --package=aggregate-YYYY.MM.NN.zip \
  --manifest=aggregate-release.json --signature=aggregate-release.json.sig   # release ZIP you downloaded
```

**Settings.** `updates_method` (`release` or `repository`) can be chosen on the
Updates page, with `app:updates:method`, or in YAML. `updates_branch` (default
`master`) is the branch Git pulls and the branch releases must be published from;
it can be saved on the Updates page. `updates_repository` (default
`Subschema-LLC/aggregate`) is YAML-only; see
[configuration](docs/CONFIGURATION.md#github-update-checks).

**System check.** The Updates page lists everything an update depends on, as seen
by the web server user: the update method (and whether it fits the directory) and
repository, PHP and extensions, Git
and Composer (repository updates), `release.json`, the trusted signing key and the
upload size limit (release ZIPs), write access to the application files, whether
the page can start the command line, free disk space, database backup handling,
OPcache, maintenance mode and the last update. A problem there disables the
buttons and says what to fix; `app:updates:apply --preflight` prints the same
checks for the command-line user.

Both then run database migrations, `app:analytics:glossary:sync`, warm the cache and
signal async workers to restart. While files are replaced, every web request,
including `/api/receive`, gets a 503 maintenance response, so events sent during
the update are not recorded. `var/maintenance.json` controls this page;
`app:updates:maintenance status|on|off` shows or changes it.

**Your configuration is not overwritten.** Updates never replace or delete
`.env.local` and other local environment files, `config/aggregate.yaml` and
`config/aggregate_*.yaml`, `config/websites.yaml`, `config/tag-manager/sites/`,
`config/*.local.yaml`, the installed `config/release-signing.pub`, or anything in
`var/` (database, branding uploads, logs). If you edited a shipped default,
`config/goals.yaml`, `config/navigation.yaml` or `config/quick_search.yaml`, the
edits move to the matching [local override](docs/CONFIGURATION.md#local-overrides-for-shipped-defaults)
first. New keys in a release's `.env` are appended; existing values stay.

**Recovery.** Changed files are backed up under `var/updates/backups/` (the last
three updates are kept). If a check fails or a download does not verify, nothing
changes. If installing files fails, the previous files are restored automatically.
If a later step fails, such as a migration, the site stays in maintenance mode:
fix the cause and run `php bin/console app:updates:apply --resume`, or restore the
previous files with `php bin/console app:updates:rollback`. Rolling back files
does **not** reverse database migrations. With SQLite, add `--restore-database` to
put back the snapshot taken before the update, which discards data recorded since.
With other databases, restore your own backup. If the console itself cannot start
after an interrupted update, `php scripts/restore-update-files.php` restores the
files without loading the application. `app:updates:apply --status` shows the last
update and its log.

The dashboard buttons run the same command in the background (output in
`var/updates/last-run.log`). They are always shown; when nothing can be installed
they are disabled and the page says why (already up to date, no stable release
found, a branch mismatch, or an update that needs attention). Starting updates
from the dashboard requires the PHP web server user to be able to write the
application files, which is common on shared hosting but not in hardened
deployments where code is read-only to PHP. When the system check finds that the
web user cannot write the files, or that the command line sees a different
environment or database than the web server, run the command on the server
instead.

After an update, reload PHP (PHP-FPM, Apache with mod_php, FrankenPHP or your
host's equivalent) if OPcache does not revalidate file timestamps
(`opcache.validate_timestamps=0`), and restart workers if your process manager
does not restart them after the stop signal. For Docker images, rebuild and
redeploy the image instead.

Installations whose code predates `app:updates:apply` need one update with the
manual steps below; later updates use the command. The remainder of this section
describes version checks, source-only pulls and those manual steps.

After every migration run, synchronize the declared BI glossary in the same
environment with `php bin/console app:analytics:glossary:sync`. The command needs
table read/write privileges but no view-creation privileges. Recheck routine BI
view grants using the [glossary guide](docs/BI-GLOSSARY.md#view-only-grants).


The official repository is [Subschema-LLC/aggregate](https://github.com/Subschema-LLC/aggregate).
For existing checkouts, update the remote once:

```bash
git remote set-url origin https://github.com/Subschema-LLC/aggregate.git
```

Open **Updates** in the admin dashboard or check from the CLI, including headless installations:

```bash
php bin/console app:updates:check
php bin/console app:updates:check --refresh
php bin/console app:updates:check --refresh --json
```

Set `updates_branch` in the active `config/aggregate.yaml` environment to choose
the upstream branch; it defaults to **`master`**. Git checks compare the installed
commit with that branch and show the installed branch separately. Official ZIP
installations use their embedded `release.json` to check stable packaged releases
for that branch without Git. Checks never switch branches or apply packages. Successful GitHub results
are cached for one hour; failed requests are retried after one minute. **Check now**
and `--refresh` bypass the cache. Checks run
when requested through this page or command, and contact GitHub with branch and
commit metadata. They do not send event data. The page shows updates, local commits
ahead of upstream, diverged histories, and checks that could not be completed.
CLI checks exit `0` for a known compatible comparison (including updates or divergence)
and `1` when status is unavailable, invalid, or incompatible; use the JSON `state`
and `installation_type` for automation. Packaged checks label signatures as
unverified until the downloaded package is independently verified. See the
[release guide](docs/RELEASES.md) for signed ZIPs, verification, and manual deployment.

For source installations, Git 2.30 or newer must be installed and the checkout's `.git` metadata readable. If the deployment
user owns the checkout and PHP runs as a different user, Git may require that exact
checkout path in the PHP user's `safe.directory` configuration. Keep source files
read-only and scope Git trust to this checkout; do not use a wildcard. Source ZIPs
without official `release.json`, partial clones, unpublished branches/commits,
and missing GitHub access may prevent comparison. Detached Git checkouts can be
checked but cannot be pulled. Public checks need no token. For a private repository or higher API rate limits, configure
`AGGREGATE_GITHUB_TOKEN` in the server environment or untracked `.env.local` with
repository read access. This token is used only for API checks. Pulling uses the
deployment user's existing Git HTTPS credentials; configure a credential helper
for private repository access. Credentials are never entered in the dashboard.

Run `app:updates:pull` as the deployment user with write access to the checkout.
It fetches the configured branch directly from the official repository and applies
only a fast-forward. The local branch must match `updates_branch`; the command
does not switch or cross-merge branches. Local modifications, untracked files, detached HEADs,
in-progress Git operations, and divergent or ahead histories stop the update.
Ignored local configuration is preserved; a conflicting incoming tracked file
also stops the update. Resolve custom code changes through your normal Git
workflow. The dashboard only checks status and does not need permission to write
application code.

The pull command updates **source code only**; `app:updates:apply` also runs the
steps below. Edits to `config/goals.yaml`, `config/navigation.yaml` and
`config/quick_search.yaml` move to their `.local.yaml` overrides before a pull
instead of blocking it; other local modifications still stop the update. Back up
the database and local configuration, review the changes, and complete all steps below before resuming
collection. If the installed version predates these commands, use `git pull --ff-only`
after updating `origin` and verifying its tracking branch for that first upgrade.
For image-based deployments, rebuild and redeploy the image using your usual pipeline.

#### Manual update steps

Use these steps when a deployment tool updates the files, or for an installation whose code predates `app:updates:apply`.

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

Reload PHP/OPcache as required by your host, verify `/api/health`, then resume
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
chmod -R 775 var/
chown -R www-data:www-data var/ public/
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
