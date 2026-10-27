# Symptoms and checks

Work through the checks in order and stop when one explains the symptom. Each
section ends with what is routine (propose a fix) and what goes to the partner.

- [Counts dropped, or a day looks empty](#counts-dropped-or-a-day-looks-empty)
- [A website shows no data at all](#a-website-shows-no-data-at-all)
- [The health check is unhealthy or unreachable](#the-health-check-is-unhealthy-or-unreachable)
- [Scheduled tasks are late, not set up, or a job failed](#scheduled-tasks-are-late-not-set-up-or-a-job-failed)
- [An update is available, failed, or a maintenance page is showing](#an-update-is-available-failed-or-a-maintenance-page-is-showing)
- [A BI tool or AI assistant cannot connect or read a view](#a-bi-tool-or-ai-assistant-cannot-connect-or-read-a-view)
- [A conversion goal is not being recorded](#a-conversion-goal-is-not-being-recorded)
- [The server is slow, erroring or running out of space](#the-server-is-slow-erroring-or-running-out-of-space)

## Counts dropped, or a day looks empty

1. **Is it today, or the current hour?** Those are never included. Look at
   completed days only.
2. **What does coverage say?** Call `get_coverage` for the days in question. A
   low band means much of the day was withheld as small counts. On a small
   site, query `bi_anonymous_daily_rollup_v1`, whose daily totals add up and
   whose `(other)` rows pool what detailed rows withhold.
3. **Is the comparison fair?** Same view, same websites, same weekdays,
   holidays and campaigns accounted for.
4. **Did consent change?** The approved views count anonymous-mode events only.
   More visitors accepting enhanced analytics, through a new banner, a changed
   default or a new region rule, moves their events out of those views.
5. **Did collection settings change?** New excluded paths, a disabled goal, or
   the kill switch (`anonymous_tracking_enabled: false`) reduces or stops what
   is counted. Ask what changed and when, or look at the
   **Audit trail** for administrator actions on that day.
6. **Is it abrupt and website-wide?** Then treat it as
   [no data at all](#a-website-shows-no-data-at-all) from that hour on.

Routine: explaining withheld counts, coverage and consent effects; proposing a
coarser view for a small site. Partner: a drop that matches a health, scheduled
task or server problem. Privacy owner: any change to consent or collection
settings that the explanation points to.

## A website shows no data at all

1. **Health check.** If it is `unhealthy`, see the next section first.
2. **Is the snippet on the pages?** Ask the person to view the page source, or
   check the tag manager container, for the Aggregate script and the website's
   token. A copied snippet with another website's token sends events to the
   wrong website.
3. **Domain rules.** New registrations accept only the primary hostname. A site
   registered as `example.com` but served at `www.example.com`, or a new
   subdomain, has its events rejected. Compare the address in the browser with
   the website's `domain_policy` in `config/websites.yaml` or on the
   **Websites** page.
4. **An invalid configuration file for that website or its group.** One
   website's invalid file stops collection for that website only, and the
   health check reports the configuration as invalid until it is fixed. Look at
   `config/website-scopes/websites/` and `config/website-scopes/groups/` for a
   recent edit.
5. **Collection switches.** A kill switch, or excluded paths that cover the
   whole site, in the website's, group's or deployment's settings.
6. **The browser.** A strict Content Security Policy or an ad blocker can stop
   the script. The browser console shows a blocked request.

Routine: a missing domain rule (keep the token unchanged), a wrong token in the
snippet, a YAML syntax error in a file the person just edited. Partner: an
invalid configuration nobody edited, or a cause you cannot find.

## The health check is unhealthy or unreachable

The check reports three components. Only the first two can make it
`unhealthy`:

| Component | What a problem usually means |
| --- | --- |
| `database`: `error` | The database server is down, unreachable, out of space or refusing the credentials |
| `configuration`: `error` | A YAML file is not valid YAML or not a valid setting, including one website's or group's file |
| `messenger`: `warning` | No message transport is configured. It appears alongside a healthy status and is not a fault by itself; mention it to the partner as routine. |

- **No answer at all** (timeout, DNS or certificate error): the website, the
  web server or the server itself is down, or the address changed. On LitePanel,
  check `list_services` for nginx and PHP-FPM and `list_domains` for the
  certificate.
- **A login page, a redirect or a 404**: the address is wrong or the web server
  configuration changed.

Routine: a configuration error that matches a recent, visible edit, with the
fix shown as a diff. Partner: everything else here. Urgent if collection has
stopped.

## Scheduled tasks are late, not set up, or a job failed

**Administration → Scheduled tasks** shows **Working** (ran in the last 30
minutes), **Late** (ran before, not recently) or **Not set up** (never ran).
`app:scheduler:status` exits `0` only while it is **Working**.

- Runs from a terminal, or with **Run due jobs now**, count as runs by hand.
  They prove the jobs work, not that the server will run them.
- **Late**: the server's task stopped. On LitePanel, its scheduled task for the
  site runs `php bin/console app:scheduler:run --no-interaction` every five
  minutes.
- **Permission denied in `var/`**, or files there owned by root: the task ran as
  root. The fix is on the server.
- **A job shows Failed**: the message says why, and the job's page has details.
  The next run retries it.

Why it matters: the scheduled task runs maintenance, which purges old task and
audit records and applies archiving and retention when they are on, and runs
any BigQuery or reporting database syncs.

Routine: explaining the state; a sync that failed because of a setting the
person controls. Partner: anything that needs the server's scheduler, file
ownership or PHP version changed.

## An update is available, failed, or a maintenance page is showing

All of this goes to the partner. Explain what the person is seeing:

- **An update is available**: routine for the partner to schedule. Ask whether
  the release notes mention security.
- **An update failed**: files are restored automatically if installing them
  failed; if a later step such as a database migration failed, the site stays
  in maintenance mode. Fixing it means `app:updates:apply --resume` or a
  rollback, on the server.
- **A maintenance page stays up**: urgent. Collection may be stopped.

Useful facts for the handoff note: the version shown on **Administration →
Updates**,
and, if someone with server access can run them, `app:updates:apply --status`
and `app:updates:check --refresh`.

## A BI tool or AI assistant cannot connect or read a view

| Symptom | Usual cause |
| --- | --- |
| `404` at `/mcp` | The `mcp_server` feature flag is off |
| `401` with a token | The token lacks AI assistant access, or is mistyped, revoked or expired |
| `429` | More than 120 requests a minute on one token or connection; wait and retry |
| `view_not_permitted` | The token or connection was not granted that view. `get_coverage` needs `bi_anonymous_coverage_v1`. |
| `view_unavailable` | The view does not exist here, for example geography is off |
| The assistant cannot find sign-in or reach the server | The installation is not reachable from the assistant's servers, `/.well-known/` is blocked, or the dashboard is disabled |
| A BI tool lost access after views were regenerated | On SQLite, regenerating drops and recreates the views, and SQLite has no grants. Other databases keep their grants. |
| A BI tool cannot log in to the database | The reporting account, its password or a firewall rule; this is on the server |

Routine: issuing or narrowing a token, granting an approved view to a token,
turning on a feature flag the organization has already agreed to. Partner:
database accounts, grants and firewall rules. Privacy owner: granting private
views or enhanced data.

## A conversion goal is not being recorded

- The goal code must be listed in `config/goals.yaml` or
  `config/goals.local.yaml`, enabled, and match exactly, including case. The
  browser console shows `[Aggregate] Goal was not recorded because it is not an
  approved goal type.` when it is not.
- A goal with `anonymous: false` is recorded only for visitors who accepted
  enhanced analytics, so it does not appear in the approved views.
- After a goal file changes in production, the cache must be cleared and the
  glossary synced (`cache:clear`, then `app:analytics:glossary:sync --env=prod`)
  before the change takes effect and its label appears in reports.

Routine: adding, enabling or relabeling a goal, with the server steps routed to
whoever runs commands. Privacy owner: changing a goal's `anonymous` setting.

## The server is slow, erroring or running out of space

On LitePanel, run `diagnose_server_health` and `get_system_metrics`, then
`get_service_logs` for the service that looks unhappy.

- **Disk nearly full**: the database, logs and update backups under
  `var/updates/backups/` all grow. Report the numbers; cleanup is the partner's.
- **High load during traffic peaks**: report when it happens and which service
  is busy.
- **A service inactive or failed**: report the unit, since when, and the last
  log lines.
- **A certificate expiring or expired**: report the domain and the date.

All of these go to the partner. Urgent when the disk is nearly full or a
service the site needs is down.
