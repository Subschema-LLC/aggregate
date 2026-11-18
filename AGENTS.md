# Working on Aggregate

This is the repository guide for coding agents. Read it alongside
[CONTRIBUTING.md](CONTRIBUTING.md), and consult the linked feature documentation
before changing collection, storage, configuration, or deployment behavior.
Explicit user instructions take precedence over this guide. All PHP code
follows [FIDES](docs/FIDES-MIGRATION.md); the build enforces it.

## Product purpose

Aggregate is self-hosted, headless, privacy-focused analytics infrastructure.
It collects events, maintains a simple database model, and provides stable data
contracts that people can connect to Power BI, Tableau, or another BI tool.
Installation, operation, white labeling, and connecting existing BI tools should
remain straightforward. Favor understandable designs over feature count.

## Code structure: FIDES is required

All PHP development follows FIDES; read
[code structure: FIDES](docs/FIDES-MIGRATION.md) before adding or
restructuring classes. Run `php tools/arch-check.php` before you finish; it
fails the build when a rule it checks is broken.

Where code goes:

- A rule always true for a concept -> `src/Entities/<Owner>/`
- Something a user or the system does -> a new folder in
  `src/Features/<Group>/`, named as an action, with its Twig view, CSS and JS.
  The groups are `Alerts`, `Destinations` and `Incidents`; add one only for a
  new area
- A dashboard page -> `src/Delivery/<Page>/` (controller, page template, page
  CSS and JS); a command, handler or API endpoint -> `src/Delivery/<Owner>/`
- The outside world (database, YAML, HTTP, email) or older code a feature
  needs -> `src/Integrations/<Owner>/`, behind a contract
- A contract no single entity owns -> `src/Shared/`; its binding ->
  `config/wiring.yaml`
- A read across two entities or more -> a read model: its query contract and
  read-only rows in `src/Shared/<ReadModel>/`, answered by one query in an
  integration and used by features

Rules:

1. New PHP files go in a zone. A new file elsewhere in `src/` fails the check
   (`outside-zone`); only `src/Kernel.php` is exempt.
2. A change to an existing area ports that area, in the same pull request or a
   port just before it. A port changes structure, never behavior; keep it in
   its own commits and name every intended difference.
3. Only the owner changes its data; others call its actions. Entities use only
   their own folder and refer to others by id.
4. Features never use each other: one records a message, another reacts.
5. The core (Entities, Features, Shared) never touches the database, files,
   network, environment or clock, and never uses code that has not moved.
   Wrap older services, such as `App\Service\Operations`, in an integration.
6. No inheritance: compose, make classes `final`, use interfaces as
   contracts. No traits either; a framework trait stays at the edge. A custom
   exception may extend one built-in exception, never another custom one, and
   Doctrine records may stay open.
7. One write by default; a `UnitOfWork` makes a multi-entity change visible.
   Messages are saved with the change and dispatched after commit.
8. Views only display values their feature shaped; a template never names an
   entity. A page template places feature views; a feature view is a fragment
   that extends nothing and never renders another's. CSS and JS sit with the
   layout, feature or page, never inline.
9. Files under 200 lines, lines under 100 characters, never deeper than
   `src/<Zone>/<Owner>/<File>` (`src/Features/<Group>/<UseCase>/<File>` for
   features), and `PascalCase` for every folder and file in
   the zones. Only a data file listed in `ZoneRules::SIZE_EXEMPT` with its
   reason may be longer.
10. Never add a finding to `tools/arch-check-baseline.json` or
    `phpstan-baseline.neon`, and never let a listed file grow. When a change
    removes findings, run `php tools/arch-check.php --update-baseline`.
11. Comments say what is true now; history goes in the commit message.
12. Every contract is bound in `config/wiring.yaml`, and only that file names
    an integration class. The core never asks the container for anything and
    carries no container attributes.

If a change touches more than its feature folder, the entities it names, one
integration and the wiring, stop and ask whether a concept is spread too far.

## Architecture and product boundaries

- **Keep tables simple.** Prefer a small number of meaningful tables and columns.
  The unified `events` table distinguishes privacy modes; page views are events.
  Use the existing bounded `custom_data` model for suitable optional scalar
  properties. Do not introduce a table per event type, campaign parameter, or
  custom property. Add tables or abstractions only for a concrete requirement.
  Background jobs record runs in the shared `processing_tasks` and
  `audit_trail` tables through `App\Service\Operations`, not in a status table
  of their own. Email goes through `App\Service\Email\TransactionalMailer`,
  which records each send there without the recipient; a new email service is
  an `EmailProvider` class, not a new code path (see [docs/EMAIL.md](docs/EMAIL.md#add-a-provider)).
- **Keep the application headless.** Collection, installation, configuration,
  and maintenance must remain usable with the dashboard disabled. The optional
  web UI is for administration: setup, website/user management, configuration,
  model definitions, health, lifecycle, and updates.
- **Keep visual reporting outside the app.** Never add in-app analytics reports,
  charts, funnels, or visual report builders. Users visualize data through a
  simple Power BI or Tableau database connection. SQL reporting views are an
  intentional integration interface: preserve and improve them. Admin controls
  for defining columns, previewing SQL, and regenerating those views belong in
  the app; displaying analytics reports does not.
- **Keep configuration in YAML.** Every operator setting lives in YAML files
  (`config/aggregate*.yaml` and the other files listed in
  [configuration sources](docs/CONFIGURATION.md)), with environment overrides
  where documented. Do not store settings in database tables. The database
  holds collected events, archives, users and operational records; tables that
  publish configuration to BI tools, such as `analytics_glossary`, are generated
  from YAML and never edited directly.
- **Keep the dashboard, YAML and the command line in step.** Every operator
  setting lives in YAML, is changed on a dashboard page, and is changed from the
  command line: through an `app:config` section (an `App\Service\Settings\SettingsSection`
  that calls the page's own service) or a command of its own. Reuse the same
  services and validation across entry points, and keep a single source of truth:
  put a page's checks in a service, not the controller, so the section can call
  them. Every page action needs its command in `SettingsParityTest`, which fails
  until it has one. Respect existing environment precedence, including settings
  that intentionally have no environment-variable override. Never overwrite
  unrelated settings or deployment configuration. Settings the application reads should be
  read when used, not compiled into the container, so every entry point's
  change applies without a cache clear. The few exceptions (browser sign-ins,
  per-server secrets, the update signing key, the dashboard switch) are listed in
  [settings from the command line](docs/CONFIGURATION.md#settings-from-the-command-line);
  document any new one there.
- **Keep the dashboard small.** Fold a single setting into an existing page
  rather than adding a menu entry. A page that only matters once something is
  turned on elsewhere may get a `shown_when` condition in
  `config/navigation.yaml`, so the YAML states when it appears and the
  dashboard search still finds it. Never hide the page where that thing is
  turned on: people look for it in the menu, not the search, which is why
  **Sync** is always shown. New feature flags start off and hidden
  (`FeatureFlagDefaultsTest`). See
  [flags, settings and menu conditions](docs/FEATURE-FLAGS.md#flags-settings-and-menu-conditions).
- **Give every dashboard route a permission.** Administrators can do
  everything; standard users get pages at view or edit and websites, directly
  or through permission groups. List each new `/dashboard` route in
  `App\Security\Access\DashboardPages` with its page and level
  (`DashboardPagesTest` fails otherwise; an unlisted route is
  administrator-only). Controllers call `denyUnlessPermitted()`, and website
  pages filter and check websites and scopes with the `DashboardAccess`
  helpers rather than listing every website. See
  [users and permissions](docs/USERS-AND-PERMISSIONS.md).
- **Support white labeling.** Use the existing branding and navigation helpers
  for names, logos, colors, typography, and presentation. Avoid hardcoded vendor
  identity in ordinary product UI. Preserve accessible contrast, keyboard use,
  license notices, and the correct identity of update sources and trust keys.
- **Keep deployment portable.** Preserve support for PostgreSQL, MySQL, MariaDB,
  SQL Server, and SQLite at their documented versions. Use portable Doctrine
  operations where possible and explicit platform SQL where necessary. Keep
  dependencies modest; do not assume Docker, a particular hosting panel, or
  build tools on a server installing a prepared release ZIP. Stay on the most
  recent Symfony LTS (7.4.*, PHP 8.2+) and move every `symfony/*` package to
  the next LTS together; never merge a single package's major bump.
  `config.platform.php` in `composer.json` is the minimum supported PHP
  (8.2.0), so every update resolves for it; raise it only with the documented
  minimum and the CI matrix. See
  [PHP and Symfony](docs/DESIGN-DECISIONS.md#php-and-symfony).

BI disclosure thresholds (`anonymous_min_cell_count`,
`anonymous_geo_min_cell_count`) and `reporting_timezone` are YAML settings
compiled into the `bi_anonymous_*` view SQL. The BI disclosure and page groups
pages, the reporting timezone section of General settings, plus
`app:analytics:bi-views:regenerate`, share
`AnalyticsPrivacySettings`, `ReportingTimezone` and `AnonymousBiViewManager`;
reuse those services rather than regenerating views elsewhere. The reporting
timezone relabels event-view hours and days only. Do not store local times on
events or add a timezone lookup table. Goal and geography views stay on UTC
days. Collection settings and data models can vary by website or website
group. The deployment YAML remains the base, a group replaces the keys it
sets, and a website replaces those keys again. Reporting views stay on the
deployment model until columns are published. See
[website groups](docs/CONFIGURATION.md#website-groups).

## Privacy is an implementation constraint

- Anonymous collection must not acquire person/session identifiers,
  fingerprints, or hashes derived from IP addresses and browser details.
  Preserve server-side path sanitization, coarse dimensions, fixed event names,
  and server-generated UTC hour timestamps. Do not retain raw IP addresses,
  User-Agent strings, or exact event timestamps in anonymous rows.
- Enhanced collection follows the consent signal, and administrators decide
  how that signal is set, with their legal team. With the built-in or
  standalone banner, the banner's or region's consent type sets it before a
  choice: `explicit_eu` (the default) and `explicit_us` stay anonymous until the
  visitor accepts; `implicit_us`, an operator's choice for notice-and-opt-out
  regions, starts with its configured default-on categories (enhanced analytics
  unless changed) until the visitor opts out, and a respected Global Privacy
  Control signal withholds them. With a third-party consent manager, the signal
  it pushes decides (`setConsent`, a preloaded `consent` value, or Google Consent
  Mode `analytics_storage` as the `google_consent_mode` setting allows). Only an
  explicit grant counts: a missing or malformed signal, or a truthy string such
  as `"false"`, must not grant consent, and strict collection keeps everything
  anonymous. Do not add default grants outside `implicit_us`. Keep each of these
  choices available in the dashboard and YAML. Withdrawal removes SDK identifiers
  and stops future enhanced detail; coarse anonymous collection may continue.
  Withdrawal does not erase previously stored history. Describe these
  distinctions honestly. See
  [which consent signal sets the mode](docs/PRIVACY-COMPLIANCE.md#which-consent-signal-sets-the-mode).
- Enforce collection rules on the server as well as in the SDK and persistence
  boundaries. Browser overrides cannot expand server permissions. Invalid
  collection-control or consent configuration must fail closed. Kill switches
  and sensitive-path exclusions apply to both privacy modes before lookups,
  queueing, or recording events.
- All UTM properties require consent by default. Allow operators to whitelist
  individual properties for anonymous collection. Recommend no finer granularity
  than broad `utm_medium` values, but permit explicit finer-property overrides
  with warnings in the UI and documentation. This recommendation is advisory;
  do not turn it into a hard ban. Even an allowed medium value can contain
  identifying text. Preserve scalar bounds, sanitization, and alias handling.
  Optional `query_parameter_persistence` (`off` by default, `consented`,
  `always`) is the only way mapped values reach later pages: tab
  `sessionStorage`, never cookies or local storage. Never write
  consent-required values before consent; remove them on withdrawal and when a
  page starts without consent. A served setting is a ceiling pages can only
  narrow. `entry_channel` stays a fixed referrer-channel code, accepted by the
  server only while persistence is on. See
  [keeping values for the tab session](docs/TRACKING.md#keep-values-for-the-tab-session).
- Keep anonymous goal codes explicitly permitted and the organization-traffic
  flag a boolean under the fixed key `org_internal_traffic`. Marker values,
  marking codes and sharing tokens are not event properties. Do not let query
  parameters or submitted properties spoof the flag.
- Optional `page_sequence` is a bounded page-depth number, enabled only by the
  UI/YAML `page_sequence_enabled` setting. Keep it off by default, capped at 20
  (20+), and free of IDs, path history and timestamps. Kill switches and path
  exclusions apply before counter storage or URL propagation. The UI/YAML
  `page_sequence_method` selects tab session storage (default) or URL parameter
  passing. The URL method uses no counter Web Storage, propagates only to
  eligible same-origin links, and keeps the number untrusted. Trim only its
  transport parameter after capture and keep event page paths query-free.
  Disclose the chosen method's storage or URL effects; a bounded count does not
  establish legal anonymity or consent exemption.
- Optional geography must stay coarse and use local MMDB lookups. Never send
  visitor IP addresses to an external geolocation service or copy them into
  event payloads, queues, or application logs.

The complete rules and their exceptions are in
[CONTRIBUTING.md](CONTRIBUTING.md#privacy-invariants) and the
[privacy guide](docs/PRIVACY-COMPLIANCE.md). Protect the actual collection path;
a warning or a consent checkbox is not enforcement.

## Database reporting contracts

Routine anonymous BI access uses approved `bi_anonymous_*` views. Preserve
completed time buckets, minimum event counts, geography pooling and secondary
suppression, including when live and archived data are combined. Thresholds
count events, not people; they are not a guarantee of legal anonymity. The
hourly, daily, roll-up and coverage views read events through one definition in
`AnonymousBiViewSqlBuilder`; change event sources, filters or the hourly cell
grouping there, never in one view alone.

The seven `bi_dim_*_v1` views plus `bi_glossary_values_v1` and
`bi_glossary_columns_v1` are approved metadata contracts; keep their backing
`analytics_glossary` table private. Publish only built-in codes and declared
configuration, never observed events or archive values. Glossary sync is explicit
and must not affect collection or health configuration validation. See
[BI-GLOSSARY.md](docs/BI-GLOSSARY.md) for locales, grants, and publishing limits.

Raw `events`, archive tables, unsuppressed operational views, and
`analytics_custom_*` projections are private inputs. [BigQuery sync](docs/BIGQUERY.md)
copies views, never tables: approved views by default, private views only when
each is listed under `bigquery_private_views`. Keep its credentials in
`config/secrets` and out of logs and messages. The [forensic database](docs/FORENSIC-DATABASE.md)
is the one sync that copies raw tables: keep its identifiers hashed in the
application under a per-copy key that is never stored, its writes limited to a
marked or confirmed database, its page administrator-only with no view of the
rows, and its copies out of `ViewCatalog`. Custom-property projections
must not weaken the existing anonymous BI contracts or silently expose new data.
Keep column names and meanings stable for existing BI connections. Coordinate
destructive or semantic changes through documented migrations. Do not create
parallel facts or joins that let routine BI users undo suppression.

Read [DATABASE.md](docs/DATABASE.md) and [DATA-MODEL.md](docs/DATA-MODEL.md) before
changing view generation, retention, archives, or reporting aliases. Test SQL on
the affected engines when behavior depends on their syntax or transactions;
passing mocked tests does not establish database compatibility.

## Releases and operation

Create `feature/...`, `issue/...`, and other working branches from `development`
and target `development` with contributor PRs. Maintainers promote changes by PR
from `development` to `uat` for user acceptance testing, then from `uat` to
`master` for production release. Follow the
[branching strategy](CONTRIBUTING.md#branching-strategy). Production
publishing defaults to `master`, configured in `config/release.yaml`; installations
select updates with YAML `updates_branch`, also defaulting to `master`. Preserve
both settings rather than assuming the checked-out branch is the release channel.

Use public GitHub Releases for production ZIPs. Keep production dependencies,
compiled assets, metadata, and license notices complete, while excluding secrets,
operator configuration, runtime data, caches, and IDE files. A separate artifact
repository is not required. Version discovery does not verify a signature;
package verification must use an independently trusted public key.

`app:updates:apply` (and the dashboard **Install update** button, which runs it
in the background) installs signed release ZIPs and fast-forward Git updates in
place. Keep it preserving configuration and data: operator paths are listed once
in `App\Service\Update\UpdatePaths` and mirrored by the release builder, edits to
shipped config defaults move to `config/*.local.yaml`, and `.env` only gains new
keys. Keep maintenance, the update lock and journal, backups, resume and rollback
working, and keep file recovery distinct from database restoration: rollback
never reverses migrations; only SQLite snapshots can be restored, on request.
Anything a new step needs after files are replaced must run in the fresh
`--resume` process, not in the process that replaced the files. See
[RELEASES.md](docs/RELEASES.md#update-an-installation).

## How to deliver changes

- Follow clear requests through implementation and appropriate validation. Ask
  focused follow-ups when requirements are uncertain or conflict with these
  product boundaries. Keep documentation aligned with what actually exists.
- The repository Markdown is the only documentation; the public site and the
  wiki are built from it. Link dashboard help and command messages to it through
  `DocumentationLinks` topics (`Ui:DocsLink`, `docs_url()`), which operators can
  point elsewhere or turn off, instead of copying guides into templates. See
  [linking the application to the documentation](CONTRIBUTING.md#linking-the-application-to-the-documentation).
- Inspect the working tree and preserve the user's edits and staging. Include
  all new runtime dependencies and relevant tests in the change set; a previous
  deployment failed because a controller was committed without its new service
  files. Review tracked, staged, and untracked files before handing work back,
  without broadly staging unrelated files.
- Put behavior in shared services rather than duplicating it in controllers,
  commands, and scripts. Preserve optional minification, source fallback, public
  runtime tracker configuration, and license headers when changing browser assets.
- Run checks appropriate to the change. Privacy and data-boundary changes need
  meaningful regression coverage, including direct requests that bypass the SDK.
  Test headless behavior when touching routes, services, or configuration. Pure
  documentation edits need link/content checks, not a full application test run.
  Commands and test scope are documented in [CONTRIBUTING.md](CONTRIBUTING.md#tests-and-checks).
- Preserve the AGPL server/documentation and BSD-3-Clause tracker split, the
  Contributor Covenant attribution, and third-party notices. Follow
  [SECURITY.md](SECURITY.md) for vulnerabilities and
  [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md) for community conduct.
- Report what changed, how it was checked, and any remaining limitations. Keep
  future features in [ROADMAP.md](ROADMAP.md), without implying they are delivered.
  When a change alters a recorded choice, update
  [docs/DESIGN-DECISIONS.md](docs/DESIGN-DECISIONS.md) in the same change.
