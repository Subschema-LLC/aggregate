# Beta testing

[README](../README.md) · [Contributing](../CONTRIBUTING.md) · [Roadmap](../ROADMAP.md)

Help make Aggregate straightforward to install and dependable to operate. Useful
beta feedback includes successful installations, database compatibility results,
confusing instructions, accessibility problems, and reproducible bugs. You do
not need to contribute code.

Start on an isolated installation with synthetic events and a disposable
database. Assess the [privacy guide](PRIVACY-COMPLIANCE.md) and
[deployment guidance](../DEPLOYMENT.md) before a live-traffic pilot. Beta status
does not establish production readiness or legal compliance; no independent
security or privacy audit is claimed.

## Choose a candidate

Maintainers promote `development` to `uat` for acceptance testing. For a
coordinated beta, use the full commit named in its promotion PR or test request
and report that commit. If no candidate is announced, the README's
[development quick start](../README.md#quick-start) is suitable for exploratory
testing; record `git rev-parse HEAD` so others can reproduce your result.

Contributions always branch from and target `development`, including fixes found
on `uat`. Never switch an existing live installation to a moving branch solely
to reproduce a bug. Work in a separate checkout/database.

The production publishing branch and installed `updates_branch` both default to
`master`; they are separate settings. Keep those defaults when evaluating a
source candidate unless the test explicitly concerns source branch selection.
The current signed-package workflow accepts stable `vX.Y.Z` tags, and ZIP update
discovery ignores prereleases and drafts, so `app:updates:apply` never installs a
beta ZIP. There is no beta package update channel.

## First test session

1. Follow the [README quick start](../README.md#quick-start) for Docker, or
   [native development setup](../CONTRIBUTING.md#native-development). Set the
   database connection and `app_host` for your local environment; preserve any
   existing local configuration instead of copying examples over it.
2. Run migrations against the disposable database. Restrict a web installer to
   your machine while creating the first administrator, or create that user with
   `php bin/console app:install` before exposing the installation. Register a
   synthetic website through the admin UI or `php bin/console app:create-website`.
3. Serve a test page from that registered website's origin and add its tracker
   snippet using the actual local analytics host. The website token is a public
   ingestion token, not a BI/database credential. Use fixed event names such as
   `beta_button_click` and synthetic paths such as `/beta/example`.
4. Check `/api/health`, then exercise the consent states below. Use browser
   network/storage tools and administrator-only inspection of the disposable
   database to check accepted rows; a successful HTTP response alone does not
   establish the stored privacy mode.
5. Record the outcome and anything that required guessing. File a
   [beta feedback report](https://github.com/Subschema-LLC/aggregate/issues/new?template=beta_feedback.yml),
   including successful scenarios and failures. One reproducible bug per issue
   is easier to fix than several unrelated symptoms.

## Test checklist

Mark each scenario **pass**, **fail**, or **not tested**, and include the tested
commit and environment. Deeper SQL, archive, and recovery checks need a
disposable database and the linked feature documentation.

| Scenario | Expected result |
| --- | --- |
| Fresh installation | Migrations complete on the named database/version; first administrator and website creation work; health and the configured tracker route respond. |
| Consent unknown or rejected | Accepted anonymous events have sanitized paths, coarse dimensions, server UTC-hour timestamps, and no visitor/session IDs, raw IP, User-Agent string, or exact event timestamp. All UTMs require consent by default. |
| Explicit consent granted | `setConsent(true)` permits enhanced identifiers/details. With an asynchronous transport, a running worker persists accepted enhanced events. |
| Consent withdrawn | `setConsent(false)` removes SDK identifiers and future enhanced detail. Coarse anonymous events may continue; historical server data is not erased. |
| Collection disabled or sensitive path excluded | Both anonymous and enhanced requests are blocked before lookups, queueing, or event storage. Check a direct HTTP request too; the SDK is not the enforcement boundary. |
| Custom model | UI/YAML changes use the same saved model; only explicitly permitted properties survive anonymous collection. Browser overrides cannot expand server permission. See [Data model](DATA-MODEL.md). |
| Synthetic event examples | Save a model, then compare anonymous/enhanced UI JSON and `app:analytics:examples` output. Only the permitted modeled properties appear, with synthetic values and consent/type notes. Unsaved edits do not affect saved-model output; the ecommerce recipe does not install itself. Replace only the public website-token placeholder for direct-request tests. See [Event examples](EVENT-EXAMPLES.md). |
| Typed properties and numeric views | Send actual integers, fractions, numeric strings, booleans, null, and a mismatched type through both the SDK and direct JSON. Types must not grant consent, truncate fractions to integers, or coerce numeric strings. Check `integer` safe-range limits. In the disposable database, existing text aliases remain text and separate numeric aliases return numbers or SQL `NULL` as documented. |
| Reporting compatibility | On the named engine/version, test numeric regeneration against historical missing, string, boolean, fractional, and out-of-range values. Verify existing grants and reject incompatible alias/order/type changes, including a text addition that would move deployed numeric aliases. A dry run alone is not an execution test. |
| Routine BI access | A restricted BI connection queries approved `bi_anonymous_*` views. Current hours/days and below-threshold cells remain withheld; raw events, archives, operational views, and `analytics_custom_*` stay private. |
| Headless operation | After setting `dashboard_enabled: false` and `DASHBOARD_ENABLED=0` and clearing the cache, dashboard/login/install UI routes are unavailable; health, collection, website CLI, event-example exports, and relevant maintenance commands still work. |
| Navigation and layout | Navigate the configured submenu groups with a keyboard, at a narrow viewport, and with custom branding. Expand a model property, copy example JSON, and open/close the website dialog. Dedicated settings pages, labels, focus, validation errors, and the Updates heading remain readable inside their containers. |
| Administrator boundaries | General settings, branding, collection, BI disclosure, user management, model pages, and HTTP example exports require an administrator. Test direct URLs with an ordinary account as well as hidden navigation entries. Missing/forged CSRF tokens must not save settings; unrelated settings must survive a valid save. |
| Updates | Version checks identify the source/package installation and report network/branch limitations honestly. Verification uses a trusted public key. Package replacement still requires manual deployment. |
| Lifecycle and recovery | On synthetic fixtures, review `app:analytics:maintain --dry-run` before changes. After archiving, approved BI views retain completed buckets and suppression. Rehearse restoration of the database and configuration separately from application-file recovery. |

See [tracking checks](TRACKING.md#health-and-ingestion-checks),
[privacy invariants](../CONTRIBUTING.md#privacy-invariants), and
[database lifecycle behavior](DATABASE.md#analytics-archive-and-maintenance).
For reporting checks, completed event hours and completed goal/geography days
must contain enough synthetic events to meet their configured thresholds.
An empty view can be correct; thresholds count events, not people. Do not lower
live-deployment thresholds to make a beta screenshot look populated.

## Known limitations

- Automatic application of signed packages is planned. Git source pulls still
  require dependency, migration, asset, worker, and health-check deployment steps.
  File replacement does not undo database migrations.
- Custom models, collection controls, and feature flags are deployment-wide.
  Website-specific configuration and rollout targeting are planned.
- BI disclosure thresholds live in `analytics_privacy_settings`; they currently
  use the admin UI or controlled database operations, without YAML/CLI parity.
- `float` and `double` are approximate; use integer minor units and a currency
  code for money. Numeric projections do not convert historical numeric strings.
  Database numeric ranges and rounding still apply. The ecommerce recipe adds
  no order-based deduplication.
- PostgreSQL, MySQL, MariaDB, SQL Server, and SQLite are documented targets.
  Existing mocked and SQL-generation tests do not verify execution on all
  engines or versions. Report the exact database, PDO driver, and PHP version
  you exercised; a successful run is evidence for that environment only.
- Anonymous mode has no unique visitors or sessions. Completed time buckets,
  suppression, and coarse dimensions are intentional reporting constraints.
  The application provides administration; analytics visualization belongs in
  external BI or analysis tools.

Other planned capabilities are in the [roadmap](../ROADMAP.md). Use the candidate's
promotion PR and linked issues for known failures specific to that commit.

## Share useful feedback

Include the commit or packaged version, installation method, PHP/database/PDO
versions, operating system or hosting type, browser if relevant, dashboard mode,
and sync/async transport. List scenarios tested, actual versus expected results,
and the smallest synthetic reproduction. A successful installation report with
these details is useful too; no test duration or traffic capacity is implied by
simply reporting that the application started.

Use the [bug form](https://github.com/Subschema-LLC/aggregate/issues/new?template=bug_report.yml)
for a focused reproducible defect and the
[feature form](https://github.com/Subschema-LLC/aggregate/issues/new?template=feature_request.yml)
for a proposed improvement. Redact credentials, tokens, connection strings,
identifying URLs, local paths, and personal data from screenshots and diagnostics.
Do not upload production events or database dumps.

Report authentication bypasses, consent bypasses, configuration disclosure, or
unsuppressed-data exposure privately using [SECURITY.md](../SECURITY.md). Test only
systems you control or have permission to assess. Ordinary beta issues are
public; community behavior follows the [Code of Conduct](../CODE_OF_CONDUCT.md).
