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
| During beta | Execute migrations, reporting views, and lifecycle operations against disposable PostgreSQL, MySQL, MariaDB, SQL Server, and SQLite databases at documented versions. The fresh-install CI job now does this for a fresh install on SQLite, MySQL 8.0, MariaDB 10.11, PostgreSQL 16, and SQL Server 2017, 2019 and 2022; still open are the remaining documented versions (such as MySQL 8.4, MariaDB 10.6/11.4, PostgreSQL 13–15, Azure SQL) and upgrades from earlier releases. | Record which versions actually passed; SQL-string assertions and mocked tests do not establish engine compatibility. |
| During beta | Rehearse upgrades, worker restart, and backup restoration; test extracted signed packages on a server without build tools. Extend runtime coverage as newer PHP versions are verified. | Reproducible install/upgrade reports, package verification with an independently trusted key, and separate file/database recovery results. |
| During beta | Improve administrator usability, keyboard access, narrow-screen layouts, error messages, and white labeling from real adopter reports. | Focused fixes with screenshots or reproduction steps, relevant validation, and preserved headless workflows. |
| Before a stable release | Review beta blockers, publish the verified environment matrix and known limitations, and document operator actions for migrations and configuration changes. | Maintainer acceptance on the `uat` → `master` promotion PR and reviewed release notes. A stable version is not a claim of legal anonymity or an independent audit. |

Useful first contributions include a reproducible installation report, a database
integration fixture, a confusing documentation step corrected against a fresh
checkout, or a small accessibility fix. Keep each PR focused and target
`development`.

## After beta feedback

- **Hosting panel guides.** A release ZIP installs from any hosting panel's
  file manager through the vendor-neutral [setup page](DEPLOYMENT.md#release-zip-on-a-web-host),
  and [Plesk](PLESK-DEPLOYMENT.md#install-from-a-release-zip-no-ssh) has a
  step-by-step guide. Add a **cPanel** guide next, verified on a real account:
  creating the site and its document root, the database and user, PHP version
  and extension settings, and routing on the Apache or LiteSpeed servers cPanel
  hosts commonly use. Add other panels when adopters ask for them, and list each
  guide in the [hosting panel guides](DEPLOYMENT.md#hosting-panel-guides) table.
  Verify the cPanel Git Version Control example in
  [deploy the code another way](docs/UPDATES.md#cpanel) on the same account.
  Keep panel names out of the application itself; panel-specific steps belong
  in the guides.
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
- **Administrator actions in the audit trail.** The `audit_trail` table records
  every finished processing task and reserves the `admin` category for
  administrator actions, such as saving settings, managing users and websites, and
  installing updates. Record those with the acting username, keeping secrets and
  submitted values out of the details. Other background jobs, such as glossary
  sync and updates, can record their runs in `processing_tasks` the same way.
- **Targeted feature rollout.** Extend
  [deployment-wide feature flags](docs/FEATURE-FLAGS.md) to selected websites or
  administrators where there is a concrete need. Preserve authorization and
  privacy enforcement; anonymous visitors must not acquire experiment identifiers.
- **Updater follow-ups.** `app:updates:apply` and the dashboard **Install update**
  button now [install signed releases and Git updates in place](docs/RELEASES.md#update-an-installation).
  Still open: scheduled or unattended updates, a key-rotation path for the release
  signing key, database snapshots for server databases, and per-entry merging of
  `config/*.local.yaml` overrides with newly shipped defaults (an override currently
  replaces the whole parameter).
- **Retire the `eventData` payload name.** Collection requests now carry custom
  properties in `customData`, and `/api/receive` still reads the earlier
  `eventData` when `customData` is absent. Remove that fallback in a later
  release, once older static or CDN copies of the tracker and server-side
  integrations send `customData`, and say so in the release notes. The GTM tag
  template calls `emit()`, so it already follows the served tracker.
- **Versioned documentation.** The [documentation site](CONTRIBUTING.md#documentation-site)
  follows `master`, so it describes the latest release, and the dashboard links
  to it through [`documentation_url`](docs/CONFIGURATION.md#documentation-links).
  Publish a copy of the site for each release and link each installation to the
  guides for its own version.
- **MCP Connector.** An AI connector so agents have ample context and an easy way to integrate and interact with Aggregate.  
- **Microsoft Fabric Connector.** Similar to the BigQuery Sync connector, but for Microsoft Fabric.
- **Sendgrid Integration** For transactional emails.
- **Postmark Integration.** For transactional emails.

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
- **External analysis examples and an MCP integration.** AI assistants can
  already [query the approved views](docs/BI-CONNECTION.md#connect-an-ai-assistant)
  through a general SQL connector and the read-only reporting account. Package a
  read-only integration over the approved `bi_anonymous_*` and glossary views,
  with synthetic sample Python notebooks. Any MCP server needs explicit credential scope and authorization;
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
- **Custom HTML tags.** [Custom JavaScript tags](docs/TAG-MANAGER.md#custom-javascript)
  run script compiled into the manager. Explore a separate custom HTML action for
  vendor snippets that ship as markup, such as `<noscript>` pixels or several
  `<script>` elements. Inserting HTML runs inline scripts that skip the server's
  parser checks and need `'unsafe-inline'` or a nonce under a Content Security
  Policy, so the design needs its own validation, consent and CSP story.
- **BI Translation Table** Add a new table called glossary or lookup where columns from certain tables or the values that are found in them can be translated. The UI and yaml will need fields for table, column, value, then translation, much like a lookup table. The [BI glossary](docs/BI-GLOSSARY.md) already translates declared codes of the reporting dimensions, custom properties and, through `bi_dim_website_token_v1`, website tokens; free-form lookups for other columns remain open.
- **Visitor objection to anonymous collection.** Let visitors object to anonymous
  measurement, not only to enhanced detail: an SDK opt-out call, a consent
  drop-in toggle, and server-side honoring of the Global Privacy Control
  (`Sec-GPC: 1`) request header, which needs no browser storage. Enforce an
  objection on the server as well as in the SDK, and apply it before rate-limit
  buckets, geographic lookups, queueing, or storage, as the kill switch and path
  exclusions do. The UK statistical-purposes exception added to PECR by the
  Data (Use and Access) Act 2025 requires a simple, free means of objecting.
  Any stored opt-out preference needs its own disclosure.
- **A documented consent-exemption configuration.** Build on the
  [strict collection profile](docs/PRIVACY-COMPLIANCE.md#strict-collection-profile),
  which already disables page depth, the organization marker and other device
  reads, with a checklist for operators assessing an audience-measurement
  exemption, such as the CNIL's in France or the UK statistical-purposes
  exception. Cover raw and archive retention of at most 25 months, consent tools
  and tags still loaded alongside the tracker, and visitor objection. "Cookieless" is not an exemption by itself: the EDPB treats scripts
  that make the browser send device information as within ePrivacy Article 5(3).
  Consider the CNIL's evaluation of audience-measurement tools once the profile
  exists, and revisit if the EU Digital Omnibus's proposed first-party
  audience-measurement exemption is adopted. Aggregate cannot certify a
  deployment as exempt; the operator remains responsible for the assessment.
- **Consent-banner categorization guidance.** Document how to list Aggregate in
  consent management platforms and the micro consent drop-ins: anonymous
  measurement in a separate exempt audience-measurement category with disclosure
  text where an exemption applies, rather than as strictly necessary, and
  enhanced collection behind analytics consent. Update the drop-in UI and sample
  disclosures to match.
- **Built-in server-side collection.** A site's backend can already send
  anonymous events to the public endpoint, as the
  [server-side guide](docs/SERVER-SIDE.md) describes, forwarding the visitor
  address through trusted-proxy rules for rate limiting and geography. Still
  open: an authenticated ingestion key for server callers, and per-caller rate
  limits that don't depend on `TRUSTED_PROXIES`. Keep path sanitization, kill
  switches, path exclusions and anonymous-mode rules identical to browser
  collection. GDPR still applies to the processing.

## Available foundations

- [Independent consent controls and regional examples](docs/CONSENT-REGIONS.md),
  with a separate optional banner, per-site YAML/UI configuration, GPC handling,
  optional Formspree request submission, and explicit tracker/Google signal
  adapters. Conservative examples gate the initial tracker load; they are not
  legal advice, region detection, a rights-processing system or provider enforcement.

- [Strict collection profile](docs/PRIVACY-COMPLIANCE.md#strict-collection-profile),
  set in Collection controls, YAML or `COLLECTION_PROFILE`. Every event is
  anonymous with only the sanitized path, event name and approved goal; the
  served tracker reads no screen size or referrer and does not touch cookies or
  browser storage. It limits device access but is not by itself a consent
  exemption.
- [BI glossary](docs/BI-GLOSSARY.md), with declared value labels, column definitions,
  localized fallback, YAML/admin editing, and a headless sync command. Nine fixed
  metadata views, including website names for `website_token`, join to existing
  BI columns without reading event data or changing suppression. Database and BI-tool acceptance checks remain part of beta validation.

- [Optional page depth](docs/DATA-MODEL.md#optional-page-depth), configured in the
  Data model UI or YAML. A bounded counter adds `page_sequence` to all event
  types, including asynchronous events, without creating visitor/session IDs.
  Choose tab session storage or URL parameter passing. It is off by default,
  requires review of the method's storage/URL disclosures and consent obligations,
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
- [BigQuery sync](docs/BIGQUERY.md) of selected reporting views on an interval, with service account key, Google Cloud and Google sign-in authentication, explicit opt-in for private views, a status page and headless commands.
- [Processing tasks and an audit trail](docs/DATABASE.md#processing-tasks-and-audit-trail): two shared private tables for background job runs (status, lock, failure details) and their outcomes, used by BigQuery sync and maintenance, with UI/YAML purge periods.
- [Organization traffic markers](docs/PRIVACY-COMPLIANCE.md#organization-traffic), with YAML/UI settings, marking links that set the marker on each tracked website, and a fixed `org_internal_traffic` flag on every event. Filtering uses retained event JSON; grouped BI views and archives do not retain the flag.
- [Custom data models](docs/DATA-MODEL.md), including UTM/query mappings, per-property consent settings, downloadable YAML, and private reporting columns. All UTMs require consent by default; anonymous attribution should use at most broad `utm_medium` values, with documented warnings for overrides.
- [Optional JavaScript minification](docs/JS-BUILD.md) for tracker, organization-marker, and drop-in scripts.
- [Git source updates](DEPLOYMENT.md#updates) and [signed release packages](docs/RELEASES.md), with dashboard/CLI version checks and configurable update branches defaulting to `master`.
- [Code deployed another way](docs/UPDATES.md#deploy-the-code-another-way), such as with a hosting panel's Git deployment or CI/CD: a read-only Updates page with the deployed commit and how far behind it is, and one post-deployment command for the tool's deployment action.

The [README](README.md) covers the wider feature set and privacy tradeoffs. The [release history](https://github.com/Subschema-LLC/aggregate/releases) records published packages.
