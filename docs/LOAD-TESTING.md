# Load testing and capacity

How many collection requests one Aggregate server handles, how that was
measured, and how to measure your own server. The test sends synthetic page
views to `/api/receive`, the endpoint every tracked page view and event uses,
at rising rates until the server falls behind.

## Results

Measured on 14 October 2026 on GitHub's standard Linux runners, at commits
`f925700` and `2f92705` of this guide's pull request. Every request ran on one
machine:

- 4 vCPU and 15 GB of memory, shared with other GitHub customers. The CPU model
  changed from run to run, among AMD EPYC and Intel Xeon generations.
- Nginx with the [documented site configuration](nginx/aggregate-analytics.conf),
  over plain HTTP on the loopback interface (no TLS), and PHP 8.3 with PHP-FPM
  (16 static workers) and production OPcache settings.
- The database on the same machine: SQLite, or PostgreSQL 16 or MySQL 8.0 in a
  container with default settings.
- The k6 load generator on the same machine too, using 6–8% of its CPU.
- `MESSENGER_TRANSPORT_DSN=sync://`, so enhanced events are written during the
  request, as in a default installation.

Each stage sends requests at a fixed rate for 30 seconds: 50, 100, 150, 200,
250, 300, 350, 400, 500 and 600 per second. A stage is **sustained** when the
server delivers at least 95% of the rate, fewer than 1% of requests fail, and
95% of responses (p95) arrive within 500 ms. The sustained rate of a run is the
highest stage before the first one that is not.

Two configurations were measured:

- **Default:** Aggregate as installed, which now includes the
  [cached YAML parsing](#optimizations).
- **Tuned:** the same plus two server settings from the
  [deployment guide](../DEPLOYMENT.md#performance): OPcache preloading and, with
  MySQL and PostgreSQL, persistent database connections.

| Setup | Default, sustained per second (1 run) | Tuned, sustained per second (2 runs) | Server CPU per event, default → tuned |
| --- | ---: | ---: | ---: |
| MySQL 8.0, anonymous | 200 | 250–300 | 15.0 → 9.9–11.5 ms |
| MySQL 8.0, enhanced | 200 | 250 | 14.6 → 11.7–11.8 ms |
| PostgreSQL 16, anonymous | 100 | 350–500 | 26.7 → 5.4–9.0 ms |
| PostgreSQL 16, enhanced | 100 | 250–400 | 28.7 → 7.0–10.9 ms |
| SQLite, anonymous | 200 | 250 (1 run) | 13.1 → 10.7 ms |
| SQLite, enhanced | 200 | none: writes stalled (1 run) | 9.7 ms (newer CPU) |

*Server CPU per event* is the CPU time of PHP, the database and Nginx divided
by the events stored. On the same CPU model (AMD EPYC 7763), tuning cut it by
19–34% with MySQL, 18% with SQLite and 62% with PostgreSQL; the lowest tuned
PostgreSQL figures come from newer CPUs. Multiply a rate per second by 60 for
the rate per minute: 250 per second is 15,000 per minute.

What the runs show:

- **The limit is CPU, and mostly PHP.** At the limit the CPU was 98–100% busy
  (less with SQLite, which also waits for the disk), PHP used 72–92% of it, the database 6–24%, and Nginx and k6 the rest.
- **With default settings, PostgreSQL sustains half of what MySQL does.** PHP
  opens a new database connection for every request, and PostgreSQL starts a
  server process and checks the password with SCRAM for each one, where MySQL
  reuses threads. Its database CPU per event was 6.7–7.0 ms against MySQL's
  1.5–1.6 ms.
- **Tuned, PostgreSQL catches up and can pass MySQL.** Persistent connections
  remove that cost: PostgreSQL's database CPU per event fell to 0.4–0.7 ms, and
  on the same CPU model tuned PostgreSQL and MySQL needed about the same CPU per
  event.
- **SQLite is fast until writes collide.** It sustained 200–250 per second in
  most runs, but in some runs requests stalled from the first stage with the
  CPU mostly idle and the disk busy (I/O wait above 20%), and the server
  answered with errors and time-outs. It happened in both privacy modes, at
  rates as low as 50 per second. SQLite allows one write at a time, and the
  [database guide](DATABASE.md#sqlite) already recommends it only for small
  deployments.
- **The hardware matters too.** The same setup's CPU per event differed by up to
  two times between CPU models. CPU steal, time taken by other virtual
  machines, was 0%.
- **Overload delays events more than it loses them.** Past the limit, requests
  queue and p95 climbs to several seconds, but in the MySQL and PostgreSQL runs
  at most 2 requests per run went unanswered, and the server kept serving at
  its maximum rate. The tracker
  sends with `fetch` and `keepalive`, without a time-out or a retry, so a browser
  usually waits rather than resending. A queue that keeps growing is eventually
  refused, as the time-outs in the stalled SQLite runs show.

Before the YAML cache, four runs of the default configuration sustained 150–300
per second with MySQL, 50–175 with PostgreSQL and 125–250 with SQLite, in line
with these figures given the CPU differences.

### Sizing a server

Plan from these per-vCPU figures, about 70% of the lowest measured limit, which
leaves room for maintenance jobs, the dashboard, syncs and spikes:

| Database | Default settings | Tuned (preloading and persistent connections) |
| --- | ---: | ---: |
| MySQL or MariaDB | 35 per vCPU at your peak | 45 per vCPU at your peak |
| PostgreSQL | 15 per vCPU at your peak | 45 per vCPU at your peak |
| SQLite | low-traffic sites only | low-traffic sites only |

For one instance with its database on the same server, that gives:

| Server | MySQL or MariaDB, default | PostgreSQL, default | MySQL, MariaDB or PostgreSQL, tuned |
| --- | ---: | ---: | ---: |
| 2 vCPU | 70 per second (4,200 per minute) | 30 per second (1,800 per minute) | 90 per second (5,400 per minute) |
| 4 vCPU | 140 per second (8,400 per minute) | 60 per second (3,600 per minute) | 180 per second (10,800 per minute) |
| 8 vCPU | 280 per second (16,800 per minute) | 120 per second (7,200 per minute) | 360 per second (21,600 per minute) |

Only 4 vCPU was measured; the other rows assume capacity grows with the CPU, as
it should while CPU is the limit. MariaDB was not tested; it is expected to
behave like MySQL.

Peak traffic matters, not the daily total. Each page view and each tracked event
is one request. If a site's busiest minute carries five times its average
minute, which is common, **every 10 requests per second at the peak covers
about 5 million requests a month**. A 2-vCPU instance with tuned settings
(90 per second) therefore covers about 45 million requests a month at that
ratio, and with default PostgreSQL (30 per second) about 15 million. Confirm on
your own hardware with the test below.

These figures cover collection requests only. Serving the tracker script,
consent banner and tag manager adds load unless a CDN or browser cache serves
them, and TLS adds Nginx CPU that the test did not include. A database on its
own server takes its CPU share off the web server but adds a network round trip
to every request.

### Where the time goes

Profiling 900 requests per setup with xhprof, at a steady 20 per second,
showed where PHP's time goes on a collection request:

- **Parsing `config/aggregate.yaml`:** about a third of PHP's time on MySQL,
  because every request parsed the whole file again.
- **Saving the event:** about a fifth, roughly half of it Doctrine ORM's own
  work around a one-row insert.
- **Loading classes:** about a tenth, around 625 classes per request.
- **PostgreSQL only:** opening the connection took about 6 ms and the commit
  about 5.5 ms, together about a third of the request. PostgreSQL starts a
  server process and checks the password for every new connection.

## Optimizations

Each change below was measured with the `compare` suite: the two versions are
installed side by side on one machine and take turns (base, head, head, base)
at 40 requests per second, below any limit, so differences between runners
cancel out. The table shows the change in server CPU per event, the CPU time
of PHP, the database and Nginx for each stored event.

| Setup | Cached YAML parsing | OPcache preloading | Persistent connections |
| --- | ---: | ---: | ---: |
| MySQL 8.0, anonymous | −28% | −15% | −8% |
| MySQL 8.0, enhanced | −22% | −14% | +4% |
| PostgreSQL 16, anonymous | −18% | −9% | **−51%** |
| PostgreSQL 16, enhanced | −18% | −9% | **−44%** |
| SQLite, anonymous | −26% | −15% | no effect |
| SQLite, enhanced | −29% | −9% | no effect |

- **Cached YAML parsing** is part of Aggregate. Each request still reads the
  YAML files, but an unchanged file's parsed result comes from
  `var/cache/<environment>/parsed-yaml`, keyed by the file's exact contents
  ([ParsedYamlCache](../src/Service/ParsedYamlCache.php)). Median response
  times fell by the same 17–29%.
- **OPcache preloading** is a PHP setting: PHP-FPM loads the classes Symfony
  lists in `config/preload.php` once at start-up instead of on every request.
  It needs PHP-FPM to be restarted after every update. See
  [preloading](../DEPLOYMENT.md#performance) in the deployment guide.
- **Persistent connections** keep each PHP-FPM worker's database connection
  open between requests, set with `persistent=true` in `DATABASE_URL`.
  PostgreSQL's database CPU per event fell by 83% and median response times
  by 37–47%; MySQL, whose connections are cheap, barely changed. See
  [PostgreSQL tuning](DATABASE.md#performance-tuning).

The [results](#results) include the YAML cache in both configurations; the
tuned configuration adds the two settings.

## Clearing the cache under traffic

Editing YAML settings needs no cache clearing: each file's parsed result is keyed
by its contents, so an edit takes effect on the next request. `cache:clear` is
needed after changing Symfony's own configuration (`config/packages/`, the
`services*.yaml` and `routes*.yaml` files, or `DASHBOARD_ENABLED`) and after
updates, which the updater does for you.

The `cache` suite sends a steady 100 requests per second and, in the middle of
each stage, clears the cache, reloads or restarts PHP-FPM. Two runs on every
setup gave:

| Action during traffic | Failed collection requests |
| --- | --- |
| `php bin/console cache:clear` alone | none in 12 runs; the 4–8 seconds of rebuilding raised response times, once to a p95 of 634 ms |
| `cache:clear`, then `systemctl reload php8.3-fpm` with the packaged `process_control_timeout = 0` | 3–4 requests (0.1%) in 10 of 12 runs, 0.3–0.6% in the two below: busy workers are stopped at once |
| The same reload with `process_control_timeout = 10s` | none in 6 runs |
| `systemctl restart php8.3-fpm` | 9–12 requests (0.3–0.4%) every time: the socket disappears while PHP-FPM restarts |

Twice in 24 attempts, `cache:clear` itself failed, most likely racing requests
that were rebuilding the same cache at that moment. Those two stages also lost
the most requests (0.3–0.6%, one of them a server error), and the next
`cache:clear` succeeded.

So, to apply a Symfony configuration change without losing collection requests:

1. Set `process_control_timeout = 10s` in the `[global]` section of
   `php-fpm.conf` (on Ubuntu, `/etc/php/8.3/fpm/php-fpm.conf`) and restart PHP-FPM
   once, outside busy hours.
2. Run `php bin/console cache:clear`; if it reports an error, run it again.
3. Reload PHP-FPM gracefully: `sudo systemctl reload php8.3-fpm`. A reload also
   reloads [preloaded classes](../DEPLOYMENT.md#performance).

Avoid restarting PHP-FPM during traffic. The updater's
[maintenance mode](UPDATES.md) answers every request, including collection,
with 503 while it works; schedule updates for quiet hours.

## Run the test

The test is [tests/Load/receive.js](../tests/Load/receive.js), a
[k6](https://grafana.com/docs/k6/latest/) script. Run it only against a
**disposable installation**: every request stores an event, and the test
stores tens of thousands. Never run it against a production server.

### On GitHub

The **Load test** workflow
([.github/workflows/load-test.yml](../.github/workflows/load-test.yml)) installs
a fresh copy for each of SQLite, PostgreSQL 16 and MySQL 8.0 and runs anonymous
and enhanced traffic on each. It has four suites:

| Suite | What it measures | Pull request label | Runner time |
| --- | --- | --- | ---: |
| `load` | The step test above: capacity at rising rates; the tuned variant adds OPcache preloading and persistent connections | `load-test`, `load-test-tuned` | about 50 minutes |
| `profile` | xhprof profiles at 20 requests per second: PHP time per request by step and the functions with the most own time | `profile` | about 25 minutes |
| `compare` | The pull request's code against its base on one machine: change in server CPU per event and response times | `compare` | about 60 minutes |
| `cache` | Steady traffic while the cache is cleared and PHP-FPM reloads or restarts: failures and response times in each stage | `cache-clear` | about 30 minutes |

The `compare` suite can also keep the code the same and change one setting on
the head side: label `compare-persistent` for persistent database connections,
or `compare-preload` for OPcache preloading. Start any suite from **Actions →
Load test → Run workflow**, where you can also change the stage rates, the
comparison base and the variant. Runner time is the total of the six jobs, which
run in parallel. Results appear in each job's summary and as a notice on the
run.

### Against your own server

1. Install a disposable copy of Aggregate on hardware like your production
   server, with the same web server, PHP settings and database.
2. Register a website for the test, for example with
   `php bin/console app:create-website`, entering `loadtest.example` as the
   domain. Note its token from `config/websites.yaml`.
3. To spread requests over many simulated visitors, as real traffic is, the
   test sends client addresses from the reserved benchmarking range
   `198.18.0.0/15` in `X-Forwarded-For`. The server uses them only if
   `TRUSTED_PROXIES` includes the address the test connects from. Set that on
   the disposable server only. Otherwise add `-e SPOOF_CLIENTS=0`, and set
   `rate_limit_per_minute` above the highest rate times 60, since every
   request then comes from one address.
4. Install [k6](https://grafana.com/docs/k6/latest/set-up/install-k6/) on a
   separate machine near the server, and run:

   ```bash
   k6 run \
     -e BASE_URL=https://analytics-loadtest.example.net \
     -e WEBSITE_TOKEN=your-test-website-token \
     -e ORIGIN=https://loadtest.example \
     -e MODE=anonymous \
     -e RATES=25,50,100,150,200,300,400 \
     tests/Load/receive.js
   ```

5. Summarize the run with
   `python3 tests/Load/report.py --summary k6-summary.json --label "My server"`.
   Add `--vmstat` with the output of `vmstat -n -t 1` recorded on the server
   during the run to see CPU use per stage.

| Setting | Default | Purpose |
| --- | --- | --- |
| `BASE_URL` | `http://127.0.0.1` | Installation address |
| `WEBSITE_TOKEN` | none | Public token of the test website (required) |
| `ORIGIN` | `https://loadtest.example` | `Origin` header; must pass the website's domain rules |
| `MODE` | `anonymous` | `anonymous`, `enhanced` (consent granted) or `mixed` (30% enhanced) |
| `RATES` | `50,100,125,150,175,200,225,250,300,350` | Requests per second, one stage each |
| `STAGE_SECONDS` | `30` | Length of each stage |
| `GAP_SECONDS` | `10` | Pause between stages, so a backlog can drain |
| `WARMUP_SECONDS` | `10` | Unreported warm-up at 20 requests per second; `0` skips it |
| `VISITORS` | `20000` | Simulated client addresses |
| `SPOOF_CLIENTS` | `1` | Send simulated addresses in `X-Forwarded-For`; `0` sends none |
| `MAX_VUS` | `1000` | Most requests k6 keeps in flight at once |

A request counts as stored only when the response says the event was
`recorded` (anonymous) or `accepted` (enhanced); ignored events also answer
202. *Dropped* requests in the report are ones k6 could not start because
`MAX_VUS` requests were already waiting, a sign the server had fallen behind.

## Not covered yet

- A load generator on its own machine and a dedicated server, which would
  remove both the shared-runner noise and k6's share of the CPU.
- A queue: `doctrine://` with background workers for enhanced events.
- FrankenPHP (including its worker mode, which keeps the application loaded
  between requests) and Apache, SQL Server and MariaDB, and other PHP-FPM
  worker counts.
- PgBouncer, an alternative to persistent connections for PostgreSQL.
- Servers with other than 4 vCPU, and TLS between browsers and Nginx.
- Writing anonymous events with a direct insert instead of Doctrine ORM.
- Requests for the tracker, consent and tag manager scripts, dashboard use, and
  `app:analytics:maintain` running during load.

The [roadmap](../ROADMAP.md) tracks this work.
