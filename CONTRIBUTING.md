# Contributing

Contributions to Aggregate Analytics are welcome. Start a branch from `development`
and target `development` when opening a pull request. See the [README](README.md)
for product setup and the [privacy and compliance guide](docs/PRIVACY-COMPLIANCE.md)
for the limits and responsibilities of a deployment.

Follow the [code of conduct](CODE_OF_CONDUCT.md). Report vulnerabilities privately
using the [security policy](SECURITY.md); ordinary bugs and proposals belong in
[GitHub issues](https://github.com/Subschema-LLC/aggregate/issues). Review the
[roadmap](ROADMAP.md) before proposing substantial work.

New to the project? Three short pages give the background this guide assumes:
[why Aggregate exists](docs/WHY.md), an [architecture tour](docs/ARCHITECTURE.md)
that follows one event through the code, and the [design decisions](docs/DESIGN-DECISIONS.md)
behind it. The [glossary](docs/GLOSSARY.md) explains the terms.

Coding agents should also read [AGENTS.md](AGENTS.md) for the product ethos,
architectural boundaries, and expectations for delivering changes.

## Branching strategy

Create each working branch from the latest `development`. Use a descriptive
prefix, for example `feature/add-goal-validation`, `issue/123-fix-consent`,
`fix/tracker-config`, `docs/update-setup`, or `chore/update-dependencies`.
These branches are for individual changes; `development`, `uat`, and `master`
are the shared integration, acceptance-testing, and production branches.

Changes move through these pull requests in order. On GitHub, **compare** is the
source branch and **base** is the target branch:

| Source (compare) | Target (base) | Purpose |
| --- | --- | --- |
| `feature/...`, `issue/...`, or another working branch | `development` | Review and integrate a contributor's change |
| `development` | `uat` | Promote integrated changes for user acceptance testing (UAT) |
| `uat` | `master` | Promote accepted changes for production release |

Contributors open PRs against `development`, including when contributing from a
fork. Maintainers open the promotion PRs from `development` to `uat`, then from
`uat` to `master` after acceptance testing. Follow the same path for fixes found
during UAT: branch from `development`, submit the fix to `development`, and
promote it to `uat` for another check before promotion to `master`.

Each stage uses a PR and the relevant review and checks; keep individual changes
on working branches and preserve the promotion order. Production tagging and
package publication follow the [release guide](docs/RELEASES.md#publish-a-release).

## Design principles

- Keep the database simple: prefer fewer, meaningful tables and columns, with a
  clear purpose for every retained field.
- Preserve easy installation and portability across PostgreSQL, MySQL, MariaDB,
  SQL Server, and SQLite. Consider the supported versions and SQL differences in
  the [database guide](docs/DATABASE.md).
- Keep features usable without the dashboard. New UI settings should have a YAML
  and/or CLI equivalent, and changes should work with the dashboard disabled.
- Design for security, useful test coverage, scalability, maintainability,
  extensibility, and ease of use. Avoid dependencies and abstraction layers that
  add more maintenance than value.
- Since this is a headless, UI-minimal tool, complex frontend build processes are not necessary, unless a very compelling case can be made. We bias ease of install and leveraging Bulma and Turbo/Stimulus over heavily investing in UI overkill. 

Contributions developed with AI coding agents are welcome. Apply your own
expertise and judgment: provide the project's context, guide the agent's
decisions, and review and test the complete change. Submit work you understand
and can explain, including its architectural, privacy, and licensing implications.
The author remains responsible for the contribution and for verifying the
behavior described in the PR.

## Feature flags for optional capabilities

Consider proposing a feature flag for beta functionality, optional integrations,
or operational actions that benefit from a staged rollout. Explain the default,
navigation visibility, affected UI/CLI/service entry points, disabling/recovery
behavior, and graduation or retirement criteria in the issue or PR.

Follow the [feature flag developer guide](docs/FEATURE-FLAGS.md#add-a-feature-as-a-developer)
to register a flag, add shared server enforcement, associate navigation links,
and cover configuration and disabled paths with regression tests. Registered
flags appear in the administrator UI and use active YAML configuration. Current
flags are deployment-wide; website/user targeting remains planned. Mandatory
privacy and security protections must always apply.

## Privacy invariants

Treat these as constraints on implementation and documentation:

- **Anonymous mode has no person or session identifier.** Do not introduce visitor
  IDs, session IDs, fingerprints, or hashes derived from IP addresses and browser
  details. Anonymous rows omit arbitrary custom properties, raw IPs, User-Agent
  strings, exact screen dimensions, and exact timestamps. Keep path sanitization
  and the fixed event-name rules effective on the server as well as in the SDK.
- **The existing exceptions are narrow.** A goal code may survive anonymous
  ingestion only when its enabled definition in `config/goals.yaml` permits
  anonymous use. The explicitly installed organization marker is a boolean in
  `custom_data`, under the configured cookie/local storage name:
  `{"orgInternalTraffic": true}` by default. Its configured value and sharing
  token are not event properties. Custom properties may also survive when the
  deployment model explicitly sets `consent_required: false`; enforce that
  whitelist in the SDK and on the server, and preserve only approved scalar
  values through entity lifecycle callbacks. The optional `page_sequence_enabled`
  setting separately permits only the generated page-depth number (1–20, with 20
  meaning 20+), carried by `page_sequence_method`: tab session storage (default)
  or the `aggregate_page_sequence` URL parameter, without IDs, path history or
  timestamps. URL mode uses no counter Web Storage and only decorates eligible
  same-origin links. It defaults off, applies to all event types, and respects
  kill switches and path exclusions before counter storage or URL propagation.
  Keep the chosen method's consent/storage/URL disclosure
  distinct from a claim of legal anonymity. All UTMs require consent by default.
  Recommend at most broad `utm_medium` values for anonymous attribution, while
  allowing per-property overrides with the documented warning about detailed
  values. See [Data model](docs/DATA-MODEL.md). Browser overrides never authorize
  additional server-side collection.
- **Reporting releases completed, thresholded buckets.** Anonymous event rows use
  server-generated UTC hour buckets. The supported `bi_anonymous_*` views exclude
  the current hour for events and the current day for goals and geography. Preserve
  threshold checks, geography pooling and secondary suppression, and the same
  behavior when live and archived counts are combined.
  The separate `analytics_custom_*` views project unsuppressed retained events;
  treat them as private raw-data access and preserve the existing BI contracts.
- **Glossary metadata is declared, never observed.** The glossary resolver and
  sync must never read events, archives, or reporting facts. Publish complete
  built-in lists and explicit configuration only; keep administrator suggestions
  bounded, explicit, and unsaved until review. Metadata errors must not affect
  tracking, ingestion, or health checks. See [BI glossary](docs/BI-GLOSSARY.md).
- **Thresholds count events, not people.** A single person can contribute multiple
  events to a cell. Do not describe suppression as a unique-visitor minimum,
  k-anonymity, or proof that data is legally anonymous. Raw events, archive tables,
  and unsuppressed operational views remain private reporting inputs.
- **Ingestion controls apply to both modes.** The collection kill switch and
  sensitive-path exclusions must also block enhanced events. Configuration load
  failures must fail closed. Disabled or excluded requests must not create local
  rate-limit buckets, geographic lookups, queue messages, or stored events.
- **Optional geography stays local and coarse.** Resolve only configured
  country/continent codes from a local MMDB. Do not send IPs to an external lookup
  service or copy addresses or detailed lookup records into events, queues, or
  application logs.
- **Enhanced collection needs explicit consent.** Parse consent explicitly;
  truthy values such as the string `"false"` must never grant consent.
  `setConsent(false)` removes SDK identifiers and
  stops future enhanced details; coarse anonymous collection can continue.
  Withdrawal is prospective and does not erase server history. Documentation
  must distinguish this behavior from stopping all collection or fulfilling an
  erasure request.

Add regression coverage at the boundary where a privacy change could fail. A
browser check alone does not protect ingestion from a direct HTTP request.

## Set up a development checkout

Use PHP 8.2 or newer, Composer, the PHP extensions required by the dependencies,
and the PDO driver for your chosen database. Node.js 18 or newer runs the browser
regression tests using its built-in test runner; install the pinned npm dependencies
for the JavaScript build and its tests. Python 3.11 or newer is needed for
release-tooling tests. Docker Compose and Make are optional.

After cloning, update `development` from its tracked upstream and create your
working branch (replace the example name):

```bash
git switch development
git pull --ff-only
git switch -c feature/your-change
```

If you cloned a fork, first sync its `development` with the main repository's
`development`. Set the PR's base repository to `Subschema-LLC/aggregate` and its
base branch to `development`.

For a fresh checkout, create local configuration before installing dependencies:

```bash
cp .env.dev .env
cp config/aggregate.yaml.example config/aggregate.yaml
composer install
```

Keep Composer development dependencies installed: PHPUnit is a `require-dev`
dependency. The production install script uses `--no-dev` and is not the
contributor setup. If the configuration files already exist, edit them instead of
copying over them. The example development secret is for local use only.

If you install Composer dependencies with `--no-scripts`, run
`php bin/console importmap:install --env=test --no-debug --no-interaction`
before PHPUnit. This installs the pinned browser packages from `importmap.php`
into the ignored `assets/vendor/` directory. UI rendering tests need them even
though PHPUnit does not execute browser JavaScript. Normal `composer install`
runs this command through its automatic scripts; `npm ci` does not replace it.

### Native development

Set `DATABASE_URL` in `.env.dev.local` for a disposable development database; this
file takes precedence over the checked-in `.env.dev` defaults. For example,
with `pdo_sqlite` available:

```dotenv
DATABASE_URL="sqlite:///%kernel.project_dir%/var/aggregate-dev.db"
```

See [database connection examples](docs/DATABASE.md#quick-reference) for server
databases, then initialize the schema:

```bash
php bin/console doctrine:migrations:migrate -n
php bin/console app:analytics:glossary:sync
php bin/console app:create-website
```

Point your development web server at `public/`, with requests handled by Symfony's
front controller. `/aggregate.js` must also reach Symfony so it receives runtime
configuration; the checked-in [Nginx](docs/nginx/aggregate-analytics.conf),
[Apache](docs/apache/aggregate-analytics.conf), and
[Caddy](frankenphp/Caddyfile) configurations show the routing. Set `app_host` in the
active YAML environment to your local URL. Open `/install` if you need a dashboard
administrator, or use `php bin/console app:install`.

`MESSENGER_TRANSPORT_DSN=sync://` is suitable for local work and needs no worker.
When exercising asynchronous enhanced ingestion with `doctrine://default`, run:

```bash
php bin/console messenger:consume async -vv
```

### Docker development

Complete the configuration and `composer install` steps above first. Compose
mounts the checkout and runs asset compilation on startup; it does not install
Composer dependencies. The PHP runtime in the container also needs the driver
for the selected database.

```bash
make start-mysql
make migrate-mysql
docker compose exec php php bin/console app:create-website
```

The checked-in Compose/Caddy configuration serves the application at
`http://localhost:9001`; use that URL for `app_host` and open
`http://localhost:9001/install` for dashboard setup. The profile helpers select
the database connection string as well as the service profile. Substitute
`start-postgres` / `migrate-postgres` or `start-mariadb` / `migrate-mariadb` to work
with those databases. Run one database profile at a time.

The services are `php` (FrankenPHP/Symfony), `asset-compile` (dashboard assets),
`worker` (asynchronous enhanced events), and the selected database service. The
worker is only needed when testing an asynchronous transport.

Compose uses `MYSQL_VERSION` for the image tag and `MYSQL_SERVER_VERSION` for
the full Doctrine version hint. When overriding a database image, also update
the full version in the selected Makefile DSN or Compose environment; see
[database version configuration](docs/DATABASE.md#upgrade-to-doctrine-dbal-4).

## Documentation site

The public documentation site at
[subschema-llc.github.io/aggregate](https://subschema-llc.github.io/aggregate/) is
built from the Markdown files in this repository; there is no separate copy to
maintain. [website/pages.mjs](website/pages.mjs) lists which files are published,
at which address and in which sidebar section. Keep writing links the way GitHub
renders them (`../DEPLOYMENT.md#release-zip-on-a-web-host`): the build turns links
between published files into site links, links to other repository files into
GitHub links, and fails when a linked file or heading does not exist. External
images are left out so the site makes no third-party requests, and search runs in
the browser from a local index.

To add a page, add its file to `website/pages.mjs`. To preview the site locally
(Node 20 or newer):

```bash
cd website
npm ci
npm run dev     # live preview at http://localhost:5173/aggregate/
npm run build   # the same strict build CI runs
```

The [documentation workflow](.github/workflows/docs.yml) builds the site on pull
requests that change documentation and publishes it to GitHub Pages from `master`,
so the site describes the current release.

### Linking the application to the documentation

The dashboard and command line link to the site through
[DocumentationLinks](src/Service/DocumentationLinks.php), whose `TOPICS` name a
site page and heading for each subject. Templates use
`{{ component('Ui:DocsLink', {topic: 'tracking.setup'}) }}` or `docs_url('topic')`,
and navigation entries use `docs: topic`; none of them build documentation URLs
themselves. Operators can point the links at another site or turn them off with
[`documentation_url`](docs/CONFIGURATION.md#documentation-links), so every link
must be optional: render nothing, or name the documentation file, when it is off.
`DocumentationLinksTest` fails when a topic's page or heading no longer exists, so
update `TOPICS` when you rename a heading or move a page.

Keep help in the application when it depends on the installation (generated
snippets, live settings, SQL previews) or must be read at the point of action
(warnings next to a setting). Put explanations and procedures in the
documentation and link to them, rather than copying them into templates.

The project wiki on GitHub is a short signpost to this site. Its pages live in
[.github/wiki](.github/wiki) and are published by the
[wiki workflow](.github/workflows/wiki.yml); edit them there, not on GitHub.

## Tests and checks

The [CI workflow](.github/workflows/ci.yml) runs PHP tests and syntax/configuration
checks, the JavaScript build and browser tests, release-tooling tests, and a fresh
install on each database engine on pushes and pull requests. Its final `CI` check
requires all jobs to pass. The separate
[release workflow](.github/workflows/release.yml) builds and verifies production
packages; see the [release guide](docs/RELEASES.md) for maintainer setup.

Run these from the repository root after installing development dependencies:

```bash
npm ci --ignore-scripts
php vendor/bin/phpunit
node --test tests/JavaScript/*.test.js
python3 -m unittest discover -s tests/Release -p 'test_*.py' -v
```

For PHP inside a running container, replace the PHPUnit command with:

```bash
docker compose exec php php vendor/bin/phpunit
```

The Node and Python commands run on the host in either setup. `make test` currently
runs the PHP suite and `aggregate-consent.test.js`; use the wildcard command above
to also run the marker and optional JavaScript-build tests. The build test requires
the pinned Terser dependency (or its matching global installation); see
[JavaScript build](docs/JS-BUILD.md). Make automatically selects Docker when it
finds Docker and `compose.yaml`; `make test USE_DOCKER=0` selects native PHP.

PHPUnit reads [phpunit.dist.xml](phpunit.dist.xml) and
[tests/bootstrap.php](tests/bootstrap.php), using `APP_ENV=test`. Most existing
PHP tests use doubles or temporary files rather than a live database. The
migration tests inspect SQL for supported platforms; passing them is not evidence
that a migration has executed successfully on every database engine. Use a
disposable database for real migration and query checks, and identify the engines
you actually exercised in the PR.

[tests/Integration/fresh-install.php](tests/Integration/fresh-install.php) is that
check. It installs the checkout through the browser setup page and `/install`,
opens every dashboard page, collects events through the API, archives, applies
retention and suppression, and verifies every reporting view. CI runs it on SQLite,
MySQL 8.0, MariaDB 10.11, PostgreSQL 16, and SQL Server 2017, 2019 and 2022 (both
Doctrine SQL Server drivers). It rewrites `.env`, `.env.local`, `config/` and `var/`,
so run it only in a disposable copy of the repository with an empty database:

```bash
AGGREGATE_E2E_DISPOSABLE=1 AGGREGATE_E2E_DRIVER=pgsql AGGREGATE_E2E_DB_HOST=127.0.0.1 \
AGGREGATE_E2E_DB_PORT=5432 AGGREGATE_E2E_DB_NAME=aggregate AGGREGATE_E2E_DB_USER=aggregate \
AGGREGATE_E2E_DB_PASSWORD=secret php tests/Integration/fresh-install.php
```

`AGGREGATE_E2E_DRIVER` is `mysql`, `pgsql`, `sqlsrv` or `sqlite` (no database
settings needed). For SQL Server, `AGGREGATE_E2E_TRUST_CERTIFICATE=1` trusts a
self-signed certificate and `AGGREGATE_E2E_SQLSRV_NATIVE=1` switches to the
`sqlsrv://` driver after setup.

The reporting-view suite includes an optional PostgreSQL execution test. With
Docker running and `postgres:16-alpine` already cached locally, run:

```bash
AGGREGATE_TEST_POSTGRES=1 php vendor/bin/phpunit tests/Service/ReportingViewManagerTest.php
```

It creates and removes a disposable container with networking disabled, uses no
operator database connection, and never downloads an image. It checks numeric
projections, existing text normalization, and grant preservation. The ordinary
suite skips this opt-in case and still executes its SQLite projection tests.

Choose checks that cover the behavior you changed:

| Change | Relevant checks |
| --- | --- |
| SDK payloads, consent, or browser storage | `node --test tests/JavaScript/*.test.js`; relevant ingestion and entity tests |
| Server ingestion or privacy policy | `php vendor/bin/phpunit tests/Controller/ReceiveControllerPrivacyTest.php`; `tests/Service`, `tests/Entity`, and `tests/MessageHandler` cases affected by the change |
| SQL, migrations, archiving, or retention | `php vendor/bin/phpunit tests/Migration`; relevant lifecycle service tests; migration/query checks on disposable databases |
| YAML settings, installation, or headless behavior | Relevant `tests/Configuration`, `tests/Command`, and controller/service tests; YAML and container checks |
| Dashboard templates or branding | Relevant controller/service tests; Twig lint; inspect the changed UI and keyboard interactions |
| Release packaging or signing | `python3 -m unittest discover -s tests/Release -p 'test_*.py' -v`; relevant PHP update and verification tests; extracted-package checks in the [release guide](docs/RELEASES.md) |
| Documentation only | Verify commands against the repository and check relative links, anchors, and examples; application tests are usually unnecessary |

Useful syntax and configuration checks are:

```bash
php -l src/Path/ChangedFile.php
php bin/console lint:yaml config
php bin/console lint:twig templates
php bin/console lint:container
composer validate --no-check-publish
git diff --check
```

Replace the PHP file placeholder with a file you changed. To check dependency
injection without the dashboard, also run
`DASHBOARD_ENABLED=0 php bin/console lint:container`. Clear the application cache
when switching dashboard mode. Compile dashboard assets after relevant asset
changes with `php bin/console asset-map:compile`.

## Architecture and file map

UI pages follow the [Twig and Stimulus conventions](templates/README.md). Route
templates own their page markup under `templates/<feature>/<page>.html.twig`;
page JavaScript and CSS use matching paths under `assets/controllers/pages/`
and `assets/styles/pages/`. Reusable Twig Components keep PascalCase names under
`templates/components/`, with snake_case asset paths under the corresponding
`assets/controllers/components/` and `assets/styles/components/` directories.
Use ordinary Symfony discovery and AssetMapper imports, and create assets only
where behavior or styles are needed. Standalone tracker, CMP, tag-manager, and
organization-marker artifacts keep their separate browser delivery paths.

| Location | Responsibility |
| --- | --- |
| [public/aggregate.js](public/aggregate.js) | Browser tracker and its source; no separate tracker build |
| [ScriptController](src/Controller/ScriptController.php) | Serves the tracker with public runtime settings; preserves its license header and keeps sharing tokens private |
| [ReceiveController](src/Controller/ReceiveController.php) | Validates requests, website origin/token, collection controls, consent, and sanitized inputs |
| [AnonymousEventRecorder](src/Service/AnonymousEventRecorder.php) | Writes anonymous events synchronously using UTC hour buckets |
| [TrackEventMessage](src/Message/TrackEventMessage.php) and [TrackEventHandler](src/MessageHandler/TrackEventHandler.php) | Carry and persist enhanced events through Symfony Messenger |
| [src/Service](src/Service) | Configuration, privacy policy, sanitization, goals, organization markers, branding, and data lifecycle services |
| [src/Service/GeoIp](src/Service/GeoIp) | Local MMDB lookup and coarse geographic codes |
| [src/Entity](src/Entity), [src/Repository](src/Repository), [migrations](migrations) | Doctrine model, database access, schema, and versioned reporting views |
| [config](config) | YAML application/website/goal settings, Symfony services, routes, and package configuration |
| [src/Command](src/Command) | CLI installation, website creation, and analytics maintenance |
| [templates](templates/README.md) and [assets](assets) | Twig pages and partials grouped by feature; AssetMapper dashboard assets |
| [tests](tests) | PHP regressions by component and Node browser/storage regressions |

BI thresholds are currently stored in `analytics_privacy_settings` and managed in
the dashboard. They are not YAML settings. Other application configuration is
loaded by `AggregateConfigLoader`; website registrations live in an untracked
`config/websites.yaml` file. Preserve environment override behavior and avoid
overwriting unrelated configuration when adding settings.

## Make shortcuts

Run `make help` for the complete list and inspect [Makefile](Makefile) for the
current commands.

| Command | Purpose |
| --- | --- |
| `make start`, `make stop`, `make restart` | Manage Compose services; the default profile is MySQL |
| `make start-mysql`, `make start-postgres`, `make start-mariadb` | Start with the corresponding profile and database URL |
| `make migrate-mysql`, `make migrate-postgres`, `make migrate-mariadb` | Apply migrations for that profile |
| `make logs`, `make logs-worker` | Follow application or worker logs |
| `make create-website`, `make worker` | Create a website or run a worker interactively |
| `make cache-clear`, `make assets-compile` | Clear Symfony cache or compile dashboard assets |
| `make db-shell`, `make php-shell` | Open a database client or PHP container shell |
| `make test-tracking` | Send a real sample event; edit/use a manual request if your local URL differs from `http://localhost` |
| `make clean` | Remove Compose volumes, including development database data, and clear local cache/logs |

For a simple health check against the checked-in Docker setup, use
`curl http://localhost:9001/api/health`. `make status` assumes port 80 for its HTTP
health check.

## Preparing a pull request

For an individual contribution, select `development` as the base branch and your
working branch as the compare branch. Maintainer promotion PRs use the targets
in the [branching strategy](#branching-strategy).

Keep the change focused and follow the conventions of the surrounding code.
Explain the concrete problem, the resulting behavior, and any configuration,
migration, deployment, or reporting implications. Include the checks you ran and
their results; name any relevant checks you could not run. For UI changes,
describe the interaction and how you verified it.

Before submitting, review the complete diff for unintended changes, secrets,
personal data in examples, and generated artifacts. Update the relevant guide or
configuration example when behavior changes. If the change alters or adds one of
the [design decisions](docs/DESIGN-DECISIONS.md), update that page too. Privacy-sensitive PRs should explain
which fields reach the browser payload, queue, event row, and reporting views,
including the anonymous and withdrawn-consent cases.

The project defaults to **AGPL-3.0-only** under [LICENSE](LICENSE), including
documentation, server code, dashboard assets, and tests. The browser tracker
[public/aggregate.js](public/aggregate.js) is **BSD-3-Clause** under its header and
[js/LICENSE.txt](js/LICENSE.txt); it is also the tracker source, with no separate
build source. The [Code of Conduct](CODE_OF_CONDUCT.md) adapts Contributor Covenant
2.1 under **CC BY 4.0**, with attribution in that file. Preserve these license
notices. Third-party dependencies and vendored assets retain their own licenses.
