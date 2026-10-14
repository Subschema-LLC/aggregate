# Load testing and capacity

How many collection requests one Aggregate server handles, how that was
measured, and how to measure your own server. The test sends synthetic page
views to `/api/receive`, the endpoint every tracked page view and event uses,
at rising rates until the server falls behind.

## Results

Measured on 14 October 2026 on GitHub's standard Linux runners, in four runs
of the same test (the last at commit `ece63a3`). Every request ran on one
machine:

- 4 vCPU and 15 GB of memory, shared with other GitHub customers. The CPU model
  changed from run to run, among Intel Xeon and AMD EPYC generations.
- Nginx with the [documented site configuration](nginx/aggregate-analytics.conf),
  and PHP 8.3 with PHP-FPM (16 static workers) and production OPcache settings.
- The database on the same machine: SQLite, or PostgreSQL 16 or MySQL 8.0 in a
  container with default settings.
- The k6 load generator on the same machine too, using 2–7% of its CPU.
- `MESSENGER_TRANSPORT_DSN=sync://`, so enhanced events are written during the
  request, as in a default installation.

Each stage sends requests at a fixed rate for 30 seconds: 50, 100, 125, 150,
175, 200, 225, 250, 300 and 350 per second. A stage is **sustained** when the
server delivers at least 95% of the rate, fewer than 1% of requests fail, and
95% of responses (p95) arrive within 500 ms. The sustained rate of a run is the
highest stage before the first one that is not.

| Setup | Sustained per second (4 runs) | Most served when overloaded | Server CPU per event |
| --- | ---: | ---: | ---: |
| MySQL 8.0, anonymous | 150–300 | 196–342/s | 10–21 ms |
| MySQL 8.0, enhanced | 150–175 | 185–206/s | 20–23 ms |
| PostgreSQL 16, anonymous | 100–175 | 136–210/s | 20–36 ms |
| PostgreSQL 16, enhanced | 50–150 | 123–197/s | 22–39 ms |
| SQLite, anonymous | 175–250, and once none | 81–298/s | 11–20 ms |
| SQLite, enhanced | 125–150 | 196–201/s | 21 ms |

*Server CPU per event* is the CPU time of PHP, the database and Nginx divided
by the events stored. Multiply a rate per second by 60 for the rate per minute:
150 per second is 9,000 per minute.

What the runs show:

- **MySQL sustained 150 to 300 requests per second (9,000 to 18,000 per
  minute)** in every run, with p95 between 9 and 250 ms at 150 per second.
- **PostgreSQL sustained less: 50 to 175 per second.** Its database CPU per
  event was 4–8 ms against MySQL's 1–2 ms, and PHP also spent more time per
  request. A likely cause is that PHP opens a new database connection for every
  request, and PostgreSQL starts a server process and checks the password with
  SCRAM for each one, where MySQL reuses threads and caches authentication. A
  connection pooler such as PgBouncer, or persistent connections, should narrow
  the gap; that is untested.
- **SQLite is fast until writes collide.** It sustained 175–250 per second
  with anonymous events in three runs, the most of any database, but in the
  fourth requests stalled from the first stage with the CPU mostly idle, and
  the server answered with 502 errors and time-outs. SQLite allows one write at
  a time, and the [database guide](DATABASE.md#sqlite) already recommends it
  only for small deployments.
- **The limit is CPU, and mostly PHP.** Past the limit the CPU was 98–100%
  busy except in the SQLite runs. PHP used 73–95% of the CPU time, the database
  server 7–23%, and Nginx and k6 the rest.
- **The hardware matters too.** The same setup's CPU per event
  differed by up to two times between runs, as the runners' CPU models changed.
  CPU steal, time taken by other virtual machines, was 0% in the two runs that
  measured it.
- **Overload delays events more than it loses them.** Past the limit, requests
  queue and p95 climbs to several seconds, up to k6's 10-second time-out. In two
  PostgreSQL runs about 10,000 requests each passed that time-out, yet the
  server still stored 98–99% of those events after k6 had given up. The
  tracker sends with `fetch` and `keepalive`, without a time-out or a retry,
  so a browser usually waits rather than resending. A queue that keeps growing is eventually refused, as the 502
  errors in the stalled SQLite run show.

An earlier run with coarser stages (25, 50, 100, 200, 300 and up) sustained
100 per second in five setups and 200 in one, in line with these ranges.

### Sizing a server

Plan from the lower end of each range, since the runner's CPU varied:

- **MySQL or MariaDB:** about 37 requests per second per vCPU at the limit.
  Allow **about 25 per vCPU at your peak**, which leaves room for maintenance
  jobs, the dashboard and spikes. MariaDB was not tested; it is expected to
  behave like MySQL.
- **PostgreSQL:** about 12 per vCPU at the limit with enhanced events. Allow
  **about 10 per vCPU at your peak** until connection pooling is tested.
- **SQLite:** low-traffic sites only.

So a 4-vCPU server with MySQL should handle a peak of about 100 requests per
second (6,000 per minute); confirm on your own hardware with the test below.
Peak traffic matters, not the daily total: a site's busiest minute is usually
several times its average.

These figures cover collection requests only. Serving the tracker script,
consent banner and tag manager adds load unless a CDN or browser cache serves
them. A database on its own server takes its CPU share off the web server but
adds a network round trip to every request.

### Where the time goes

PHP spends 9–31 ms of CPU on each collection request, most of the cost of an
event. Profiling that time is the next step before tuning anything. Every
request parses `config/aggregate.yaml` and `config/websites.yaml` again and
opens a new database connection, since PHP keeps neither between requests by
default, so both are worth checking first.

## Run the test

The test is [tests/Load/receive.js](../tests/Load/receive.js), a
[k6](https://grafana.com/docs/k6/latest/) script. Run it only against a
**disposable installation**: every request stores an event, and the test
stores tens of thousands. Never run it against a production server.

### On GitHub

The **Load test** workflow
([.github/workflows/load-test.yml](../.github/workflows/load-test.yml)) installs
a fresh copy for each of SQLite, PostgreSQL 16 and MySQL 8.0, runs anonymous and
enhanced traffic on each, and reports every stage in the job summary and as a
notice on the run. Start it from **Actions → Load test → Run workflow**, where
you can change the stage rates and length. On a pull request, add the
`load-test` label to run it. Each of the six jobs takes about 8 minutes, so a
run uses about 50 minutes of runner time.

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
- FrankenPHP and Apache, SQL Server and MariaDB, and other PHP-FPM worker
  counts.
- Requests for the tracker, consent and tag manager scripts, dashboard use, and
  `app:analytics:maintain` running during load.
- Profiling PHP's time per request.

The [roadmap](../ROADMAP.md) tracks this work.
