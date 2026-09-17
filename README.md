# Headless Privacy Analytics

Measure events without assigning every visitor an identity.

Self-hosted web analytics with privacy-minimized anonymous-mode events, optional consent-based enhanced analytics, and reporting views for Power BI, Tableau, and other BI tools.

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](LICENSE)
[![Tracker: BSD-3](https://img.shields.io/badge/tracker-BSD--3--Clause-green.svg)](js/LICENSE.txt)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777BB4.svg)](https://php.net)
[![CI](https://github.com/Subschema-LLC/aggregate/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/Subschema-LLC/aggregate/actions/workflows/ci.yml)

![Dashboard screenshot](docs/images/aggregate_logo_alt4.png)

Most privacy-first analytics tools still build a pseudonymous identity for every visitor — typically a rotating hash of IP address, user agent and domain. Better than a cookie. Still an identifier.

Aggregate's anonymous mode creates no visitor identifier. By default, an anonymous event holds a sanitized path, a coarse referrer channel, device and viewport buckets, and a UTC hour. It stores no visitor or session ID, IP address, User-Agent string, or exact event timestamp. Optional goals, coarse geography, organization markers, and explicitly allowlisted properties can add context; their values still need review for identifying detail.

When you need more, enhanced mode adds identifiers, properties and exact dimensions — but only for visitors who have made an affirmative choice, and it stops the moment they withdraw it.

Once you start collecting data, connect Power BI, Tableau, or Looker to the approved aggregate reporting views.

![Dashboard screenshot](docs/images/dashboard.png)

## What you get

**Privacy-minimized event collection.** Page views and named events, stored with sanitized
paths, coarse dimensions and hour buckets. Emails, UUIDs and numeric route IDs are stripped
out of paths before storage. Optional allowlisted conversion goals and continent- or
country-level geography. Keep raw events private and use the thresholded reporting views
for routine BI access.

**Suppression in the view, not the dashboard.** `bi_anonymous_events_v1` and its siblings
withhold the current bucket and hide any cell below your configured minimum. The threshold
lives in the database and applies to every consumer — nobody gets a thin cell by connecting
a different tool.

**Consent that actually toggles something.** `setConsent(true)` turns on visitor and session
IDs, custom properties and exact dimensions. `setConsent(false)` drops the identifiers and
returns to coarse rows. Wire it to your CMP and the behavior matches what the banner
promised.

**Optional administration UI.** Operate collection, setup, and maintenance through
YAML and CLI commands; set `DASHBOARD_ENABLED=0` to disable dashboard/login routes.
BI disclosure thresholds currently require the admin UI or controlled database
administration; see [configuration sources](docs/CONFIGURATION.md). Visualize
analytics in your BI tool; the application UI manages the installation and its data contracts.

**Rebrandable without a fork.** Name, logo, colors and fonts are configuration. Contrast
ratios are validated, so an unreadable palette gets rejected rather than shipped.

**Boring infrastructure.** PHP 8.2+ on PostgreSQL, MySQL, MariaDB, SQL Server or SQLite.
A 2 GB VPS or shared hosting is enough. No ClickHouse, no Kafka, no warehouse.

**A way to exclude your own team.** Mark staff browsers with a configurable cookie or local
storage flag and filter them out in your BI tool, without dropping the data or trusting an
IP range.

Details:
- **Privacy-minimized collection:** page views and safe named events, with optional allowlisted goals and coarse local geography.
- **Reporting views with suppression:** completed hourly or daily aggregates with configurable minimum event counts.
- **Consent-based enhanced detail:** visitor/session IDs, properties, and exact dimensions when enabled by your consent manager.
- **Headless operation:** an ingestion API, YAML configuration, and CLI commands, with an optional admin dashboard.
- **Organization traffic markers:** mark team browsers with a configurable cookie or local storage entry, defaulting to `orgInternalTraffic=true`, and filter retained event JSON in BI reports.
- **Configurable branding and lifecycle:** dashboard name, logo, colors, fonts, archiving, and retention policies.
- **Database choice:** PostgreSQL, MySQL, MariaDB, SQL Server, or SQLite. Enhanced ingestion uses Symfony Messenger with synchronous or asynchronous delivery.

## What you give up

This matters more than the feature list, so it's up front.

**No unique visitors, sessions or bounce rate in anonymous mode.** Not because they haven't
been built yet, but because computing them requires exactly the identifier this project
refuses to create. Anonymous mode counts events. One person can contribute several of them
to the same cell.

**Small numbers disappear.** A cell below your threshold is withheld, and widening the time
window in your BI tool won't recover it. Low-traffic sites will see gaps until they adjust
the threshold or report over longer periods.

**Reports lag one bucket.** The current UTC hour is never released for events; the current
UTC day is never released for goals and geography. There is no real-time view.

**"Anonymous" is the name of a mode, not a legal conclusion.** Rare paths, unusual event
names, small populations and outside information can still make a row personal in context.
Withdrawing consent is prospective — it stops future enhanced detail, it does not erase
what the server already holds. The
[compliance guide](docs/PRIVACY-COMPLIANCE.md) is specific about where the line sits and
what remains your responsibility.

This project suits teams that want self-hosted event measurement feeding their existing BI tools. It does not provide session replay, heatmaps, or a built-in marketing attribution suite.

## Is this for you?

**Likely yes** if you answer to a privacy office or DPO, you run a public-sector, health,
education or legal site, you already own a BI stack and want measurement to feed it rather
than compete with it, or you want to be able to explain your whole data model on one page.

**Likely no** if you need unique-visitor counts, funnels, session replay, heatmaps or
marketing attribution. [Plausible](https://plausible.io) and [Matomo](https://matomo.org)
are good software and will serve you better. This isn't trying to win that comparison.

## Quick start

For this development setup, install PHP 8.2+, Composer, the required PHP extensions and database driver, Docker Compose, and Make. The [deployment guide](DEPLOYMENT.md) covers native and production installation; [CONTRIBUTING.md](CONTRIBUTING.md#set-up-a-development-checkout) covers development prerequisites in detail.

From a fresh checkout:

```bash
git clone --branch development https://github.com/Subschema-LLC/aggregate.git
cd aggregate
cp .env.dev .env
cp config/aggregate.yaml.example config/aggregate.yaml
composer install
make start-mysql
make migrate-mysql
```

The checked-in Compose setup serves **http://localhost:9001**. Set the active YAML environment's `app_host` to that URL for local integration snippets. Open `/install` to create a dashboard administrator, then register a website in the dashboard or through the CLI:

```bash
docker compose exec php php bin/console app:create-website
curl http://localhost:9001/api/health
```

Use `start-postgres` / `migrate-postgres` or `start-mariadb` / `migrate-mariadb` for another Docker profile. The sample environment is for development; use your own secrets and connection settings in production. Existing installations should follow the [upgrade guidance](docs/PRIVACY-COMPLIANCE.md#upgrading-older-installations) before applying historical privacy migrations.

Admins can check **Updates**, or run `php bin/console app:updates:check --refresh`. The YAML setting `updates_branch` defaults to `master`. Git installations support clean fast-forward source pulls with `app:updates:pull`, followed by the [deployment steps](DEPLOYMENT.md#updates). Official ZIP installations use version metadata to check public GitHub Releases without Git; see [signed release packages](docs/RELEASES.md).

The **Feature flags** admin page and YAML can disable Updates or hide its
navigation entries. Updates stays enabled by default. See the
[feature flag guide](docs/FEATURE-FLAGS.md) for configuration and contributor examples.

## Add tracking to a website

Use the website token from your registration and your analytics host:

```html
<script>
  window.Aggregate = {
    endpoint: 'https://analytics.example.com/api/receive',
    websiteToken: 'your-website-token'
  };
</script>
<script src="https://analytics.example.com/aggregate.js" async referrerpolicy="no-referrer"></script>
```

The tracker sends a page view automatically. Once it has loaded, record a named event with `window.Aggregate.emit('button_click')`. Configure goals and review event properties before using them.

Connect your consent manager to `window.Aggregate.setConsent(true)` only after an affirmative choice, and call `setConsent(false)` for rejection or withdrawal. The [tracking and GTM guide](docs/TRACKING.md) covers custom events, tag setup, consent wiring, and troubleshooting.

## Configuration and reporting

| Setting | Source of truth |
| --- | --- |
| Database, transport, app secret, proxy trust | Symfony environment files or server environment variables |
| Branding, collection controls, organization markers, lifecycle, dashboard toggle | `config/aggregate.yaml` and supported environment overrides |
| Custom property model, UTM/query mappings, consent requirements, reporting columns | `config/aggregate.yaml`, managed through the Data model admin page or YAML |
| Website domains and public ingestion tokens | `config/websites.yaml`, managed through the dashboard or CLI |
| Conversion-goal definitions | `config/goals.yaml` |
| Navigation labels and links | `config/navigation.yaml` |
| BI disclosure thresholds | `analytics_privacy_settings` in the database; dashboard or controlled database administration |

For headless deployments, set `dashboard_enabled: false` in the active YAML environment and `DASHBOARD_ENABLED=0`, then clear Symfony's cache. BI thresholds remain database settings; they are not mirrored in YAML. The [configuration reference](docs/CONFIGURATION.md) explains defaults, overrides, branding, goals, organization markers, and retention.

Routine BI connections should use approved views:

| View | Reports |
| --- | --- |
| `bi_anonymous_events_v1` | Hourly page views and named events |
| `bi_anonymous_goals_v1` | Daily occurrences of configured goals |
| `bi_anonymous_geo_events_v1` | Daily coarse geography with additional suppression |

Keep raw `events`, archive tables, and unsuppressed operational views private. Organization markers are stored under their configured name in raw event JSON; the grouped views and archives omit that flag. See the [compliance guide](docs/PRIVACY-COMPLIANCE.md#bi-exposure-and-suppression) for access and disclosure rules, [organization traffic](docs/PRIVACY-COMPLIANCE.md#organization-traffic) for filtering, and the [database guide](docs/DATABASE.md) for connections and schema details.

The [Data model](docs/DATA-MODEL.md) page provides UTM/query mappings, configurable anonymous property whitelists, downloadable YAML, and UI/CLI regeneration of private custom reporting views. All UTMs require consent by default. Anonymous attribution should use at most a broad `utm_medium`; administrators can override this recommendation with the documented warning about more detailed values.

Use the optional [JavaScript build](docs/JS-BUILD.md) for minified tracker and drop-in scripts.

## Documentation

| Guide | Contents |
| --- | --- |
| [Contributing](CONTRIBUTING.md) | Branching strategy, development setup, architecture, privacy invariants, tests, Make commands, and pull requests |
| [Agent guide](AGENTS.md) | Product ethos, architecture boundaries, privacy rules, and development expectations for coding agents |
| [Roadmap](ROADMAP.md) | Planned work, available foundations, and current update limitations |
| [Security policy](SECURITY.md) | Private vulnerability reporting and disclosure guidance |
| [Code of conduct](CODE_OF_CONDUCT.md) | Community expectations and reporting concerns |
| [Public release preparation](docs/PUBLIC-RELEASE.md) | Maintainer reporting setup, Git history review, GitHub checks, and launch steps |
| [Privacy and compliance](docs/PRIVACY-COMPLIANCE.md) | Measurement limits, consent, organization traffic, BI suppression, logging, retention, and operator checks |
| [Configuration](docs/CONFIGURATION.md) | YAML settings, environment overrides, branding, goals, and lifecycle policy |
| [Feature flags](docs/FEATURE-FLAGS.md) | YAML/admin controls, navigation visibility, and developer/contributor guidance |
| [Data model](docs/DATA-MODEL.md) | UTM/query mappings, anonymous property whitelists, model sharing, and custom reporting columns |
| [JavaScript build](docs/JS-BUILD.md) | Optional minification, dynamic tracker configuration, and build verification |
| [Release packages](docs/RELEASES.md) | Publishing from master, signing keys, installable ZIPs, verification, and update groundwork |
| [Tracking and GTM](docs/TRACKING.md) | Browser integration, custom events, tag-manager examples, and troubleshooting |
| [Deployment](DEPLOYMENT.md) | Docker/native setup, web servers, workers, production operations, and upgrades |
| [Plesk deployment](PLESK-DEPLOYMENT.md) | Shared-hosting setup and worker options |
| [Database](docs/DATABASE.md) | Supported engines, connection strings, migrations, and reporting schema |

## Contributing

Create a working branch from the latest `development`, using a descriptive name
such as `feature/add-goal-validation`, `issue/123-fix-consent`, `docs/update-setup`,
or `chore/update-dependencies`. Changes move through pull requests in this order:

1. **Working branch → `development`:** contributors submit changes for review and integration.
2. **`development` → `uat`:** maintainers promote changes for user acceptance testing (UAT).
3. **`uat` → `master`:** maintainers promote accepted changes for production release.

Keep contributor PRs targeted at `development`; `uat` and `master` receive the
promotion PRs above. See the [branching strategy](CONTRIBUTING.md#branching-strategy)
for branch roles and PR targets, and the [release guide](docs/RELEASES.md#publish-a-release)
for tagging and publishing from `master`.

Contributions should preserve the privacy invariants and favor simple, portable,
secure, maintainable designs. Start with [CONTRIBUTING.md](CONTRIBUTING.md).

Contributions developed with AI coding agents are welcome. Bring your own expertise and judgment to the collaboration: provide project context, guide the agent's decisions, and review and test the result. Please submit changes you understand and can explain, including how they fit Aggregate's architecture and privacy goals. The contributor remains responsible for the work they submit.

Follow the [code of conduct](CODE_OF_CONDUCT.md), review the [roadmap](ROADMAP.md) before proposing substantial work, and use the [security policy](SECURITY.md) for private vulnerability reports.

## License

The license split is:

- **Browser tracker:** [public/aggregate.js](public/aggregate.js) is licensed under **BSD-3-Clause**, with the full text in its header and [js/LICENSE.txt](js/LICENSE.txt). This file is also the tracker source; there is no separate build source. The configured script served at `/aggregate.js` carries the same BSD license.
- **Code of Conduct:** [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md) adapts Contributor Covenant 2.1 under **CC BY 4.0**, with source attribution and license links in that file.
- **Everything else in this project's first-party code and documentation:** **GNU AGPL version 3 only (AGPL-3.0-only)**, under [LICENSE](LICENSE). This includes the server, dashboard, and other JavaScript; the tracker exception does not change their license.

Third-party dependencies and vendored assets retain their own licenses, including the vendored [Bulma MIT notice](assets/styles/vendor/bulma/LICENSE).
