# Headless Privacy Analytics

Analytics with nothing to anonymize.

Self-hosted web analytics with privacy-minimized anonymous-mode events, optional consent-based enhanced analytics, and reporting views for Power BI, Tableau, and other BI tools.

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](LICENSE)
[![Tracker: BSD-3](https://img.shields.io/badge/tracker-BSD--3--Clause-green.svg)](js/LICENSE.txt)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777BB4.svg)](https://php.net)

Most privacy-first analytics tools still build a pseudonymous identity for every visitor — typically a rotating hash of IP address, user agent and domain. Better than a cookie. Still an identifier.

Aggregate never creates one. An anonymous-mode row holds a sanitized path, a coarse referrer channel, device and viewport buckets, and a UTC hour. No visitor ID, no session ID, no cookie, no IP, no user agent, no exact timestamp. There is no de-identification step because nothing identifying is collected in the first place.

When you need more, enhanced mode adds identifiers, properties and exact dimensions — but only for visitors who have made an affirmative choice, and it stops the moment they withdraw it.

Once you start collecting data, just point Power BI, Tableau, or Looker at the reporting views and go.

By default, Aggregate Analytics records events without visitor IDs, session IDs, or IP-derived fingerprints. Anonymous rows contain sanitized paths, coarse dimensions, and server-generated UTC hour buckets. Enhanced mode adds identifiers and detailed properties only after an affirmative consent choice.

![Dashboard screenshot](docs/images/dashboard.png)

## What you get

**Rows you could hand to a stranger.** Page views and named events, stored with sanitized
paths, coarse dimensions and hour buckets. Emails, UUIDs and numeric route IDs are stripped
out of paths before storage. Optional allowlisted conversion goals and continent- or
country-level geography.

**Suppression in the view, not the dashboard.** `bi_anonymous_events_v1` and its siblings
withhold the current bucket and hide any cell below your configured minimum. The threshold
lives in the database and applies to every consumer — nobody gets a thin cell by connecting
a different tool.

**Consent that actually toggles something.** `setConsent(true)` turns on visitor and session
IDs, custom properties and exact dimensions. `setConsent(false)` drops the identifiers and
returns to coarse rows. Wire it to your CMP and the behavior matches what the banner
promised.

**No UI lock-in.** Everything the dashboard does, YAML and the CLI do. Set
`DASHBOARD_ENABLED=0` and you have a pure ingestion API with no login surface at all.

**Rebrandable without a fork.** Name, logo, colors and fonts are configuration. Contrast
ratios are validated, so an unreadable palette gets rejected rather than shipped.

**Boring infrastructure.** PHP 8.2+ on PostgreSQL, MySQL, MariaDB, SQL Server or SQLite.
A 2 GB VPS or shared hosting is enough. No ClickHouse, no Kafka, no warehouse.

**A way to exclude your own team.** Mark staff browsers with a configurable cookie or local
storage flag and filter them out in your BI tool, without dropping the data or trusting an
IP range.

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

Anonymous mode counts **events, not unique people**. It cannot provide identifier-based sessions, unique visitors, or bounce rates. One person can contribute enough events to pass a reporting threshold.

Low-volume cells are withheld. Event reports exclude the current UTC hour; goal and geography reports exclude the current UTC day. Combining already-suppressed results into a larger time window does not recover hidden cells.

“Anonymous” names the product mode, not a legal guarantee. Rare paths, event names, small populations, and outside information can still make data personal in context. Rejecting enhanced analytics removes browser identifiers and stops future enhanced details; coarse measurement continues and past server data is not erased. See the [privacy and compliance guide](docs/PRIVACY-COMPLIANCE.md) before deployment.

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
git clone https://github.com/degagius/aggregate
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

## Documentation

| Guide | Contents |
| --- | --- |
| [Contributing](CONTRIBUTING.md) | Development setup, architecture, privacy invariants, tests, Make commands, and pull requests |
| [Privacy and compliance](docs/PRIVACY-COMPLIANCE.md) | Measurement limits, consent, organization traffic, BI suppression, logging, retention, and operator checks |
| [Configuration](docs/CONFIGURATION.md) | YAML settings, environment overrides, branding, goals, and lifecycle policy |
| [Tracking and GTM](docs/TRACKING.md) | Browser integration, custom events, tag-manager examples, and troubleshooting |
| [Deployment](DEPLOYMENT.md) | Docker/native setup, web servers, workers, production operations, and upgrades |
| [Plesk deployment](PLESK-DEPLOYMENT.md) | Shared-hosting setup and worker options |
| [Database](docs/DATABASE.md) | Supported engines, connection strings, migrations, and reporting schema |

## Contributing

Create a branch from `development` and target `development` in your pull request. Contributions should preserve the privacy invariants and favor simple, portable, secure, maintainable designs. AI-assisted contributions are welcome when the author reviews and verifies the work. Start with [CONTRIBUTING.md](CONTRIBUTING.md).

## License

The license split is:

- **Browser tracker:** [public/aggregate.js](public/aggregate.js) is licensed under **BSD-3-Clause**, with the full text in its header and [js/LICENSE.txt](js/LICENSE.txt). This file is also the tracker source; there is no separate build source. The configured script served at `/aggregate.js` carries the same BSD license.
- **Everything else in this project's first-party code and documentation:** **GNU AGPL version 3 only (AGPL-3.0-only)**, under [LICENSE](LICENSE). This includes the server, dashboard, and other JavaScript; the tracker exception does not change their license.

Third-party dependencies and vendored assets retain their own licenses.
