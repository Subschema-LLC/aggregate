# Load testing and capacity

How many collection requests one Aggregate server handles, how that was
measured, and how to measure your own server. The test sends synthetic page
views to `/api/receive`, the endpoint every tracked page view and event uses,
at rising rates until the server falls behind.

## Results

Measured on 14 October 2026, at commit `e00c5d8`, on GitHub's standard Linux
runner. Every request ran on one machine:

- 4 vCPU and 15 GB of memory, shared with other GitHub customers.
- Nginx with the [documented site configuration](nginx/aggregate-analytics.conf),
  and PHP 8.3 with PHP-FPM (16 static workers) and production OPcache settings.
- The database on the same machine: SQLite, or PostgreSQL 16 or MySQL 8.0 in a
  container with default settings.
- The k6 load generator on the same machine too, using 3–5% of its CPU.
- `MESSENGER_TRANSPORT_DSN=sync://`, so enhanced events are written during the
  request, as in a default installation.

Each stage sends requests at a fixed rate for 30 seconds. A stage is
**sustained** when the server delivers at least 95% of the rate, fewer than 1%
of requests fail, and 95% of responses (p95) arrive within 500 ms.

| Setup | Sustained | p95 at 150/s | Most served when overloaded | Server CPU per event |
| --- | ---: | ---: | ---: | ---: |
| SQLite, anonymous | 250/s (15,000/min) | 16 ms | 298/s | 12 ms |
| SQLite, enhanced | 150/s (9,000/min) | 29 ms | 196/s | 21 ms |
| PostgreSQL 16, anonymous | 175/s (10,500/min) | 32 ms | 210/s | 20 ms |
| PostgreSQL 16, enhanced | 150/s (9,000/min) | 57 ms | 197/s | 22 ms |
| MySQL 8.0, anonymous | 150/s (9,000/min) | 39 ms | 196/s | 21 ms |
| MySQL 8.0, enhanced | 175/s (10,500/min) | 27 ms | 206/s | 20 ms |

*Sustained* is the highest stage before the first one that missed the
criteria, from stages of 50, 100, 125, 150, 175, 200, 225, 250, 300 and 350
requests per second. *Server CPU per event* is PHP, database and Nginx CPU time
divided by the events stored.

What the runs show:

- **Every setup sustained at least 150 requests per second (9,000 per
  minute)**, with p95 under 60 ms at that rate.
- **The limit is CPU, and mostly PHP.** Past the limit the CPU was 98–100%
  busy in every setup but SQLite with anonymous events, which leveled off at
  83–91%, probably because SQLite lets only one write happen at a time. PHP
  used 73–95% of the CPU time, the database server 7–23%, and Nginx and k6 the
  rest. The database engine changes the result less than run-to-run variation
  does.
- **Overload slows requests down; it does not lose them.** Past the limit,
  throughput levels off at about 190–300 per second (160–330 across both
  runs) and requests wait in the queue, reaching p95 times of 4–6 seconds at
  350 per second. No request
  failed within k6's 10-second timeout, and every request reported as stored
  had a row in the database. A browser that gives up sooner would lose those
  events.
- **Results vary between runs by about a quarter.** An earlier run of the same
  test with coarser stages (25, 50, 100, 200, 300 and up) sustained 100 per
  second in five setups and 200 in one, with similar overload throughput.
  Treat the table as a range, not exact limits.

### Sizing a server

The CPU cost per event is the number that carries over to other hardware:
12–22 ms of server CPU per event means one fully busy CPU core handles
roughly 45–80 events per second. The runs above sustained 37–62 per second
per vCPU while sharing the machine with the load generator.

For planning, allow about **25–30 requests per second per vCPU at your peak**,
which leaves room for maintenance jobs, the dashboard and traffic spikes, then
confirm on your own server with the test below. Peak traffic matters, not the
daily total: a site's busiest minute is usually several times its average.

These figures cover collection requests only. Serving the tracker script,
consent banner and tag manager adds load unless a CDN or browser cache serves
them, and a database on another server adds a network round trip to every
request.

### Where the time goes

PHP spends 12–22 ms of CPU on each collection request, most of the cost of
an event. Profiling that time is the next step before tuning anything. Every
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
you can change the stage rates and length. It also runs on pull requests that
change the test. Each of the six jobs takes about 8 minutes of runner time.

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
