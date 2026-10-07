# Plesk Deployment Guide

This guide applies the general [release ZIP steps](DEPLOYMENT.md#release-zip-on-a-web-host)
to Plesk, with its menu names and fixes. There are two ways to install Aggregate
Analytics with Plesk:

- **[From a release ZIP](#install-from-a-release-zip-no-ssh) (recommended).** Everything
  happens in Plesk and the browser: no SSH, Git, Composer or Node. The ZIP already
  contains the dependencies and compiled assets, and the dashboard installs later
  updates.
- **[From Git with SSH](#install-from-git-with-ssh-advanced).** For people who
  want to deploy from the repository and run commands on the server.

## Install from a release ZIP (no SSH)

You need Plesk Obsidian with PHP 8.2 or newer available, and about ten minutes.

### 1. Create the site

1. In **Websites & Domains**, click **Add Subdomain** (or **Add Domain**), for
   example `analytics.example.com`. Keep the suggested document root for now.
2. Open the new site's **PHP Settings** and choose **PHP 8.2** or newer. Plesk's
   PHP builds normally include every extension Aggregate needs; the setup page
   tells you if one is missing.
3. Optional but recommended: **SSL/TLS Certificates**, then **Install** a free
   Let's Encrypt certificate.

### 2. Create a database

In **Databases**, click **Add Database**. Enter a database name, then a new
user name and password, and keep them for step 5. By default Plesk gives the
new user full access to its database, which is what Aggregate needs; the setup
page checks it. The server is `localhost`.

Skip this step to use SQLite instead: it needs no database server and suits
trying Aggregate out or small sites, but BI tools read the whole file, so you
cannot limit them to the reporting views. MySQL 8.0+ or MariaDB 10.6+ is the better
choice for production.

### 3. Upload and extract the release

1. Download `aggregate-VERSION.zip` from the **Assets** list of the
   [latest release](https://github.com/Subschema-LLC/aggregate/releases/latest).
   Do not use the **Source code** archives: they lack the dependencies.
2. In **Files**, open the site's folder (for example `analytics.example.com`),
   click **Upload**, and choose the ZIP.
3. Click the ZIP's menu (**⋯** or the arrow next to it) and choose **Extract
   Files**. The folder now contains `public`, `vendor`, `README.md` and the other
   application files. You can delete the ZIP afterwards.

### 4. Point the document root at `public`

Open the site's **Hosting Settings** and change **Document root** to the
`public` folder inside it, for example `analytics.example.com/public`. Save.

Only `public` may be reachable from the web: the rest of the folder holds your
configuration and data. The setup page refuses to continue until this is right.

### 5. Open the site and finish setup

Open `https://analytics.example.com`. The setup page:

1. **Checks the server** and explains how to fix anything it finds, such as a PHP
   version or a missing extension. It also checks that the web server passes
   the application's routes to PHP (see [troubleshooting](#zip-install-troubleshooting)).
2. **Asks for the setup code.** Back in **Files**, open `SETUP-CODE.txt` in the
   site's folder, next to `README.md` (refresh the list if you do not see it),
   and copy the code. This shows that you manage the server. Bots watch for
   new HTTPS certificates and open fresh installers within minutes, and without
   the code one of them could finish your setup first.
3. **Connects the database.** Choose **MySQL or MariaDB** and enter the details
   from step 2, or choose **SQLite**. The page tests the connection, detects the
   server version, and writes `.env.local` with the connection and a newly
   generated application secret.

Then create the administrator account. **Public address** is filled in with the
address you opened; tracking snippets use it. Click **Install Now**, log in,
and use **Setup** in the dashboard to register your first website and copy
its tracking code.

`SETUP-CODE.txt` is deleted when the administrator is created. No background
worker or scheduled task is needed: events are recorded as they arrive.

### Keep it running

- **Updates:** the dashboard **Updates** page installs new release ZIPs, keeping
  your configuration and data. See the [update guide](docs/UPDATES.md).
- **Backups:** include the site's files (at least `.env.local`, `config/` and
  `var/`) and the database, for example with Plesk's **Backup Manager**.
- **BigQuery sync (optional):** add a Plesk **Scheduled Task** that runs
  `php bin/console app:bigquery:sync --no-interaction` in the site's folder every
  five minutes. See [Sync to BigQuery](docs/BIGQUERY.md#schedule-the-sync).
- **Do not also deploy with Plesk Git** into the same folder: its deployments
  would overwrite installed updates.

### ZIP install troubleshooting

| What you see | What to do |
| --- | --- |
| Plesk's default page, **403 Forbidden** or a file list | The document root is not the `public` folder. Repeat [step 4](#4-point-the-document-root-at-public). |
| "This site needs PHP 8.2 or newer" | Choose PHP 8.2+ in **PHP Settings** and reload. |
| "This folder does not contain a prepared release" | You extracted a **Source code** archive. Delete the files and extract `aggregate-VERSION.zip` from the release's **Assets**. |
| "Addresses other than the home page do not reach the application", or **Not Found** at `/install` | Requests reach nginx only. In **Apache & nginx Settings**, turn on **Proxy mode** so Apache and `public/.htaccess` handle them. To stay with nginx only, see [nginx without Apache](#nginx-without-apache). |
| "The web server answers some .js and .css addresses itself" | nginx serves `/aggregate.js` from disk, so tracker settings saved in the dashboard would not reach browsers. In **Apache & nginx Settings**, remove `js` and `css` from **Serve static files directly by nginx** (or clear that option), then reload the setup page. |
| "PHP cannot write to …" | The files do not belong to the site's system user, for example after uploading over FTP as another account. Upload and extract them with the site's **Files**, or ask your provider to fix the owner. |
| The database check fails | Open **Technical details** under the message. Check the name, user and password from **Databases**; the server is usually `localhost`. MariaDB older than 10.6 is not supported: choose SQLite or ask your provider about an upgrade. |
| You want to start over | Delete `.env.local` (and `var/data.db` if you chose SQLite) in **Files**, then reload the site. |

#### nginx without Apache

With **Proxy mode** off, Plesk's nginx does not read `public/.htaccess`. Add
these lines to **Apache & nginx Settings**, **Additional nginx directives**, so
that application routes and `/aggregate.js` reach PHP:

```nginx
location = /aggregate.js {
    rewrite ^ /index.php last;
}
if (!-e $request_filename) {
    rewrite ^ /index.php last;
}
```

These have not been tested on every Plesk version; reload the setup page
afterwards to confirm its routing check passes.

## Install from Git with SSH (advanced)

### Prerequisites

- Plesk Obsidian 18.0.35+ or later
- PHP 8.2 or higher (PHP 8.3 recommended)
- MySQL 8.0+ or MariaDB 10.6+
- SSH access to your server

### Quick Reference — Plesk-Specific Paths

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

Choose one of three update methods on the dashboard **Updates** page (see the
[update guide](docs/UPDATES.md#choose-an-update-method)): release ZIPs
(recommended), the repository for a Git clone (advanced), or **I deploy the code
another way** to keep deploying with Plesk's Git. From the domain's deployment
account, the same commands run with Plesk's PHP:

```bash
/opt/plesk/php/8.3/bin/php bin/console app:updates:method
/opt/plesk/php/8.3/bin/php bin/console app:updates:check --refresh
/opt/plesk/php/8.3/bin/php bin/console app:updates:apply --preflight
```

If you use the in-app updater, turn off automatic deployment in Plesk's **Git**
settings (or stop using its **Deploy** action), because each deploy copies the
repository over the installed files.

### Plesk Git deployment actions

To keep deploying with Plesk's **Git**, choose **I deploy the code another way**
(`app:updates:method deployment`). The Updates page then shows the deployed
commit and how far behind it is, and Plesk installs every update. Add the
post-deployment commands to the repository's **Additional deployment actions**,
so each deployment installs Composer dependencies, runs database migrations and
rebuilds the dashboard assets and cache. The Updates page shows them with this
domain's paths, as does
`/opt/plesk/php/8.3/bin/php bin/console app:updates:deployed --show-action`:

```bash
rm -rf /var/www/vhosts/your-domain.com/analytics/var/cache/prod
/opt/plesk/php/8.3/bin/php /opt/psa/var/modules/composer/composer.phar install --working-dir=/var/www/vhosts/your-domain.com/analytics --no-interaction --optimize-autoloader --no-dev
/opt/plesk/php/8.3/bin/php /var/www/vhosts/your-domain.com/analytics/bin/console app:updates:deployed --git-dir=/var/www/vhosts/your-domain.com/git/aggregate.git --database-backup-confirmed
```

Run them as the domain's system user, with SSH access set to `/bin/bash`: when SSH
access is forbidden, Plesk runs deployment actions in a chroot without PHP or
Composer. Pass `--database-backup-confirmed` only when the database is backed up
on a schedule, because migrations cannot be reversed automatically. See
[deploy the code another way](docs/UPDATES.md#deploy-the-code-another-way) for
what each command does and for the files to keep out of the repository. After a
successful deploy, hard refresh the browser. If the layout or saved colors remain
wrong, check the [stylesheet troubleshooting steps](#navigation-layout-or-theme-colors-are-missing).

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
- [ ] Assets compiled (`asset-map:compile`; release ZIPs include them)
- [ ] Background worker running (async mode only)
- [ ] Setup page and `/install` completed
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
