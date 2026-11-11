# Scaling phase 3: server roles sharing one database

_Spec for JOIN-59. The first section is the consolidated spec, written by Claude Code in Scott's session from its draft ([Claude Doc](https://claude.ai/artifact/6JTyoWhMbWDEq9JKGDMAbi)) and the team's replies in Slack. The team's replies follow it as they wrote them. Each section names who wrote it. The anchor is [`docs/features/server-roles.md`](../features/server-roles.md)._

## Consolidated spec

Phase 3 lets one Aggregate installation run on several servers that share one database. Every server runs the same release with one `server_role`. The team agrees on the direction and changes it in two ways. **The shared database is measured before any role code is written** (slice 0). **The 1.8× scaling target only applies where that measurement shows headroom.** The sections below include every change the team asked for; [where the team's input went](#where-the-teams-input-went) maps each one.

### Decisions already made

- **Spec first, then build** in reviewable slices (Scott, in the JOIN-59 session).
- **Rate limits at the balancer.** Each collection server's own limiter (APCu or files, since #456) is only a backstop (Scott).
- **Configuration pulled from the administration server,** the only place YAML is edited (Scott).
- **The reporting API, MCP, OAuth sign-in and the dashboard stay on the administration server,** so `config/secrets/` never leaves it (spec; no objection from the team).
- **The scheduled task runs on the administration server,** where its credentials are; worker servers run Messenger consumers only (spec; the Product Owner asks the roadmap to match).
- **SQLite supports only `all`.** A file database cannot be shared; `app:server:role` refuses another role on SQLite and says why (Product Owner, DBA).
- **No schema change for roles.** The `cluster` list and the configuration version stay in YAML; there is no cluster table (DBA).

### Slice 0: measure the shared database first

Phase 3 starts ahead of phase 2 only on a measured gate. The amendment to `ROADMAP.md` ("Each phase starts only when …") goes in this slice and replaces the unconditional rule with that gate, not with an exception.

Run a **database headroom** test (`docs/LOAD-TESTING.md`):

- One database server, fed by two load generators, so it carries twice one application server's load.
- Both queue setups: `sync://` (enhanced events written during the request) and `doctrine://` with consumer workers.
- A large `events` table (10 and 50 million rows), and a BI-style query loop over the `bi_anonymous_*` views running at the same time, because BI reads stay on the primary.
- Per engine and mode, report:
  - database CPU and I/O;
  - connection count;
  - commit (fsync) wait;
  - queue statements per enhanced event;
  - for PostgreSQL, the `pg_stat_user_indexes` use of each `events` index.

**The gate:** where an engine and mode show less than about 2× headroom, phase 3 waits for phase 2's batching there. MySQL anonymous is the likely case: phase 1 found it stopped on per-event commits waiting for the disk.

These are also phase 1's still-missing measurements (the large table, `doctrine://` with workers, dedicated machines).

### Roles

`server_role` in `config/aggregate.yaml`, with the `SERVER_ROLE` environment override. A missing or unknown value means `all`.

| Role | Serves over HTTP | Runs |
| --- | --- | --- |
| `all` (default) | Everything, as today | The scheduled task, Messenger consumers, migrations |
| `administration` | Dashboard and setup (`routes_dashboard.yaml`, including `McpAuthorizeController`), home and how-it-works (`HomeController`), the reporting API, MCP with its OAuth metadata and token endpoints (`/oauth/*`, `/.well-known/*` on `McpController`), branding, `/api/health`, and the configuration endpoint | Migrations, updates, the scheduled task |
| `collection` | `/api/receive`, the browser scripts, `/internal-traffic`, `/branding/*`, `/api/health` | The configuration pull |
| `worker` | `/api/health` | `messenger:consume`, the configuration pull |
| `balancer` | Nothing of Aggregate's | `app:server:balancer-config` (open question: role or only a command) |

How the gating works (Architect, with the second opinion's corrections):

- **One boot-time resolver.** A `ServerRole` resolver reads the role the way `Kernel::isDashboardFeatureEnabledForBoot()` reads the dashboard switch: environment first, then `config/aggregate.yaml` under a file lock. The dashboard switch derives "off" from the role.
- **Routes.** Split `config/routes_public.yaml` into two files, and load each by role:
  - `routes_collection.yaml`: Receive, Script, ConsentScript, StandaloneConsentScript, TagScript, BrandingLogo, BrandingTheme, InternalTrafficPublic, Health.
  - `routes_admin_api.yaml`: Home, ReportingApi, Mcp.
- **`McpAuthorizeController` stays where it is,** in `routes_dashboard.yaml` and `services_dashboard.yaml`, because OAuth consent needs a dashboard session.
- **Unused controllers are removed.** `Kernel::build()` removes the controller definitions for routes a role does not load, as it already does for the dashboard. The lists are generated from the route files, not kept by hand, and a test boots each role and compares its routes with this table.
- **Commands.** One console event listener checks each command against the commands its role allows. It refuses migrations, `app:updates:apply` without `--files-only`, and configuration writers on `collection` and `worker`, naming the administration server.
- **Services too.** `AggregateConfigLoader::save()` and the configuration writers (`WebsiteConfigManager`, `WebsiteScopeConfigManager`, `GoalsConfigManager`, `TagManagerConfig`) also refuse on those roles, so a future endpoint or script cannot write configuration either.
- **Dead mail routing goes.** Remove the unused `SendEmailMessage`, `ChatMessage` and `SmsMessage` routing from `config/packages/messenger.yaml`. `TransactionalMailer` sends through `mailer.transport_factory`, and nothing in `src/` uses `MailerInterface` or the Notifier, so workers never need mail credentials.

### `collection_base_url`, shipped first and on its own

Snippets, scripts and the consent UI point at `collection_base_url`, which defaults to the site's own URL. A single server with a separate tracking hostname benefits today. The configuration pull, the balancer and the tracker all depend on it.

### Configuration pull

- **`ClusterConfigController`** exists only on the administration server, at `GET /api/cluster/config`.
- **What it returns:** one bundle of the files collection and Messenger handling read, with a content hash as its version. It answers `304` when the caller already has that version.
  - Included: `config/aggregate*.yaml`, `config/websites.yaml`, `config/website-scopes*`, `config/goals.local.yaml`, `config/tag-manager/sites/*.yaml`, and `var/branding/`.
  - Never included: `config/secrets/`, `.env*`, users, and dashboard-only files.
- **Signed requests:** an HMAC with `CLUSTER_KEY` over the method, path, timestamp and body hash. A request older than a minute, or from an address not in `cluster`, gets the same `404` as an unknown route.
- **`app:cluster:pull`** writes each pulled file to a sibling temporary file and `rename()`s it into place. It does not rename the whole directory, because `config/` also holds shipped framework files that are not in the bundle.
- **No cache clear is needed:** `ParsedYamlCache` keys entries by an xxh128 hash of the content, and `AggregateConfigLoader` resets between Messenger messages.
- **The local role is kept.** The pull preserves the server's own `server_role` key when it writes `config/aggregate*.yaml`. Otherwise a node that sets its role in YAML would become `administration`.
- **Health metadata only for the cluster:** `/api/health` is public, so the release and configuration version are returned only on requests carrying a valid cluster signature. The anonymous response is unchanged.
- **Staleness:** behaviour past a limit is an open question below. Either way:
  - the administration server asks every server in `cluster` to pull right after a configuration save, which cuts the usual lag from up to 60 seconds to seconds;
  - the one-minute timer stays as the backstop;
  - a server that has never pulled does not collect.

### The balancer

`app:server:balancer-config --nginx|--caddy` writes the reference configuration from the `cluster` list. The configuration:

- spreads collection with least connections;
- limits `/api/receive` per address at `rate_limit_per_minute`, answering `429`;
- turns `access_log` off and sets `limit_req_log_level` below the error log's level, so no address is logged;
- overwrites `X-Forwarded-For` with `$remote_addr`;
- reaches the servers over a private network.

Stock Caddy has no rate limiter: its reference relies on each server's backstop, or on a build with `caddy-ratelimit`. Collection servers accept web traffic only from the balancer, so its limit cannot be skipped.

### Updates across servers

1. The administration server updates first, as today: maintenance, files, migrations, `--resume`.
2. Each other server runs `app:updates:apply --files-only`, which skips migrations and maintenance.
3. While servers are on mixed releases, old code runs against the new schema. CI enforces backward compatibility (PO, Architect, DBA):
   - Migrations on `events`, and on any table or view the collection path or Messenger handler writes, must be additive. That means new columns nullable or defaulted, no renames, no drops, and no `NOT NULL` without a default; a removal waits a release.
   - Message classes (`TrackEventMessage`) follow the same additive-only rule, because a message from an old collection server may sit in `messenger_messages` until a worker on the new release reads it.
   - Index builds on `events` must not block inserts:
     - PostgreSQL: `CREATE INDEX CONCURRENTLY` with `isTransactional(): false`, and `lock_timeout` on any `ALTER`.
     - MySQL and MariaDB: `ALGORITHM=INPLACE, LOCK=NONE`.
     - SQL Server: `ONLINE=ON` where the edition allows it.
4. The Updates page lists every server with its release and configuration version, inside the existing page (with a `shown_when` condition in `config/navigation.yaml` if needed).

### The database (DBA)

- **Connection budget.** PostgreSQL keeps connections open by default (DESIGN-DECISIONS), so N servers × `pm.max_children`, plus consumers and the scheduled task, must stay under `max_connections`. The docs recommend PgBouncer in transaction mode for PostgreSQL clusters; Aggregate keeps no session state on a connection. MySQL and MariaDB connect per request, so their count follows requests in flight. `app:server:role` prints the budget and warns above about 70% of `max_connections`.
- **Index write amplification.** Every insert maintains each secondary index on `events` (`docs/install_fresh.sql` lists five). An index is dropped only if slice 0 shows it unused.
- **The queue table.** Compare the consumer's claim query with a composite `(queue_name, available_at, delivered_at)` index at three or more workers. Add it by migration only if measured, because Symfony's Doctrine transport owns that table's schema. Otherwise tune autovacuum on PostgreSQL and watch InnoDB history length on MySQL.
- **Queue retention.** The failed transport, and a backlog behind stopped workers, keep full enhanced payloads in `messenger_messages`, outside the events retention rules. They get a bounded age and a documented purge (`Version20260724000500` already deletes `TrackEventMessage` rows, a precedent).
- **Retention batches.** The retention and archive job, now on the administration server only, deletes in small batches so it does not hold locks or flood WAL or replication while collection servers insert.

### `app:server:role`, the contract PRAGMA builds on

`app:server:role --json` prints:

- the role, the release and the configuration version;
- the queue transport, with a note that `sync://` keeps enhanced writes on the request path;
- the connection budget;
- a `requirements` list with a check result and the fix for each.

The JSON carries a `schema_version`. Fields are only ever added, never renamed or removed within a version, so PRAGMA (in its own thread, if the open question below goes that way) can rely on it.

### Done when

The anchor's criteria, changed as the team asked:

- [ ] The slice 0 report exists per engine and mode, and `ROADMAP.md`'s phase rule is a measured gate.
- [ ] Two collection servers handle at least 1.8× one server's events per second at the same p95, for PostgreSQL and MySQL/MariaDB enhanced, and for anonymous wherever slice 0 showed headroom. Where the database is the limit (connections, commit wait, queue churn), the load test says so instead of failing silently.
- [ ] A server with no `server_role` behaves exactly as today, and SQLite refuses every role except `all`.
- [ ] Routes, controllers, commands, configuration writers and scheduled jobs outside a role are not registered or refuse to run, naming the administration server, with a test per role.
- [ ] Collection and worker servers get configuration only from the administration server, with signed requests and per-file atomic writes that keep the local role. The staleness rule from the open question is met.
- [ ] `app:server:role` gives the role, release, configuration version, queue, connection budget and requirement checks, as text and versioned JSON. The Updates page shows the same for every server.
- [ ] The Nginx and Caddy references spread collection, limit `/api/receive` per address, keep addresses out of logs, and overwrite `X-Forwarded-For`.
- [ ] Updates run migrations once on the administration server and files only elsewhere. CI rejects non-additive migrations on the collection path's tables and non-additive changes to message classes.
- [ ] No visitor address, User-Agent string or exact timestamp passes between servers beyond what one server handles today. `config/secrets/` and unbounded queued payloads stay where the rules above put them.

### Rollout

| # | Slice | Needs |
| --- | --- | --- |
| 0 | Database headroom measurement, the `ROADMAP.md` gate, and the roadmap's phase 3 text corrected (scheduled task on administration, rate limits after #456, API and MCP on administration) | — |
| 1 | `collection_base_url` | — |
| 2 | `ServerRole`, route split, controller removal, command listener and service guards, SQLite refusal, `app:server:role`, dead mail routing removed | 0 |
| 3 | Configuration pull, signed health metadata, pull-after-save | 2 |
| 4 | `app:server:balancer-config` for Nginx and Caddy | 1, 2 |
| 5 | `--files-only`, the additivity checks for tables and message classes, the Updates page server list | 3 |
| 6 | Queue retention: a bounded age for failed and undelivered rows | — |
| 7 | Two-servers-one-database fresh-install CI job | 3 |
| 8 | Multi-server load test on rented machines (four to six for a few hours per run), with the BI query loop | 2–5, 7 |

Index changes on `events` or `messenger_messages` come only after slice 0 or 8 shows they are needed.

### Risks

- **Connections run out before CPU does.** That shows up as "too many connections" or 500s at the balancer, not as a slower p95. Slice 0 measures connections, and `app:server:role` states the budget.
- **The MySQL anonymous commit wait caps scaling** however many servers there are. The scoped 1.8× criterion keeps phase 3 from being judged on phase 2's problem.
- **`doctrine://` puts queue polling, locking and deleting on the database that takes event inserts.** More consumers can raise collection p95. Slice 0 measures both queue setups.
- **Heavy BI queries or a large archive delete on the one primary slow every collection server at once.** Slice 0 and slice 8 run with a BI query loop.
- **PostgreSQL bloat on `messenger_messages` and on a fast-growing `events` table,** if autovacuum falls behind several writers.
- **A composite index on `messenger_messages` may read as schema drift** to Doctrine's tooling, or be dropped by a later sync.
- **Privacy lag.** A kill switch or path exclusion reaches collection servers after a pull. Asking servers to pull after each save makes that seconds rather than up to a minute; a partition between servers makes it longer. See the open question.
- **The DBA has not yet read the retention code's batch size and locking,** nor whether `TrackingFailureRetryRunner` purges the failed transport. That is checked in slice 6.

### Open questions for the approver

- [ ] **Staleness.** When a collection server cannot pull, does it keep collecting on the last configuration it has, or fail closed (`503`) after a limit such as 5 or 10 minutes?
  - Failing closed matches AGENTS.md's fail-closed rule, but an administration server outage, including a long update in maintenance, then stops all collection.
  - Claude Code recommends keeping collection going on the last configuration, with pull-after-save and an alert when a server is stale. The Product Owner and Architect lean toward failing closed.
- [ ] **PRAGMA.** Is PRAGMA's side (install with a role, shared MariaDB and firewall, balancer site, MCP plan-and-approve) part of JOIN-59's done criteria, or its own thread building on `app:server:role --json`? The Product Owner recommends its own thread; the anchor marks it as open.
- [ ] **Queue.** Is a cluster on `sync://` supported, with collection servers writing enhanced events themselves and no worker servers?
  - If yes, `worker` is optional, slice 8 covers both setups, and `app:server:role` warns rather than fails on `sync://`. Claude Code recommends yes: `sync://` keeps queue churn off the shared database.
  - If no, collection servers must use `doctrine://`.
- [ ] **Engines.** Which engines and modes must meet 1.8×? Is SQL Server supported for roles but not load-tested?
- [ ] **Database hosting.** Is the shared database usually self-run or managed (RDS, Cloud SQL)? This decides whether the docs recommend PgBouncer or ProxySQL, or only `max_connections`.
- [ ] **BI on the primary.** Is a read replica for reporting acceptable as a later phase, or must BI stop touching the primary once collection is clustered? This makes the BI-contention test a gate or only a report.
- [ ] **Queue retention ownership.** Who owns retention of failed messages: an administration-side job, or an operator purge? And how long may enhanced payloads wait in `messenger_messages` when workers are down?
- [ ] **Balancer.** Does `balancer` earn a role, or is `app:server:balancer-config` on the administration server enough?
- [ ] **`APP_SECRET`.** Must it match on every server? Anything one server signs with it and another checks needs auditing in slice 2.

### Where the team's input went

| Input | From | Where |
| --- | --- | --- |
| Measure database headroom before code; keep a measured gate in the roadmap | Product Owner, Architect, DBA | Slice 0 |
| Scope 1.8× to engines and modes with headroom; report the database limit instead of failing | Product Owner, DBA | Done when |
| Exclude SQLite from every role but `all` | Product Owner, DBA | Decisions |
| `collection_base_url` first, on its own | Product Owner | Slice 1 |
| Build order: gating, pull, balancer, files-only, load test | Product Owner | Rollout |
| PRAGMA out of done; a stable JSON contract instead | Product Owner | Open questions; `app:server:role` |
| Fix the roadmap: scheduled task on administration; rate limits after #456 | Product Owner | Slice 0 |
| Privacy timing needs an acceptance criterion | Product Owner, Architect | Configuration pull; Open questions |
| Additivity beyond `events`: views, other tables, message classes | Product Owner, Architect, DBA | Updates across servers |
| Updates page list stays inside the existing page | Product Owner | Updates across servers |
| Boot-time resolver like the dashboard switch; route files split; controllers removed; lists generated with a test per role | Architect | Roles |
| Missing routes: MCP OAuth endpoints and `HomeController` | Architect | Roles table |
| `McpAuthorizeController` stays in `routes_dashboard.yaml` | Architect (second opinion) | Roles |
| One console listener for refusals, plus guards in configuration writers | Architect, second opinion | Roles |
| Remove dead mail routing rather than pin it | Architect (second opinion) | Roles |
| Per-file atomic writes, no directory swap, no manual cache clear | Architect (second opinion) | Configuration pull |
| Keep the local `server_role` on pull | Architect (second opinion) | Configuration pull |
| Health metadata only on signed requests | Architect | Configuration pull |
| Connection budget, PgBouncer, warning at about 70% | Architect, DBA | The database |
| Index use read before any index change; queue index only if measured | DBA | The database |
| Online index builds per engine; `lock_timeout` | DBA | Updates across servers |
| Bounded age and purge for queued payloads | DBA | Slice 6 |
| Retention deletes in small batches | DBA | The database |
| No cluster table; YAML only | DBA | Decisions |

_By Claude Code (claude-opus-5-5), in Scott's JOIN-59 session._

## Product Owner

Yes, the database is likely to become the limit, and on MySQL anonymous it may be the limit from the start. ROADMAP.md (phase 1) says MySQL anonymous throughput already 'stopped on per-event commits waiting for the disk, which is phase 2's batching'. Adding collection servers adds writers in front of that same commit wait. doctrine:// also adds several queue reads and writes per enhanced event to the same database. Neither the 10–50M row table nor doctrine:// with workers has been measured yet. So phase 3 can still go ahead before phase 2, but only after a measurement slice shows how much database headroom there is. The 1.8x criterion should also be scoped to the engines and modes that have that headroom. The anchor is already drafted in this thread (docs/features/server-roles.md). Below are the changes I would make to it and the order I would build the work in.

**Approach**
- Slice 0, before any code: run the missing measurements as a 'database headroom' run (docs/LOAD-TESTING.md): doctrine:// with workers, a large events table, and one database fed by two load generators. It reports database CPU, I/O and commit wait per engine and mode. If headroom is under about 2x, phase 3 waits for phase 2's batching on that engine. The ROADMAP.md amendment (Scaling section, 'Each phase starts only when…') goes in this slice and keeps a measured gate, not an exception with no conditions.
- Narrow done criterion 1: 1.8x at the same p95 applies to PostgreSQL and MySQL/MariaDB enhanced, and anonymous only where Slice 0 shows headroom. Where the database is the limit, the load test must report that instead of failing silently.
- Exclude SQLite from every role except all: a file database cannot be shared across servers. app:server:role should refuse and explain why. Add this to the anchor's constraints.
- Ship collection_base_url first, on its own. Single servers that use a separate tracking hostname benefit from it today, and the config pull, the balancer and the trackers all depend on it.
- Then build in this order: (1) server_role gating in src/Kernel.php plus app:server:role; (2) the config pull and read-only copies; (3) app:server:balancer-config for Nginx and Caddy; (4) app:updates:apply --files-only plus the CI check that migrations on events are additive; (5) the multi-server load test.
- Move the PRAGMA and PRAGMA MCP work out of this anchor's done criteria. PRAGMA is a separate product. Aggregate's job is a stable app:server:role --json contract that PRAGMA can consume, and PRAGMA's side gets its own thread.
- Update ROADMAP.md phase 3 to match the spec: the scheduled task runs on administration, not on worker. The roadmap's line that rate-limit buckets are 'files under var/rate_limit' also needs updating after PR #456 (APCu).

**Risks**
- Privacy timing: a kill switch or sensitive-path exclusion turned on at the administration server takes up to about 60 seconds to reach collection servers, and longer if pulls fail. AGENTS.md says these rules must apply before anything is recorded, and invalid configuration must fail closed. We need an acceptance criterion for this gap and for what a collection server does when its configuration is stale.
- The 1.8x target may fail on MySQL anonymous for reasons phase 3 cannot fix (per-event commits). If we don't scope it now, the phase gets judged on phase 2's problem.
- doctrine:// puts the enhanced queue's polling, locking and deleting on the same database as event inserts. More workers can lower collection p95.
- During a mixed-release update, old code runs against the new schema. The CI additivity check only covers events, but reporting views and other tables change too.
- A list of servers on the Updates page grows the dashboard. Keep it inside the existing page, with a shown_when condition in config/navigation.yaml if needed.

**Open questions**
- Which engines and modes must meet the 1.8x target? If MySQL anonymous is in, phase 2's batching has to come first.
- When a collection server cannot pull configuration, should it keep collecting on the last version it has, or fail closed after a limit (for example 5 minutes)? This changes the privacy criterion.
- Is PRAGMA work part of this thread's definition of done, or a separate thread that builds on app:server:role --json?

_By -Product Owner- (claude-opus-5-5)._

## Back-end Architect/Engineer

Short answer: yes, the shared database becomes the limit, and from my side it hits two limits before the commit wait the Product Owner described. Every PHP-FPM worker on every collection server holds its own database connection, so connection count grows with the server count. Out of the box PostgreSQL allows 100 connections and MySQL 151. Second, doctrine:// polling adds load per worker, and the cluster only gets workers at all if its queue is moved off sync://, which is the default today. I agree with the Product Owner on scope and on measuring first. My additions: one boot-time role check built on how the dashboard switch already works, fixing the spec's route list (it misses some routes and wrongly puts the queue on workers), a config pull that fails closed, and keeping cluster details off the public health check.

**Approach**
- Role gating: add a ServerRole resolver read the way src/Kernel.php reads the dashboard switch today (environment first, then config/aggregate.yaml, with a file lock), with isDashboardFeatureEnabledForBoot() deriving 'off' from the role. Split config/routes_public.yaml into routes_collection.yaml (Receive, Script, ConsentScript, StandaloneConsentScript, TagScript, BrandingLogo, BrandingTheme, InternalTrafficPublic, Health) and routes_admin_api.yaml (ReportingApi, McpController, McpAuthorizeController). The spec's list leaves out the MCP OAuth routes (/oauth/*, /.well-known/*) and HomeController. Kernel::build() should also remove the controller definitions for routes a role doesn't load, as it already does for the dashboard.
- Commands: one console event listener checks the role against a list of commands each role allows (migrations, app:updates:apply without --files-only, configuration writers). Refusing to run belongs there, not repeated in every command.
- Queue and workers: a cluster needs the queue on doctrine://default. app:server:role should fail a collection server left on sync://, because enhanced writes would then stay on its request path and worker servers would sit idle. config/packages/messenger.yaml also sends SendEmailMessage to async. If any mail goes through that bus, a worker would need mail credentials, so pin email to sync:// on administration.
- Config pull: app:cluster:pull plus ClusterConfigController on administration, signed with an HMAC over the method, path, timestamp and body hash. Swap files in by renaming the directory, then clear the AggregateConfigLoader cache. A server whose config is past a staleness limit should fail closed (see questions).
- Health and status: /api/health is public, so release and config version should go out only on requests carrying a valid cluster signature. The administration server's Updates page fetches them signed and keeps them out of the anonymous response.
- For the DBA: connection limits per engine across N servers, how often doctrine:// consumers poll and lock messenger_messages, and whether that table needs its own index or connection pool. Phase 0 should measure connections and commit wait, not only CPU.

**Risks**
- Connections run out first: N servers × pm.max_children connections can pass max_connections well before CPU limits, so failures show up as connection errors rather than slower p95. The balancer configs and the app:server:role check should state the connection budget for each server.
- Privacy lag: the up-to-60-second delay before a kill switch reaches collection servers goes against AGENTS.md's 'apply before recording' rule. Pulling every minute narrows the window but doesn't close it.
- Mixed releases: a collection server on the old release keeps compiling messages for the old schema, and those sit queued in messenger_messages until a worker on the new release reads them. Message classes such as TrackEventMessage need the same additive-only rule as migrations on events, not just the events table.
- The tables Kernel uses to switch off dashboard controllers are a hand-kept list. A second role-based list will drift, so generate both from the route files, with a test for each role.

**Open questions**
- When a collection server's configuration is past the staleness limit, should it keep collecting anonymous events only, or answer 503? This decides whether the pull path needs a fail-closed mode.
- Does any mail actually go through the Messenger async route today? If yes, I keep email off workers; if no, the routing line can go.
- Is a cluster without doctrine:// (every collection server writing directly) supported? If yes, the worker role is optional and the 1.8x test has to cover both setups.

_By -Architect- (claude-opus-5-5)._

**Second opinion:**

**What I would change or dispute**
- Keep McpAuthorizeController in config/routes_dashboard.yaml, not routes_admin_api.yaml. It is already registered in routes_dashboard.yaml and config/services_dashboard.yaml because OAuth consent requires a dashboard session; the headless OAuth metadata and token endpoints (/oauth/*, /.well-known/*) already live on McpController. Moving McpAuthorizeController into routes_admin_api.yaml would break an administration server running with DASHBOARD_ENABLED=0.
- Do not swap config/ by directory rename or clear ParsedYamlCache manually. config/ holds shipped framework files (packages/, services*.yaml, routes*.yaml) that are not part of the pull bundle. app:cluster:pull must write each pulled file to a sibling temp file and rename() it in place. ParsedYamlCache (src/Service/ParsedYamlCache.php) keys entries by xxh128 content hash and prunes old hashes on write, while AggregateConfigLoader implements ResetInterface (reset between Messenger messages), so a separate cache-clear step is unnecessary.
- Remove the dead SendEmailMessage routing instead of pinning it. TransactionalMailer (src/Service/Email/TransactionalMailer.php) sends directly through mailer.transport_factory and never dispatches SendEmailMessage on the Messenger bus. Drop the unused SendEmailMessage, ChatMessage, and SmsMessage lines from config/packages/messenger.yaml.

**What it missed**
- Pulling config/aggregate.yaml will overwrite the node's own server_role. If an operator configures server_role: collection in YAML rather than SERVER_ROLE in the environment, pulling the administration server's config/aggregate*.yaml verbatim turns the node into administration. app:cluster:pull must preserve the local server_role key when writing config/aggregate*.yaml.
- Enforcing read-only config in services, not just CLI listener. Guarding commands via a console listener isn't enough if a headless endpoint or future script calls a config writer; AggregateConfigLoader::save() and the config-writing services (WebsiteConfigManager, WebsiteScopeConfigManager, GoalsConfigManager, TagManagerConfig) should also check ServerRole and refuse on collection and worker.

**What I agree with**
- I agree with gating routes and container definitions in src/Kernel.php, requiring signed requests for cluster health metadata on /api/health, enforcing additive compatibility on TrackEventMessage, and sizing database connection budgets across PHP-FPM and messenger:consume workers before Phase 3 scales out.

_By -Architect- (gemini-4-argon), second opinion on claude-opus-5-5._

## DBA

Yes, the shared database becomes the limit, and it is what the 1.8x test will measure. I agree with the Product Owner and the Architect on measuring first, so I add only the database side. The limits come in this order. (1) Connections: the Architect covered the budget, and I add pooling per engine. (2) Commit wait: per-event commits on MySQL anonymous, which is phase 2's batching. (3) Index write amplification on events. (4) messenger_messages churn under doctrine://. (5) BI reads on the same primary. Server roles need no schema change: the cluster list and config version stay in YAML, and no cluster table is added. The only schema work is optional and measured: possible index changes on events and on messenger_messages.

**Approach**
- Slice 0 report, per engine and mode: DB CPU, connection count, commit or fsync wait, and the number of queue statements per enhanced event. Run it with a BI-style query loop going at the same time, because phase 3 keeps BI reads on the primary (bi_anonymous_* views over events).
- Connection budget: on PostgreSQL, persistent connections mean N servers × pm.max_children, plus workers, plus the scheduled task, must stay under max_connections. docs/DATABASE.md and DESIGN-DECISIONS ('PostgreSQL connections kept open by default') say Aggregate keeps no session state, so I'd document PgBouncer in transaction mode for PostgreSQL clusters. On MySQL and MariaDB, connections are opened per request (persistence is off there), so the count follows in-flight requests, not worker count. app:server:role should print the budget and warn above ~70% of max_connections.
- Index write amplification: events carries five secondary indexes (docs/install_fresh.sql: website_token, session_id, visitor_id, consent_state, and (privacy_mode, website_token, created_at)). Every insert from every server maintains all of them. Before any change, read per-index use (pg_stat_user_indexes, MySQL sys.schema_unused_indexes). Only then propose dropping an unused one. The Slice 0 report may show that consent_state has too few distinct values to help any query.
- Queue table: messenger_messages has three single-column indexes (queue_name, available_at, delivered_at). The consumer's claim query filters on all three and sorts by available_at. Compare the plan with a composite (queue_name, available_at, delivered_at) at 3+ workers. Symfony's Doctrine transport manages this table's schema, so only add an index through a migration if the measurement justifies it. Otherwise tune PostgreSQL autovacuum on this table, whose rows are inserted, updated and deleted continuously, and watch InnoDB history length on MySQL.
- Live migrations: while the other servers are still on the old release, an events change must be additive and nullable or defaulted. Migrations that build indexes on events must not lock inserts. PostgreSQL: CREATE INDEX CONCURRENTLY with isTransactional(): false, and lock_timeout on any ALTER so it cannot sit in the lock queue behind a long BI query and stall every server's inserts. MySQL and MariaDB: ALGORITHM=INPLACE, LOCK=NONE. SQL Server: ONLINE=ON where the edition allows it. Rollback for each is a DROP INDEX or a drop of the added column. Extend the Architect's CI additivity check to flag NOT NULL without a default and column renames.
- Retention and privacy: the failed transport (doctrine://default?queue_name=failed) and a backlog behind stopped workers keep full enhanced payloads in messenger_messages, outside the events retention rules. Add a bounded age for failed and undelivered rows, and a documented purge. Version20260724000500 already deletes TrackEventMessage rows, which is a precedent. The scheduled retention and archive job now runs only on administration. It must delete in small batches so it doesn't hold locks or flood WAL or replication while collection servers insert.

**Risks**
- Connection exhaustion looks like HTTP 500s or 'too many connections' at the balancer, not a slower p95. The 1.8x test would then fail for a reason that has nothing to do with the code.
- The MySQL anonymous commit wait caps scaling regardless of server count (ROADMAP phase 1). The 1.8x criterion must say so per engine.
- Heavy BI queries or a large archive delete on the one primary can slow every collection server at the same moment. A load test with no concurrent BI reads would overstate headroom.
- PostgreSQL table bloat on messenger_messages and on a fast-growing events table, if autovacuum falls behind with several writers.
- A composite index added to messenger_messages can be reported as schema drift by Doctrine's schema tooling, or dropped by a later Symfony schema sync.
- IDX_EVENTS_PRIVACY_SITE_CREATED on a 10–50M row table is expensive to build. Run unthrottled, it will compete with live inserts.
- I did not read the archive/retention code, so I haven't confirmed its batch size or lock behaviour. I also haven't confirmed whether TrackingFailureRetryRunner purges the failed transport.

**Open questions**
- Is the shared database usually self-run or managed (RDS, Cloud SQL)? That decides whether we can document PgBouncer or ProxySQL, or only tune max_connections.
- Must SQL Server be in the 1.8x test, or is it supported for roles but not load-tested? SQLite is already excluded from every role except all.
- Is read-replica reporting acceptable as a later phase, or must BI never touch the primary once collection is clustered? That decides whether the BI-contention test is a gate or only a report.
- Who owns failed-message retention (an administration-side job, or an operator purge)? And may enhanced payloads stay in messenger_messages for days when workers are down?

_By -DBA- (claude-sonnet-5-5)._

## To do

_The work this spec plans, as `- [ ] …` items, each naming the role that does it. Whoever finishes an item checks it off in the same commit as the work: `- [x] … (done in JOIN-59)`._

- [ ] Approver: answer the open questions (staleness, PRAGMA, `sync://`, engines, database hosting, BI on the primary, queue retention, balancer, `APP_SECRET`)
- [ ] Product Owner: bring the anchor's done criteria in line with the answers
- [ ] DBA: run the slice 0 database headroom test and write its report into `docs/LOAD-TESTING.md`
- [ ] Product Owner: amend `ROADMAP.md`: the measured gate, and phase 3's text (scheduled task on administration, rate limits after #456, API and MCP on administration)
- [ ] Back end: `collection_base_url` (slice 1)
- [ ] Back end: `ServerRole`, the route split, controller removal generated from the route files, the command listener and service guards, the SQLite refusal, `app:server:role` with versioned JSON, and the dead mail routing removed (slice 2)
- [ ] Back end: the configuration pull, signed health metadata and pull-after-save (slice 3)
- [ ] Back end: `app:server:balancer-config` for Nginx and Caddy (slice 4)
- [ ] Back end: `--files-only`, the additivity checks for tables and message classes, and the Updates page server list (slice 5)
- [ ] DBA: confirm retention batch size and locking and whether the failed transport is purged; then a bounded age and purge for queued payloads (slice 6)
- [ ] Back end: the two-servers-one-database fresh-install CI job (slice 7)
- [ ] DBA: the multi-server load test with the BI query loop, and any measured index change (slice 8)
- [ ] Docs: a server-roles guide, the balancer guide, `docs/DATABASE.md` connection budgets, and `docs/DESIGN-DECISIONS.md` entries for roles and the configuration pull

_By Claude Code (claude-opus-5-5), in Scott's JOIN-59 session._
