# Configuration reference

[README](../README.md) · [Deployment](../DEPLOYMENT.md) · [Privacy and compliance](PRIVACY-COMPLIANCE.md)

Use this guide for application settings and runtime overrides. Run commands from the repository root. The complete default configuration is in [config/aggregate.yaml.example](../config/aggregate.yaml.example); copy it to the untracked `config/aggregate.yaml` before editing deployment values.

- [Environment files](#environment-files)
- [Application settings](#application-settings)
- [Website groups](#website-groups)
- [Website domains](#website-domains)
- [Administration pages](#administration-pages)
- [Users and permissions](USERS-AND-PERMISSIONS.md)
- [Feature flags](#feature-flags)
- [Organization traffic](#organization-traffic)
- [Custom data and UTM parameters](#custom-data-and-utm-parameters)
- [Standalone consent settings](#standalone-consent-settings)
- [BI glossary](#bi-glossary)
- [Archiving and retention](#archiving-and-retention)
- [Sync](#sync): [BigQuery](#bigquery-sync) and [reporting database](#reporting-database)
- [Transactional email](#transactional-email)
- [Main navigation](#main-navigation)
- [Conversion goals](#conversion-goals)
- [Coarse geography](#optional-coarse-geography)
- [Documentation links](#documentation-links)
- [JavaScript namespace](#customizing-the-javascript-namespace)
- [Environment overrides](#environment-variable-override)

Configuration has separate sources of truth:

- **Environment files and server variables:** Symfony infrastructure — database connection, the optional [reporting database](#reporting-database) connection, message queue, app secret, and explicit proxy trust. Keep deployment values in untracked local files or server configuration.
- **`config/aggregate.yaml`** (app-level, example committed): Analytics-specific settings — application branding, privacy measurement controls, internal traffic markers and sharing token, lifecycle policy, BigQuery sync, the [email service](#transactional-email) and sender, rate limit, JS namespace, dashboard toggle, and deployment-wide feature flags.
- **`config/secrets/`** (untracked): Credentials Aggregate writes for itself, such as the BigQuery service account key and Google sign-in, the hashes of [reporting API tokens](REPORTING-API.md#tokens) in `api-tokens.yaml`, the [AI assistant sign-in](MCP.md#sign-in) state in `mcp-oauth.yaml`, and the [email service's credential](EMAIL.md) in `email.yaml`, readable only by the application's user and kept by updates.
- **`config/goals.yaml`** (app-level, committed): Stable conversion-goal codes and whether each is enabled for anonymous collection. Customize it in `config/goals.local.yaml` (see [Local overrides](#local-overrides-for-shipped-defaults)).
- **`config/navigation.yaml`** (app-level, committed): Main navigation labels, icons, and link targets. Customize it in `config/navigation.local.yaml`.
- **`config/quick_search.yaml`** (app-level, committed): Dashboard quick-search synonyms and extra entries. Customize it in `config/quick_search.local.yaml`.
- **`config/websites.yaml`** (untracked): Website names, primary domains, allowed event-source domains, and public ingestion tokens, managed through the Websites page, YAML, or `app:create-website`.
- **`config/website-scopes.yaml`** and **`config/website-scopes/`** (untracked): Named website groups and the collection settings or data models that a group or one website overrides. A missing catalog means every website uses the deployment settings. See [Website groups](#website-groups).
- **`config/permission-groups.yaml`** (untracked): Permission groups (roles), the named sets of websites and pages that administrators assign to standard users, managed through the Users page, YAML, or `app:permission-groups`. A missing file means no groups. See [Users and permissions](USERS-AND-PERMISSIONS.md#permission-groups).
- **`config/tag-manager/sites/<site-id>.yaml`** (untracked): Per-website CMP settings, tags, triggers, and variables, managed through the Tag manager page or YAML. Run `app:tag-manager:sites` to list IDs and paths; see [Tag manager](TAG-MANAGER.md).
- **`anonymous_min_cell_count` / `anonymous_geo_min_cell_count`** (deployment config): BI disclosure thresholds in `config/aggregate*.yaml`, managed through the BI disclosure dashboard form, YAML, or environment overrides, and applied with `app:analytics:bi-views:regenerate` (see [BI disclosure thresholds](#bi-disclosure-thresholds)).

The database holds no operator settings: it stores collected events, archives, users (with each user's own permission grants) and operational records. Tables such as `analytics_glossary` are generated copies of YAML for BI joins.

### When an edit takes effect

Edits to `config/aggregate.yaml`, `config/websites.yaml`, `config/website-scopes.yaml`, files under `config/website-scopes/` and the tag manager's site files take effect on the next request, whether they come from the dashboard, the command line or an editor. Aggregate reads the file a request needs and keeps only the parsed result of an unchanged file in `var/cache/<environment>/parsed-yaml`, keyed by the file's exact contents, so there is no cache to clear after an edit. A health check does not reread every website override on each poll: it notices a file that was added, removed, or whose size or modification time changed, and reads the overrides again only then. An edit that is not valid YAML, or not a valid configuration, stops collection for the websites that use it until it is fixed rather than falling back to the previous settings.

A few settings are built into Symfony's compiled container or into SQL, and need a step after an edit:

- `dashboard_enabled` or `DASHBOARD_ENABLED`, and Symfony's own files under `config/packages/` and the `services*.yaml` and `routes*.yaml` files: run `php bin/console cache:clear`, then reload PHP-FPM gracefully. Clearing the cache also removes the parsed-YAML entries, which are rebuilt on the next request. Measured during live traffic, this fails no collection requests when PHP-FPM lets busy workers finish; see [clearing the cache under traffic](LOAD-TESTING.md#clearing-the-cache-under-traffic).
- The BI disclosure thresholds: run `php bin/console app:analytics:bi-views:regenerate` (see [BI disclosure thresholds](#bi-disclosure-thresholds)).

The web installer at `/install` is optional and only needed when you want dashboard-based setup.

## Local overrides for shipped defaults

`config/goals.yaml`, `config/navigation.yaml` and `config/quick_search.yaml` are
shipped with the application, and updates replace them. To customize one, copy it
to the matching untracked override and edit the copy:

| Shipped default | Your override |
| --- | --- |
| `config/goals.yaml` | `config/goals.local.yaml` |
| `config/navigation.yaml` | `config/navigation.local.yaml` |
| `config/quick_search.yaml` | `config/quick_search.local.yaml` |

Each parameter defined in an override (for example `app.goal_events`) replaces the
shipped parameter **as a whole**; entries are not merged. Parameters the override
does not define keep their shipped values. After an update, compare your override
with the new default to pick up newly shipped entries, such as navigation links
for new features. Clear the production cache after changing an override.

Updates never write these override files. If you edited a shipped default
directly, `app:updates:apply` (and `app:updates:pull`) first moves those edits into
the override, then installs the new default. When an override already exists with
different content, the update stops without changing anything so you can merge
the two yourself. Git ignores `config/*.local.yaml`, and release packages never
contain them.

## Environment files

Create environment files in the project root. Use `.env.dev` for local development or `.env.prod.example` for production as the baseline copied to `.env`, then set `APP_ENV` for the intended environment.

Symfony loads `.env`, `.env.local`, `.env.<APP_ENV>`, and `.env.<APP_ENV>.local` in that order; later files override earlier file values. It skips `.env.local` in the test environment. Use `.env.dev.local` to override the checked-in `.env.dev` defaults for native development, or `.env.prod.local` for production overrides. These local override files are ignored by Git.

Generate a real `APP_SECRET` with `openssl rand -hex 32` and put its output in your production configuration.

```dotenv
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=generate-with-openssl-rand-hex-32
TRUSTED_PROXIES=""
DATABASE_URL="mysql://user:pass@localhost:3306/dbname?serverVersion=8.0.0"
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
- MySQL: `mysql://user:pass@host:3306/dbname?serverVersion=8.0.0`
- MariaDB: `mysql://user:pass@host:3306/dbname?serverVersion=11.4.0-MariaDB`
- SQL Server: `mssql://user:pass@host:1433/dbname?serverVersion=2022` (pdo_sqlsrv) or `sqlsrv://…` (sqlsrv); add `&driverOptions[TrustServerCertificate]=1` for a self-signed server certificate
- SQLite: `sqlite:///%kernel.project_dir%/var/data.db`

`%kernel.project_dir%` in `DATABASE_URL` is replaced with the application
directory; no other `%` sequence is interpreted, so encode special characters
in passwords as usual (`@` as `%40`). A release ZIP's browser setup page writes
`.env.local` with the encoded connection and detected version for you.

The optional `REPORTING_DATABASE_URL` uses the same formats for a second
database that receives copies of the reporting views. The Reporting database
page and `app:reporting-database:configure` test it and write it to
`.env.local`; see [Reporting database](#reporting-database).

MySQL and MariaDB version hints must include the patch component. See the
[DBAL 4 upgrade notes](DATABASE.md#upgrade-to-doctrine-dbal-4) before updating an
existing installation that uses a short version hint.

## Website groups

Collection settings and the data model can vary by registered website. The deployment configuration in `config/aggregate.yaml` is the base. A website group replaces the keys it sets for its members, and a website file replaces those keys again. The most specific setting wins. Environment variables still win for settings that have them, because they are read after this merge.

A missing `config/website-scopes.yaml` means every website uses the deployment settings. Existing installations need no migration. An invalid catalog or setting file fails closed: collection for the affected websites stops instead of falling back to a broader setting the operator tried to replace. One website's invalid file does not stop collection for the others. Health reports the configuration as invalid until it is corrected, and a repeated check reads the override files again only after one is added, removed, or its size or modification time changes.

The word "group" can be renamed, for example to Brand and Brands. That changes only the word in the dashboard and command output. Each group still has its own name, such as Amazon or Whole Foods. A website belongs to one group at most.

Open **Website groups** (`/dashboard/website-groups`) to rename the word, add or rename groups, and choose which websites belong where. The same choices are available with the dashboard disabled:

```bash
php bin/console app:website-scopes label --singular=Brand --plural=Brands
php bin/console app:website-scopes create-group --name=Amazon
php bin/console app:website-scopes assign --token=WEBSITE_TOKEN --group=amazon
php bin/console app:website-scopes list
```

Shared settings are separate YAML files, not database tables:

- `config/website-scopes/groups/<id>.yaml` for a group
- `config/website-scopes/websites/<site-id>.yaml` for one website

On **Collection controls** and **Data model**, choose the deployment, a group, or a website. The page shows the settings in effect. Check a section to save it for that scope; leave it unchecked to keep the broader setting. Only collection controls and the data model can vary. Branding, retention, reporting views, users and the dashboard stay on the deployment.

The approved `bi_anonymous_*` views and the private custom reporting views are still built from the deployment data model. A website or group model changes what that website collects. To add a new reporting column from those models, open the deployment data model and choose **Publish website and group columns**, or run `php bin/console app:website-scopes publish-model`, then regenerate views if they are not already current. Publishing does not change a column the deployment model already has, and it refuses two scopes that introduce the same new property with different columns.

Copy `config/website-scopes.yaml.example` for the catalog shape. A group file contains only the keys it overrides, for example:

```yaml
collection_profile: strict
anonymous_excluded_paths:
  - /checkout/**
```

Already installed window snippets that load `/aggregate.js?min=1` without a token keep the deployment browser configuration until the snippet is refreshed. The server still applies the website's settings to events. New window and query snippets include the token, so the served tracker matches that website's profile, page depth and consent-mode settings. A tag-manager action that loads the tracker should use the website's tracker URL.

## Website domains

Each website token has its own event-source rules in `config/websites.yaml`.
Open **Websites** (`/dashboard`), expand a registration, choose **Listed domains only**
or **Allow all domains**, and save. The same choices are available when
adding a website. These controls follow the existing website-management access:
signed-in users can manage registrations. Rules also work with the dashboard
disabled through YAML. There are no environment-variable overrides for this file.

For example, add a `domain_policy` to an existing registration while keeping its
token unchanged:

```yaml
websites:
  - name: Example website
    domain: example.com
    token: KEEP_THE_EXISTING_PUBLIC_WEBSITE_TOKEN
    domain_policy:
      mode: restricted
      domains:
        - example.com
        - '*.example.com'
        - shop.other-example.com
```

| Rule | Accepted hosts |
| --- | --- |
| `example.com` | Only `example.com` |
| `shop.example.com` | Only `shop.example.com` |
| `'*.example.com'` | `shop.example.com`, `a.b.example.com`, and other descendants; excludes `example.com` itself |

List both the root hostname and its wildcard to allow both. Quote wildcard values
in YAML. Lists accept up to 32 entries. Hostnames are case-insensitive; trailing
DNS dots are normalized. Use ASCII hostnames (punycode for international names),
without a protocol, port, path, query, or fragment. Exact IP literals and
`localhost` are supported; wildcard IPs, bare `*`, partial wildcards, and regex
patterns are rejected. Matching uses the hostname, so HTTP/HTTPS and different
ports do not create separate rules.

To accept events from any source for that token, use:

```yaml
domain_policy:
  mode: all
```

`all` skips the origin restriction, including for clients without `Origin` or
`Referer`. A valid website token is still required, and consent, collection
switches, sensitive-path exclusions, and other ingestion checks still apply.
An optional valid `domains` list can remain saved while `mode: all` is active;
it takes effect again when switching to `restricted`.

In restricted mode, the server checks a valid HTTP(S) `Origin`, using `Referer`
only if `Origin` is absent. Missing, malformed, or nonmatching headers are
rejected before geographic lookup, queueing, or recording in either privacy
mode. An invalid explicit policy rejects events; it never falls back to allowing
all sources. Direct clients can forge these headers, so domain rules do not
authenticate clients. Website tokens are public ingestion identifiers.

Existing registrations with no `domain_policy` retain the original behavior:
their primary `domain` and all its subdomains are allowed. The UI shows these
effective rules and makes them explicit when saved. New UI/CLI registrations
default to only the primary hostname; an empty list in the **Add website** form
uses that hostname. An existing restricted registration must have a nonempty
list. Older creation clients that omit both policy form fields retain the legacy
creation behavior.

For headless creation, the CLI still asks for the website name and primary domain:

```bash
# Default: only the primary hostname
php bin/console app:create-website
# Several exact/wildcard hosts under one newly generated token
php bin/console app:create-website --allowed-domain=example.com --allowed-domain='*.example.com'
# Any source with the newly generated token
php bin/console app:create-website --allow-all-domains
```

Use YAML or the Websites page to edit existing rules. Saving rules preserves the
website token, primary domain, other registrations, and unrelated YAML values.
Changes apply to subsequent requests without clearing the application cache.
Each token must uniquely identify one registration; duplicate tokens are rejected.
The primary `domain` remains the reference for classifying internal referrers;
adding other allowed hosts does not change that classification or store their
hostnames in events. Data models and other collection settings remain scoped to
the deployment's active aggregate configuration.

## Application settings

Copy the example and adjust as needed:

```bash
cp config/aggregate.yaml.example config/aggregate.yaml
```

The loader first uses `config/aggregate_<environment>.yaml` when that file exists. Otherwise, it reads `config/aggregate.yaml`, combining shared top-level values with the active `environments` entry. Supported uppercase environment variables take precedence. For example:

```yaml
environments:
  dev:
    app_host: "http://localhost:9002"
    dashboard_enabled: true
  prod:
    app_host: "https://analytics.example.com"
    dashboard_enabled: false
```

**Settings:**

- `rate_limit_per_minute`: API requests per IP per minute (default: `100`)
- `app_host`: Public hostname, used in dashboard integration snippets
- `documentation_url`: Address of the documentation site that dashboard help links and command-line messages point to (default: `https://subschema-llc.github.io/aggregate/`). See [documentation links](#documentation-links)
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
- `js_namespace`: JavaScript global variable name (default: `Aggregate`). Letters, digits, `_` and `$`, not starting with a digit, up to 64 characters; any other value is ignored and `Aggregate` is used. See [Customizing the JavaScript namespace](#customizing-the-javascript-namespace)
- `tracker_omit_unused_features`: Send a smaller `/aggregate.js?min=1` without the code for features these settings turn off: page depth while it is off, and what only the standard profile uses while the strict profile is on (default: `true`; `TRACKER_OMIT_UNUSED_FEATURES` environment override). Also on **General settings → Page speed**. See [page speed](PAGE-SPEED.md#leave-out-unused-tracker-features)
- `updates_method`: `release` (signed release ZIPs, recommended), `repository` (Git clone pulls, advanced) or `deployment` (deployed another way, such as a hosting panel's Git deployment or CI/CD); unset until an administrator chooses on the Updates page, with `app:updates:method`, or here. See [the update guide](UPDATES.md#choose-an-update-method)
- `updates_branch`: GitHub branch used by repository pulls, packaged-release checks and comparisons of deployed commits (default: `master`); also editable on the Updates page, with active-environment precedence
- `updates_signing_public_key`: Optional base64 Ed25519 public key overriding the packaged `config/release-signing.pub` for offline package verification; never a private key
- `internal_traffic_storage`, `internal_traffic_name`, `internal_traffic_value`, `internal_traffic_cookie_domain`, `internal_traffic_share_token`: Browser marker and team sharing settings; see [Organization traffic](#organization-traffic)
- `custom_data_properties`, `query_parameter_mappings`: Shareable property model, per-property consent settings, UTM/query capture, and reporting aliases; see [Custom data and UTM parameters](#custom-data-and-utm-parameters)
- `bi_glossary`: Declared value labels, column glossary, and published locales; see [BI glossary](#bi-glossary)
- `page_sequence_enabled`: Optional page depth on all events in both modes (default: `false`, strict YAML boolean, no environment override). Configure in Data model or YAML; see [page depth](DATA-MODEL.md#optional-page-depth) for the `20+` cap and reporting limits.
- `page_sequence_method`: `session_storage` (default) or `url_parameter`, with no environment override. Choose tab storage or the fixed `aggregate_page_sequence` URL parameter; review [storage and URL tradeoffs](DATA-MODEL.md#optional-page-depth) before enabling.
- `dashboard_enabled`: Enable/disable dashboard/login/install behavior (default: `true`)
- `website_activity_active_days`, `website_activity_stale_days`: How recently a website must have sent an event for the Websites page to show it as receiving data, and after how many days without one it shows as stale (defaults: `1` and `3`, range `1`–`365`, stale at least active). Also on **General settings**. The statuses are cached for 15 minutes and recalculated whenever someone signs in; `php bin/console app:websites:check-activity` prints them, and `--refresh` recalculates them first
- `collection_profile`: `standard` (default) or `strict`, with an uppercase `COLLECTION_PROFILE` environment override. Strict records every event anonymously with only the sanitized path, event name and an approved goal, and the served tracker reads no screen size or referrer and does not touch cookies or browser storage. Other collection settings are kept but unused while strict is selected. See the [strict collection profile](PRIVACY-COMPLIANCE.md#strict-collection-profile).
- `anonymous_tracking_enabled`: Administrative collection kill switch; `false` rejects anonymous and enhanced events (default: `true`)
- `anonymous_excluded_paths`: Paths/globs excluded from anonymous and enhanced collection (default: `[]`)
- `bot_traffic`: `label` (default) records bot events with `device_class = 'bot'`; `drop` ignores them before any other processing. Uppercase `BOT_TRAFFIC` environment override. See [bot traffic](PRIVACY-COMPLIANCE.md#bot-traffic).
- `bot_user_agents`: Extra phrases, matched in the User-Agent ignoring case, that mark a request as a bot, such as your own monitoring tool (default: `[]`; up to 50 phrases of 3–100 characters; YAML or dashboard only, no environment override)
- `anonymous_geo_enabled`: Enable transient, local-IP-to-area lookup for accepted events (default: `false`)
- `anonymous_geo_level`: Store `macro_region` (continent-level, recommended) or `country` (default: `macro_region`)
- `anonymous_geo_database_path`: Local GeoLite2-Country-compatible `.mmdb` path (absolute recommended, or contained by the project root); URI/UNC/network-share/device paths are rejected and no database is downloaded automatically
- `tracking_retry_enabled`: Allow or block manual retry runs for failed enhanced tracking messages in the dashboard (**Collection controls**) and with `php bin/console app:tracking:retry-failed` (default: `true`)
- `tracking_retry_batch_size`: Maximum failed tracking messages retried per run (default: `100`, range: `1`–`1000`); `app:tracking:retry-failed --limit=N` sets it for one run
- `analytics_archiving_enabled`: Aggregate older raw rows into private event/pageview, goal, and geography cells (default: `false`)
- `analytics_archive_after_days`: Age at which completed raw data becomes eligible for archiving (default: `90`, range: `1`–`36500`)
- `analytics_retention_enabled`: Enable irreversible raw-row and archive-cell deletion (default: `false`)
- `analytics_anonymous_retention_days`: Anonymous raw-event retention (default: `365`, range: `1`–`36500`)
- `analytics_enhanced_retention_days`: Enhanced raw-event retention (default: `90`, range: `1`–`36500`)
- `analytics_archive_retention_days`: Archived aggregate-cell retention (default: `730`, range: `1`–`36500`)
- `analytics_maintenance_batch_size`: Raw rows processed per maintenance batch (default: `1000`, range: `100`–`10000`)
- `DASHBOARD_ENABLED` (env var): Boot-time dashboard feature boundary for loading dashboard routes/services. Set `0` for API-only deploys.
- After changing dashboard feature settings (`dashboard_enabled` or `DASHBOARD_ENABLED`) in production, run `php bin/console cache:clear`.
- Malformed YAML or invalid collection-kill-switch/path/profile/bot-traffic settings fail closed: ingestion stops and `/api/health` reports a generic configuration error. Invalid optional GeoIP lookup settings instead produce no `geo_area`.

Logo images may be at most 2 MiB, 4096 pixels per axis, and 16 megapixels in total. The settings UI validates and copies uploaded logos below `var/branding` in an environment-specific directory; keep `var/branding` on persistent storage shared by all application replicas, make it writable by the PHP process, and include it in backups. Replicas that use the settings UI must also share the active `aggregate.yaml` file (or otherwise coordinate and deploy each saved revision) so every replica switches logo references together. Uploaded bytes are served as supplied, so remove EXIF/XMP or other embedded metadata before uploading. A configured `brand_logo_path` is resolved from the project root when relative, while absolute local filesystem paths are also supported. Leave it empty for a text-only identity.

Theme colors accept `#RGB` or `#RRGGBB` hex values; three-digit colors are normalized to six-digit form. Quote them in YAML so `#` is not parsed as a comment. Text must maintain at least 4.5:1 contrast against both page and surface colors; the UI rejects lower-contrast palettes and YAML/env palettes fall back to safe defaults. Filled primary, accent, and navigation elements automatically use a contrasting black or white foreground. Font settings accept safe comma-separated local/system font stacks and do not download or embed web fonts. The deployer remains responsible for checking overall legibility and focus visibility.

The navigation's light/dark control starts with the site's configured palette.
That palette is preserved in its matching light or dark mode. Switching to the
opposite mode uses neutral page, surface, and text colors while retaining the
configured primary and accent colors, navigation color, and fonts; contrasting
link and foreground colors are recalculated for readability. Choosing the site
default restores the configured palette.

The mode choice is a preference saved in this browser's local storage. It does
not change deployment YAML, environment settings, or another browser's choice.
If browser storage is unavailable, switching modes still works for the current
page. Operator colors and fonts continue to come from the shared branding
settings below; there is no separate YAML mode setting.

Environment variables (`BRAND_NAME`, `BRAND_LOGO_TEXT`, `BRAND_LOGO_PATH`, `BRAND_PRIMARY_COLOR`, `BRAND_ACCENT_COLOR`, `BRAND_NAVBAR_COLOR`, `BRAND_BACKGROUND_COLOR`, `BRAND_SURFACE_COLOR`, `BRAND_TEXT_COLOR`, `BRAND_FONT_FAMILY`, and `BRAND_HEADING_FONT_FAMILY`) override the corresponding YAML values, including explicit empty logo-text/path values. A `BRAND_LOGO_PATH` override disables logo upload/removal in the UI. To save any dashboard-backed YAML setting, the active `aggregate.yaml` file (or its symlink target) must be writable by the PHP process; the surrounding `config/` directory can remain read-only. YAML-only deployments do not need to grant write access.

After deploying a release that adds or changes branding services, rebuild the production container and Twig cache with `APP_ENV=prod APP_DEBUG=0 php bin/console cache:clear --env=prod --no-debug`. Reload long-running PHP workers when OPcache timestamp validation is disabled. A deployment verification can run `php bin/console debug:twig --filter=app_branding --format=json --env=prod --no-debug`; the result must contain a non-empty `app_branding` global.

Source deployments must also run `php bin/console importmap:install --env=prod --no-debug`
and `php bin/console asset-map:compile --env=prod --no-debug` after dashboard asset
changes. Cache clearing alone does not rebuild those assets. Prepared release
ZIPs include them. `app:updates:apply` runs these steps for Git checkouts, and
`app:updates:deployed` for code [deployed another way](UPDATES.md#deploy-the-code-another-way)
(for Plesk, see its [deployment actions](../PLESK-DEPLOYMENT.md#plesk-git-deployment-actions)).
Saving existing branding settings takes effect without an asset build.

### BI disclosure thresholds

The two BI disclosure thresholds are deployment configuration values in `config/aggregate*.yaml`:

- `anonymous_min_cell_count`: completed hourly cells in `bi_anonymous_events_v1`, completed daily cells in `bi_anonymous_daily_v1` and `bi_anonymous_daily_rollup_v1`, completed daily goal cells in `bi_anonymous_goals_v1`, and the smallest day `bi_anonymous_coverage_v1` reports (default `5`, range `2`–`1000`)
- `anonymous_geo_min_cell_count`: completed daily cells in `bi_anonymous_geo_events_v1` (default `25`, range `10`–`1000`)

The thresholds are written into the SQL of the views, so a new value takes effect when the views are regenerated. Saving the **BI disclosure** page writes YAML and regenerates them. After editing YAML directly, or setting an environment override, run:

```bash
php bin/console app:analytics:bi-views:regenerate --dry-run   # print the SQL
php bin/console app:analytics:bi-views:regenerate
```

Environment overrides (`ANONYMOUS_MIN_CELL_COUNT`, `ANONYMOUS_GEO_MIN_CELL_COUNT`) take precedence over YAML. An invalid value stops regeneration and leaves the existing views in place. PostgreSQL, MySQL, MariaDB and SQL Server replace each view in place, so the reporting account's grants are kept; SQLite drops and recreates them and has no grants.

`bi_page_groups` (no environment override) lists the [page groups](BI-CONNECTION.md#page-groups) used by `bi_anonymous_daily_v1` and `bi_anonymous_daily_rollup_v1`. It is managed on **Reporting → Page groups** or in YAML, and applied by the same regeneration.

### Reporting timezone

`reporting_timezone` (default `UTC`) is the IANA name compiled into the event views when they are regenerated. The **Reporting timezone** section of **Administration → General settings**, YAML, and `app:analytics:reporting-timezone` share `ReportingTimezone` and `AnonymousBiViewManager`. `REPORTING_TIMEZONE` overrides the saved value. An invalid name, a MySQL or MariaDB server without time zone tables, or an IANA name SQL Server cannot map stops regeneration and leaves the existing views in place. Load MySQL or MariaDB time zone tables with the one command in [reporting timezone](BI-CONNECTION.md#reporting-timezone), or `php bin/console app:analytics:mysql-timezones:load` when this server can administer the database.

The setting relabels `event_hour` and the event views' `event_day`. It does not change stored events, archives, goal days or geography days, which stay UTC. See [reporting timezone](BI-CONNECTION.md#reporting-timezone).

**Upgrading from database-stored thresholds.** Earlier releases kept the thresholds in an `analytics_privacy_settings` table. The migration that removes it rebuilds the views with the values it found there, but it does not write them to YAML. If you had changed either threshold from its default, copy the values into `config/aggregate.yaml` before you next save the BI disclosure page or run the command; otherwise the views return to the YAML values (the defaults when unset).

## Feature flags

Administrators use **Feature flags** at `/dashboard/feature-flags`, or operators
edit the active YAML:

```yaml
feature_flags:
  updates:
    enabled: true
    hide_from_navigation: false
  custom_scripts:
    enabled: true
    hide_from_navigation: false
  reporting_api:
    enabled: false
    hide_from_navigation: true
  mcp_server:
    enabled: false
    hide_from_navigation: true
```

Updates is enabled and visible by default. Disabling it blocks update routes and
commands; hiding removes navigation entries independently of availability.
`custom_scripts` (enabled by default) serves the tag manager's
[custom JavaScript tags](TAG-MANAGER.md#custom-javascript); disabling it keeps
their YAML but stops serving them. `reporting_api` (off and hidden by default)
turns on the [reporting API](REPORTING-API.md) and its **API tokens** page.
`mcp_server` (off and hidden by default) turns on the [MCP server](MCP.md) for
AI assistants and their sign-in.
A feature that is off and shown appears greyed out with an **Off** tag; hide it
when that would look like a problem. Saving on the Feature flags page writes
every feature with both options, so the YAML states what is on and what shows,
and the page displays that YAML. These settings have
no uppercase environment-variable overrides. Use YAML booleans; malformed or
unregistered flag settings make flagged features unavailable. An active
environment's entire flag mapping replaces the shared mapping. See the
[feature flag guide](FEATURE-FLAGS.md) for precedence, headless operation, and
developer/contributor instructions.

## Organization traffic

Every standard-profile event carries `org_internal_traffic`, `true` when the browser holds the organization marker on that website and `false` otherwise, stored in the existing event JSON under that fixed key; no migration is needed. The browser marker defaults to the cookie `orgInternalTraffic=true`. Configure `internal_traffic_storage`, `internal_traffic_name`, `internal_traffic_value`, `internal_traffic_cookie_domain`, and `internal_traffic_share_token` in the active YAML environment, or use the Organization traffic admin page; the marker's name never changes the JSON key.

Team members mark a browser through the marking links on the Organization traffic page or the share page: each link opens a tracked website, whose tracker saves the marker in that website's own storage. See [organization traffic](PRIVACY-COMPLIANCE.md#organization-traffic) for how marking links work, browser scope, installation-generated sharing tokens, downloadable marker pages, and Power BI/Tableau filtering. The private `analytics_custom_*` views show the flag as an `org_internal_traffic` column, a [built-in property](DATA-MODEL.md#built-in-properties) of the data model; existing grouped views and archives omit it.

## BI glossary

`bi_glossary` in the active aggregate YAML controls published locales and declared
value/column metadata. **Reporting → BI glossary** edits the same mapping; YAML
plus `php bin/console app:analytics:glossary:sync` also work with the dashboard
disabled. An environment file or `environments.<env>.bi_glossary` replaces the
whole mapping, and there are no uppercase environment-variable overrides.
Omitting the block publishes the built-in catalog, goal labels, registered
website names and domains (for `bi_dim_website_token_v1`), and saved custom
property descriptions in English after sync. Translate or relabel websites under
`bi_glossary.values.website_token`, and sync after adding, renaming or removing a
website. Invalid glossary metadata makes its
save/sync fail with field-level errors but does not affect tracker configuration,
ingestion, or the health configuration check. See the
[YAML reference and fallback rules](BI-GLOSSARY.md#yaml-reference).

Treat all declared codes, labels, and descriptions as published text. Declaring
values for consent-gated properties makes those codes visible to routine BI users
without granting access to the underlying private events. Never include personal
or identifying text. Goal default labels stay in `config/goals.yaml`; custom
column descriptions stay in `custom_data_properties`.

## Custom data and UTM parameters

Use **Collection → Data model** at `/dashboard/data-model` to define properties, opt into scalar types, whitelist selected keys for anonymous collection, and configure URL-parameter aliases. Separate linked pages provide observed-key discovery, saved-model examples with copy/download, and private reporting view regeneration. These mappings use active-environment precedence; uppercase environment-variable overrides are not supported for the model.

Standard `utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`, and `utm_id` map to properties with the same names and require enhanced consent by default. For anonymous attribution, prefer only `utm_medium` with broad channel values. This advice is overridable: select **Allow without consent** or set `consent_required: false` for any property. Detailed campaign data may contain search text or identifiers, and even medium values require review; the UI and [data model guide](DATA-MODEL.md) document this warning.

The guide includes complete YAML examples, many-to-one mappings, strict optional value types, additive numeric columns, the [built-in properties](DATA-MODEL.md#built-in-properties) `org_internal_traffic` and `page_sequence`, and `app:analytics:views:regenerate`. Saving the model on the Data model page changes future collection and updates the `analytics_custom_*` views over retained raw events; after a YAML edit, run `app:analytics:views:regenerate`. Existing grouped BI views and archives retain their contracts. Generate synthetic JSON through the UI or `app:analytics:examples`; the [event examples guide](EVENT-EXAMPLES.md) includes the flat ecommerce recipe and numeric calculations.

An optional [JavaScript minification build](JS-BUILD.md) produces the tracker and drop-in artifacts. Request `/aggregate.js?min=1` to use the built tracker with current public configuration; missing or stale builds fall back to configured source.

## Archiving and retention

Administrators can edit the nine lifecycle settings at `/dashboard/data-lifecycle`: the seven `analytics_*` settings below and the two record retention periods. API-only deployments can manage the same keys in `config/aggregate.yaml`. Uppercase environment variables (for example, `ANALYTICS_RETENTION_ENABLED`) take precedence and lock the corresponding UI controls. Values are validated strictly. When archiving and retention are both enabled, each raw retention period must be at least `analytics_archive_after_days`. Whenever retention is enabled, archive retention must be at least the longer raw retention period so marked source rows cannot disappear from reporting early.

Maintenance runs outside the web process, once a day, from the server's one [scheduled task](SCHEDULED-TASKS.md) (`app:scheduler:run`, every five minutes). Both features are disabled by default; inspect a dry run before enabling irreversible deletion:

When retention is enabled without archiving, eligible raw rows are deleted without first preserving aggregate history.

```bash
php bin/console app:analytics:maintain --dry-run
php bin/console app:analytics:maintain
```

Archiving creates private, unsuppressed aggregate cells and marks the source rows as archived; it does not itself delete raw rows. Retention deletes raw anonymous, raw enhanced, and archived aggregate data only after their configured periods. Backups, BI extracts, queues, failed messages, logs, and replicas need separate retention controls.

### Task and audit records

Background jobs (each BigQuery or reporting database view sync and each maintenance run) are recorded in
the private [`processing_tasks` and `audit_trail` tables](DATABASE.md#processing-tasks-and-audit-trail).
The [audit trail](AUDIT-TRAIL.md) also records administrator actions and, when
turned on, sign-ins; its job runs and its other entries have their own periods.
Maintenance purges them after these periods; `0` keeps them indefinitely. The
latest success and the latest failure of each job are always kept so schedules
and status pages still know when it last ran.

| Setting | Environment variable | Default | Range |
| --- | --- | --- | --- |
| `processing_tasks_retention_days` | `PROCESSING_TASKS_RETENTION_DAYS` | `90` | `0`–`36500` |
| `audit_trail_retention_days` | `AUDIT_TRAIL_RETENTION_DAYS` | `365` | `0`–`36500` (job-run entries) |
| `audit_trail_admin_retention_days` | `AUDIT_TRAIL_ADMIN_RETENTION_DAYS` | `730` | `0`–`36500` (administrator actions and sign-ins) |

What the audit trail records is chosen by area under `audit_trail_areas`, on
**Administration → Audit trail**; there is no environment variable for it. See
[turn areas on and off](AUDIT-TRAIL.md#turn-areas-on-and-off).

Purging is on by default, so set up the [scheduled task](SCHEDULED-TASKS.md)
even when archiving and retention are off. Its dry run shows how many records would be
purged, and each run records what it archived, deleted and purged.

## Sync

**Reporting → Sync** copies selected reporting views to another destination on
an interval. Each destination is a tab of the Sync page with its own address:
BigQuery at `/dashboard/bigquery` and the reporting database at
`/dashboard/reporting-database`. `/dashboard/sync` opens the destination that
is on. The menu entry appears once either destination is on, or its settings
need repair; until then, find **Sync** with the dashboard search, or follow the
link in the BI connection guide. See [main navigation](#main-navigation).

### BigQuery sync

The **BigQuery** tab at `/dashboard/bigquery` copies selected reporting
views to a BigQuery dataset on an interval. The `bigquery_*` keys in the active
YAML environment hold the same settings, with uppercase environment-variable
overrides that lock the matching fields; the [scheduled task](SCHEDULED-TASKS.md),
`app:bigquery:sync` and `app:bigquery:check` work with the dashboard disabled. Approved views
are selected under `bigquery_views`; row-level or unsuppressed views must be
listed separately under `bigquery_private_views`. Credentials are stored in
`config/secrets`. Signing in with Google needs the dashboard; headless
deployments use a service account key or Google Cloud credentials. See
[Sync to BigQuery](BIGQUERY.md) for every setting, the sign-in methods, column
types and privacy notes.

### Reporting database

The **Reporting database** tab at `/dashboard/reporting-database` copies
selected reporting views into same-named tables of a second database on an
interval, so BI users can connect there instead of to the application database.
The `reporting_database_*` keys in the active YAML environment hold the
settings, with uppercase environment-variable overrides that lock the matching
fields; the [scheduled task](SCHEDULED-TASKS.md), `app:reporting-database:sync`
and `app:reporting-database:check` work with the dashboard disabled. Approved views
are selected under `reporting_database_views`; row-level or unsuppressed views
must be listed separately under `reporting_database_private_views`.

The connection is the documented exception to keeping settings in YAML:
`REPORTING_DATABASE_URL` is infrastructure, like `DATABASE_URL`, and holds a
password, so it lives in `.env.local` or the server environment and has no YAML
key. The admin page and `app:reporting-database:configure` test a connection
and write it to `.env.local`, as the setup page does for `DATABASE_URL`; they
never show the password back. A value set in the server environment or a file
that overrides `.env.local` is left alone, and the page says where to change it. See [Reporting database](REPORTING-DATABASE.md) for the database
setup on each engine, every setting, column types and privacy notes.

## Reporting API and AI assistants

**Reporting → API tokens** at `/dashboard/api-tokens` (administrators only,
shown while the `reporting_api` or `mcp_server` feature flag is on) creates and
revokes tokens for the read-only [reporting API](REPORTING-API.md), lists and
revokes the AI assistants connected to the [MCP server](MCP.md), and holds the
settings both use:

```yaml
reporting_api_private_views: false   # may tokens read private (row-level) views?
reporting_api_max_page_size: 10000   # largest limit a request may use, 1 to 1,000,000
```

Uppercase environment variables of the same names take precedence and lock the
matching fields. Tokens are kept, hashed, in `config/secrets/api-tokens.yaml`,
and managed headlessly with `app:api:token:create` (`--mcp` lets AI assistants
use a token), `app:api:token:list` and `app:api:token:revoke`. Assistants that
signed in are kept in `config/secrets/mcp-oauth.yaml` and managed with
`app:mcp:connection:list` and `app:mcp:connection:revoke`.

## Transactional email

**Administration → Email** at `/dashboard/email` (administrators only) chooses
the service that sends Aggregate's own messages to its users (Postmark,
SendGrid, Mailgun, any SMTP server, or another Symfony Mailer transport), the
sender, and each service's credential, and sends a test message. Email is off
until `email_enabled` is true:

```yaml
email_enabled: false
email_provider: ''                  # postmark, sendgrid, mailgun, smtp or dsn
email_from_address: ''              # required once email is on
email_from_name: ''                 # empty: the brand name
email_reply_to: ''
```

Each service adds `email_<service>_*` keys, listed in
[config/aggregate.yaml.example](../config/aggregate.yaml.example). Uppercase
environment variables of the same names take precedence and lock the matching
fields. Credentials are not settings: they are kept in
`config/secrets/email.yaml`, or come from `EMAIL_POSTMARK_SERVER_TOKEN`,
`EMAIL_SENDGRID_API_KEY`, `EMAIL_MAILGUN_API_KEY`, `EMAIL_SMTP_PASSWORD` or
`EMAIL_DSN`, which take precedence over the file. `app:email:status` and
`app:email:test` work with the dashboard disabled. Each send is recorded as a
processing task and an audit entry, without the recipient.

`sign_in_codes_enabled: true` (`SIGN_IN_CODES_ENABLED`, or the same page) lets
users sign in with a code emailed to them, which also serves as a password
reset; it is off by default and offered only while email can be sent. See
[Transactional email](EMAIL.md) and [sign-in codes](EMAIL.md#sign-in-codes).

## Main navigation

Associate links with a registered flag using `feature: updates` (or another
registered key). The shared template honors its availability and
`hide_from_navigation` setting for each reference: a feature that is off and
shown appears greyed out with an **Off** tag. A page that serves several
features lists them, `feature: ['reporting_api', 'mcp_server']`: the link works
while any of them is on, and is hidden only when every feature that is on hides
it (with all of them off, when every one hides it). An unknown name, an empty
list or anything other than names hides the link. See
[feature navigation integration](FEATURE-FLAGS.md#add-a-feature-as-a-developer).

A link can also state when it appears with `shown_when` and a condition name,
so the YAML says why an entry is missing instead of the menu leaving it out
silently. The menu leaves the link out until the condition is met; the
dashboard search still finds it, so the page can be opened to turn it on.

| Condition | Met when |
| --- | --- |
| `view_sync_on` | BigQuery sync or the reporting database sync is turned on, or either one's settings are invalid and need its page |

An unknown condition name hides the link, like an unknown feature name.
`shown_when` belongs on links; a group that sets it is omitted, and a group
disappears by itself once none of its links are shown. Conditions are presentation
only and never grant access.

The `brand` entry controls where the branded identity links. Its `label` remains a backward-compatible name/wordmark fallback for existing deployments. Runtime identity, logo, theme-color, and font settings live in `config/aggregate.yaml` (or their environment overrides), so UI changes do not require rebuilding navigation configuration. Edit `items` and `account` to manage the remaining authenticated-navbar links. Each link defines exactly one Symfony `route` name (for example, `app_how_it_works`), literal `url`, or documentation topic (`docs`, see [documentation links](#documentation-links)); `label` is required, while `icon`, `route_parameters`, and an optional security `role` are supported. A link to a dashboard page also appears only for users who can view that page ([users and permissions](USERS-AND-PERMISSIONS.md)), so the shipped menu names a role only for Users. Existing flat configurations and an empty `items` list remain supported.

Top-level items can instead group links under `children`. A group has a label
and optional icon/role/feature, with no route or URL of its own:

```yaml
parameters:
  app.main_navigation:
    brand: { label: 'Analytics', route: app_home }
    items:
      - label: Collection
        children:
          - { label: 'Data model', route: app_data_model }
      - label: Reporting
        children:
          - { label: Sync, route: app_sync, shown_when: view_sync_on }
      - label: Administration
        children:
          - { label: Users, route: app_users, role: ROLE_ADMIN }
      - label: Help
        children:
          - { label: 'How it works', route: app_how_it_works }
          - { label: 'Event examples', route: app_event_examples }
          - { label: Documentation, docs: home }
    account:
      user_icon: 'fas fa-user'
      logout: { label: Logout, route: app_logout }
```

One submenu level is supported, with at most 32 top-level entries and 32 children
per group. Parent and child role/feature restrictions both apply. Hidden or
inaccessible children are removed before empty groups disappear; a disabled
visible group makes its children unavailable. Invalid entries, executable URLs,
and missing enabled routes are omitted. Local/relative/fragment and HTTP(S)
destinations are supported. Application logout links retain CSRF protection.

Submenus use native disclosures that work with keyboard, touch, and without
JavaScript. Navigation is presentation only: it cannot grant access to routes.
After changing navigation in production, clear the production cache so the
container and Twig globals are rebuilt.

## Standalone consent settings

The optional independent banner uses `standalone_consent` in
`config/tag-manager/sites/<site-id>.yaml`. Find instance IDs with
`php bin/console app:tag-manager:sites`. In **Setup → Install scripts**, select
**Standalone banner + optional Formspree requests**, then **Configure standalone
option** to edit that mapping. Downloads include configured JavaScript and YAML.
This source is separate from `consent_manager` and `tag_manager`; its save preserves
those mappings and unrelated settings. It has no uppercase environment-variable
overrides. The site's environment-specific file takes precedence, or the active
`environments.<env>.standalone_consent` replaces the whole mapping.

```yaml
standalone_consent:
  name: Example website
  privacy_policy_url: 'https://www.example.com/privacy'
  formspree_endpoint: ''
  categories: [analytics, functional, marketing]
  respect_gpc: true
  consent_lifetime_days: 180
  revision: '1'
```

| Key | Validation and default |
| --- | --- |
| `name` | Defaults to the registered website's name; nonempty, at most 120 UTF-8 bytes |
| `privacy_policy_url` | Empty or an absolute HTTPS URL without credentials; default empty |
| `formspree_endpoint` | Empty or exactly `https://formspree.io/f/` plus an alphanumeric ID; empty disables the request form |
| `categories` | 1–10 unique category names including `analytics`; 1–32 lowercase letters, digits, underscores or hyphens, beginning with a letter; `none` and prototype keys reserved |
| `respect_gpc` | YAML boolean; default `true`; active GPC denies marketing and sets do-not-sell, never grants analytics |
| `consent_lifetime_days` | Integer 1–365; default 180; an operational review interval, not a legal consent lifetime |
| `revision` | Nonempty string up to 64 UTF-8 bytes; default `'1'`; change when purposes/notice change |
| `text` | Optional wording overrides, as plain text; keys and limits in the [standalone guide](../micro-consent-dropins/README.md#wording-colors-and-buttons) |
| `theme` | Optional `#RRGGBB` colors: `background`, `text`, `accent`, `border`, `button_background`, `button_text`, `button_border`; contrast is checked |
| `buttons` | Optional `show` (from `reject`, `accept`, `manage`; `reject` required) and `reopen` (`bottom-right`, `bottom-left` or `hidden`) |
| `layout` | `card` (default) or `bar`, a full-width bar across the bottom of the window |
| `external_tags` | Optional list of up to 30 tags the website loads itself: a unique `id`, the `name` visitors see and a `category` from `categories`; see [layout and other tags](../micro-consent-dropins/README.md#layout-and-other-tags) |

Text is trimmed and rejects control characters. The built-in banner's
equivalent settings are `consent_manager` in the same file, edited on the
**Consent manager lite** page; see the [consent manager guide](CONSENT-MANAGER.md).
The browser receives camelCase keys through `window.MicroConsentConfig`; its site-specific storage key is
`micro_consent_v2:<site-id>`. The configured script uses the deployment's tracker
namespace in its optional adapter. No application secret or sharing token is
included. Downloaded scripts are configuration snapshots.

The hosted bundle at `/standalone-cmp/sites/<site-id>/consent.js` contains UI, CSS
and the tracker/tag-manager bridge. It works with the dashboard disabled. The
independent static core needs no server and can omit both optional adapters; see
[the standalone guide](../micro-consent-dropins/README.md).

These controls are not a region detector or legal certification. The
[regional examples](CONSENT-REGIONS.md) all use a conservative initial script gate.
Enhanced denial alone leaves anonymous SDK measurement possible, and already
loaded scripts need withdrawal/cleanup handling. Enabling Formspree makes it a
recipient of submitted request information; a successful submission does not
complete the operator's rights-request duties.

## Administration pages

The default navigation separates collection, reporting configuration, and
administration. Each settings page loads the data needed for its own task.

| Page | Route | Purpose |
| --- | --- | --- |
| Websites | `/dashboard` | Website registrations and expandable integration snippets |
| General settings | `/dashboard/settings` | Application host, documentation links, tracker namespace, ingestion rate limit, reporting timezone, page speed |
| Branding | `/dashboard/branding` | Identity, logos, colors, typography |
| Collection controls | `/dashboard/collection` | Collection switch, exclusions, optional local geography |
| Setup | `/dashboard/setup` | Guided installation, separate built-in/standalone/external consent choice, script copy/download, optional minification |
| Standalone consent settings | `/dashboard/setup/standalone/<site-id>` | Independent per-site banner settings and optional Formspree request endpoint |
| Tag manager | `/dashboard/tag-manager` | Per-website tags that load scripts, send Aggregate events, call library methods or run custom JavaScript, with consent, triggers, variables and YAML downloads |
| BI disclosure | `/dashboard/privacy` | YAML-backed event and geography thresholds with BI view regeneration |
| Users | `/dashboard/users` | Administrators and standard users: create, change access, reset passwords, delete; the **Permission groups** tab manages roles ([guide](USERS-AND-PERMISSIONS.md)) |
| Your profile | `/dashboard/profile` | Every user's display name, email, password, and a read-only list of their access |
| Data model | `/dashboard/data-model` | Properties, types, consent, query mappings |
| Event examples | `/dashboard/data-model/examples` | Synthetic JSON generation, copy, download, and ecommerce recipe |
| Observed properties | `/dashboard/data-model/discovery` | Explicit bounded metadata discovery |
| Reporting views | `/dashboard/data-model/reporting` | Saved SQL preview and explicit regeneration |
| Sync | `/dashboard/sync` | Opens the destination that is on; each destination is a tab |
| Sync: BigQuery | `/dashboard/bigquery` | Sign-in, views, schedule and status of the BigQuery copy |
| Sync: Reporting database | `/dashboard/reporting-database` | Connection (tested, saved to `.env.local`), views, schedule and status of the reporting database copy |
| API tokens | `/dashboard/api-tokens` | Reporting API tokens, AI assistant connections and their settings, administrators only ([API guide](REPORTING-API.md), [MCP guide](MCP.md)) |
| Email | `/dashboard/email` | The service that sends transactional email, the sender, credentials, a test message and the last sends, administrators only ([guide](EMAIL.md)) |
| Data lifecycle | `/dashboard/data-lifecycle` | Archiving, retention, task and audit record purging, and the last maintenance run |
| Data transfer | `/dashboard/data-transfer` | Export, import, or copy collected rows; the last run comes from the processing task and audit trail ([guide](DATA-TRANSFER.md)) |
| Scheduled tasks | `/dashboard/scheduled-tasks` | Whether the server runs `app:scheduler:run`, the exact Plesk, cron or systemd settings for this server, each job's last and next run, and **Run due jobs now** ([guide](SCHEDULED-TASKS.md)) |

Administrators can use every page. Standard users can use the pages an
administrator granted them, at view or edit, for the websites they were granted;
Users, permission groups, API tokens and email stay with administrators, and every
user has their own profile page. Every dashboard route needs the page and level listed in
`App\Security\Access\DashboardPages`; see
[how access is enforced](USERS-AND-PERMISSIONS.md#how-access-is-enforced).
Existing POST paths remain available and return to the page that owns the form.
Dashboard-disabled deployments omit these UI routes while keeping shared YAML
services and CLI commands.

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

This is an allowlist warning, not a sensitive-data detector. Review every configured code and its use in context. After changing `config/goals.yaml` in production, clear the production cache so the service container is rebuilt, then run `php bin/console app:analytics:glossary:sync --env=prod` to publish updated labels. Prefer disabling retired goals to deleting their definitions so historical codes keep their labels.

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

## Documentation links

Each dashboard page links to its section of the documentation site, and
command-line messages about updates name the relevant page. The site's address
is `documentation_url`: set it under **Administration → General settings**, in
`config/aggregate.yaml`, or with the `DOCUMENTATION_URL` environment variable,
which takes precedence and disables the field.

```yaml
# Default: the project's public site, built from the master branch.
documentation_url: "https://subschema-llc.github.io/aggregate/"
# Your own copy or a white-labeled site with the same page paths:
# documentation_url: "https://docs.example.com/analytics/"
# No links to external documentation:
# documentation_url: ""
```

The value must be a full `https://` (or `http://`) address without a query
string, fragment or credentials; a trailing `/` is added. Page paths such as
`operate/updates#switch-methods` are appended to it, so a replacement site needs
the same paths: build it from this repository's `website/` folder
([documentation site](../CONTRIBUTING.md#documentation-site)). An empty value
hides links to external documentation. The public pages at `/how-it-works` stay
available, the Updates page links to the update guide in the update source
instead, and command-line messages name the documentation file in the
application folder, such as `docs/UPDATES.md`. An invalid value also hides the
links.

The public site describes the latest release. An installation that has not been
updated may find features described that it does not have yet; the
documentation files in its own folder match its version.

In a customized `navigation.local.yaml`, `docs: <topic>` links a menu entry to
the site and hides it when documentation links are off. Topics are listed in
`App\Service\DocumentationLinks::TOPICS`, for example `home`, `tracking.setup`,
`reporting.connect` or `updates`.

## Customizing the JavaScript Namespace

Set `js_namespace` in `config/aggregate.yaml`, in the web installer or under
**General settings**:

```yaml
js_namespace: "Company1Analytics"
```

It must be a plain JavaScript name: letters, digits, `_` and `$`, not starting
with a digit, up to 64 characters. The installer and the settings page refuse
anything else. An invalid value set another way, in the YAML or with
`JS_NAMESPACE`, is ignored, and the tracker, snippets, examples and tag manager use `Aggregate`
until it is corrected; the settings page says when that happens.

Or override per-script tag:

```html
<script src="https://your-host/aggregate.js" data-namespace="Company1Analytics" referrerpolicy="no-referrer"></script>
```

Then use your custom namespace:
```javascript
window.Company1Analytics.setConsent(true);
window.Company1Analytics.emit({eventName: 'signup', customData: {plan: 'pro'}});
```

The namespace also names the [tracking attributes](TRACKING.md#track-clicks-and-forms-with-data-attributes),
lowercased: `data-company1analytics-event`, `data-company1analytics-goal` and
`data-company1analytics-prop-<key>`. Update marked HTML when changing it.

## Environment Variable Override

### GitHub update checks

Aggregate updates **with release ZIPs** (recommended) or **from the repository**
(a Git clone in the application directory; advanced), or reports on code
**deployed another way** by your own tool. An administrator chooses the method;
see the [update guide](UPDATES.md). Three settings control updates:

```yaml
updates_method: release                       # release (recommended), repository (advanced) or deployment
updates_branch: master
updates_repository: Subschema-LLC/aggregate   # GitHub owner/name
```

`updates_method` can also be chosen on the Updates page or with
`php bin/console app:updates:method release|repository|deployment`. While it is unset, the
dashboard does not install updates and the command line uses the method that fits
the directory: repository for a Git clone, release ZIPs otherwise. An invalid value
stops updates until it is corrected. `updates_branch` can also be saved on the
Updates page. `updates_repository` is
YAML-only, because changing where application code comes from is a deployment
decision. A different repository (for example a fork) must publish releases signed
with a key this installation trusts through `updates_signing_public_key`, and its
release manifests must name that repository.

When the `updates` feature flag is enabled, the admin **Updates** page and `app:updates:check` use the
configured repository, by default the public `Subschema-LLC/aggregate`. These
settings have no uppercase environment-variable override. Git installations
compare commits against that branch, and source pulls require the checkout to
already be on it. Official ZIP installations use their embedded `release.json`
and stable GitHub Releases without requiring Git. Checks never switch branches
or install anything; `app:updates:apply` installs updates. For Git updates that
change dependencies, it uses `composer` on `PATH`, a `composer.phar` in the
application directory, or the path in the `AGGREGATE_COMPOSER` environment variable. Optionally set
`AGGREGATE_GITHUB_TOKEN` in the server environment or untracked `.env.local` for
private-repository API access or higher rate limits. Keep this credential out of
tracked YAML and browser configuration. Git pulls use the deployment user's
Git credentials separately. See the [update guide](UPDATES.md) for checking and
applying updates, including headless use.

Offline package verification uses the shipped `config/release-signing.pub` or a
YAML `updates_signing_public_key` override. Both contain only a base64 Ed25519
public key obtained independently of the package being verified. Publishing keys
and the maintainer-only `config/release.yaml` branch are described in the
[release guide](RELEASES.md).

### Application settings

App-specific settings from `aggregate.yaml` can be overridden with environment variables:

```bash
export JS_NAMESPACE="MyCustomAnalytics"
export TRACKER_OMIT_UNUSED_FEATURES="0"
export DOCUMENTATION_URL="https://docs.example.com/analytics/"
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
export APP_HOST="https://analytics.example.com"
export RATE_LIMIT_PER_MINUTE="100"
export WEBSITE_ACTIVITY_ACTIVE_DAYS="1"
export WEBSITE_ACTIVITY_STALE_DAYS="3"
export COLLECTION_PROFILE="strict"
export BOT_TRAFFIC="drop"
export ANONYMOUS_TRACKING_ENABLED="0"
export ANONYMOUS_GEO_ENABLED="0"
export ANONYMOUS_GEO_LEVEL="macro_region"
export ANONYMOUS_GEO_DATABASE_PATH="/var/lib/geoip/GeoLite2-Country.mmdb"
export ANONYMOUS_EXCLUDED_PATHS="/account/**,/checkout/**"
export TRACKING_RETRY_ENABLED="1"
export TRACKING_RETRY_BATCH_SIZE="100"
export ANALYTICS_ARCHIVING_ENABLED="1"
export ANALYTICS_ARCHIVE_AFTER_DAYS="90"
export ANALYTICS_RETENTION_ENABLED="0"
export ANALYTICS_ANONYMOUS_RETENTION_DAYS="365"
export ANALYTICS_ENHANCED_RETENTION_DAYS="90"
export ANALYTICS_ARCHIVE_RETENTION_DAYS="730"
export ANALYTICS_MAINTENANCE_BATCH_SIZE="1000"
export PROCESSING_TASKS_RETENTION_DAYS="90"
export AUDIT_TRAIL_RETENTION_DAYS="365"
```

Each variable is the setting's name in uppercase. List settings such as
`ANONYMOUS_EXCLUDED_PATHS` take comma-separated values, and switches accept
`1`/`0` or `true`/`false`. Other features have their own variables, described
with them: the [organization-traffic marker](PRIVACY-COMPLIANCE.md#organization-traffic)
(`INTERNAL_TRAFFIC_*`), [BigQuery](BIGQUERY.md) (`BIGQUERY_*`), the
[reporting database](REPORTING-DATABASE.md) (`REPORTING_DATABASE_*`), the
[BI disclosure thresholds](#bi-disclosure-thresholds) and the
[reporting timezone](BI-CONNECTION.md#reporting-timezone).

Pages that manage a setting with a variable either show it as fixed or refuse
a change that would not take effect. **General settings** shows this only for
the documentation address and the page speed option, and **Collection
controls** only for the collection profile and bot traffic. For the other
variables above, a value saved on those pages is stored in YAML but has no
effect while the variable is set.
