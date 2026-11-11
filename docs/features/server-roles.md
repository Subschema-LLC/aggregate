---
files:
  - ROADMAP.md
  - src/Kernel.php
  - src/Security/IpRateLimiter.php
  - config/aggregate.yaml.example
  - config/routes_public.yaml
  - docs/LOAD-TESTING.md
  - docs/RELEASES.md
  - docs/SCHEDULED-TASKS.md
  - docs/DATABASE.md
  - docs/DESIGN-DECISIONS.md
---
# Scaling phase 3: server roles sharing one database

## Problem
One Aggregate server handles collection, administration, scheduled jobs and Messenger workers, so collection capacity stops at what that one machine can do. Phase 1 fell short of its CPU targets (14–15% as installed against 15%; an estimated 24–32% with preloading against 30%). Operators with heavy traffic need to add collection servers in front of the database they already run, without a new required service. The shared database may become the next limit, and nobody has measured that yet: the 10–50 million row events table and doctrine:// with workers are still untested (ROADMAP.md phase 1).

## For
Operators of self-hosted Aggregate installations whose event traffic is more than one server can handle, and the agents that set up their servers through PRAGMA.

## Done when
- A multi-server load test shows two collection servers handling at least 1.8 times one server's events per second at the same p95. It also reports where the shared database becomes the limit, including the doctrine:// queue under worker load.
- A server with no server_role, or an unknown one, behaves exactly as today: existing install, update and test paths pass unchanged.
- On a collection or worker server, routes, commands, scheduled jobs and dashboard pages outside its role are not served or refuse to run, and the message names the administration server.
- Collection and worker servers get their configuration from the administration server. A server still on an old configuration version after five minutes is flagged on the Updates page.
- app:server:role reports each server's role, release, configuration version and requirement checks with fixes, as text and JSON, and the Updates page shows the same list for every server.
- Reference Nginx and Caddy balancer configurations spread collection traffic, limit /api/receive per address, keep addresses out of logs and overwrite X-Forwarded-For.
- Updating a cluster runs migrations once on the administration server, then files only on the other servers. CI rejects a release whose migrations on events are not additive.
- No visitor address, User-Agent string or exact timestamp passes between servers beyond what one server handles today, and config/secrets never leaves the administration server.
- The same role setup works through YAML, the environment override, the CLI and PRAGMA, including PRAGMA's MCP server with plan-and-approve.

## Constraints
- No new required service: the balancer is the existing Nginx or Caddy, and the database is the one already in use.
- server_role: all stays the default.
- This phase starts before phase 2. The first slice amends the rule in ROADMAP.md (line 180) that each phase starts only when load testing shows the previous one has run out.
- The roadmap's phase 3 text puts the scheduled task on worker. The spec moves it to administration, so the roadmap needs updating to match.
- Rate-limit buckets now sit in APCu when it is available (PR #456), not only in var/rate_limit. Each server's limiter is a backstop; the balancer's limit is the one that counts.
- Keep support for all five database engines, and keep configuration in YAML, never in database tables.

## Out of scope
- A proxy or load balancer built into Aggregate.
- High availability for the balancer or the database.
- Database sharding or more than one write database.
- Spreading the reporting API, MCP, OAuth sign-in or the dashboard over several servers.
- Redis, RabbitMQ and moving anonymous events off the request path (phase 2).
- Configuration tools in Aggregate's own MCP server, which stays read-only.

## Decisions
- None yet.
