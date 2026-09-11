# Headless Privacy Analytics

An open-source, self-hosted analytics system with privacy-minimized, hour-bucketed anonymous-mode events, consent-based enhanced analytics, and a queue-backed ingestion API.

## Key Features

- **Privacy-minimized events by default**: individual page views and safe named interactions with UTC hour buckets, sanitized paths, coarse dimensions, and no visitor/session identifiers
- **YAML-managed conversion goals**: fixed, allowlisted goal codes can be retained in anonymous mode without accepting arbitrary values
- **Consent-based enhanced analytics**: visitor/session IDs, custom properties, and exact dimensions only after consent is granted
- **Short-lived session cookies**: Only set with enhanced consent, 30-minute expiry, SameSite=Lax, Secure on HTTPS
- **Internal traffic markers**: Optional team cookie or local storage flag, configurable in YAML or the admin UI and saved in existing event JSON
- **Privacy controls**: administrative kill switch, sensitive-path exclusions, and low-volume BI cell suppression
- **Configurable data lifecycle**: scheduled aggregation of older events plus independently disabled-by-default raw and archive retention
- **Deployment guidance** for consent, disclosure, retention, and data-subject workflows (see [docs/PRIVACY-COMPLIANCE.md](docs/PRIVACY-COMPLIANCE.md))
- **Fast ingestion** via Symfony Messenger (`sync://` quick mode or async queue mode)
- **Domain whitelisting** and per-IP rate limiting
- **Multi-database support**: PostgreSQL, MySQL, MariaDB, MS SQL Server, and SQLite
- **Self-hosted**: Full control over your data
- **Easy setup**: One-command installation with Docker or native

## Requirements

**Choose your deployment method:**

### Option A: Docker (Recommended for Development)
- Docker and Docker Compose
- (Optional) Make for simplified commands

### Option B: Native Installation (Production/Shared Hosting)
- PHP 8.2+ with extensions: `ctype`, `iconv`, `pdo`, `mbstring`, `xml`, `curl`, `intl`
- **Database** (choose one):
  - PostgreSQL 13+ (recommended for production)
  - MySQL 8.0+
  - MariaDB 10.6+ or 11.x
  - Microsoft SQL Server 2017+ (requires `pdo_sqlsrv`)
  - SQLite 3.25+ (development/small sites)
- Composer
- Supervisor or systemd (only if using async queue mode)
- Web server (Nginx, Apache, or FrankenPHP)

## Quick Start

### Option A: Docker Setup (Recommended for Development)

**Using Make (Recommended):**

```bash
# Clone the repository
git clone <your-repo-url>
cd aggregate-sy

# Copy and edit app configuration (privacy controls, rate limit, JS namespace)
cp config/aggregate.yaml.example config/aggregate.yaml
nano config/aggregate.yaml

# Start services (MySQL default profile)
make start-mysql

# Run migrations
make migrate-mysql

# Check system status
make status
```

If you want the dashboard UI, open `http://localhost/install` after migrations.
For API-only mode, set `dashboard_enabled: false` in `config/aggregate.yaml` and `DASHBOARD_ENABLED=0` in `.env`, then run `php bin/console cache:clear`.

**Manual Docker Setup:**

```bash
# 1. Clone and configure
git clone <your-repo-url>
cd aggregate-sy

# 2. Copy app configuration
cp config/aggregate.yaml.example config/aggregate.yaml
nano config/aggregate.yaml

# 3. Start services with a DB profile
docker compose --profile mysql up -d

# 4. Run migrations
docker compose exec php php bin/console doctrine:migrations:migrate -n

# 5. Optional: open http://localhost/install to create dashboard admin
```

For PostgreSQL or MariaDB profiles, set `DOCKER_DATABASE_URL` before starting containers. Example:

```bash
export DOCKER_DATABASE_URL="postgresql://app:!ChangeMe!@database:5432/aggregate_analytics?serverVersion=16"
docker compose --profile postgres up -d
```

### Option B: Native Installation (Production/Shared Hosting)

**Manual Native Setup:**

```bash
# 1. Clone and install dependencies
git clone <your-repo-url>
cd aggregate-sy
composer install --no-dev --optimize-autoloader

# 2. Create .env in the project root (not committed to git)
cat > .env <<'EOF'
APP_ENV=prod
APP_SECRET=$(openssl rand -hex 32)
DATABASE_URL="mysql://user:pass@localhost:3306/dbname?serverVersion=8.0"
MESSENGER_TRANSPORT_DSN=sync://
MAILER_DSN=null://null
EOF

# Async scale-up mode (worker required):
# MESSENGER_TRANSPORT_DSN=doctrine://default

# 3. Copy app configuration
cp config/aggregate.yaml.example config/aggregate.yaml

# 4. Run migrations
php bin/console doctrine:migrations:migrate -n

# 5. Compile assets
php bin/console asset-map:compile

# 6. Set permissions
chmod -R 775 var/
chown -R www-data:www-data var/ public/  # Adjust user as needed

# 7. Optional (dashboard only): open https://your-domain.com/install
#    to create the admin user and save dashboard settings.
#    CLI alternative: php bin/console app:install

# 8. If using async queue mode, set up the worker (see Worker Setup below)
```

### Privacy migration warning for existing installations

Before applying the `Version20260724*` privacy migrations, back up the database, pause `/api/receive`, and stop every async worker. These migrations intentionally and irreversibly remove `daily_ip_hash`, delete legacy enhanced rows that do not contain `consent_state = 'granted'`, and delete Doctrine-transport `TrackEventMessage` envelopes so older payloads cannot be processed by the new code.

The queue cleanup can only match envelopes visible as plain text in the Doctrine transport. Inspect and purge tracker messages from failed, externally hosted, or encoded/base64 transports before resuming ingestion. Legacy rows already stored as `granted` are retained, but older releases could infer that value solely from a session ID; audit or purge those rows if you cannot establish their consent provenance. Resume ingestion and workers only after the migrations succeed.

**Worker Setup (only required for async mode):**

Choose one method:

**A. Using systemd (Recommended for Linux servers):**

```bash
# Create service file
sudo nano /etc/systemd/system/aggregate-worker.service

# Add content from docs/systemd/aggregate-worker.service
# Then enable and start:
sudo systemctl enable aggregate-worker
sudo systemctl start aggregate-worker
sudo systemctl status aggregate-worker
```

**B. Using Supervisor:**

```bash
# Install supervisor
sudo apt-get install supervisor  # Debian/Ubuntu
# or
sudo yum install supervisor      # CentOS/RHEL

# Create config
sudo nano /etc/supervisor/conf.d/aggregate-worker.conf

# Add content from docs/supervisor/aggregate-worker.conf
# Then reload:
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start aggregate-worker:*
```

**C. Using cron (Simple but less reliable):**

```bash
# Add to crontab
crontab -e

# Add this line (runs every minute):
* * * * * cd /path/to/aggregate-sy && php bin/console messenger:consume async --time-limit=60 >> var/log/worker.log 2>&1
```

## Configuration

Configuration is split between four places:

- **`.env` or `.env.local`** (server-level, never committed to git): Symfony infrastructure — database connection, message queue, app secret, and explicit proxy trust.
- **`config/aggregate.yaml`** (app-level, example committed): Analytics-specific settings — application branding, privacy measurement controls, internal traffic markers and sharing token, lifecycle policy, rate limit, JS namespace, dashboard toggle.
- **`config/goals.yaml`** (app-level, committed): Stable conversion-goal codes and whether each is enabled for anonymous collection.
- **`config/navigation.yaml`** (app-level, committed): Main navigation labels, icons, and link targets.

The web installer at `/install` is optional and only needed when you want dashboard-based setup.

### Environment File (`.env` or `.env.local`)

Create at least one env file in the project root:
- `.env` (recommended baseline)
- `.env.local` (server-only override)

Use `.env.prod.example` or `.env.local.example` as your template.  
`*.env.prod` by itself is not auto-loaded by Symfony runtime.

```bash
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=generate-with-openssl-rand-hex-32
TRUSTED_PROXIES=""
DATABASE_URL="mysql://user:pass@localhost:3306/dbname?serverVersion=8.0"
MESSENGER_TRANSPORT_DSN=sync://
MAILER_DSN=null://null
```

If neither `.env` nor `.env.local` exists, set these as real server environment variables instead.

For async scale-up mode, switch to:

```bash
MESSENGER_TRANSPORT_DSN=doctrine://default
```

Connection string formats for `DATABASE_URL` (see [Database Guide](docs/DATABASE.md)):
- PostgreSQL: `postgresql://user:pass@host:5432/dbname?serverVersion=16`
- MySQL: `mysql://user:pass@host:3306/dbname?serverVersion=8.0`
- MariaDB: `mysql://user:pass@host:3306/dbname?serverVersion=11.4.0-MariaDB`
- SQL Server: `sqlsrv://user:pass@host:1433/dbname?serverVersion=2022`
- SQLite: `sqlite:///%kernel.project_dir%/var/data.db`

### App Configuration (`config/aggregate.yaml`)

Copy the example and adjust as needed:

```bash
cp config/aggregate.yaml.example config/aggregate.yaml
```

**Settings:**
- `rate_limit_per_minute`: API requests per IP per minute (default: `100`)
- `app_host`: Public hostname, used in dashboard integration snippets
- `brand_name`: Application name used in page titles, headings, and explanatory copy (default: `Aggregate Analytics`)
- `brand_logo_text`: Visible text displayed beside the optional navigation logo; with a valid image, leave it empty for an image-only identity (default: `brand_name`)
- `brand_logo_path`: Optional PNG, JPEG, or WebP logo path; use an absolute path or a path relative to the project root
- `brand_primary_color`: Primary interactive and emphasis color (default: `#00D1B2`)
- `brand_accent_color`: Secondary accent color (default: `#485FC7`)
- `brand_navbar_color`: Navigation bar background color (default: `#14161A`)
- `brand_background_color`: Application page background color (default: `#F5F5F5`)
- `brand_surface_color`: Cards, panels, and other raised surface color (default: `#FFFFFF`)
- `brand_text_color`: Main application text color (default: `#363636`)
- `brand_font_family`: Safe comma-separated local/system font stack for application text (default: `system-ui, -apple-system, BlinkMacSystemFont, Segoe UI, sans-serif`)
- `brand_heading_font_family`: Safe comma-separated local/system font stack for headings (default: `system-ui, -apple-system, BlinkMacSystemFont, Segoe UI, sans-serif`)
- `js_namespace`: JavaScript global variable name (default: `Aggregate`)
- `internal_traffic_storage`, `internal_traffic_name`, `internal_traffic_value`, `internal_traffic_cookie_domain`, `internal_traffic_share_token`: Browser marker and team sharing settings; see [Internal traffic](#internal-traffic)
- `dashboard_enabled`: Enable/disable dashboard/login/install behavior (default: `true`)
- `anonymous_tracking_enabled`: Administrative collection kill switch; `false` rejects anonymous and enhanced events (default: `true`)
- `anonymous_excluded_paths`: Paths/globs excluded from anonymous and enhanced collection (default: `[]`)
- `anonymous_geo_enabled`: Enable transient, local-IP-to-area lookup for accepted events (default: `false`)
- `anonymous_geo_level`: Store `macro_region` (continent-level, recommended) or `country` (default: `macro_region`)
- `anonymous_geo_database_path`: Local GeoLite2-Country-compatible `.mmdb` path (absolute recommended, or contained by the project root); URI/UNC/network-share/device paths are rejected and no database is downloaded automatically
- `analytics_archiving_enabled`: Aggregate older raw rows into private event/pageview, goal, and geography cells (default: `false`)
- `analytics_archive_after_days`: Age at which completed raw data becomes eligible for archiving (default: `90`, range: `1`–`36500`)
- `analytics_retention_enabled`: Enable irreversible raw-row and archive-cell deletion (default: `false`)
- `analytics_anonymous_retention_days`: Anonymous raw-event retention (default: `365`, range: `1`–`36500`)
- `analytics_enhanced_retention_days`: Enhanced raw-event retention (default: `90`, range: `1`–`36500`)
- `analytics_archive_retention_days`: Archived aggregate-cell retention (default: `730`, range: `1`–`36500`)
- `analytics_maintenance_batch_size`: Raw rows processed per maintenance batch (default: `1000`, range: `100`–`10000`)
- `DASHBOARD_ENABLED` (env var): Boot-time dashboard feature boundary for loading dashboard routes/services. Set `0` for API-only deploys.
- After changing dashboard feature settings (`dashboard_enabled` or `DASHBOARD_ENABLED`) in production, run `php bin/console cache:clear`.
- Malformed YAML or invalid collection-kill-switch/path settings fail closed: ingestion stops and `/api/health` reports a generic configuration error. Invalid optional GeoIP lookup settings instead produce no `geo_area`.

Logo images may be at most 2 MiB, 4096 pixels per axis, and 16 megapixels in total. The settings UI validates and copies uploaded logos below `var/branding` in an environment-specific directory; keep `var/branding` on persistent storage shared by all application replicas, make it writable by the PHP process, and include it in backups. Replicas that use the settings UI must also share the active `aggregate.yaml` file (or otherwise coordinate and deploy each saved revision) so every replica switches logo references together. Uploaded bytes are served as supplied, so remove EXIF/XMP or other embedded metadata before uploading. A configured `brand_logo_path` is resolved from the project root when relative, while absolute local filesystem paths are also supported. Leave it empty for a text-only identity.

Theme colors accept `#RGB` or `#RRGGBB` hex values; three-digit colors are normalized to six-digit form. Quote them in YAML so `#` is not parsed as a comment. Text must maintain at least 4.5:1 contrast against both page and surface colors; the UI rejects lower-contrast palettes and YAML/env palettes fall back to safe defaults. Filled primary, accent, and navigation elements automatically use a contrasting black or white foreground. Font settings accept safe comma-separated local/system font stacks and do not download or embed web fonts. The deployer remains responsible for checking overall legibility and focus visibility.

Environment variables (`BRAND_NAME`, `BRAND_LOGO_TEXT`, `BRAND_LOGO_PATH`, `BRAND_PRIMARY_COLOR`, `BRAND_ACCENT_COLOR`, `BRAND_NAVBAR_COLOR`, `BRAND_BACKGROUND_COLOR`, `BRAND_SURFACE_COLOR`, `BRAND_TEXT_COLOR`, `BRAND_FONT_FAMILY`, and `BRAND_HEADING_FONT_FAMILY`) override the corresponding YAML values, including explicit empty logo-text/path values. A `BRAND_LOGO_PATH` override disables logo upload/removal in the UI. To save any dashboard-backed YAML setting, the active `aggregate.yaml` file (or its symlink target) must be writable by the PHP process; the surrounding `config/` directory can remain read-only. YAML-only deployments do not need to grant write access.

After deploying a release that adds or changes branding services, rebuild the production container and Twig cache with `APP_ENV=prod APP_DEBUG=0 php bin/console cache:clear --env=prod --no-debug`. Reload long-running PHP workers when OPcache timestamp validation is disabled. A deployment verification can run `php bin/console debug:twig --filter=app_branding --format=json --env=prod --no-debug`; the result must contain a non-empty `app_branding` global.

The two BI disclosure thresholds are configured separately in the admin dashboard and stored directly in the singleton `analytics_privacy_settings` database row:

- `anonymous_min_cell_count`: completed hourly cells in `bi_anonymous_events_v1` and completed daily goal cells in `bi_anonymous_goals_v1` (default `5`, range `2`–`1000`)
- `anonymous_geo_min_cell_count`: completed daily cells in `bi_anonymous_geo_events_v1` (default `25`, range `10`–`1000`)

Dashboard changes take effect immediately because all three BI views read this row directly. The migrations create it with safe defaults; there is no YAML copy or synchronization command. For an API-only deployment, update the singleton row through controlled database administration and keep routine BI roles read-only.

When upgrading from a version that mirrored these values, the existing database row keeps the last applied thresholds. Remove stale `anonymous_min_cell_count` and `anonymous_geo_min_cell_count` YAML/environment settings after every application instance is upgraded; the new code ignores them.

### Internal traffic (Similar to Traffic Exclusions in Google Analytics)

Instead of suppressing or removing traffic originating from the organization, you can mark your browser with a cookie or local storage entry that denotes your traffic as organization/internal traffic so filters can be added in your BI tool to exclude or treat this as you see fit for your analysis.

The default browser marker is **`orgInternalTraffic=true`**. Administrators can open **Organization traffic** at `/dashboard/internal-traffic` to configure its name and value, choose a cookie or local storage, and click a button to mark or unmark their own browser. The tracker reads an existing marker; it never creates one automatically. A match adds `{"orgInternalTraffic": true}` to the existing `events.custom_data` JSON in both anonymous and enhanced modes. The JSON property name always follows the configured marker name: `internal_traffic_name: companyStaff` produces `{"companyStaff": true}`. Unmarked events omit that key. No database migration is required.

This flag denotes your organization's traffic. The separate `internal` referrer category means navigation within the website and is unaffected by the marker.

Set these keys in the active environment in `config/aggregate.yaml` (or its environment-specific file):

```yaml
internal_traffic_storage: cookie       # cookie or local_storage
internal_traffic_name: orgInternalTraffic
internal_traffic_value: "true"          # Quote this string in YAML.
internal_traffic_cookie_domain: ""      # Host-only; e.g. example.com for sibling subdomains.
internal_traffic_share_token: ""        # Generated during installation; empty disables sharing.
```

The web installer and `php bin/console app:install` generate a random 64-character sharing token and save it in YAML, preserving any existing token. `install.sh` generates separate random tokens for each environment when creating initial YAML, including headless installs. Existing installations can generate one from the Organization traffic page. Copy the resulting `/internal-traffic/<token>` link to teammates; they can open it without signing in and click **Mark this browser**. The admin UI can rotate or revoke the link. Revocation disables the link; it does not remove markers already installed. Uppercase environment variables override these YAML settings and lock the corresponding UI controls, including an explicit empty sharing token.

Sharing pages and downloads carry `noindex, nofollow`, `no-store`, and a no-referrer policy; the page loads no third-party assets. The token is never included in the tracking script or event JSON. Keep the link within your team; anyone holding it can open the page. Cookies last one year, use `Path=/` and `SameSite=Lax`, and set `Secure` on HTTPS. Local storage lasts until removed or cleared. Changing the configured marker name/value requires teammates to mark their browsers again. Remove an old marker before changing its settings if you want to clear it too. Rejecting enhanced analytics clears visitor/session identifiers but preserves this independently chosen team marker.

**Browser scope:** a page on `analytics.example.com` can set a cookie for `example.com`, which the tracker on `www.example.com` can read. With an empty cookie domain, the cookie only applies to `analytics.example.com`. Local storage only applies to the exact origin, including scheme and port. An analytics page cannot set storage for an unrelated website. For those sites, use **Download marker page**, host the downloaded HTML on the tracked site's origin, and share that site's page with teammates. Opening the downloaded file locally does not mark a website. The download contains marker settings but no sharing token; restrict its hosted URL separately if needed.

The supplied Apache, nginx, and FrankenPHP configurations route `/aggregate.js` through Symfony so YAML/UI changes are included. Apply the updated server configuration when upgrading, clear the production Symfony cache for the new routes/services, and refresh any older cached tracker. If you serve `public/aggregate.js` directly from a static host/CDN, configure matching values in the site's snippet:

```html
<script>
  window.Aggregate = {
    endpoint: 'https://analytics.example.com/api/receive',
    websiteToken: 'your-website-token',
    internalTraffic: { storage: 'cookie', name: 'orgInternalTraffic', value: 'true' }
  };
</script>
<script src="https://analytics.example.com/aggregate.js" async referrerpolicy="no-referrer"></script>
```

Use the same name and value across your team's browser setup and tracker snippets. Per-script `data-internal-traffic-storage`, `data-internal-traffic-name`, and `data-internal-traffic-value` attributes also override the defaults.

**Power BI / Tableau:** extract the `custom_data.orgInternalTraffic` boolean as a calculated column and filter out `true`; a missing key or null JSON means unmarked traffic. Replace `orgInternalTraffic` in your report with the configured cookie/local storage name. The server uses the YAML/UI name for the JSON key, so static tracker overrides must use that same name. Changing the marker name changes the key on future events; historical and queued events keep their original key. Include both keys when reporting across a rename. Changing only the browser value leaves the JSON key unchanged. For the default name, these SQL expressions identify organization traffic:

| Database | Internal-traffic expression |
| --- | --- |
| PostgreSQL | `COALESCE(custom_data::jsonb ->> 'orgInternalTraffic', 'false') = 'true'` |
| MySQL / MariaDB | `COALESCE(JSON_UNQUOTE(JSON_EXTRACT(custom_data, '$.orgInternalTraffic')), 'false') = 'true'` |
| SQL Server | `COALESCE(JSON_VALUE(custom_data, '$.orgInternalTraffic'), 'false') = 'true'` |
| SQLite | `COALESCE(json_extract(custom_data, '$.orgInternalTraffic'), 0) = 1` |

For example, an approved PostgreSQL extract can derive a field for the report filter:

```sql
SELECT website_token, created_at, event_name,
       COALESCE(custom_data::jsonb ->> 'orgInternalTraffic', 'false') = 'true'
           AS is_internal_traffic
FROM events;
```

Keep raw-event access within your existing reporting policy. For anonymous reporting, apply the internal-traffic filter before aggregation and disclosure thresholds in a controlled export. Existing `bi_anonymous_*` views and archive tables do not expose `custom_data` and cannot distinguish internal traffic. Archives continue to combine both traffic types; once raw rows are deleted, this flag cannot be recovered from archives. Prepare filtered reporting datasets while the raw JSON is retained. This browser-supplied label is for reporting, not authorization.

### Archiving and retention

Administrators can edit the seven `analytics_*` lifecycle settings at `/dashboard/data-lifecycle`; API-only deployments can manage the same keys in `config/aggregate.yaml`. Uppercase environment variables (for example, `ANALYTICS_RETENTION_ENABLED`) take precedence and lock the corresponding UI controls. Values are validated strictly. When archiving and retention are both enabled, each raw retention period must be at least `analytics_archive_after_days`. Whenever retention is enabled, archive retention must be at least the longer raw retention period so marked source rows cannot disappear from reporting early.

Run maintenance outside the web process, normally once per day. Both features are disabled by default; inspect a dry run before enabling irreversible deletion:

When retention is enabled without archiving, eligible raw rows are deleted without first preserving aggregate history.

```bash
php bin/console app:analytics:maintain --dry-run
php bin/console app:analytics:maintain
```

Archiving creates private, unsuppressed aggregate cells and marks the source rows as archived; it does not itself delete raw rows. Retention deletes raw anonymous, raw enhanced, and archived aggregate data only after their configured periods. Backups, BI extracts, queues, failed messages, logs, and replicas need separate retention controls.

### Main Navigation (`config/navigation.yaml`)

The `brand` entry controls where the branded identity links. Its `label` remains a backward-compatible name/wordmark fallback for existing deployments. Runtime identity, logo, theme-color, and font settings live in `config/aggregate.yaml` (or their environment overrides), so UI changes do not require rebuilding navigation configuration. Edit `items` and `account` to manage the remaining authenticated-navbar links. Each link defines exactly one Symfony `route` name (for example, `app_how_it_works`) or literal `url`; `label` is required, while `icon`, `route_parameters`, and an optional security `role` are supported. The `items` list may be empty. After changing navigation in production, clear the production cache so the container and Twig globals are rebuilt.

### Conversion Goals (`config/goals.yaml`)

Goal codes are a server-enforced allowlist. The YAML key is the stable value stored in `events.goal_event`; `label` is presentation text, `enabled` controls future collection, and `anonymous` controls whether the goal may be retained without enhanced consent.

```yaml
parameters:
    app.goal_events:
        purchase:
            label: 'Purchase'
            anonymous: true
            enabled: true
        signup:
            label: 'Signup'
            anonymous: true
            enabled: true
```

The default codes are `purchase`, `lead`, `signup`, `subscription`, `booking`, `contact`, and `download`. Codes must match `[A-Za-z][A-Za-z0-9_.:-]{0,99}` and must never contain an email, order number, account ID, form value, or other user-derived text. Payload matching is exact and case-sensitive; surrounding whitespace and case variants are rejected. Set `anonymous: false` when a goal is appropriate only for enhanced analytics. Prefer `enabled: false` over deleting a historical definition.

An unknown, invalid, disabled, or anonymous-disallowed goal is omitted while the underlying event is still accepted. The API adds `warnings: ["goal_not_allowed"]`, and the SDK writes this generic warning without echoing the submitted value:

```text
[Aggregate] Goal was not recorded because it is not an approved goal type.
```

This is an allowlist warning, not a sensitive-data detector. Review every configured code and its use in context. After changing `config/goals.yaml` in production, clear the production cache so the service container is rebuilt.

#### Optional coarse geography

Install or regularly update a GeoLite2 Country (or compatible country-level) MMDB yourself, mount it read-only on the local filesystem, and point `anonymous_geo_database_path` at its absolute path. Direct UNC/network-share and Windows device paths are rejected. The ingestion process must be able to read the file. The application never downloads the database and never calls a GeoIP web service. No MMDB is bundled; follow the provider's license, attribution, and update terms.

```yaml
anonymous_geo_enabled: true
anonymous_geo_level: macro_region
anonymous_geo_database_path: /var/lib/GeoIP/GeoLite2-Country.mmdb
```

For each accepted event, the server validates the source as a public IP, performs the lookup locally, keeps only `continent:XX` or `country:XX`, and does not add the IP or detailed MMDB record to the event or queue. Invalid/private addresses, an unreadable database, or lookup errors leave `geo_area` empty and do not interrupt event ingestion. If the app is behind a proxy, set `TRUSTED_PROXIES` to only its exact IPs or narrow CIDRs so Symfony can use its forwarded address, and require that proxy to strip or overwrite client-supplied forwarding headers. Never use `0.0.0.0/0`, `::/0`, or `REMOTE_ADDR`, and never trust forwarding headers from arbitrary clients. With no trusted-proxy configuration, geography will normally describe the proxy or remain empty.

IP-derived geography describes an approximate network exit. VPNs, mobile carriers, corporate gateways, and geolocation-database errors can place it in the wrong area. Never represent `geo_area` as a visitor's precise residence or physical location.

Prefer `macro_region`. Country mode should be enabled only when traffic and risk assessment support it. IP lookup is personal-data processing in many jurisdictions, and a stored area can still contribute to singling out a person. Review the compliance guide and provide appropriate notice before enabling it.

### Customizing the JavaScript Namespace

Set `js_namespace` in `config/aggregate.yaml` (or via the web installer):

```yaml
js_namespace: "Company1Analytics"
```

Or override per-script tag:

```html
<script src="https://your-host/aggregate.js" data-namespace="Company1Analytics" referrerpolicy="no-referrer"></script>
```

Then use your custom namespace:
```javascript
window.Company1Analytics.setConsent(true);
window.Company1Analytics.emit('signup', {plan: 'pro'});
```

### Environment Variable Override

App-specific settings from `aggregate.yaml` can be overridden with environment variables:

```bash
export JS_NAMESPACE="MyCustomAnalytics"
export BRAND_NAME="Company Analytics"
export BRAND_LOGO_TEXT="Company Analytics"
export BRAND_LOGO_PATH="/var/lib/company/analytics-logo.webp"
export BRAND_PRIMARY_COLOR="#0F766E"
export BRAND_ACCENT_COLOR="#4338CA"
export BRAND_NAVBAR_COLOR="#111827"
export BRAND_BACKGROUND_COLOR="#F8FAFC"
export BRAND_SURFACE_COLOR="#FFFFFF"
export BRAND_TEXT_COLOR="#1F2937"
export BRAND_FONT_FAMILY="system-ui, -apple-system, BlinkMacSystemFont, Segoe UI, sans-serif"
export BRAND_HEADING_FONT_FAMILY="system-ui, -apple-system, BlinkMacSystemFont, Segoe UI, sans-serif"
export DASHBOARD_ENABLED="0"
export ANONYMOUS_TRACKING_ENABLED="0"
export ANONYMOUS_GEO_ENABLED="0"
export ANALYTICS_ARCHIVING_ENABLED="1"
export ANALYTICS_ARCHIVE_AFTER_DAYS="90"
export ANALYTICS_RETENTION_ENABLED="0"
export ANALYTICS_ANONYMOUS_RETENTION_DAYS="365"
export ANALYTICS_ENHANCED_RETENTION_DAYS="90"
export ANALYTICS_ARCHIVE_RETENTION_DAYS="730"
export ANALYTICS_MAINTENANCE_BATCH_SIZE="1000"
```

## Architecture

The system includes these services:
- **php**: FrankenPHP web server with Symfony application
- **worker**: Background job processor for analytics events
- **database**: one selected Docker profile (`mysql`, `postgres`, or `mariadb`)
- **asset-compile**: Compiles frontend assets on startup

## Usage

### Health Check

```bash
curl http://localhost/api/health
```

Or with Make:
```bash
make status
```

### JavaScript Integration

Add to your website:

```html
<script>
  window.Aggregate = {
    endpoint: 'https://your-host/api/receive',
    websiteToken: 'your-website-token'
  };
</script>
<script src="https://your-host/aggregate.js" async referrerpolicy="no-referrer"></script>
```
An anonymous-mode page-view row is recorded automatically when the script loads. Queries, fragments, raw referrers, cookies, visitor IDs, and session IDs are not sent in anonymous mode. You may also call `emit(...)`: before enhanced consent, each safe event name and its coarse context are retained, configured goals marked `anonymous: true` may be retained, and custom properties are omitted.

### Custom Event Tracking

Use a fixed event taxonomy. Names must match `[A-Za-z][A-Za-z0-9_.:-]{0,99}` and must not contain user-entered or identifier-like values.
Even with enhanced consent, keep event properties purpose-limited and avoid emails, account IDs, form contents, search terms, or other free text.

```javascript
// Anonymous mode records the safe event name, coarse context, and the allowlisted
// `signup` goal. The custom property is omitted.
window.Aggregate.emit('signup_click', { plan_type: 'pro' }, 'signup');

// Accept enhanced analytics to include properties, identifiers, and exact dimensions.
window.Aggregate.setConsent(true);
window.Aggregate.emit('signup_click', { plan_type: 'pro' }, 'signup');

// Reject or withdraw enhanced analytics. Coarse named-event rows and configured
// anonymous goals continue.
window.Aggregate.setConsent(false);
```

### Testing

Send a test event:
```bash
make test-tracking
```

Or manually:
```bash
curl -i -X POST http://localhost/api/receive \
  -H "Origin: http://example.com" \
  -H "Content-Type: application/json" \
  -d '{
    "pagePath":"/pricing",
    "referrerChannel":"search",
    "deviceClass":"desktop",
    "viewportBucket":"large",
    "eventName":"button_click",
    "websiteToken":"your-token-here",
    "consentState":"unknown"
  }'
```

Because this request supplies no `goalEvent`, the server stores it as an individual `privacy_mode = 'anonymous'` row with a UTC-hour timestamp and no property, identifier, or exact dimension.

## Make Commands

- `make help` - Show all available commands
- `make install` - Complete installation and setup
- `make start` - Start all services (defaults to `DOCKER_PROFILE=mysql`)
- `make stop` - Stop all services
- `make restart` - Restart all services
- `make logs` - View logs from all services
- `make logs-worker` - View worker logs only (async mode)
- `make migrate` - Run database migrations (use `DOCKER_PROFILE` for non-MySQL)
- `make start-mysql` - Start Docker with MySQL profile + DSN
- `make start-postgres` - Start Docker with PostgreSQL profile + DSN
- `make start-mariadb` - Start Docker with MariaDB profile + DSN
- `make migrate-mysql` - Run migrations for MySQL profile
- `make migrate-postgres` - Run migrations for PostgreSQL profile
- `make migrate-mariadb` - Run migrations for MariaDB profile
- `make create-website` - Create a new website (interactive)
- `make status` - Check service status and health
- `make test-tracking` - Send a test tracking event
- `make clean` - Clean up containers and volumes

Docker profile examples:
- `make start-postgres`
- `make migrate-postgres`
- `make start-mariadb`
- `make migrate-mariadb`

## JavaScript Snippet (aggregate.js)
Serve the public file at `/aggregate.js` and embed it on your site:
```html
<script>
  window.Aggregate = {
    endpoint: 'https://your-host/api/receive',
    websiteToken: 'abc-123-def-456'
  };
</script>
<script src="https://your-host/aggregate.js" async referrerpolicy="no-referrer"></script>
```
- It auto-sends a privacy-minimized `view` event on load (no `emit(...)` call needed for page views).
- Named `emit(...)` events are retained as individual anonymous-mode rows before consent. Allowlisted goals marked `anonymous: true` may also be retained; custom properties are omitted.
- To enable identifiers, event properties, exact dimensions, and configured goals marked `anonymous: false`, call:
```js
window.Aggregate.setConsent(true);
```
- Emit custom events:
```js
window.Aggregate.emit('signup-click', { plan_type: 'pro' }, 'signup');
```
- To reject or withdraw enhanced tracking, call `window.Aggregate.setConsent(false)`. This clears browser identifiers and returns to coarse, hour-bucketed anonymous-mode event rows; it does not delete previously collected server data.

### Google Tag Manager (GTM) Integration

Complete setup guide for integrating with Google Tag Manager for both pixel tracking and custom event tracking.

#### Step 1: Install the Tracking Pixel in GTM

1. **Create a Custom HTML Tag**
   - In GTM, go to **Tags** → **New**
   - Click **Tag Configuration** → **Custom HTML**
   - Name it: "Analytics Tracking Pixel"

2. **Add the Tracking Script**

   Choose one of these configuration methods:

   **Option A: Inline Configuration (Recommended)**
   ```html
   <script>
     window.Aggregate = {
       endpoint: 'https://your-analytics-host.com/api/receive',
       websiteToken: 'your-website-token-here'
     };
   </script>
   <script src="https://your-analytics-host.com/aggregate.js" async referrerpolicy="no-referrer"></script>
   ```

   **Option B: Data Attributes (No inline JS)**
   ```html
   <script
     src="https://your-analytics-host.com/aggregate.js"
     data-endpoint="https://your-analytics-host.com/api/receive"
     data-website-token="your-website-token-here"
     referrerpolicy="no-referrer"
     async>
   </script>
   ```

   **Option C: URL Parameters**
   ```html
   <script src="https://your-analytics-host.com/aggregate.js?endpoint=https%3A%2F%2Fyour-analytics-host.com%2Fapi%2Freceive&token=your-website-token-here" async referrerpolicy="no-referrer"></script>
   ```

3. **Set the Trigger**
   - Click **Triggering** → **Choose a trigger**
   - Select **All Pages** (for view tracking on every page)
   - Or create a custom trigger for specific pages

4. **Save and Publish**
   - Click **Save**
   - Submit changes and publish your GTM container

#### Step 2: Track Custom Events from GTM

Named custom events may be stored in anonymous mode. Before `setConsent(true)`, custom properties are omitted, while a goal may be retained only when its fixed code is enabled and marked `anonymous: true` in `config/goals.yaml`.

**Method A: Using GTM's Custom HTML Tag for Specific Events**

1. Create a new **Custom HTML Tag**
2. Name it based on the event (e.g., "Track Button Click - CTA")
3. Add this code:
   ```html
   <script>
     if (window.Aggregate && window.Aggregate.emit) {
       window.Aggregate.emit('cta_click', {
         button_text: 'Get Started',
         location: 'homepage_hero'
       }, 'signup');
     }
   </script>
   ```
4. Set a trigger (e.g., click on specific button/element)

**Method B: Using GTM Variables for Dynamic Event Tracking**

1. Create a **Custom HTML Tag**
2. Name it: "Analytics Custom Event - Generic"
3. Add this code:
   ```html
   <script>
     (function() {
       if (window.Aggregate && window.Aggregate.emit) {
         var eventName = {{Event Name Variable}};
         var eventData = {
           category: {{Event Category}},
           label: {{Event Label}},
           value: {{Event Value}}
         };
         var goalEvent = {{Goal Event Variable}};
         window.Aggregate.emit(eventName, eventData, goalEvent);
       }
     })();
   </script>
   ```
4. Create corresponding **User-Defined Variables** in GTM:
   - `Event Name Variable` (e.g., Data Layer Variable: `eventName`)
   - `Event Category`, `Event Label`, `Event Value`
   - `Goal Event Variable` (optional; e.g., Data Layer Variable: `goalEvent`)

   Map `Event Name Variable` to an approved fixed taxonomy and `Goal Event Variable` to a key from `config/goals.yaml`. Never populate either one from click text, URLs, form fields, or other user-provided values.

5. Trigger this tag using **Custom Events** or **Click Triggers**

#### Step 3: Consent Management Integration

Use your consent manager to control enhanced analytics. Coarse page-view and named-event rows continue after rejection unless an administrator disables collection or excludes the current path.

1. **Create a Tag for Consent Opt-in**
   - Tag Type: **Custom HTML**
   - Name: "Analytics Accept Enhanced Consent"
   - Code:
     ```html
     <script>
       if (window.Aggregate && window.Aggregate.setConsent) {
         window.Aggregate.setConsent(true);
       }
     </script>
     ```
   - **Trigger**: Fire when user accepts cookies/consent
     - Example: `consentGranted` Custom Event
     - Or use your CMP's (Consent Management Platform) built-in triggers

2. **If consent was already granted, initialize it via a data attribute**
   ```html
   <script
     src="https://your-analytics-host.com/aggregate.js"
     data-endpoint="https://your-analytics-host.com/api/receive"
     data-website-token="your-website-token-here"
     data-consent="1"
     referrerpolicy="no-referrer"
     async>
   </script>
   ```

3. **Handle rejection or withdrawal**
   ```html
   <script>
     if (window.Aggregate && window.Aggregate.setConsent) {
       window.Aggregate.setConsent(false);
     }
   </script>
   ```
   This removes the SDK's visitor/session identifiers and stops sending custom properties and exact dimensions. Safe event names and configured goals marked `anonymous: true` continue as individual anonymous-mode rows. It does not erase data already held by the server; handle deletion requests through your documented data-subject process.

#### Step 4: Common Event Tracking Examples

**Track Form Submissions**
```html
<script>
  window.Aggregate.emit('form_submit', {
    form_name: {{Form Name}},
    form_id: {{Form ID}}
  }, 'lead');
</script>
```
- **Trigger**: Form Submission trigger for your target form

**Track Button Clicks**
```html
<script>
window.Aggregate.emit('button_click', {
  button_text: {{Click Text}},
  button_id: {{Click ID}},
  page_path: {{Page Path}}
});
</script>
```
- **Trigger**: Click - All Elements, filter by Click Classes/IDs

**Track Scroll Depth**
```html
<script>
window.Aggregate.emit('scroll_depth', {
  depth_percentage: {{Scroll Depth Threshold}},
  page_path: {{Page Path}}
});
</script>
```
- **Trigger**: Scroll Depth (e.g., 25%, 50%, 75%, 100%)

**Track Video Views**
```html
<script>
  window.Aggregate.emit('video_interaction', {
    video_title: {{Video Title}},
    video_action: {{Video Status}},  // 'start', 'pause', 'complete'
    video_duration: {{Video Duration}},
    video_percent: {{Video Percent}}
  });
</script>
```
- **Trigger**: YouTube Video or Video trigger in GTM

**Track a Purchase**
```html
<script>
  window.Aggregate.emit('purchase_completed', {
    product_id: {{Product ID}},
    product_name: {{Product Name}},
    product_price: {{Product Price}},
    quantity: {{Product Quantity}}
  }, 'purchase');
</script>
```
- **Trigger**: Your completed-purchase Custom Event from the Data Layer

#### Step 5: Testing Your GTM Setup

1. **Enable GTM Preview Mode**
   - In GTM, click **Preview**
   - Enter your website URL

2. **Check Tag Firing**
   - Verify "Analytics Tracking Pixel" fires on page load
   - Verify custom event tags fire when triggered

3. **Monitor Network Requests**
   - Open browser DevTools → Network tab
   - Look for POST requests to `/api/receive`
   - Verify 202 Accepted response

4. **Check Analytics Backend**
   ```bash
   # Query database for recent events
   make logs-worker
   # Or check your database directly
   ```

#### Troubleshooting

**Pixel not loading:**
- Check GTM Preview mode to see if tag fires
- Verify `https://your-analytics-host.com/aggregate.js` is accessible
- Check browser console for errors

**Events not tracking:**
- Verify `window.Aggregate.emit` is available in browser console
- Ensure tracking pixel loaded before custom event tags fire
- Add tag sequencing: Make custom event tags wait for pixel tag

**403 Forbidden errors:**
- Verify your domain is correctly set in `config/websites.yaml`
- Check `Origin` header is being sent (subdomains are auto-allowed)

**429 Too Many Requests:**
- Increase `rate_limit_per_minute` in `config/aggregate.yaml` (or via dashboard settings)
- Check for infinite loops in your event tracking code

#### Advanced: Using dataLayer for Event Tracking

Push events to GTM's dataLayer, then capture with a single generic tag:

```javascript
// On your website
window.dataLayer = window.dataLayer || [];
dataLayer.push({
  'event': 'customAnalyticsEvent',
  'eventName': 'signup_click',
  'goalEvent': 'signup',
  'eventData': {
    'plan': 'pro',
    'source': 'pricing_page'
  }
});
```

**GTM Tag Configuration:**
1. Create trigger: Custom Event = `customAnalyticsEvent`
2. Create tag:
   ```html
   <script>
     if (window.Aggregate && window.Aggregate.emit) {
       window.Aggregate.emit(
         {{DLV - eventName}},
         {{DLV - eventData}},
         {{DLV - goalEvent}}
       );
     }
   </script>
   ```
3. Create Data Layer Variables:
   - `DLV - eventName` → Data Layer Variable Name: `eventName`
   - `DLV - eventData` → Data Layer Variable Name: `eventData`
   - `DLV - goalEvent` → Data Layer Variable Name: `goalEvent` (optional)

## Security & Privacy

### Domain Whitelisting
The `/api/receive` endpoint checks the `Origin` or `Referer` header against the registered domain for each website. Subdomains are automatically allowed.

### Rate Limiting
Simple per-IP rate limiting (default: 100 requests/minute) prevents abuse. It stores only rotating, keyed minute-bucket files in `var/rate_limit/`, not raw addresses; use an upstream limiter with a short TTL for larger deployments.

### Privacy Features

**Anonymous-mode events (default):**

- The SDK sends a sanitized path without query strings or fragments.
- Page views use the event name `view`; `emit(...)` accepts fixed names matching `[A-Za-z][A-Za-z0-9_.:-]{0,99}`, such as `button_click`, `form_submit`, or `ui:menu_open`. Identifier-like names are rejected.
- Obvious email addresses, UUIDs, numeric route IDs, and opaque tokens are redacted from path segments.
- Referrers become a coarse channel such as `direct`, `internal`, `search`, `social`, `email`, or `referral`; the raw referrer is not sent.
- Device and viewport values are coarse buckets. No visitor ID, session ID, cookie, custom properties, exact screen width, raw IP, or full User-Agent is retained in an anonymous-mode row. An enabled goal code is retained only when its definition permits anonymous use.
- An explicitly installed team marker may be read before enhanced consent. Only the boolean `orgInternalTraffic: true` is retained in `custom_data`; the cookie/local storage name, value, and sharing token are never sent as event properties.
- Optional local geolocation retains only a continent-level or country code in `geo_area`; the request IP and detailed lookup result are not put in analytics storage or the queue.
- Each event is stored as an individual row using a server-generated UTC hour bucket rather than an exact timestamp. The BI view groups these rows and suppresses cells below `anonymous_min_cell_count`.
- Hour bucketing and removal of identifiers reduce risk but do not guarantee that a row is legally anonymous; paths, event names, small populations, and outside information can still make data personal in context.
- Administrators can disable all collection globally or exclude sensitive path globs from both measurement modes.

**Enhanced analytics (requires consent):**

- `window.Aggregate.setConsent(true)` enables the visitor ID, session ID, session cookie, exact screen width, custom properties, and configured goals that are not allowed anonymously.
- `window.Aggregate.setConsent(false)` means reject or withdraw **enhanced analytics**. It removes SDK identifiers and strips enhanced event details, while coarse anonymous-mode page-view and named-event rows—and configured anonymous goals—continue.
- Consent withdrawal is prospective. It does not claim to delete previously collected server data; operators must provide and follow an appropriate data-subject request workflow.

These controls help reduce privacy risk but do not make a deployment automatically compliant with any law. The operator remains responsible for its legal basis, notices, consent-manager behavior, retention, access controls, vendor relationships, and rights-request procedures.

📖 **Full compliance guide**: [docs/PRIVACY-COMPLIANCE.md](docs/PRIVACY-COMPLIANCE.md)

## Data Model

**`events`** is the unified private storage table. `privacy_mode` separates `anonymous` and `enhanced` rows; page views use `event_name = 'view'`.

- Shared dimensions include `website_token`, `event_name`, sanitized path in `url`, coarse channel in `referrer`, `device_class`, `viewport_bucket`, optional `geo_area`, optional allowlisted `goal_event`, `privacy_mode`, and `created_at`.
- Anonymous-mode rows are individual events whose server-generated `created_at` is truncated to a UTC hour. An enabled `goal_event` may be present only when its definition permits anonymous use; identifier, exact-dimension, and generalized User-Agent columns remain null. `custom_data` is null except for the reserved `{"orgInternalTraffic": true}` marker; arbitrary event properties are still omitted.
- Enhanced rows may include `screen_width`, `visitor_id`, `session_id`, `consent_state`, `custom_data`, `goal_event`, `generalized_user_agent`, and an exact server timestamp.

Do not grant routine BI users access to raw `events`. Hour bucketing and missing IDs reduce risk, but anonymous-mode rows can still be personal data in context.

Older rows can be rolled into the private, unsuppressed `analytics_archive_events`, `analytics_archive_goals`, and `analytics_archive_geo_events` tables. The operational `analytics_archived_events_v1`, `analytics_archived_pageviews_v1`, and `analytics_archived_goals_v1` views are likewise private and unsuppressed; aggregation alone does not make their cells anonymous. The thresholded `bi_anonymous_*` views transparently combine eligible live and archived anonymous counts and remain the supported routine-BI surface.

**`bi_anonymous_events_v1`** is the supported Tableau/Power BI contract for anonymous-mode measurement:

- `website_token`, `event_hour`, `event_name`, `page_path`, `referrer_channel`, `device_class`, `viewport_bucket`, `event_count`
- It groups anonymous rows into hourly cells, withholds the current UTC hour, and exposes a completed cell only when `event_count` reaches `anonymous_min_cell_count` (default `5`, allowed range `2`–`1000`).

**`bi_anonymous_goals_v1`** is the dedicated anonymous conversion contract:

- `website_token`, `event_day`, `goal_event`, `event_count`
- It includes only anonymous rows with a retained goal, groups them by completed UTC day, and exposes a cell only when `event_count` reaches `anonymous_min_cell_count`.
- `event_count` measures goal occurrences, not unique people or unique converters. Goal labels remain presentation metadata in `config/goals.yaml`; the stable goal code is the reporting value.

**`bi_anonymous_geo_events_v1`** is the separate, lower-dimensional geography contract:

- `website_token`, `event_day`, `event_name`, `geo_area`, `event_count`
- It groups anonymous rows by UTC day, withholds the current day, and exposes a cell only when `event_count` reaches `anonymous_geo_min_cell_count` (default `25`, allowed range `10`–`1000`).
- It intentionally omits page path, referrer, device, viewport, and identifiers. Geography is not joined into `bi_anonymous_events_v1`.
- Low-volume areas are pooled into `country:other` or `continent:other` only when the pool itself meets the threshold. When exactly one area is below the threshold, the smallest otherwise-visible area is also pooled as secondary suppression to make direct subtraction harder.

`analytics_privacy_settings` is the database source of truth for the two thresholds used directly by the BI views. `anonymous_min_cell_count` is shared by the hourly event view and daily goal view. Administrators configure the thresholds in the dashboard; they are not copied from YAML or environment variables.

Suppression counts events, not distinct people: anonymous rows deliberately have no stable person identifier. One person can therefore contribute several events or goals to a released cell. Secondary suppression reduces simple differencing but cannot prevent inference across every extract, time period, or outside data source. None of the views establishes k-anonymity or guarantees that its output is legally anonymous; use higher thresholds, access controls, retention limits, and disclosure review where warranted.

Website registry is stored in `config/websites.yaml` (name/domain/token), not in relational tables.

Query examples for BI tools (Power BI, Looker, Tableau):
```sql
-- Grouped anonymous page views and named events, with low-volume cells suppressed
SELECT * FROM bi_anonymous_events_v1;

-- Daily configured goals, with low-volume cells suppressed
SELECT * FROM bi_anonymous_goals_v1;

-- Daily coarse geography with a higher threshold and fewer dimensions
SELECT * FROM bi_anonymous_geo_events_v1;

-- Enhanced page views (only if your BI policy permits raw enhanced data)
SELECT * FROM events WHERE privacy_mode = 'enhanced' AND event_name = 'view';

-- Enhanced custom events
SELECT * FROM events WHERE privacy_mode = 'enhanced' AND event_name != 'view';
```

On PostgreSQL, MySQL, MariaDB, or SQL Server, use a SELECT-only BI database role and grant it access only to approved, versioned views and any enhanced tables your policy permits. SQLite has no table/view privilege system: do not give routine BI users or desktop tools the database file, because they can query raw `events`. Export only approved view results through a controlled process, or use a server database when direct BI connectivity is required.

## Production Considerations

- Replace the file-based rate limiter with Redis-backed solution for multi-server deployments
- Use RabbitMQ or Redis instead of `doctrine://default` for high-volume message queues
- Configure proper database backups
- Schedule and monitor `php bin/console app:analytics:maintain` daily when archiving or retention is enabled
- Set up monitoring and alerting
- Use HTTPS in production (configured via Caddy/FrankenPHP)
- Disable or redact IP addresses, User-Agent strings, request bodies, and referrers in proxy/CDN/application access logs for the collection endpoint
- When coarse geography is enabled, keep the local MMDB current and read-only; do not replace it with a third-party lookup service without a separate privacy/vendor review

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.
