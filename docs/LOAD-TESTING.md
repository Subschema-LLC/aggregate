# Load testing and capacity

How much traffic one Aggregate server handles, how that was measured, and how
to measure your own server. The test sends synthetic page views to
`/api/receive`, the endpoint every tracked page view and event uses, at rising
rates until the server falls behind.

- [At a glance: hits per day](#at-a-glance-hits-per-day)
- [Results](#results)
- [Sizing a server](#sizing-a-server)
- [Disk space](#disk-space)
- [Where the time goes](#where-the-time-goes)
- [Optimizations](#optimizations)
- [Clearing the cache under traffic](#clearing-the-cache-under-traffic)
- [Run the test](#run-the-test)

## At a glance: hits per day

Every page view and every tracked event (a click, a form, a goal) is one
**hit**: one request to Aggregate. The tracker sends each hit as a CORS simple
request, so browsers make no OPTIONS preflight request before it, even when
Aggregate runs on another domain. With the database on the same server, one
Aggregate server handles about this many hits a day:

| Server | As installed | With OPcache preloading |
| --- | ---: | ---: |
| 2 vCPU | 1.2 million a day | 1.5 million a day |
| 4 vCPU | 2.4 million a day | 3 million a day |
| 8 vCPU | 4.8 million a day | 6 million a day |

- **These assume ordinary daily traffic,** where the busiest minutes bring about
  five times the day's average rate. If your traffic arrives in bursts (a
  newsletter, a TV or radio spot, a post that takes off, or most visitors in one
  hour), halve them.
- **They hold for MySQL, MariaDB and PostgreSQL,** with the setup page's
  default settings. SQLite handled as much in most runs but sometimes stalled,
  so keep it for trials and small sites.
- **A month is about 30 times as much:** a 2-vCPU server handles about 36
  million hits a month as installed.
- **In page views:** if visitors also trigger about one tracked event per page
  they view, divide by two.
- **Room to spare is included.** The figures use about 70% of the lowest rate a
  server sustained in the test, leaving the rest for maintenance, the
  dashboard, syncs and the unexpected.
- **Disk:** the events table grows by about 0.3–0.6 GB per million hits; see
  [Disk space](#disk-space).
- **Preloading** is a PHP setting in the [deployment guide](../DEPLOYMENT.md#performance)
  that gives about a quarter more.

Only 4-vCPU servers were measured; the 2- and 8-vCPU rows assume capacity grows
with the CPU, as it should while CPU is the limit. Confirm on your own hardware
with the [test](#run-the-test).

## Results

Measured on 14 October 2026 on GitHub's standard Linux runners, at commits
`f925700`, `2f92705` and `16a7927` of this guide's pull request. Every request
ran on one machine:

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

- **As installed:** Aggregate's defaults, including the
  [cached YAML parsing](#optimizations) and, with PostgreSQL, the persistent
  connections the setup page turns on.
- **With preloading:** the same plus OPcache preloading from the
  [deployment guide](../DEPLOYMENT.md#performance), and persistent connections
  with MySQL too.

| Setup | As installed, sustained per second | With preloading, sustained per second | Server CPU per event on AMD EPYC 7763 |
| --- | ---: | ---: | ---: |
| MySQL 8.0, anonymous | 200 (2 runs) | 250–500 (3 runs) | 13.4–15.0 → 9.9–11.5 ms |
| MySQL 8.0, enhanced | 200–350 (2 runs) | 250–300 (3 runs) | 14.6 → 11.7–11.8 ms |
| PostgreSQL 16, anonymous | 500 (1 run) | 300–500 (3 runs) | not measured → 10.2 ms |
| PostgreSQL 16, enhanced | 250 (1 run) | 250–400 (3 runs) | 13.6 → 10.9–11.5 ms |
| SQLite, anonymous | 200 (2 runs) | 250–300 (2 runs) | 13.1–13.4 → 10.7 ms |
| SQLite, enhanced | 200 (2 runs) | 250 (2 runs, one stalled) | 13.3 → 10.8 ms |

*Server CPU per event* is the CPU time of PHP, the database and Nginx divided
by the events stored, shown for the CPU model most runs had (as installed →
with preloading); the highest sustained rates came from newer CPUs. Multiply a
rate per second by 60 for the rate per minute: 250 per second is 15,000 per
minute.

PostgreSQL without persistent connections, the default before this change,
sustained 100 per second in both privacy modes, at 26.7–28.7 ms of CPU per
event on the same CPU.

What the runs show:

- **The limit is CPU, and mostly PHP.** At the limit the CPU was 98–100% busy
  (less with SQLite, which also waits for the disk), PHP used 72–92% of it, the
  database 6–24%, and Nginx and k6 the rest.
- **Opening connections was PostgreSQL's handicap.** Without persistent
  connections, PHP opens a new database connection for every request, and
  PostgreSQL starts a server process and checks the password with SCRAM for
  each one, where MySQL reuses threads. Its database CPU per event was 6.7–7.0
  ms against MySQL's 1.5–1.6 ms, and it sustained half as much. With persistent
  connections it fell to 0.4–0.7 ms, and PostgreSQL and MySQL needed about the
  same CPU per event.
- **SQLite is fast until writes collide.** It sustained 200–300 per second in
  most runs, but in 3 of 20 runs its writes stalled, for a stage or for the
  whole run, with the CPU mostly idle and the disk busy (I/O wait above 20%),
  even at 50 per second; the worst answered with errors and time-outs. SQLite allows one write at a time, and the
  [database guide](DATABASE.md#sqlite) already recommends it only for small
  deployments.
- **The hardware matters too.** The same setup's CPU per event differed by up to
  two times between CPU models. CPU steal, time taken by other virtual
  machines, was 0%.
- **Overload delays events more than it loses them.** Past the limit, requests
  queue and p95 climbs to several seconds, but in the MySQL and PostgreSQL runs
  at most 2 requests per run went unanswered, and the server kept serving at
  its maximum rate. The tracker sends with `fetch` and `keepalive`, without a
  time-out or a retry, so a browser usually waits rather than resending. A
  queue that keeps growing is eventually refused, as the time-outs in the
  stalled SQLite runs show.

Before the YAML cache, four runs of the default configuration sustained 150–300
per second with MySQL, 50–175 with PostgreSQL and 125–250 with SQLite.

### Sizing a server

The daily figures [above](#at-a-glance-hits-per-day) come from these rates per
vCPU, about 70% of the lowest sustained rate on the most common CPU (200 per
second as installed, 250 with preloading, on 4 vCPU):

| Database | As installed | With preloading |
| --- | ---: | ---: |
| MySQL, MariaDB or PostgreSQL | 35 per second per vCPU at your peak | 45 per second per vCPU at your peak |
| SQLite | small sites only | small sites only |

| Server | As installed | With preloading |
| --- | ---: | ---: |
| 2 vCPU | 70 per second (4,200 per minute) | 90 per second (5,400 per minute) |
| 4 vCPU | 140 per second (8,400 per minute) | 180 per second (10,800 per minute) |
| 8 vCPU | 280 per second (16,800 per minute) | 360 per second (21,600 per minute) |

**From a peak rate to hits per day.** Peak traffic matters, not the daily total.
A day has 86,400 seconds, so a server that handles 70 hits per second at its
peak handles 70 × 86,400 ÷ 5 ≈ 1.2 million hits a day when the busiest minutes
bring five times the day's average rate, and half that when they bring ten
times. To check your own ratio, compare your busiest hour's page views in your
current analytics with the day's total divided by 24: about 2–3 is ordinary
traffic, with short bursts above that; much more is bursty.

PostgreSQL with persistent connections turned off sustained less than half as
much; plan 15 per second per vCPU for it. MariaDB was not tested; it is
expected to behave like MySQL.

These figures cover collection requests only. Serving the tracker script,
consent banner and tag manager adds load unless a CDN or browser cache serves
them, and TLS adds Nginx CPU that the test did not include. A database on its
own server takes its CPU share (6–24%) off the web server but adds a network
round trip to every request.

### Disk space

The load test also measured how much the `events` table, with its indexes,
grew per stored event:

| Database | Anonymous events | Enhanced events |
| --- | ---: | ---: |
| MySQL 8.0 | 370–400 bytes | 550–590 bytes |
| PostgreSQL 16 | about 280 bytes | about 400 bytes |
| SQLite | about 350 bytes | about 480 bytes |

So plan about **0.3–0.6 GB per million hits**: a site with a million hits a day
adds 9–18 GB a month. The test's page views have short paths and no custom
properties; longer URLs and [custom data](DATA-MODEL.md) take more.
[Archiving](CONFIGURATION.md#archiving-and-retention) folds old rows into
counted cells and retention deletes them after their periods, which keeps the
table from growing without end.

### Where the time goes

Profiling 900 requests per setup with xhprof, at a steady 20 per second,
showed where PHP's time went on a collection request before the
[optimizations](#optimizations) below:

- **Parsing `config/aggregate.yaml`:** about a third of PHP's time on MySQL,
  because every request parsed the whole file again.
- **Saving the event:** about a fifth, roughly half of it Doctrine ORM's own
  work around a one-row insert.
- **Loading classes:** about a tenth, around 625 classes per request.
- **PostgreSQL only:** opening the connection took about 6 ms and the commit
  about 5.5 ms, together about a third of the request. PostgreSQL starts a
  server process and checks the password for every new connection.

The same profile in October 2026, with cached YAML parsing and PostgreSQL's
persistent connections, put a collection request at about 13 ms of PHP time
(median, including xhprof's own overhead), against 24 ms before:

- **Saving the event:** now the largest part, 28–32% for anonymous events
  (3.8–4.7 ms). Most of it is Doctrine ORM's work around the one-row insert;
  the commit itself took 0.4–1.2 ms.
- **Loading classes:** 2.3–2.7 ms for about 630 classes, the part
  [OPcache preloading](../DEPLOYMENT.md#performance) removes.
- **Parsing YAML:** 0.15 ms, about 1% of the request.
- **Enhanced events:** passing the event through Messenger to its handler took
  9–10 ms on PostgreSQL and SQLite. On MySQL a few commits were much slower
  (12 ms on average, with a median request of 13 ms), which one run cannot
  separate from noise on the shared runner.

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
  It needs a PHP-FPM reload after every update. See
  [preloading](../DEPLOYMENT.md#performance) in the deployment guide.
- **Persistent connections** keep each PHP-FPM worker's database connection
  open between requests, set with `persistent=true` in `DATABASE_URL`.
  PostgreSQL's database CPU per event fell by 83% and median response times
  by 37–47%; MySQL, whose connections are cheap, barely changed. The setup page
  therefore turns them on for PostgreSQL only. See
  [PostgreSQL tuning](DATABASE.md#performance-tuning).

The [results](#results) include the YAML cache and PostgreSQL's persistent
connections in both configurations; the other adds preloading.

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
| `load` | The step test above: capacity at rising rates, as installed; the tuned variant adds OPcache preloading, and persistent connections on MySQL | `load-test`, `load-test-tuned` | about 50 minutes |
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
- An installation with many registered websites.

The [roadmap](../ROADMAP.md) tracks this work.
