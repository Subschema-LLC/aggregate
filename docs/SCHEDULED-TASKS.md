# Scheduled tasks

[Deployment guide](../DEPLOYMENT.md) · [Plesk](../PLESK-DEPLOYMENT.md) · [Archiving and retention](CONFIGURATION.md#archiving-and-retention) · [BigQuery sync](BIGQUERY.md) · [Reporting database](REPORTING-DATABASE.md)

Aggregate records events as they arrive; collecting them needs no scheduler.
A few jobs do run in the background, and one scheduled task runs all of them:

```bash
php bin/console app:scheduler:run
```

Have the server run it every five minutes. Each time, it starts the jobs that
are turned on and due, and does nothing else. You set it up once; turning a job
on or off happens on that job's own page.

- [What runs](#what-runs)
- [Set it up](#set-it-up)
  - [Plesk](#plesk)
  - [cron](#cron)
  - [systemd timer](#systemd-timer)
- [Check that it works](#check-that-it-works)
- [Run jobs yourself](#run-jobs-yourself)
- [How a run works](#how-a-run-works)
- [Troubleshooting](#troubleshooting)

## What runs

| Job | When it runs | Turn it on or off |
| --- | --- | --- |
| Analytics maintenance | Once a day, when archiving, retention or the purging of old task and audit records is on. Purging is on by default. | **Administration → Data lifecycle**, see [archiving and retention](CONFIGURATION.md#archiving-and-retention) |
| BigQuery sync | Each selected view once per interval (15 minutes to 24 hours), while the sync is on | **Reporting → BigQuery sync**, see [BigQuery](BIGQUERY.md#schedule-the-sync) |
| Reporting database sync | Each selected view once per interval, while the sync is on | **Reporting → Reporting database**, see [Reporting database](REPORTING-DATABASE.md#schedule-the-sync) |

Maintenance is due 24 hours after the last maintenance run started, whoever
started it. A run within ten minutes of that time counts, so a task every five
minutes keeps the time of day steady.

## Set it up

Open **Administration → Scheduled tasks** in the dashboard. It shows the exact
settings for this server, with buttons to copy each one. Without the dashboard,
run:

```bash
php bin/console app:scheduler:status
```

It prints the same settings and whether the task already runs.

### Plesk

1. Open **Websites & Domains**, then **Scheduled Tasks** for the subscription
   that holds Aggregate, and click **Add Task**.
2. Fill in:

   | Field | Value |
   | --- | --- |
   | Task type | **Run a PHP script** |
   | Script path | The folder that holds Aggregate, relative to the subscription, followed by `bin/console`: `httpdocs/bin/console` when Aggregate is in `httpdocs`, or `analytics.example.com/bin/console` for a subdomain's own folder |
   | Use PHP version | The version the site uses, 8.2 or newer |
   | Arguments | `app:scheduler:run` |
   | Run | **Cron style**, `*/5 * * * *` |
   | Description | `Aggregate scheduled jobs` |
   | Notify | **Errors only** |

3. Click **OK**, then **Run Now** next to the new task.
   **Administration → Scheduled tasks** shows **Working** once it has run.

Do not use **Run a command** with `cd /var/www/vhosts/…`. Unless the
subscription's system user has full SSH access (`/bin/bash`), Plesk runs such
commands in a restricted environment where the subscription folder is `/`, so
the path does not exist and PHP is not installed. The task then fails with
`cd: /var/www/vhosts/example.com/httpdocs: No such file or directory` or
`php: command not found`. A **Run a PHP script** task uses the PHP version you
choose, outside that environment. If you already have such tasks for
`app:analytics:maintain`, `app:bigquery:sync` or `app:reporting-database:sync`,
delete them: this one task replaces them.

### cron

On any other Linux server or hosting panel, add a cron job for the user that
runs Aggregate, the same user PHP runs as on the website. Not root: files the
jobs write in `var/` would then belong to root. The Scheduled tasks page shows
the line with this server's folder and PHP binary, for example:

```cron
*/5 * * * * cd '/var/www/aggregate' && '/usr/bin/php8.3' bin/console app:scheduler:run --no-interaction
```

Add it with `crontab -e` as that user (`sudo crontab -u www-data -e`), or in
your panel's cron jobs page with **Once per five minutes**. Panels list the PHP
binary of each version; use the one the site uses. The command prints nothing
when nothing was due, so cron sends mail only when a job ran or failed.

### systemd timer

On servers with systemd, a timer works as well as cron and logs to the journal.
Copy the two examples from [`docs/systemd`](systemd/), change `User`, `Group`,
`WorkingDirectory` and the PHP path in `aggregate-scheduler.service`, then:

```bash
sudo cp docs/systemd/aggregate-scheduler.service docs/systemd/aggregate-scheduler.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now aggregate-scheduler.timer
systemctl list-timers aggregate-scheduler.timer
journalctl -u aggregate-scheduler.service
```

With [Docker Compose](../DEPLOYMENT.md#docker-deployment), run the same command
from the host's cron or a timer:
`docker compose exec -T php php bin/console app:scheduler:run --no-interaction`.

## Check that it works

The box at the top of **Administration → Scheduled tasks** shows one of:

| State | Meaning |
| --- | --- |
| **Working** | The server's task ran in the last 30 minutes. |
| **Late** | It ran before, but not in the last 30 minutes. Check the task. |
| **Not set up** | The server's task has never run. |

Only runs without a terminal count: those of cron, Plesk (including its
**Run Now**), systemd and other schedulers. A run from a terminal or with
**Run due jobs now** is shown as a run by hand: it proves the jobs work, not
that the server will run them. Below the state, the page lists each job's
result in the last run, and a table with each job's schedule, last run and next
run, linking to its settings.

`php bin/console app:scheduler:status` exits with `0` only while the state is
**Working**, so monitoring can run it; `--json` prints the same as JSON.

## Run jobs yourself

- **Run due jobs now** on the Scheduled tasks page starts the due jobs in the
  background and records you as the administrator who asked. It needs
  `proc_open` on a Unix-like server; the output goes to
  `var/scheduler/last-output.log`.
- `php bin/console app:scheduler:run` runs the due jobs once in the terminal.
  `-v` lists every job, including those that were not due; `--json` prints the
  results as JSON.
- Each job keeps its own command for runs outside the schedule:
  `app:analytics:maintain` (with `--dry-run` to preview),
  `app:bigquery:sync --force` and `app:reporting-database:sync --force` copy
  every selected view now. **Sync now** on the BigQuery and Reporting database
  pages starts the same commands.

Older crontab lines that run these commands keep working beside the scheduler:
maintenance counts 24 hours from the last run, whoever started it, and two
syncs never copy the same view at once. One line is simpler, though; remove the
old ones when you add `app:scheduler:run`.

## How a run works

- Jobs run one after another, in the order of the table above. A job that fails
  does not stop the next one; it is retried at a later run.
- Runs never overlap. A run that starts while the previous one is still going
  (a long first sync, say) ends at once and changes nothing; the next one
  continues.
- The command prints nothing when no job ran. It prints a table when a job ran,
  was busy elsewhere or failed, and exits with `1` when a job failed, so a
  panel's **Errors only** notification, or cron's mail, reaches you.
- `var/scheduler/last-run.json` keeps the last scheduled run and the last run by
  hand, with each job's result. Each job also records its work in the private
  [`processing_tasks` and `audit_trail` tables](DATABASE.md#processing-tasks-and-audit-trail),
  as before: maintenance as `analytics_maintenance`, each view sync as
  `bigquery_sync` or `reporting_database_sync`.
- Web requests never run a job, so visitors never wait for one.

## Troubleshooting

| What you see | What to do |
| --- | --- |
| `cd: /var/www/vhosts/…: No such file or directory` in a Plesk task | The task is **Run a command** in Plesk's restricted environment. Change it to [Run a PHP script](#plesk). |
| `php: command not found` | On Plesk, the same. Elsewhere, use the full PHP path the Scheduled tasks page shows. |
| `Could not open input file: …/bin/console` | The script path or folder is wrong. Copy it from the Scheduled tasks page. |
| **Not set up** after you ran the command in a terminal | Runs from a terminal count as runs by hand. Use your panel's **Run Now**, or wait five minutes for the server's task. |
| **Late** | The task stopped running. Check that it is enabled, its last output in the panel or cron mail, and that its PHP version is 8.2 or newer. |
| A job shows **Failed** | The message says why, and the job's page has details. Fix the cause; the next run retries. |
| `Permission denied` in `var/`, or files in `var/` owned by root | The task ran as root. Run it as the site's user and give the files back to that user. |
| **Run due jobs now** is unavailable | The web server cannot start background processes. Run `php bin/console app:scheduler:run` instead, or wait for the schedule. |
| "The data lifecycle settings are invalid" | Fix them on **Administration → Data lifecycle**, or in `config/aggregate.yaml`. |
