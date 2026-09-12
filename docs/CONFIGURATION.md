# Configuration reference

[README](../README.md) · [Deployment](../DEPLOYMENT.md) · [Privacy and compliance](PRIVACY-COMPLIANCE.md)

Use this guide for application settings and runtime overrides. Run commands from the repository root. The complete default configuration is in [config/aggregate.yaml.example](../config/aggregate.yaml.example); copy it to the untracked `config/aggregate.yaml` before editing deployment values.

- [Environment files](#environment-files)
- [Application settings](#application-settings)
- [Organization traffic](#organization-traffic)
- [Archiving and retention](#archiving-and-retention)
- [Main navigation](#main-navigation)
- [Conversion goals](#conversion-goals)
- [Coarse geography](#optional-coarse-geography)
- [JavaScript namespace](#customizing-the-javascript-namespace)
- [Environment overrides](#environment-variable-override)

Configuration has separate sources of truth:

- **Environment files and server variables:** Symfony infrastructure — database connection, message queue, app secret, and explicit proxy trust. Keep deployment values in untracked local files or server configuration.
- **`config/aggregate.yaml`** (app-level, example committed): Analytics-specific settings — application branding, privacy measurement controls, internal traffic markers and sharing token, lifecycle policy, rate limit, JS namespace, dashboard toggle.
- **`config/goals.yaml`** (app-level, committed): Stable conversion-goal codes and whether each is enabled for anonymous collection.
- **`config/navigation.yaml`** (app-level, committed): Main navigation labels, icons, and link targets.
- **`config/websites.yaml`** (untracked): Website names, registered domains, and public ingestion tokens, managed through the dashboard or `app:create-website`.
- **`analytics_privacy_settings`** (database): BI disclosure thresholds, managed through the dashboard or controlled database administration; these are not mirrored in YAML.

The web installer at `/install` is optional and only needed when you want dashboard-based setup.

## Environment files

Create environment files in the project root. Use `.env.dev` for local development or `.env.prod.example` for production as the baseline copied to `.env`, then set `APP_ENV` for the intended environment.

Symfony loads `.env`, `.env.local`, `.env.<APP_ENV>`, and `.env.<APP_ENV>.local` in that order; later files override earlier file values. It skips `.env.local` in the test environment. Use `.env.dev.local` to override the checked-in `.env.dev` defaults for native development, or `.env.prod.local` for production overrides. These local override files are ignored by Git.

Generate a real `APP_SECRET` with `openssl rand -hex 32` and put its output in your production configuration.

```dotenv
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=generate-with-openssl-rand-hex-32
TRUSTED_PROXIES=""
DATABASE_URL="mysql://user:pass@localhost:3306/dbname?serverVersion=8.0"
MESSENGER_TRANSPORT_DSN=sync://
MAILER_DSN=null://null
```

Real server environment variables take precedence over values loaded from these files. Keep deployment secrets out of committed examples.

For async scale-up mode, switch to:

```dotenv
MESSENGER_TRANSPORT_DSN=doctrine://default
```

Connection string formats for `DATABASE_URL` (see [Database Guide](DATABASE.md)):

- PostgreSQL: `postgresql://user:pass@host:5432/dbname?serverVersion=16`
- MySQL: `mysql://user:pass@host:3306/dbname?serverVersion=8.0`
- MariaDB: `mysql://user:pass@host:3306/dbname?serverVersion=11.4.0-MariaDB`
- SQL Server: `sqlsrv://user:pass@host:1433/dbname?serverVersion=2022`
- SQLite: `sqlite:///%kernel.project_dir%/var/data.db`

## Application settings

Copy the example and adjust as needed:

```bash
cp config/aggregate.yaml.example config/aggregate.yaml
```

The loader first uses `config/aggregate_<environment>.yaml` when that file exists. Otherwise, it reads `config/aggregate.yaml`, combining shared top-level values with the active `environments` entry. Supported uppercase environment variables take precedence. For example:

```yaml
environments:
  dev:
    app_host: "http://localhost:9001"
    dashboard_enabled: true
  prod:
    app_host: "https://analytics.example.com"
    dashboard_enabled: false
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
- `internal_traffic_storage`, `internal_traffic_name`, `internal_traffic_value`, `internal_traffic_cookie_domain`, `internal_traffic_share_token`: Browser marker and team sharing settings; see [Organization traffic](#organization-traffic)
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

## Organization traffic

The default browser marker is `orgInternalTraffic=true`. Configure `internal_traffic_storage`, `internal_traffic_name`, `internal_traffic_value`, `internal_traffic_cookie_domain`, and `internal_traffic_share_token` in the active YAML environment, or use the Organization traffic admin page. A match stores a boolean in the existing event JSON under the configured marker name; no migration is needed.

See [organization traffic](PRIVACY-COMPLIANCE.md#organization-traffic) for YAML examples, browser scope, installation-generated sharing tokens, downloadable marker pages, and Power BI/Tableau filtering. Existing grouped views and archives omit this JSON flag.

## Archiving and retention

Administrators can edit the seven `analytics_*` lifecycle settings at `/dashboard/data-lifecycle`; API-only deployments can manage the same keys in `config/aggregate.yaml`. Uppercase environment variables (for example, `ANALYTICS_RETENTION_ENABLED`) take precedence and lock the corresponding UI controls. Values are validated strictly. When archiving and retention are both enabled, each raw retention period must be at least `analytics_archive_after_days`. Whenever retention is enabled, archive retention must be at least the longer raw retention period so marked source rows cannot disappear from reporting early.

Run maintenance outside the web process, normally once per day. Both features are disabled by default; inspect a dry run before enabling irreversible deletion:

When retention is enabled without archiving, eligible raw rows are deleted without first preserving aggregate history.

```bash
php bin/console app:analytics:maintain --dry-run
php bin/console app:analytics:maintain
```

Archiving creates private, unsuppressed aggregate cells and marks the source rows as archived; it does not itself delete raw rows. Retention deletes raw anonymous, raw enhanced, and archived aggregate data only after their configured periods. Backups, BI extracts, queues, failed messages, logs, and replicas need separate retention controls.

## Main navigation

The `brand` entry controls where the branded identity links. Its `label` remains a backward-compatible name/wordmark fallback for existing deployments. Runtime identity, logo, theme-color, and font settings live in `config/aggregate.yaml` (or their environment overrides), so UI changes do not require rebuilding navigation configuration. Edit `items` and `account` to manage the remaining authenticated-navbar links. Each link defines exactly one Symfony `route` name (for example, `app_how_it_works`) or literal `url`; `label` is required, while `icon`, `route_parameters`, and an optional security `role` are supported. The `items` list may be empty. After changing navigation in production, clear the production cache so the container and Twig globals are rebuilt.

## Conversion goals

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

## Optional coarse geography

Install or regularly update a GeoLite2 Country (or compatible country-level) MMDB yourself, mount it read-only on the local filesystem, and point `anonymous_geo_database_path` at its absolute path. Direct UNC/network-share and Windows device paths are rejected. The ingestion process must be able to read the file. The application never downloads the database and never calls a GeoIP web service. No MMDB is bundled; follow the provider's license, attribution, and update terms.

```yaml
anonymous_geo_enabled: true
anonymous_geo_level: macro_region
anonymous_geo_database_path: /var/lib/GeoIP/GeoLite2-Country.mmdb
```

For each accepted event, the server validates the source as a public IP, performs the lookup locally, keeps only `continent:XX` or `country:XX`, and does not add the IP or detailed MMDB record to the event or queue. Invalid/private addresses, an unreadable database, or lookup errors leave `geo_area` empty and do not interrupt event ingestion. If the app is behind a proxy, set `TRUSTED_PROXIES` to only its exact IPs or narrow CIDRs so Symfony can use its forwarded address, and require that proxy to strip or overwrite client-supplied forwarding headers. Never use `0.0.0.0/0`, `::/0`, or `REMOTE_ADDR`, and never trust forwarding headers from arbitrary clients. With no trusted-proxy configuration, geography will normally describe the proxy or remain empty.

IP-derived geography describes an approximate network exit. VPNs, mobile carriers, corporate gateways, and geolocation-database errors can place it in the wrong area. Never represent `geo_area` as a visitor's precise residence or physical location.

Prefer `macro_region`. Country mode should be enabled only when traffic and risk assessment support it. IP lookup is personal-data processing in many jurisdictions, and a stored area can still contribute to singling out a person. Review the compliance guide and provide appropriate notice before enabling it.

## Customizing the JavaScript Namespace

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

## Environment Variable Override

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
