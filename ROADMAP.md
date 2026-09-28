# Roadmap

Aggregate's next milestone is a public beta that people can install, evaluate,
and contribute to with clear expectations. Prioritize trustworthy collection,
repeatable operation, and useful documentation over adding features. These
items have no promised release dates; scope and priorities may change with
[beta feedback](docs/BETA-TESTING.md).

Discuss substantial proposals in
[GitHub issues](https://github.com/Subschema-LLC/aggregate/issues) before starting,
and follow the [contribution guide](CONTRIBUTING.md). Maintainers track completion
and evidence in issues and promotion PRs. A roadmap entry is planned work, not
an assertion that a release gate has passed.

## Public beta priorities

| Priority | Work | Completion evidence |
| --- | --- | --- |
| Before making the repository public | Review all published Git history and refs for secrets and private material; verify private security/conduct contacts; configure GitHub protections and contribution settings. | Maintainer sign-off against [public release preparation](docs/PUBLIC-RELEASE.md#before-changing-repository-visibility), with any real exposed credentials rotated. |
| Before inviting adopters to a beta candidate | Exercise a fresh install, anonymous/enhanced consent boundaries, headless operation, and routine BI access on a named commit. Resolve known authorization, disclosure, and data-loss blockers. | A promotion PR or test record identifies the commit, runtime/database versions, scenarios, failures, and remaining limitations using the [beta checklist](docs/BETA-TESTING.md#test-checklist). |
| During beta | Execute migrations, reporting views, and lifecycle operations against disposable PostgreSQL, MySQL, MariaDB, SQL Server, and SQLite databases at documented versions. Add repeatable integration coverage. | Record which versions actually passed; SQL-string assertions and mocked tests do not establish engine compatibility. |
| During beta | Rehearse upgrades, worker restart, and backup restoration; test extracted signed packages on a server without build tools. Extend runtime coverage as newer PHP versions are verified. | Reproducible install/upgrade reports, package verification with an independently trusted key, and separate file/database recovery results. |
| During beta | Improve administrator usability, keyboard access, narrow-screen layouts, error messages, and white labeling from real adopter reports. | Focused fixes with screenshots or reproduction steps, relevant validation, and preserved headless workflows. |
| Before a stable release | Review beta blockers, publish the verified environment matrix and known limitations, and document operator actions for migrations and configuration changes. | Maintainer acceptance on the `uat` → `master` promotion PR and reviewed release notes. A stable version is not a claim of legal anonymity or an independent audit. |

Useful first contributions include a reproducible installation report, a database
integration fixture, a confusing documentation step corrected against a fresh
checkout, or a small accessibility fix. Keep each PR focused and target
`development`.

## After beta feedback

- **Page-depth settings by event type.** The optional `page_sequence` counter
  currently applies to all events through one UI/YAML setting. Explore per-event
  inclusion where operators need it, keeping one page counter and shared server
  enforcement. Preserve numeric bounds and anonymous collection controls.
- **Complete headless BI-threshold administration.** Provide a supported CLI path
  for the existing `analytics_privacy_settings` database source, sharing UI
  validation. These thresholds are currently managed by the UI or controlled
  database operations; do not introduce competing YAML synchronization.
- **Website-specific configuration and data models.** Allow collection settings
  and models to vary by registered website. They currently use the deployment's
  active aggregate YAML configuration. Tags and CMP already have per-website YAML;
  registration manages primary domains, per-token source-domain rules, and ingestion
  tokens separately. Preserve existing deployments and reporting
  contracts through an explicit migration design.
- **Targeted feature rollout.** Extend
  [deployment-wide feature flags](docs/FEATURE-FLAGS.md) to selected websites or
  administrators where there is a concrete need. Preserve authorization and
  privacy enforcement; anonymous visitors must not acquire experiment identifiers.
- **Apply verified release packages automatically.** Signed production ZIP
  tooling, discovery, and offline verification are available. Application still
  requires the [manual deployment steps](docs/RELEASES.md#install-or-deploy-a-verified-package).
  A future resumable updater must coordinate maintenance and workers, preserve
  configuration/data, run migrations and health checks, prevent concurrent
  updates, and provide separate application-file and database recovery procedures.

## Proposals to explore

- **Useful insight without visit or session IDs.** Develop practical measurement
  recipes and external Power BI/Tableau examples showing how broad medium,
  channels, page-path activity, events and goals can together paint a picture of
  what is working. Start with available channel/path/event counts and separate
  goal trends. Explore safe reporting extensions for explicitly permitted medium
  and aggregate pathing, with pathing defined as page activity and fixed navigation
  events. Keep individual journey reconstruction and cross-page attribution out
  of anonymous measurement. Any new reporting dimensions need disclosure review,
  completed buckets, suppression and consistent live/archive behavior; preserve
  existing BI contracts and keep private custom views restricted. See the
  [current measurement boundaries](docs/PRIVACY-COMPLIANCE.md#insight-without-visit-or-session-ids).
- **External analysis examples and an MCP integration.** Start with read-only
  connections to approved `bi_anonymous_*` views and synthetic sample Python
  notebooks. Any MCP server needs explicit credential scope and authorization;
  it must not expose private raw inputs or let consumers undo suppression.
  Charts and analysis remain in external tools.
- **Branding polish.** Improve the default logo and administration presentation
  while preserving configured white labels, accessibility, and license notices.
- **Operational email integrations.** Explore Postmark/SendGrid support for a
  concrete administration or maintenance notification need. Keep secrets in
  deployment configuration and avoid embedding private analytics data in email.
  Analytics report delivery belongs in external reporting tools.
- **Tag management integrations.** The
  [Aggregate GTM tag template](https://github.com/Subschema-LLC/aggregate-gtm-tag-template)
  is under development in its own repository; track template progress and
  availability there. Explore Matomo Tag Manager support where adopters need it.
- **Extend Tag Manager Lite.** Still keep things simple but look for gaps and improvements.
- **BI Translation Table** Add a new table called glossary or lookup where columns from certain tables or the values that are found in them can be translated. The UI and yaml will need fields for table, column, value, then translation, much like a lookup table.

## Available foundations

- [Optional page depth](docs/DATA-MODEL.md#optional-page-depth), configured in the
  Data model UI or YAML. A bounded tab counter adds `page_sequence` to all event
  types, including asynchronous events, without creating visitor/session IDs.
  It is off by default, requires review of browser-storage consent obligations,
  and remains outside the routine anonymous BI views.
- [Guided setup and script downloads](docs/SETUP.md), with a four-step administrator
  wizard, button callouts, website-specific installation snippets, a self-contained
  consent UI, and copy/download controls. Setup guides operators to existing settings;
  it does not certify a deployment or replace beta acceptance testing.
- [Simple YAML-backed tag management](docs/TAG-MANAGER.md), disabled by default,
  with per-website YAML, CMP controls, remote script URLs, script and method actions,
  per-tag consent categories, browser/data-layer triggers, named variables, and a
  headless public loader. Optional builds run from Setup or `app:assets:build-js`.
- [Website domain rules](docs/CONFIGURATION.md#website-domains), with YAML/UI allow-all or restricted exact-host and wildcard-subdomain policies, CLI creation options, and server enforcement in both privacy modes. Existing registrations preserve their domain-plus-subdomains behavior until rules are explicitly saved.
- [Synthetic event examples](docs/EVENT-EXAMPLES.md), with saved-model anonymous/enhanced JSON, UI copy/download, a headless export, and a proposed flat ecommerce model. Optional value types enforce scalar input types, while additive numeric reporting columns preserve existing text aliases.
- [Focused administration pages and grouped navigation](docs/CONFIGURATION.md#administration-pages), with keyboard/touch submenus configured in YAML and role/feature checks at each level. Model editing, discovery, examples, and reporting SQL have separate pages.
- [Configurable branding and light/dark appearance](docs/CONFIGURATION.md#application-settings),
  with a browser preference, a return to the site's configured palette, and
  preserved brand colors and fonts across modes.
- [Feature flags](docs/FEATURE-FLAGS.md), with shared YAML/admin UI settings, server and CLI enforcement, and independent navigation visibility. Updates is the first registered capability; developer and contributor guidance explains how to add more.
- [Organization traffic markers](docs/PRIVACY-COMPLIANCE.md#organization-traffic), with YAML/UI settings and shareable browser setup. Filtering uses retained event JSON; grouped BI views and archives do not retain the marker.
- [Custom data models](docs/DATA-MODEL.md), including UTM/query mappings, per-property consent settings, downloadable YAML, and private reporting columns. All UTMs require consent by default; anonymous attribution should use at most broad `utm_medium` values, with documented warnings for overrides.
- [Optional JavaScript minification](docs/JS-BUILD.md) for tracker, organization-marker, and drop-in scripts.
- [Git source updates](DEPLOYMENT.md#updates) and [signed release packages](docs/RELEASES.md), with dashboard/CLI version checks and configurable update branches defaulting to `master`.

The [README](README.md) covers the wider feature set and privacy tradeoffs. The [release history](https://github.com/Subschema-LLC/aggregate/releases) records published packages.
