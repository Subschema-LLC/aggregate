# Glossary

[Why Aggregate exists](WHY.md) · [Architecture tour](ARCHITECTURE.md) · [Design decisions](DESIGN-DECISIONS.md)

Terms used across the code, settings and documentation, in alphabetical order.
Setting names are shown as they appear in `config/aggregate.yaml`.

**Affirmative consent.** A visitor's explicit opt-in to enhanced analytics,
passed to the tracker as the boolean `true`. Anything else, including the
string `"false"`, is not consent. See [enhanced analytics consent](PRIVACY-COMPLIANCE.md#enhanced-analytics-consent).

**Anonymous mode.** The default way events are stored: a sanitized path, coarse
dimensions and the UTC hour, with no visitor or session ID, IP address,
User-Agent string or exact time. The name describes the mode; it is not a legal
conclusion that the data is anonymous.

**Archive.** Private tables of counted cells (`analytics_archive_*`) that old
rows are rolled into when archiving is on (`analytics_archiving_enabled`). The
reporting views include archived counts. See
[archiving and retention](CONFIGURATION.md#archiving-and-retention).

**BI glossary.** Labels and descriptions for codes and columns, declared in
configuration and published to BI tools through the `bi_dim_*` and
`bi_glossary_*` views by `app:analytics:glossary:sync`. See
[BI labels and glossary](BI-GLOSSARY.md).

**Built-in consent banner (CMP).** The small self-hosted consent manager served
at `/cmp-lite/sites/<site-id>/consent.js`, configured per website. CMP stands for
consent management platform. See [setup](SETUP.md#consent-manager-behavior).

**Cell.** One row of a reporting view: a count of events that share the same
website, time bucket and dimensions.

**Collection profile.** `collection_profile`: `standard` (the default) or
`strict`. The strict profile keeps only the page path, event name and allowed
goal codes, and ignores consent and every other dimension.

**Completed bucket.** A UTC hour (for events) or day (for goals and geography)
that has ended. Reporting views release only completed buckets, so the current
hour or day never appears.

**Custom data model.** The list of optional event properties an installation
accepts (`custom_data_properties`), each with its consent requirement, type and
reporting columns. Properties are stored in the event's bounded JSON
`custom_data`. See [custom data model](DATA-MODEL.md).

**Custom reporting views.** `analytics_custom_*` views that turn allowed
properties into columns. They show individual raw rows, so they are private.
Regenerate them with `app:analytics:views:regenerate`.

**Dashboard.** The optional administration interface. `DASHBOARD_ENABLED=0`
removes it and its login page. It never shows analytics reports.

**Dimension.** A property an event is grouped by: page path, referrer channel,
device class, viewport bucket, event name, goal code or geography area.

**Enhanced mode.** Events from visitors who gave affirmative consent. They can
include visitor and session IDs, allowed properties, exact dimensions and the
exact time. Withdrawing consent stops future enhanced detail but does not erase
stored history.

**Event.** One thing that happened on a page, such as a page view (`view`) or a
named action. Page views are events.

**Excluded paths.** `anonymous_excluded_paths`: routes, such as checkout or
account pages, for which nothing is recorded in either mode.

**Feature flag.** A deployment-wide switch for an optional capability, such as
updates or the tag manager. See [feature flags](FEATURE-FLAGS.md).

**Fresh-install test.** `tests/Integration/fresh-install.php`: an end-to-end
install and operation check that CI runs on every supported database engine.

**Geography.** Optional coarse location (`anonymous_geo_enabled`): a continent or
country code looked up in a local MMDB file. It is published only in its own
daily view, with small areas pooled into "other". See
[optional coarse geography](CONFIGURATION.md#optional-coarse-geography).

**Goal.** A conversion, such as `purchase` or `signup`, recorded by a stable goal
code from `config/goals.yaml`. Anonymous events accept only goals marked for
anonymous collection.

**Headless.** Running without the dashboard, managed through YAML, environment
variables and command-line commands.

**Kill switch.** `anonymous_tracking_enabled: false`, which stops all collection
in both modes before anything is derived from a request.

**Maintenance.** `app:analytics:maintain`: archiving and retention, run on a
schedule. A database lease keeps two runs from overlapping.

**Maintenance mode.** The 503 page served while an update replaces files,
controlled by `app:updates:maintenance`.

**Minimum cell count.** The suppression threshold: `anonymous_min_cell_count`
(default 5) for events and goals, and `anonymous_geo_min_cell_count` (default 25)
for geography. Stored in the database and managed in the dashboard. They count
events, not people.

**Namespace.** `js_namespace`: the global JavaScript name the tracker uses on a
website (default `window.Aggregate`).

**Organization traffic marker.** A cookie or local storage flag (default
`orgInternalTraffic=true`) that marks your own team's browsers, so BI reports can
filter them out without dropping the data.

**Page sequence.** Optional page depth (`page_sequence_enabled`, off by
default): a number from 1 to 20 (20 meaning 20 or more) carried between pages in
tab storage or a URL parameter. See [optional page depth](DATA-MODEL.md#optional-page-depth).

**Referrer channel.** A coarse category for where a visit came from, such as
`search` or `direct`, instead of the full referring URL.

**Release ZIP.** `aggregate-VERSION.zip` from a GitHub release: the application
with its dependencies and compiled assets, signed by the maintainers. Not the
"Source code" archives GitHub adds automatically.

**Reporting views.** The SQL views that BI tools and AI assistants read. The approved ones for routine
BI users are `bi_anonymous_*`, `bi_dim_*` and `bi_glossary_*`; everything else is
private. Their names carry a version (`_v1`).

**Retention.** Deleting raw rows and archive cells after configured periods
(`analytics_retention_enabled` and the `*_retention_days` settings). Off by
default.

**Setup code.** The one-time code in `SETUP-CODE.txt`, created by the browser
setup page of a fresh release ZIP install. It proves the person finishing setup
can read the server's files, and is deleted once the administrator exists.

**Setup page.** The first-run page a fresh release ZIP shows until it has a
database and secret. It checks the server, connects the database and writes
`.env.local`.

**Signing key.** The Ed25519 key pair that signs release manifests. Installations
verify packages with the public key in `config/release-signing.pub`, which they
already trust. See [signed release packages](RELEASES.md).

**Standalone consent banner.** `MicroConsent`, an independent consent banner with
optional request forms, which can also be hosted without an installation. See
[standalone consent banner](../micro-consent-dropins/README.md).

**Suppression.** Hiding cells below the minimum cell count. The geography view
also applies secondary suppression, pooling another small area so a hidden one
cannot easily be worked out by subtraction.

**Tag manager.** The lightweight, per-website tag loader served at
`/tms-lite/sites/<site-id>/lib.js`. It loads configured scripts by consent
category. See [tag manager](TAG-MANAGER.md).

**Tracker.** `public/aggregate.js`, the BSD-licensed browser script that sends
events, served at `/aggregate.js` with the installation's settings.

**Update method.** How an installation updates: `release` (signed ZIPs,
recommended) or `repository` (a Git clone). `updates_branch` chooses the release
channel, `master` by default. See [updating](UPDATES.md).

**Viewport bucket and device class.** Coarse size and device categories derived
from the screen width and User-Agent, in place of exact values.

**Website token.** The public token that identifies a registered website in
tracking requests. It is not a secret; the website's domain rules decide which
origins may use it. See [website domains](CONFIGURATION.md#website-domains).
