# Audit trail

[Scheduled tasks](SCHEDULED-TASKS.md) · [Archiving and retention](CONFIGURATION.md#archiving-and-retention) · [Database](DATABASE.md#processing-tasks-and-audit-trail) · [Privacy and compliance](PRIVACY-COMPLIANCE.md)

The audit trail answers "who changed what, and when". It records what
administrators do in the dashboard and on the command line, every finished
background job run, and, when you turn it on, sign-ins. Open
**Administration → Audit trail** to read and filter it, export it, and choose
what it records. Only administrators can open the page.

The entries are kept in the private `audit_trail` table. They never hold
passwords, keys, submitted values or visitor data: see [Privacy](#privacy).

- [What is recorded](#what-is-recorded)
- [What an entry holds](#what-an-entry-holds)
- [Find entries](#find-entries)
- [Export entries](#export-entries)
- [From the command line](#from-the-command-line)
- [Turn areas on and off](#turn-areas-on-and-off)
- [How long entries are kept](#how-long-entries-are-kept)
- [Commands and background work](#commands-and-background-work)
- [Privacy](#privacy)
- [Adding a dashboard form](#adding-a-dashboard-form)

## What is recorded

Entries belong to areas. Each area can be switched off on its own.

| Area | Code | Records | Default |
| --- | --- | --- | --- |
| Settings changes | `settings` | Saving a settings page: collection, BI disclosure, data model, glossary, page groups, reporting timezone, branding, general settings, consent and tag manager, organization traffic, feature flags, data lifecycle, reporting API settings and the audit switches | On |
| Users | `users` | Installing; creating, changing and deleting users, their access and permission groups; profile changes and passwords; from the dashboard, the installer or the command line | On |
| Websites and groups | `websites` | Adding and removing websites, their domain rules, and creating, renaming, deleting and assigning website groups | On |
| Integrations | `integrations` | BigQuery and reporting database connections, keys, Google sign-in, connection tests, and the views and schedules they copy; creating and revoking reporting API tokens, from the dashboard or the command line | On |
| Updates | `updates` | Checking for, uploading and installing updates, rollbacks, recorded deployments, the update method and source, and maintenance mode | On |
| Manual runs | `operations` | **Sync now**, running the scheduled jobs by hand, publishing columns and regenerating reporting views, glossary sync, building browser scripts, retrying failed tracking, starting a data transfer and exporting the audit trail | On |
| Background job runs | `task` | Each finished view sync and maintenance run, scheduled or started by someone | On |
| Sign-ins | `sign_ins` | Successful and failed sign-ins and sign-outs | Off |

A request is recorded only once its page has run: a request the sign-in check
turned away is not. A change that a signed-in user without the
[permission](USERS-AND-PERMISSIONS.md) for it tries to make, including a
view-only user's save, is recorded as **Denied**, with who tried.
Pages that only show something are not recorded, except the CSV export.

## What an entry holds

| Field | Holds |
| --- | --- |
| Time | When it happened, in UTC |
| Area and action | What kind of change, such as **Users → User created** |
| Outcome | **Succeeded**; **Failed** when the page reported an error or the command exited with an error; **Denied** when the user was not allowed; **Started** for updates, which are recorded when they begin |
| User | The username, or for a command the operating-system account followed by "(command line)". Scheduled job runs have none |
| Subject | What was acted on, when there is one thing: a user, a website (by name, never its token), a website group (`group:id`) or a reporting view |
| Details | The settings that changed, by name only, such as `Changed aggregate.yaml: brand_name, feature_flags.updates`; a short summary such as "Standard account. Permission groups: client-editors. Websites: 1 group(s), 0 website(s). Pages: Tag manager lite (edit)." or "Turned off: sign_ins."; or a job's result |

Settings nested in a group are named one level down (`feature_flags.updates`).
Secret files, such as `config/secrets/bigquery-service-account.json`, are named
without anything about their contents (an API token is named by its public ID,
never its secret or hash), and `.env.local` changes name the
variable only.

## Find entries

The filters at the top of the page combine: an entry must match every filter
you set. Within one filter, choosing several values matches any of them.

- **From** and **To**: whole days in UTC, both included. The quick ranges
  (today, 7, 30 and 90 days, a year, all time) fill them in.
- **Area** and **Outcome**: tick one or more.
- **Action**: choose one or more from the actions recorded so far.
- **User**: one user, or **No user** for scheduled and system entries.
- **Search**: text anywhere in the action, user, subject or details, ignoring
  case. Words also find codes, so `user created` finds `user_created` and
  `brand name` finds the `brand_name` setting.
- **Subject contains**: part of a user, website, group or view name.
- **Job run**: the processing task number of one background run.
- **Order** and **Per page**: newest or oldest first, 25 to 200 entries.

Each active filter shows as a tag above the entries; its × removes just that
filter. Clicking an area, action, outcome, user or subject in the list narrows
the list to it, keeping the other filters. The address of the page holds the
filters, so a filtered view can be bookmarked or shared with another
administrator.

## Export entries

**Export CSV** downloads the entries that match the filters, in the page's
order, up to 100,000 at a time. Times are UTC; values that a spreadsheet would
run as a formula start with an apostrophe. Each export is itself recorded under
**Manual runs**, with the kinds of filters used but not what was searched for.

## From the command line

`app:audit:list` lists entries with the same filters:

```bash
php bin/console app:audit:list                                   # the newest 50
php bin/console app:audit:list --days=7 --area=settings --area=users
php bin/console app:audit:list --outcome=failed --outcome=denied --json
php bin/console app:audit:list --user=scott --search=feature_flags --limit=500
php bin/console app:audit:list --from=2026-10-01 --to=2026-10-31 --oldest-first
php bin/console app:audit:list --summary                         # each area's switch and entry count
```

`--user=-` lists entries without a user. `--json` prints a JSON array with
every field. Listing entries is not recorded.

## Turn areas on and off

Under **What is recorded** on the page, tick the areas to record and save.
Turning an area off stops new entries; the entries already recorded stay until
they expire. Changes to these switches are always recorded, even when the
**Settings changes** area is off, so turning auditing off leaves a trace.

The switches are saved under `audit_trail_areas` in `config/aggregate.yaml`.
Only the areas you change are written; the others keep their defaults:

```yaml
audit_trail_areas:
  sign_ins: true
  operations: false
```

There is no environment variable for them. If the value in YAML is invalid,
every area is recorded as by default until it is corrected, and the page says
so.

## How long entries are kept

Two settings on **Administration → Data lifecycle** decide when maintenance
removes old entries:

| Setting | Environment variable | Default | Removes |
| --- | --- | --- | --- |
| `audit_trail_retention_days` | `AUDIT_TRAIL_RETENTION_DAYS` | `365` | Background job runs |
| `audit_trail_admin_retention_days` | `AUDIT_TRAIL_ADMIN_RETENTION_DAYS` | `730` | Every other entry: administrator actions and sign-ins |

`0` keeps entries indefinitely. Maintenance runs once a day from the
[scheduled task](SCHEDULED-TASKS.md); `php bin/console app:analytics:maintain --dry-run`
shows how many entries it would remove. Set these periods to match your audit
and staff-data policies.

## Commands and background work

Console commands that change something are recorded with the operating-system
account that ran them, when they finish, with their exit status:
`app:install`, `app:user:create`, `app:user:reset-password`,
`app:create-website`, `app:website-scopes` (except `list`),
`app:reporting-database:configure`, `app:analytics:reporting-timezone` with a
time zone, `app:analytics:mysql-timezones:load`, the view regeneration and
glossary sync commands (not their `--dry-run`, `--check` or export modes),
`app:assets:build-js`, `app:tracking:retry-failed`, `app:updates:method` with
a method and `app:updates:maintenance on` or `off`.

`app:updates:apply`, `app:updates:pull`, `app:updates:rollback` and
`app:updates:deployed` are recorded as **Started** when they begin, because an
update replaces the application's files while it runs. The update's own result
is on the Updates page.

Commands the application starts itself, such as **Sync now** or an update
started from the dashboard, are not recorded a second time: the dashboard
action that started them already was. Their job runs are recorded under
**Background job runs** as usual.

## Privacy

The audit trail is private operational data. It names administrators, so treat
it as staff data in your records of processing.

- Settings are recorded by name, never by value, so a password, key, token or
  connection URL saved on a settings page cannot reach the audit trail.
- A failed sign-in names the account only when that account exists. Anything
  else typed into the username field, a password included, is not stored.
- IP addresses, browsers and visitor data are not recorded.
- Searches and filters used for an export are recorded only by kind.

## Adding a dashboard form

`App\Service\Operations\AdminActions::ROUTES` lists each route that is an
administrator action, with its area and action code. A test fails when a route
that accepts POST is neither listed there nor explained in `NOT_AUDITED`, so a
new form is never silently left out. A controller can name what it acted on
with the request attribute `AdminAuditSubscriber::SUBJECT`, and add a short
summary with `AdminAuditSubscriber::DETAILS`. Files written through
`AggregateConfigLoader`, `WebsiteConfigManager`, the website group files,
`LocalEnvironmentFile`, `BigQuerySecrets` and `BrandingLogoManager` are
reported by name automatically.
