# Working on Aggregate

This is the repository guide for coding agents. Read it alongside
[CONTRIBUTING.md](CONTRIBUTING.md), and consult the linked feature documentation
before changing collection, storage, configuration, or deployment behavior.
Explicit user instructions take precedence over this guide.

## Product purpose

Aggregate is self-hosted, headless, privacy-focused analytics infrastructure.
It collects events, maintains a simple database model, and provides stable data
contracts that people can connect to Power BI, Tableau, or another BI tool.
Installation, operation, white labeling, and connecting existing BI tools should
remain straightforward. Favor understandable designs over feature count.

## Architecture and product boundaries

- **Keep tables simple.** Prefer a small number of meaningful tables and columns.
  The unified `events` table distinguishes privacy modes; page views are events.
  Use the existing bounded `custom_data` model for suitable optional scalar
  properties. Do not introduce a table per event type, campaign parameter, or
  custom property. Add tables or abstractions only for a concrete requirement.
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
- **Provide both UI and headless configuration.** New operator settings should
  be available in the admin UI and through CLI and/or YAML. Reuse the same
  services and validation across entry points, and keep a single source of truth.
  Respect existing environment precedence, including settings that intentionally
  have no environment-variable override. Never overwrite unrelated settings or
  deployment configuration. Document any necessary exception or existing gap.
- **Support white labeling.** Use the existing branding and navigation helpers
  for names, logos, colors, typography, and presentation. Avoid hardcoded vendor
  identity in ordinary product UI. Preserve accessible contrast, keyboard use,
  license notices, and the correct identity of update sources and trust keys.
- **Keep deployment portable.** Preserve support for PostgreSQL, MySQL, MariaDB,
  SQL Server, and SQLite at their documented versions. Use portable Doctrine
  operations where possible and explicit platform SQL where necessary. Keep
  dependencies modest; do not assume Docker, a particular hosting panel, or
  build tools on a server installing a prepared release ZIP.

There are existing configuration gaps: BI disclosure thresholds currently live
in `analytics_privacy_settings` and are managed by the UI or controlled database
operations, not YAML/CLI synchronization. Do not claim complete parity or silently
create a competing configuration source. Website-specific collection settings
and data models are still [planned work](ROADMAP.md); current models are scoped
to the deployment's active aggregate configuration.

## Privacy is an implementation constraint

- Anonymous collection must not acquire person/session identifiers,
  fingerprints, or hashes derived from IP addresses and browser details.
  Preserve server-side path sanitization, coarse dimensions, fixed event names,
  and server-generated UTC hour timestamps. Do not retain raw IP addresses,
  User-Agent strings, or exact event timestamps in anonymous rows.
- Enhanced collection requires explicit consent. A truthy string such as
  `"false"` must not grant consent. Withdrawal removes SDK identifiers and stops
  future enhanced detail; coarse anonymous collection may continue. Withdrawal
  does not erase previously stored history. Describe these distinctions honestly.
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
- Keep anonymous goal codes explicitly permitted and the organization-traffic
  marker a boolean under its configured key. Marker values and sharing tokens
  are not event properties. Do not let query parameters spoof the reserved marker.
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
count events, not people; they are not a guarantee of legal anonymity.

Raw `events`, archive tables, unsuppressed operational views, and
`analytics_custom_*` projections are private inputs. Custom-property projections
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

Automatic package application remains on the roadmap. Do not claim one-click
updates are implemented. Future application must preserve configuration and data,
coordinate maintenance and workers, handle migrations, and distinguish file
recovery from database restoration. See [RELEASES.md](docs/RELEASES.md).

## How to deliver changes

- Follow clear requests through implementation and appropriate validation. Ask
  focused follow-ups when requirements are uncertain or conflict with these
  product boundaries. Keep documentation aligned with what actually exists.
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
