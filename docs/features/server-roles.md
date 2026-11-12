---
files:
  - ROADMAP.md
  - src/Kernel.php
  - src/Security/IpRateLimiter.php
  - config/aggregate.yaml.example
  - config/routes_collection.yaml
  - config/routes_admin_api.yaml
  - config/routes_branding.yaml
  - config/routes_health.yaml
  - src/Service/Cluster/ServerRole.php
  - docs/LOAD-TESTING.md
  - docs/RELEASES.md
  - docs/SCHEDULED-TASKS.md
  - docs/DATABASE.md
  - docs/DESIGN-DECISIONS.md
  - docs/specs/JOIN-59-server-roles.md
  - config/routes_dashboard.yaml
  - config/packages/messenger.yaml
  - src/Service/AggregateConfigLoader.php
  - src/Service/Operations/ProcessingTasks.php
  - src/Controller/HealthController.php
  - docs/UPDATES.md
  - docs/ALERTS.md
  - DEPLOYMENT.md
  - PLESK-DEPLOYMENT.md
---
# Scaling phase 3: server roles sharing one database

## Problem
One Aggregate server handles collection, administration, scheduled jobs and Messenger workers, so collection capacity stops at what that one machine can do. Phase 1 fell short of its CPU targets (14–15% as installed against 15%; an estimated 24–32% with preloading against 30%). Operators with heavy traffic need to add collection servers in front of the database they already run, without a new required service. The shared database may become the next limit, and nobody has measured that yet: the 10–50 million row events table and doctrine:// with workers are still untested (ROADMAP.md phase 1).

## For
Operators of self-hosted Aggregate installations whose event traffic is more than one server can handle, and the agents that set up their servers through PRAGMA.

## Done when
- A database headroom report (slice 0) exists for each engine and mode: database CPU and I/O, connections, commit wait, and queue statements per enhanced event, measured with a large events table, doctrine:// with workers and a BI query loop running. ROADMAP.md's phase rule becomes this measured gate.
- A multi-server load test shows two collection servers handling at least 1.8 times one server's events per second at the same p95 for PostgreSQL and MySQL/MariaDB enhanced, and for anonymous wherever the headroom report allows it, with sync:// and with doctrine:// and workers. Where the shared database is the limit (connections, commit wait, queue churn), the test reports that instead of failing silently.
- A server with no server_role, or an unknown one, behaves exactly as today: existing install, update and test paths pass unchanged. SQLite refuses every role except all.
- On a collection or worker server, routes, controller definitions, commands, configuration writers and scheduled jobs outside its role are not registered or refuse to run, and the message names the administration server. A test boots each role and checks its routes.
- Collection and worker servers get their configuration only from the administration server, through signed requests and per-file atomic writes that keep the server's own role, by app:cluster:sync every minute. A server pauses collection (503, out of the balancer's rotation) when the administration server has recorded a newer configuration version, as a processing_tasks fingerprint, that it has not applied within 5 minutes, or when it has not verified its version for 5 minutes. An unreachable administration server alone does not pause it, and a long update on the administration server does not either. The administration server alerts on any server that has not pulled for 5 minutes.
- app:server:role reports each server's role, release, configuration version, queue transport, database connection budget and requirement checks with fixes, as text and as JSON with a schema_version. The Updates page shows the same for every server, inside the existing page.
- On the administration server, app:server:balancer-config prints reference Nginx and Caddy balancer configurations from YAML settings. They spread collection traffic, limit /api/receive per address (Caddy only with the caddy-ratelimit module), keep addresses out of access logs, overwrite X-Forwarded-For and take paused servers out of rotation.
- Updating a cluster runs migrations once on the administration server, then files only on the other servers. CI rejects non-additive migrations on the tables and views the collection path and Messenger handler use, and non-additive changes to message classes such as TrackEventMessage.
- No visitor address, User-Agent string or exact timestamp passes between servers beyond what one server handles today, config/secrets never leaves the administration server, and queued enhanced payloads have a bounded age.
- A generic Linux guide and a Plesk guide take an operator through every role, the shared database, the firewall and the balancer without PRAGMA, and each is followed once on fresh servers.
- PRAGMA sets up every role: install with a role, the shared MariaDB and the firewall between servers, the pull timer and workers, and the balancer site. The same setup works through PRAGMA's MCP server, where an agent's plan changes nothing until a person approves it in PRAGMA's web interface.

## Constraints
- No new required service: the balancer is the existing Nginx or Caddy, and the database is the one already in use.
- No step may require PRAGMA or another hosting panel.
- No setting may require the dashboard: each is YAML or an environment variable, and a command sets it with the same validation (Scott).
- server_role: all stays the default.
- This phase starts before phase 2 only on a measured gate: where the headroom report shows less than about 2x headroom for an engine and mode, phase 3 waits for phase 2's batching there. The first slice amends the rule in ROADMAP.md that each phase starts only when load testing shows the previous one has run out.
- ROADMAP.md's phase 3 text is brought in line with the spec: the scheduled task runs on administration, not on worker; rate-limit buckets sit in APCu when available (PR #456); the reporting API and MCP stay on administration.
- SQLite supports only server_role: all. A file database cannot be shared across servers.
- No schema change for roles: the cluster list and configuration version stay in YAML. Index changes on events or messenger_messages only after a measurement shows the need.
- Index builds and column changes on events must not block inserts from other servers (CONCURRENTLY and lock_timeout on PostgreSQL, INPLACE/LOCK=NONE on MySQL and MariaDB, ONLINE on SQL Server where available).
- Keep support for all five database engines, and keep configuration in YAML, never in database tables.

## Out of scope
- A proxy or load balancer built into Aggregate.
- High availability for the balancer or the database.
- Database sharding or more than one write database.
- Spreading the reporting API, MCP, OAuth sign-in or the dashboard over several servers.
- Redis, RabbitMQ and moving anonymous events off the request path (phase 2).
- Configuration tools in Aggregate's own MCP server, which stays read-only.

## Decisions
- Spec first, then build in reviewable slices (Scott).
- Rate limits at the balancer; each server's own limiter is a backstop (Scott).
- Configuration is pulled from the administration server, the only place YAML is edited (Scott).
- The reporting API, MCP, OAuth sign-in, the dashboard and the scheduled task stay on the administration server; worker servers run Messenger consumers only.
- collection_base_url ships first, on its own.
- A collection or worker server pauses collection (503) when the administration server has recorded a configuration version newer than its own for 5 minutes, or when it has not checked for 5 minutes. Being unable to reach the administration server alone does not pause it. The version is recorded as a processing_tasks fingerprint by app:cluster:sync, which runs every minute on every server (Scott, revised with the Architect).
- PRAGMA's side is part of JOIN-59 (Scott), but it is never required: everything PRAGMA automates is also documented for a generic Linux server and for Plesk (Scott).
- Clusters on sync://, with collection servers writing enhanced events themselves and no worker servers, are supported; worker is optional (Scott).
- doctrine:// with worker servers on the shared database is supported too, documented and tested, with a warning that it is the costliest setup for that database (Scott).
- The balancer has no role and runs no Aggregate: app:server:balancer-config on the administration server prints its configuration (Scott).
- Queued and failed messages are deleted by a scheduled job on the administration server after 7 days by default (Scott).
- The full spec, with the team's input and the open questions, is docs/specs/JOIN-59-server-roles.md.
