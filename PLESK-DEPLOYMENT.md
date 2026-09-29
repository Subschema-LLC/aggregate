# Plesk Deployment Guide

This guide walks you through deploying Aggregate Analytics on a Plesk hosting environment.

## Prerequisites

- Plesk Obsidian 18.0.35+ or later
- PHP 8.2 or higher (PHP 8.3 recommended)
- MySQL 8.0+ or MariaDB 10.6+
- SSH access to your server

## Quick Reference — Plesk-Specific Paths

On Plesk, PHP and Composer are not in the default system `PATH` for root SSH sessions. Use the full paths:

```bash
# PHP (adjust version as needed)
/opt/plesk/php/8.3/bin/php

# Composer
/opt/psa/var/modules/composer/composer.phar

# Example: install dependencies
/opt/plesk/php/8.3/bin/php /opt/psa/var/modules/composer/composer.phar install --no-dev --optimize-autoloader
```

---

## Step-by-Step Setup

### 1. Domain and PHP Configuration

#### Set Document Root
1. Log into Plesk
2. Go to **Domains** > **your-domain.com** > **Apache & nginx Settings**
3. Set **Document root** to: `/var/www/vhosts/your-domain.com/analytics/public`
4. Click **OK**

#### Configure PHP Version
1. Go to **Domains** > **your-domain.com** > **PHP Settings**
2. Select **PHP 8.2** or higher
3. Ensure these extensions are enabled:
   - `ctype`, `iconv`, `pdo_mysql`, `mbstring`, `xml`, `curl`, `intl`

### 2. Upload Application Files

Clone or upload the application to your Plesk domain directory:

```bash
cd /var/www/vhosts/your-domain.com
git clone https://github.com/Subschema-LLC/aggregate.git analytics
cd analytics
```

### 3. Install Composer Dependencies

Before a Doctrine DBAL 4 upgrade, update short MySQL/MariaDB version hints in
the active `DATABASE_URL` as described in the
[database upgrade guide](docs/DATABASE.md#upgrade-to-doctrine-dbal-4).

```bash
cd /var/www/vhosts/your-domain.com/analytics

/opt/plesk/php/8.3/bin/php \
  /opt/psa/var/modules/composer/composer.phar \
  install --no-dev --optimize-autoloader
```

### 4. Database Setup

#### Create via Plesk Panel
1. Go to **Databases** > **Add Database**
2. Database name: e.g. `analytics_prod`
3. Create a database user with full privileges
4. Note the host, database name, username, and password

### 5. Application Configuration

Create `.env` in the project root for infrastructure settings (or use `.env.local` only):
```dotenv
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=generate-with-openssl-rand-hex-32
DATABASE_URL="mysql://db_user:db_pass@localhost:3306/db_name?serverVersion=8.0.0"
MESSENGER_TRANSPORT_DSN=sync://   # default quick mode (no worker required)
```

Important:
- Do not rely on `.env.prod` as your only file name; Symfony does not auto-load it by itself.
- Keep at least one of `.env` or `.env.local` present unless you provide real OS-level env vars.

For async scale-up mode:
```dotenv
MESSENGER_TRANSPORT_DSN=doctrine://default
```

Create `config/aggregate.yaml` for app-level analytics settings:
```bash
cp config/aggregate.yaml.example config/aggregate.yaml
```
Example `aggregate.yaml`:
```yaml
environments:
  prod:
    rate_limit_per_minute: 100
    js_namespace: "Aggregate"
    dashboard_enabled: true
    app_host: "https://analytics.your-domain.com"
    anonymous_tracking_enabled: true
    anonymous_excluded_paths:
      - /account/*
      - /checkout/*
    anonymous_geo_enabled: false
    anonymous_geo_level: "macro_region"
    anonymous_geo_database_path: ""
```

The two BI disclosure thresholds are configured in the admin dashboard and stored directly in `analytics_privacy_settings`, not in this YAML file.

Optional coarse geography requires a locally managed GeoLite2-Country-compatible MMDB. Keep it on the local filesystem outside the document root, for example `/var/www/vhosts/your-domain.com/private/GeoLite2-Country.mmdb`, and make it read-only but readable by the domain's PHP process. Direct UNC/network-share and Windows device paths are rejected. Aggregate never downloads the file or calls a GeoIP web service. After installing it, set `anonymous_geo_enabled: true` and its absolute path above. Missing/unreadable/invalid databases leave `geo_area` empty without stopping ingestion.

If the Plesk proxy chain does not already preserve the client address as `REMOTE_ADDR`, ask the server administrator for the exact proxy IPs/CIDRs and set them as a comma-separated `TRUSTED_PROXIES` value in `.env`. Confirm that each trusted proxy strips or overwrites client-supplied `X-Forwarded-*` headers. Never trust `0.0.0.0/0`, `::/0`, the Symfony `REMOTE_ADDR` shortcut, or an address range that untrusted clients can reach directly. An unset or incorrect trust boundary yields proxy/empty geography; an overly broad or unsanitized one permits forwarded-IP spoofing.

For API-only mode, add `DASHBOARD_ENABLED=0` in `.env` and run:
```bash
/opt/plesk/php/8.3/bin/php bin/console cache:clear
```

### 6. Initialize Database Schema

Preferred (Symfony migrations):
```bash
cd /var/www/vhosts/your-domain.com/analytics

/opt/plesk/php/8.3/bin/php bin/console doctrine:migrations:migrate --no-interaction
/opt/plesk/php/8.3/bin/php bin/console app:analytics:glossary:sync
```

Alternative for Plesk SQL import workflow (fresh install/reset on MySQL/MariaDB):
- Import [docs/install_fresh.sql](docs/install_fresh.sql) in phpMyAdmin.
- Then continue with step 7.

### 7. Compile Frontend Assets

The dashboard uses Symfony AssetMapper. Run these commands from the project
root using the PHP version selected for the domain:

```bash
/opt/plesk/php/8.3/bin/php bin/console importmap:install --env=prod --no-debug --no-interaction
/opt/plesk/php/8.3/bin/php bin/console asset-map:compile --env=prod --no-debug
```

Repeat asset compilation after deploying dashboard CSS or JavaScript changes.
Clearing Symfony's cache does not rebuild `public/assets/`. Node/npm is only
needed for the separate [optional browser-script minifier](docs/JS-BUILD.md);
it is not required to compile dashboard assets. Official release ZIPs already
include compiled assets.

### 8. Set File Permissions

```bash
cd /var/www/vhosts/your-domain.com/analytics

# Replace aggregate_admin with your actual Plesk domain user
chown -R aggregate_admin:psacln var/ public/
chmod -R 775 var/
```

### 9. Optional: Complete Dashboard Setup via Web Installer

Open your browser and go to:

```
https://analytics.your-domain.com/install
```

The web installer will:
- Let you set admin credentials
- Let you configure the JS namespace
- Configure privacy measurement defaults, which administrators can review in the dashboard
- Dashboard setup is optional for API-only deployments

CLI alternative for admin creation:
```bash
/opt/plesk/php/8.3/bin/php bin/console app:install
```

---

## Background Worker Setup

The analytics system requires a background worker only when using async queue mode.
If `MESSENGER_TRANSPORT_DSN=sync://`, skip this section.

### Option A: Plesk Scheduled Tasks (Simplest)

1. Go to **Tools & Settings** > **Scheduled Tasks** (or per-domain: **Domains** > **your-domain.com** > **Scheduled Tasks**)
2. Add new task:
   - **Command**: `cd /var/www/vhosts/your-domain.com/analytics && /opt/plesk/php/8.3/bin/php bin/console messenger:consume async --time-limit=3600`
   - **Run**: Every hour
   - **Run as**: Your domain user (e.g. `aggregate_admin`)

This restarts the worker every hour. Suitable for low-to-medium traffic.

### Option B: systemd (Recommended for Production)

Create a service file:

```bash
sudo nano /etc/systemd/system/analytics-worker.service
```

Content (adjust paths and user):

```ini
[Unit]
Description=Aggregate Analytics Worker
After=network.target

[Service]
Type=simple
User=aggregate_admin
WorkingDirectory=/var/www/vhosts/your-domain.com/analytics
ExecStart=/opt/plesk/php/8.3/bin/php bin/console messenger:consume async --time-limit=3600 --memory-limit=128M
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

Enable and start:

```bash
sudo systemctl daemon-reload
sudo systemctl enable analytics-worker
sudo systemctl start analytics-worker
sudo systemctl status analytics-worker
```

### Option C: Supervisor

Install and configure Supervisor:

```bash
sudo apt-get install supervisor

sudo nano /etc/supervisor/conf.d/analytics-worker.conf
```

Content:

```ini
[program:analytics-worker]
command=/opt/plesk/php/8.3/bin/php /var/www/vhosts/your-domain.com/analytics/bin/console messenger:consume async --time-limit=3600
user=aggregate_admin
numprocs=1
autostart=true
autorestart=true
stderr_logfile=/var/www/vhosts/your-domain.com/analytics/var/log/worker.err.log
stdout_logfile=/var/www/vhosts/your-domain.com/analytics/var/log/worker.out.log
```

Reload:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start analytics-worker
```

---

## SSL/HTTPS Setup

1. Go to **Domains** > **your-domain.com** > **SSL/TLS Certificates**
2. Click **Install** next to Let's Encrypt and follow the wizard

---

## Updating the Application

The application updates itself; see the [update guide](docs/UPDATES.md). An
administrator chooses the method on the dashboard **Updates** page: release ZIPs
(recommended) or the repository for a Git clone (advanced). From the domain's
deployment account, the same commands run with Plesk's PHP:

```bash
/opt/plesk/php/8.3/bin/php bin/console app:updates:method
/opt/plesk/php/8.3/bin/php bin/console app:updates:check --refresh
/opt/plesk/php/8.3/bin/php bin/console app:updates:apply --preflight
```

If you use the in-app updater, turn off automatic deployment in Plesk's **Git**
settings (or stop using its **Deploy** action), because each deploy copies the
repository over the installed files. A directory that Plesk fills from Git has no
`.git` folder, so it uses release ZIPs unless you
[set it up as a Git clone](docs/UPDATES.md#set-up-a-git-clone). To keep deploying
with Plesk instead, use the actions below and the
[manual update steps](DEPLOYMENT.md#manual-update-steps).

### Plesk Git deployment actions

Plesk's **Deploy** action copies the Git source into the deployment directory;
it does not automatically compile Symfony's dashboard assets. For source
deployments, add the following to the repository's **Additional deployment
actions**, adjusting the directory and PHP version to match the domain. Run
them as the domain's deployment user, who needs write access to `public/assets/`
and the application cache:

```bash
set -eu
cd /var/www/vhosts/your-domain.com/analytics
export APP_ENV=prod APP_DEBUG=0

/opt/plesk/php/8.3/bin/php bin/console cache:clear --env=prod --no-debug
/opt/plesk/php/8.3/bin/php bin/console importmap:install --env=prod --no-debug --no-interaction
/opt/plesk/php/8.3/bin/php bin/console asset-map:compile --env=prod --no-debug
```

This block rebuilds the cache and dashboard assets. Install the locked production
Composer dependencies before it, as shown in the full upgrade sequence below;
database migrations and worker coordination are also part of that sequence.
Running `npm run build:js` does not replace AssetMapper compilation. After a
successful deploy, hard refresh the browser. If the layout or saved colors
remain wrong, check the [stylesheet troubleshooting steps](#navigation-layout-or-theme-colors-are-missing).

### Full source upgrade

The update command runs all of the steps below and keeps your configuration. Point
it at Plesk's Composer, then run it with the domain's PHP:

```bash
cd /var/www/vhosts/your-domain.com/analytics
export APP_ENV=prod APP_DEBUG=0 AGGREGATE_COMPOSER=/opt/psa/var/modules/composer/composer.phar
/opt/plesk/php/8.3/bin/php bin/console app:updates:apply --database-backup-confirmed
```

Back up the database first. See the [deployment guide](DEPLOYMENT.md#updates) for
what is preserved, `--resume` and `app:updates:rollback`. Installations whose code
predates `app:updates:apply` use the manual steps below once.

For upgrades that include the `Version20260724*` privacy migrations, pause `/api/receive` and stop all async workers first. The migrations permanently remove daily IP hashes, legacy non-granted event rows, and matching Doctrine-queue tracker envelopes. Inspect failed, external, and encoded/base64 queue transports separately before resuming ingestion.

```bash
cd /var/www/vhosts/your-domain.com/analytics
export APP_ENV=prod APP_DEBUG=0

# Stop the async worker (skip this command when using sync://) and pause the collection endpoint at the proxy
sudo systemctl stop analytics-worker

# Pull source code with a clean, fast-forward update
/opt/plesk/php/8.3/bin/php bin/console app:updates:pull

# Install dependencies (no-dev for production)
/opt/plesk/php/8.3/bin/php \
  /opt/psa/var/modules/composer/composer.phar \
  install --no-dev --optimize-autoloader

# Run migrations
/opt/plesk/php/8.3/bin/php bin/console doctrine:migrations:migrate --no-interaction
/opt/plesk/php/8.3/bin/php bin/console app:analytics:glossary:sync

# Clear cache
/opt/plesk/php/8.3/bin/php bin/console cache:clear --env=prod --no-debug

# Install browser dependencies and compile dashboard assets
/opt/plesk/php/8.3/bin/php bin/console importmap:install --env=prod --no-debug --no-interaction
/opt/plesk/php/8.3/bin/php bin/console asset-map:compile --env=prod --no-debug

# Fix permissions
chown -R aggregate_admin:psacln var/
chmod -R 775 var/

# Restart the worker when using async mode, then resume the collection endpoint
sudo systemctl restart analytics-worker
```

---

## Troubleshooting

### Navigation layout or theme colors are missing

Rebuild dashboard assets using the [deployment actions](#plesk-git-deployment-actions),
then hard refresh. In the browser's Network panel, inspect the actual stylesheet
URLs from the page: `/assets/styles/app-*.css`, its imported stylesheets, and
`/branding/theme.css`. They should return successful responses with a `text/css`
content type; a login page or an HTML error response cannot apply styles. The
fingerprinted files must exist beneath the domain's `public/assets/` directory.
If old HTML is cached by a proxy/CDN, refresh that cache too.

`/branding/theme.css` is a Symfony route that supplies the current validated
branding settings, not a physical CSS file. Plesk's **Serve static files directly
by nginx** option can intercept `.css` requests and return 404 before Symfony
runs. Route this URL through the site's existing front-controller handler. For
nginx proxy mode with Apache, removing `css` from the directly served extension
list lets Apache's normal fallback handle it. For nginx-only hosting, configure
a front-controller fallback for this route using the domain's existing PHP
handler. Keep real compiled files served from `public/assets/` and verify the
theme response after changing the server configuration. Saving branding colors
or fonts does not require rebuilding assets.

### Blank page or 500 error

1. Check PHP error log: `/var/www/vhosts/your-domain.com/logs/error_log`
2. Check Nginx error log (if enabled): `/var/www/vhosts/your-domain.com/logs/proxy_error_log`
3. App logs in production are written to `php://stderr`, so they appear in web server logs above (not `var/log/prod.log` by default)
4. Verify `.env` exists and has correct values (especially `APP_ENV=prod`)
5. Verify permissions: `chown -R aggregate_admin:psacln var/ && chmod -R 775 var/`

### Database connection errors

1. Verify `DATABASE_URL` in `.env`
2. Test the connection:
   ```bash
   /opt/plesk/php/8.3/bin/php bin/console dbal:run-sql "SELECT 1"
   ```
3. Check special characters in password are URL-encoded

### Events not being processed

1. Check worker is running:
   ```bash
   sudo systemctl status analytics-worker
   # or
   sudo supervisorctl status analytics-worker
   ```
2. Check logs:
   ```bash
   tail -f /var/www/vhosts/your-domain.com/logs/error_log
   tail -f /var/www/vhosts/your-domain.com/logs/proxy_error_log
   ```
3. Manually process queue to see errors:
   ```bash
   /opt/plesk/php/8.3/bin/php bin/console messenger:consume async -vv
   ```

### Cache permission errors after `composer install`

If Composer runs as a different user (e.g. root during SSH), reset permissions:

```bash
chown -R aggregate_admin:psacln var/
chmod -R 775 var/
```

### CORS / 403 Forbidden

Check the website's [domain rules](docs/CONFIGURATION.md#website-domains) on the
Websites page or in `config/websites.yaml`. Exact hostname entries allow only that
host; use `'*.example.com'` for its subdomains and list `example.com` separately
for the root. Only existing registrations without `domain_policy` automatically
allow their primary domain and all subdomains. Invalid explicit rules reject events.

---

## Security Checklist

- [ ] HTTPS (SSL certificate installed)
- [ ] Strong `APP_SECRET` in `.env` (`openssl rand -hex 32`)
- [ ] Sensitive routes listed in `anonymous_excluded_paths`
- [ ] Every `config/goals.yaml` code is fixed and non-identifying; enhanced-only goals use `anonymous: false`
- [ ] Database-backed `anonymous_min_cell_count` reviewed in the admin dashboard for your traffic volume (default `5`, range `2`–`1000`)
- [ ] If coarse geography is enabled: local MMDB is current/read-only, macro-region is preferred, and the processing purpose/legal basis/notice are documented
- [ ] Database-backed `anonymous_geo_min_cell_count` reviewed in the admin dashboard for geography traffic volume (default `25`, range `10`–`1000`; counts events, not people)
- [ ] `APP_DEBUG=0` in `.env`
- [ ] `.env` is not publicly accessible (it's outside `public/`, so this is automatic)
- [ ] Database user has minimal required privileges
- [ ] Routine BI users can query only approved `bi_anonymous_events_v1` / `bi_anonymous_goals_v1` / `bi_anonymous_geo_events_v1` views, not raw `events`
- [ ] File permissions correct (`775` on `var/`, not `777`)
- [ ] Worker running as non-root user
- [ ] Regular database backups configured

## Production Checklist

Before going live:

- [ ] SSL certificate installed and working
- [ ] Document root set to `public/` in Plesk
- [ ] PHP 8.2+ with required extensions enabled
- [ ] `.env` created with correct credentials
- [ ] Database created and migrations run
- [ ] Assets compiled (`asset-map:compile`)
- [ ] Background worker running
- [ ] Web installer completed at `/install`
- [ ] Test event successfully tracked (`curl /api/health`)
- [ ] File permissions correct
- [ ] Backups configured

### BI glossary after deployment

Run `app:analytics:glossary:sync` with the same PHP binary and environment after
every migration run, as shown above. The initial migration creates empty views;
sync publishes declared configuration without reading events. After editing goal
labels, clear the production cache and run sync again. Review the
[view-only grant examples](docs/BI-GLOSSARY.md#view-only-grants); the backing
`analytics_glossary` table is not part of the routine BI grant.
