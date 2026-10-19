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
- **More jobs in processing tasks.** Glossary sync and updates are recorded in
  the [audit trail](docs/AUDIT-TRAIL.md) when an administrator starts them; they
  could also record their runs and results in `processing_tasks`, as the syncs
  and maintenance do, so the Scheduled tasks and Audit trail pages show them.
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
- **Alerts rules engine.** Notify administrators when collection looks wrong, so
  a broken tag, a removed tracker or a misconfigured consent banner is noticed
  within hours instead of at the next report. Administrators define rules such
  as "fewer than 200 events for this website in a completed hour", "no `signup`
  goal events for 24 hours" or "more than 50 tracking failures in a day", with a
  threshold, a time window, the websites it covers and its recipients.
  - **Configuration:** rules live in YAML, like every other operator setting,
    with an **Alerts** page in the dashboard and command-line equivalents that
    share one validation service. The page manages rules and recipients, sends
    a test alert and lists recent alerts; it does not chart traffic, which stays
    in BI tools.
  - **Evaluation:** a job of the [scheduled task](docs/SCHEDULED-TASKS.md),
    like maintenance, evaluates rules on completed time buckets, so
    hour-bucketed anonymous events and partial hours do not cause false alarms.
    It works with the dashboard disabled. A cooldown and a recovery notice keep
    a persistent problem from sending repeated alerts.
  - **Records:** each evaluation run is recorded in `processing_tasks` and each
    alert sent, suppressed or failed in `audit_trail`, through
    `App\Service\Operations`, with no alerts table of its own.
  - **Delivery:** email through the Postmark and SendGrid integrations above,
    and SMS through a provider still to be chosen, since Postmark and SendGrid
    send email only (Twilio, SendGrid's parent company, is one option).
    Credentials stay in `config/secrets` or the environment, never in YAML,
    messages or logs.
  - **Privacy:** an alert carries the rule, website, threshold, window and
    observed count, never event rows, page paths, custom property values or
    visitor data. Recipients' email addresses and phone numbers are personal
    data too; keep them out of logs and the audit trail details.
- **Medallion Architecture.** Add a means to define and generate silver and gold views. Also docs: events table and glossary are bronze.
- **Snowflake Integration** Add a Snowflake connector.
- **Agency deployments.** Agencies serve each client with a separate Aggregate
  installation rather than one shared multi-tenant application. Each client's
  settings, retention, data model and database stay apart, and the client can
  own the database. Aggregate does not model agencies, clients or hierarchies,
  and clients do not sign in to it: they read their data in a BI tool or an AI
  assistant. Tenant hierarchies, per-client logins and per-client theming inside
  one installation are not planned; white labeling stays per installation. What
  is missing is making a new installation quick to repeat:
  - **Provisioning:** one non-interactive, rerunnable command that reads a
    provisioning file (administrator, websites and their domain rules, BI
    scopes) and runs the existing steps: `doctrine:migrations:migrate`,
    `app:install`, `app:create-website`, `app:user:create`,
    `app:analytics:glossary:sync` and `app:analytics:bi-views:regenerate`.
    It prints the view-only grant SQL for the reporting account instead of
    creating database users. The database URL, passwords and other secrets come
    from the environment or `config/secrets`, never from the provisioning file.
  - **Starting configuration:** a reusable set of YAML files (consent banner,
    data model, goals, branding) that an agency copies into each installation.
    Copies are independent; nothing is shared live between installations.
  - **Updates across installations:** document how to script
    `app:updates:apply` and the deployed-code commands over many installations.
  - **Guide:** an agency deployment guide covering when to use a separate
    installation and when per-website views in one installation are enough (see
    below), the client's role as data controller and the agency's as processor,
    and handover when a client leaves with its database and YAML files.
- **Per-website and website-group BI views.** Within one installation, give a
  brand, a location or a small client read-only access to only its own
  websites. This fits one organization with several brands, or clients content
  to share one configuration. Every approved view already has a `website_token`
  column, but [filtering in a BI tool is not separation](docs/BI-CONNECTION.md#postgresql-mysql-mariadb-and-sql-server).
  - **Scopes:** reuse the website groups that already share collection settings
    ([website groups](docs/CONFIGURATION.md#website-groups)), rather than a
    second list of the same websites.
  - **Views:** for each scope, generate copies of the approved `bi_anonymous_*`
    views filtered to its websites, with the same columns, so a report built on
    one can be repointed to another. Build them over the approved views, never
    over raw `events`, archive tables or `analytics_custom_*` projections. A
    group's views filter rows; they never recompute suppression over the
    combined websites, which could let them be subtracted from the per-website
    views. Confirm first that every pooled `(other)` and geography row is
    computed within one website, so filtering keeps each row whole.
  - **Metadata:** scoped copies of `bi_dim_website_token_v1` and the glossary
    values, so a scope's account cannot see other websites' names. Labels that
    are deployment-wide, such as goal and event names, stay shared; when those
    are confidential, use a separate installation.
  - **Operation:** `AnonymousBiViewManager` and
    `app:analytics:bi-views:regenerate` create and regenerate scoped views with
    the approved ones, and print the [grant statements](docs/BI-GLOSSARY.md#view-only-grants)
    for each scope's reporting account. SQLite has no database users or
    per-view permissions, so scopes need a server database.
  - **When to use a separate installation instead:** the client needs a
    different retention period, disclosure threshold, branding or database.
    Collection settings and the data model can already vary by website group.
- **Implementation partners.** Give agencies and consultants a supported way to
  install and run Aggregate for their clients, so adoption grows without the
  application taking on hosting or tenant features. Subschema can be the first
  partner, including hosting installations for clients who want that.
  - An installation and acceptance checklist that builds on the
    [beta checklist](docs/BETA-TESTING.md#test-checklist): fresh install, consent
    boundaries, headless operation, BI grants, backups and updates.
  - A partner guide covering agency deployments, handover and support
    boundaries.
  - A partners page listing implementers and the criteria for being listed. A
    listing does not certify any deployment's legal compliance.
  - Partner names and offerings stay out of the application; each installation
    keeps its operator's white label.
- **Load testing: what is not measured yet.** The harness, the Load test
  workflow and the published results are in place
  ([load testing and capacity](docs/LOAD-TESTING.md)). Still to measure:
  - a load generator and server on separate dedicated machines, without the
    shared runner's noise or k6's share of the CPU;
  - `doctrine://` with background workers, FrankenPHP, Apache, SQL Server and
    MariaDB, and other PHP-FPM worker counts;
  - requests for the tracker, consent and tag manager scripts, dashboard use,
    and `app:analytics:maintain`, running during load;
  - an installation with many registered websites;
  - writing anonymous events with a direct insert instead of Doctrine ORM.

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
  concrete administration or maintenance notification need; the alerts rules
  engine above is the first. Keep secrets in
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
- **Scale-out architecture.** Once load testing shows where one server stops,
  explore running Aggregate as separate roles that share one database. Keep the
  single-server install as the default, and make every step optional.
  - **Roles:** an administration server with the dashboard, setup and updates;
    collection servers behind a load balancer, serving `/api/receive` and the
    browser scripts with `DASHBOARD_ENABLED=0`; and worker servers running
    `messenger:consume` and the scheduled task. The YAML-first design already
    lets a collection server run without a login page.
  - **Shared state to solve first:**
    - *Configuration.* YAML lives on each server's disk, and the dashboard writes
      it locally. Collection servers need identical files with one writer:
      deployed from version control, a read-only shared mount, or a sync from the
      administration server.
    - *Rate limiting.* Buckets are files under `var/rate_limit`, so each server
      counts on its own and a load balancer multiplies the effective limit. Use a
      shared store with the same HMAC keys, or limit at the load balancer.
    - *Updates.* Servers must not serve different releases' tracker and schema
      against one database. Run migrations once, from one server, and roll the
      code across the others.
    - *Scheduled jobs.* Maintenance already holds a database lease, and jobs lock
      through `processing_tasks`, so they should run safely from any worker;
      confirm that under load. The scheduler's own lock and its record of runs
      are files in `var/scheduler`, per server, so the Scheduled tasks page
      reports only the server it runs on.
  - **Queues and brokers:** enhanced events already go through Messenger. Add
    `symfony/amqp-messenger` (RabbitMQ) and `symfony/redis-messenger` as optional
    transports, documented with the PHP extensions they need, while `sync://` and
    `doctrine://` keep working on shared hosting.
  - **Anonymous events off the request path:** anonymous rows are written during
    the request so that nothing more detailed than the stored row is ever kept,
    not even in a queue. Queueing or batching only the already-sanitized,
    hour-bucketed row keeps that guarantee, and is what the
    [design decision](docs/DESIGN-DECISIONS.md#anonymous-events-written-immediately-enhanced-events-through-messenger)
    names as the change that would revisit it. Kill switches, path exclusions and
    rate limits still run before anything is queued; update the design decision
    in the same change.
  - **Feeding exports:** when the alerts rules engine or a streaming export on
    this roadmap (BigQuery, Fabric or Snowflake) needs events as they arrive, a
    RabbitMQ topic exchange or Redis Streams could deliver accepted events to it
    alongside storage. Add this only for one of those consumers, not as a general
    event bus. Publish only what storage would accept, after sanitization and the
    consent decision; send enhanced payloads only to consumers allowed to have
    them; never publish IP addresses, User-Agent strings or exact anonymous
    timestamps; and keep broker credentials in `config/secrets`. Delivery is at
    least once, so consumers must not count a redelivered message twice.

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
- [Reporting database](docs/REPORTING-DATABASE.md): selected reporting views copied into same-named tables of a second database on any supported engine, swapped in whole at each sync, so BI users connect without access to the application database. Same view opt-ins, schedule, status page and headless commands as BigQuery sync.
- [YAML disclosure thresholds](docs/CONFIGURATION.md#bi-disclosure-thresholds):
  `anonymous_min_cell_count` and `anonymous_geo_min_cell_count` live in the
  aggregate YAML with environment overrides, are edited on the BI disclosure page
  or in YAML, and are applied by `app:analytics:bi-views:regenerate`, which keeps
  reporting grants on server databases.
- [Daily view by page group](docs/BI-CONNECTION.md#bi_anonymous_daily_v1):
  `bi_anonymous_daily_v1` counts completed days by YAML-defined page group,
  channel and device, releasing far more of a low-traffic site's events, with
  complementary suppression so it cannot be subtracted from the hourly view.
  Page groups are edited on the Page groups page or as `bi_page_groups`.
- [Daily roll-up](docs/BI-CONNECTION.md#bi_anonymous_daily_rollup_v1) and
  [coverage](docs/BI-CONNECTION.md#bi_anonymous_coverage_v1) views:
  `bi_anonymous_daily_rollup_v1` pools the cells the daily view withholds into
  coarser `(other)` rows, with the same rule against the hourly view, so daily
  totals add up; `bi_anonymous_coverage_v1` reports, in bands, how much of each
  day's traffic each event view releases.
- [One scheduled task](docs/SCHEDULED-TASKS.md), `app:scheduler:run` every five minutes, for maintenance, BigQuery sync and the reporting database sync, with a Scheduled tasks page and `app:scheduler:status` that give the exact Plesk, cron or systemd setup for the server and show whether the server's task runs. The reporting database connection is entered on its page or with `app:reporting-database:configure`, tested, and saved to `.env.local`.
- [Administrator actions in the audit trail](docs/AUDIT-TRAIL.md): settings changes (by setting name, never value), users, websites and groups, integrations, updates, manual runs and, when turned on, sign-ins, from the dashboard and the command line. Each area can be switched off; an Audit trail page filters by date, area, action, outcome, user, subject, text and job run, exports CSV, and `app:audit:list` does the same on the command line. Administrator actions have their own retention period (730 days by default).
- [Processing tasks and an audit trail](docs/DATABASE.md#processing-tasks-and-audit-trail): two shared private tables for background job runs (status, lock, failure details) and their outcomes, used by BigQuery sync, the reporting database sync and maintenance, with UI/YAML purge periods.
- [Organization traffic markers](docs/PRIVACY-COMPLIANCE.md#organization-traffic), with YAML/UI settings, marking links that set the marker on each tracked website, and a fixed `org_internal_traffic` flag on every event. Filtering uses retained event JSON; grouped BI views and archives do not retain the flag.
- [Custom data models](docs/DATA-MODEL.md), including UTM/query mappings, per-property consent settings, downloadable YAML, and private reporting columns. All UTMs require consent by default; anonymous attribution should use at most broad `utm_medium` values, with documented warnings for overrides.
- [Optional JavaScript minification](docs/JS-BUILD.md) for tracker, organization-marker, and drop-in scripts.
- [Load testing and capacity](docs/LOAD-TESTING.md): k6 scripts and a Load test workflow that measure the hits per day one server handles on SQLite, MySQL and PostgreSQL, profile collection requests, compare two versions, and clear the cache under traffic; results feed the [sizing table](DEPLOYMENT.md#performance).
- [Git source updates](DEPLOYMENT.md#updates) and [signed release packages](docs/RELEASES.md), with dashboard/CLI version checks and configurable update branches defaulting to `master`.
- [Code deployed another way](docs/UPDATES.md#deploy-the-code-another-way), such as with a hosting panel's Git deployment or CI/CD: a read-only Updates page with the deployed commit and how far behind it is, and one post-deployment command for the tool's deployment action.

The [README](README.md) covers the wider feature set and privacy tradeoffs. The [release history](https://github.com/Subschema-LLC/aggregate/releases) records published packages.
