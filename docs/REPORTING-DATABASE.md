# Reporting database

[Connect BI tools](BI-CONNECTION.md) · [BigQuery sync](BIGQUERY.md) · [Configuration](CONFIGURATION.md#reporting-database) · [Privacy guide](PRIVACY-COMPLIANCE.md#reporting-database)

Aggregate can copy its reporting views into tables of a second database on a
schedule. BI users and tools then connect to that database instead of the
application database: they get the same view names and columns, as plain
tables, and no way to reach the raw tables.

- [When to use it](#when-to-use-it)
- [How the sync works](#how-the-sync-works)
- [Create the database](#create-the-database)
- [Connect Aggregate to it](#connect-aggregate-to-it)
- [Choose the views](#choose-the-views)
- [Schedule the sync](#schedule-the-sync)
- [Settings without the dashboard](#settings-without-the-dashboard)
- [Column types](#column-types)
- [Privacy and data protection](#privacy-and-data-protection)
- [Troubleshooting](#troubleshooting)

## When to use it

Connecting a BI tool straight to the [reporting views](BI-CONNECTION.md) is the
simplest setup and is always live. A reporting database is worth adding when:

- **BI users should have no login on the application database.** The reporting
  database holds only the views you select; raw `events`, archives and settings
  stay behind. This also gives SQLite installations, where anyone who can read
  the database file can read every table, a server database for BI access.
- **BI queries should not load the server that collects events.** Put the
  reporting database on another server; dashboard refreshes then run there.
  Only the sync reads the application database.
- **Reports should be quick.** A table answers queries without recomputing the
  view's suppression on every read.

The trade-off is freshness: the tables match the views as of their last sync, 15
minutes to 24 hours ago. Aggregate never reads from the reporting database; it
is an output, not a replica.

## How the sync works

- The server's [scheduled task](SCHEDULED-TASKS.md), `app:scheduler:run`,
  runs every five minutes and copies the views that are due (the same as
  `php bin/console app:reporting-database:sync`). Each selected view is copied when the configured
  interval has passed since its last copy. Web requests never run a sync; **Sync
  now** on **Reporting → Sync → Reporting database** starts the same command in the
  background.
- Each view becomes a table with the view's name, for example
  `bi_anonymous_events_v1`, in the reporting database's default schema.
- Aggregate reads the whole view into a temporary file in
  `var/reporting-database`, then writes the rows to a new table
  (`<view>_sync_new`). Only when every row is in and counted does that table
  replace the previous copy, in one step: MySQL and MariaDB rename both tables in
  one statement; PostgreSQL, SQL Server and SQLite drop and rename in one
  transaction. A BI query sees the previous copy or the new one, never a partial
  one. If anything fails, the previous copy stays and the half-written table is
  dropped.
- A scheduled sync keeps a table whose view has the same rows and columns as
  at its last successful sync, when the table still has as many rows, instead
  of writing it again (status `unchanged`). Rows are compared by a fingerprint
  of the file, whatever their order. Any change in the view gives different
  rows, so suppression, threshold changes, archiving and deletions in Aggregate
  reach the reporting database at the next sync, a new column in a view, such
  as a new [custom property](DATA-MODEL.md), appears in its table at the next
  sync, and a dropped table is copied again. **Sync now** and `--force` replace
  every table.
- A view that fails is recorded with its error and retried after 15 minutes (or
  the interval, if shorter); the other views continue. Two scheduled runs never
  copy the same view at once.
- The table swap waits at most 60 seconds for BI queries that are reading the
  table, then fails and is retried, so a long report cannot block the sync for
  good.

Status for each view (last successful copy, rows, next sync and the last
message) appears on the admin page and in
`php bin/console app:reporting-database:check`. Every view sync is a row in the
private [`processing_tasks` table](DATABASE.md#processing-tasks-and-audit-trail)
(`task_type` `reporting_database_sync`, `subject` the view) and an entry in the
`audit_trail`. A sync started with **Sync now** records the administrator who
asked for it.

## Create the database

Create an empty database for reporting. It can be on the same server as the
application database, which separates access, or on another server, which also
separates load. It can even use a different engine: an installation on SQLite
can copy its views to PostgreSQL or MySQL. Aggregate refuses its own
application database as the reporting database.

Use two database users:

- **A sync user** for Aggregate, which creates, fills, renames and drops the
  tables.
- **A read-only user** for each BI tool or group of BI users.

Each sync creates new tables, so permissions granted on a single table are lost
at the next sync. Grant BI users read access on the whole database or schema
instead, as below.

**MySQL or MariaDB**

```sql
CREATE DATABASE aggregate_reporting CHARACTER SET utf8mb4;

CREATE USER 'aggregate_sync'@'app.example.net' IDENTIFIED BY 'a-long-random-password';
GRANT SELECT, INSERT, CREATE, DROP, ALTER ON aggregate_reporting.* TO 'aggregate_sync'@'app.example.net';

CREATE USER 'bi_reader'@'%' IDENTIFIED BY 'another-long-random-password';
GRANT SELECT ON aggregate_reporting.* TO 'bi_reader'@'%';
```

**PostgreSQL**

```sql
CREATE ROLE aggregate_sync LOGIN PASSWORD 'a-long-random-password';
CREATE DATABASE aggregate_reporting OWNER aggregate_sync;
CREATE ROLE bi_reader LOGIN PASSWORD 'another-long-random-password';

\c aggregate_reporting
REVOKE ALL ON DATABASE aggregate_reporting FROM PUBLIC;
GRANT CONNECT ON DATABASE aggregate_reporting TO bi_reader;
GRANT USAGE ON SCHEMA public TO bi_reader;
-- Every table the sync user creates from now on is readable by bi_reader:
ALTER DEFAULT PRIVILEGES FOR ROLE aggregate_sync IN SCHEMA public GRANT SELECT ON TABLES TO bi_reader;
```

On PostgreSQL 14 and older, also run `REVOKE CREATE ON SCHEMA public FROM PUBLIC;`
in the reporting database, so BI users cannot create tables there.

**SQL Server**

```sql
CREATE DATABASE aggregate_reporting;
CREATE LOGIN aggregate_sync WITH PASSWORD = 'A-long-random-password-1';
ALTER AUTHORIZATION ON DATABASE::aggregate_reporting TO aggregate_sync;

CREATE LOGIN bi_reader WITH PASSWORD = 'Another-long-random-password-2';
USE aggregate_reporting;
CREATE USER bi_reader FOR LOGIN bi_reader;
GRANT SELECT ON SCHEMA::dbo TO bi_reader;
```

**SQLite**

No setup is needed; the first sync creates the file. A SQLite reporting database
suits a BI tool on the same server that reads a file. Anyone who can read the
file reads every table in it, so keep it outside `public/` and limit the file's
permissions.

## Connect Aggregate to it

Open **Reporting → Sync** and choose the **Reporting database** tab (before any
sync is on, the menu has no **Sync** entry yet: type "Sync" or "reporting
database" in the dashboard search). Fill in **1. Connect the reporting
database**: the database type, server, port (empty for the type's default), the
sync user's name and password, and the database name (for SQLite, a file such
as `var/reporting.db`). For SQL Server with a self-signed certificate, tick
**Trust the server certificate**. Click **Test and save**.

Aggregate saves nothing until the connection works: it connects, creates and
drops a small table to prove the sync user may replace tables, and refuses the
application database. Then it writes the connection to `.env.local` on the
server as `REPORTING_DATABASE_URL`, like the setup page writes `DATABASE_URL`,
and reports the server version and any views already copied. The password is
never shown again: the form leaves it empty, and an empty password keeps the
saved one while the type, server and user stay the same. **Remove connection**
deletes the line from `.env.local`; tables already copied stay in the reporting
database.

From the command line, `app:reporting-database:configure` does the same. It asks
for the password, or reads it from standard input, so it stays out of the shell
history:

```bash
php bin/console app:reporting-database:configure --type=pgsql \
    --host=reporting.example.net --database=aggregate_reporting --user=aggregate_sync

printf '%s\n' "$PASSWORD" | php bin/console app:reporting-database:configure \
    --type=mysql --database=aggregate_reporting --user=aggregate_sync --password-stdin

php bin/console app:reporting-database:configure --type=sqlite   # var/reporting.db
php bin/console app:reporting-database:configure --remove
```

| Option | Meaning |
| --- | --- |
| `--type` | `mysql` (MySQL or MariaDB), `pgsql`, `sqlsrv` or `sqlite` |
| `--host`, `--port` | The server (default `localhost`) and port (default for the type) |
| `--database` | The database name, or for SQLite a file path (default `var/reporting.db`) |
| `--user` | The sync user |
| `--password-stdin` | Read the password from standard input instead of asking |
| `--trust-certificate` | SQL Server: trust a self-signed server certificate |
| `--url-stdin` | Read a complete URL, written like `DATABASE_URL`, from standard input |
| `--remove` | Remove `REPORTING_DATABASE_URL` from `.env.local` |

### Set it by hand

You can also set `REPORTING_DATABASE_URL` yourself, in `.env.local` or the
server environment, written like `DATABASE_URL`:

```dotenv
# MySQL or MariaDB
REPORTING_DATABASE_URL="mysql://aggregate_sync:a-long-random-password@reporting.example.net:3306/aggregate_reporting?serverVersion=8.0.39&charset=utf8mb4"
# PostgreSQL
REPORTING_DATABASE_URL="postgresql://aggregate_sync:a-long-random-password@reporting.example.net:5432/aggregate_reporting?serverVersion=16"
# SQL Server
REPORTING_DATABASE_URL="mssql://aggregate_sync:A-long-random-password-1@reporting.example.net:1433/aggregate_reporting?serverVersion=2022"
# SQLite
REPORTING_DATABASE_URL="sqlite:///%kernel.project_dir%/var/reporting.db"
```

Encode special characters in the password as in `DATABASE_URL` (`@` as `%40`,
`%` as `%25`). Then use **Test connection** on the admin page, or run
`php bin/console app:reporting-database:check`.

The admin page and the command change only `.env.local`. When the variable is
set where `.env.local` cannot override it (the server environment,
`.env.prod.local`, or a `.env.local.php` made by `composer dump-env`), or when
PHP cannot write `.env.local`, the page says so instead of showing the form;
change the value where it is set. The address is never a YAML setting, because
it holds a password, and the page shows only the engine, server, database name
and user name.

## Choose the views

The views are the same ones [BigQuery sync](BIGQUERY.md#choose-the-views)
offers. **Approved views** (the anonymous counts with small cells withheld, the
label views and the glossary) are selected by default. **Private views** hold
individual retained events or unsuppressed counts; each one must be selected
separately, and the admin page asks you to confirm that it may be copied. In
YAML they go under `reporting_database_private_views`, never
`reporting_database_views`. Raw tables, such as `events`, are never offered.

Removing a view from the selection stops its sync; its table stays in the
reporting database until you drop it there. The same applies when you turn the
sync off.

## Schedule the sync

Turn the sync on and choose the interval on the admin page. The copies are made
by the server's one [scheduled task](SCHEDULED-TASKS.md), `app:scheduler:run`
every five minutes, which also runs maintenance and BigQuery sync. Set it up
once; **Administration → Scheduled tasks** shows the exact settings for your
server (a Plesk **Run a PHP script** task, a crontab line or a systemd timer)
and whether it runs.

Each view is copied about once per interval (a run within five minutes of the
due time counts). The page warns when the views have not been copied on time.
An existing crontab line for `app:reporting-database:sync` keeps working, but
the scheduler's line replaces it.

| Command | Purpose |
| --- | --- |
| `app:reporting-database:sync` | Copy the views that are due; exits with an error when a view fails |
| `app:reporting-database:sync --force` | Copy every selected view now, replacing unchanged tables too |
| `app:reporting-database:sync --view=bi_anonymous_goals_v1` | Limit a run to selected views (repeatable) |
| `app:reporting-database:sync --json` | Machine-readable results |
| `app:reporting-database:check` | Show the settings and status, then test the connection (`--no-connect` skips the test) |
| `app:reporting-database:configure` | Test a connection and save it to `.env.local` ([above](#connect-aggregate-to-it)) |

**Sync now** starts `app:reporting-database:sync --force` in the background,
which needs `proc_open` on a Unix-like server; otherwise run the command
yourself. Its output goes to `var/reporting-database/last-run.log`.

A sync reads each whole view from the application database, even when it then
keeps the table, and writes it to a temporary file first, so leave free space
in `var/reporting-database` for the largest view; the file is deleted after
each view. The approved views are small. Private row-level views grow with your
traffic; give them a longer interval, or a schedule outside busy hours, if a
copy takes long.

## Settings without the dashboard

Every setting except the [connection](#connect-aggregate-to-it) lives in the active environment of
`config/aggregate.yaml` (or its environment-specific file). Uppercase
environment variables take precedence and lock the matching admin fields; lists
in the environment are comma-separated.

```yaml
reporting_database_enabled: true
reporting_database_views:                    # Approved views
  - bi_anonymous_events_v1
  - bi_anonymous_goals_v1
  - bi_dim_website_token_v1
reporting_database_private_views: []         # Row-level or unsuppressed views, by explicit opt-in
reporting_database_interval_minutes: 60      # 15, 30, 60, 120, 180, 360, 720 or 1440
```

| Setting | Environment variable | Default |
| --- | --- | --- |
| — | `REPORTING_DATABASE_URL` | not set |
| `reporting_database_enabled` | `REPORTING_DATABASE_ENABLED` | `false` |
| `reporting_database_views` | `REPORTING_DATABASE_VIEWS` | every approved view |
| `reporting_database_private_views` | `REPORTING_DATABASE_PRIVATE_VIEWS` | none |
| `reporting_database_interval_minutes` | `REPORTING_DATABASE_INTERVAL_MINUTES` | `60` |

Invalid settings stop the sync with an error instead of guessing.

## Column types

Tables keep the views' column names. Types follow the documented view
contracts, the same ones [BigQuery sync](BIGQUERY.md#column-types) uses, so a
table has the same columns whichever engine the application database uses:

| Columns | Type | MySQL, MariaDB | PostgreSQL | SQL Server | SQLite |
| --- | --- | --- | --- | --- | --- |
| `event_count`, `id`, `*_sort`, `sort_order` | integer | `BIGINT` | `BIGINT` | `BIGINT` | `BIGINT` |
| Numeric custom columns | integer or decimal, from the [data model](DATA-MODEL.md) | `BIGINT` or `DOUBLE PRECISION` | `BIGINT` or `DOUBLE PRECISION` | `BIGINT` or `DOUBLE PRECISION` | `BIGINT` or `DOUBLE PRECISION` |
| `event_hour`, `created_at`, `archived_at` | timestamp in UTC, without a time zone | `DATETIME` | `TIMESTAMP(0)` | `DATETIME2(6)` | `DATETIME` |
| `event_day` | date | `DATE` | `DATE` | `DATE` | `DATE` |
| `is_fallback`, `is_default_locale` | boolean | `TINYINT(1)` | `BOOLEAN` | `BIT` | `BOOLEAN` |
| Everything else | text | `LONGTEXT` (utf8mb4) | `TEXT` | `NVARCHAR(MAX)` | `CLOB` |

Every column is nullable, and the tables have no keys or indexes. A value that
does not fit its type stops that view's sync with an error naming the view and
column (never the value), and the previous copy stays.

The [relationships between views](BI-GLOSSARY.md#views-and-bi-relationships)
are the same between the tables, so a BI model built on the views works on the
reporting database after changing its connection.

## Privacy and data protection

- **Approved views carry their suppression.** Small cells are already withheld
  in the rows Aggregate copies. Anyone who can read the reporting database sees
  the same cells a routine BI user sees; keep the same rules about joining views.
- **Private views are row-level.** Select them only for an approved purpose, and
  restrict access to their tables as you would to the raw `events` table. A
  reporting database that holds private views needs the same protection as the
  application database.
- **Retention.** Each sync replaces the tables, so data Aggregate deletes leaves
  the copy at the next sync. Tables of deselected views, or of all views after
  the sync is turned off, stay until you drop them; so do the reporting
  database's own backups. Include them in your retention and deletion
  procedures.
- **Credentials.** `REPORTING_DATABASE_URL`, with the sync user's password,
  lives in `.env.local` or the server environment, like `DATABASE_URL`. The
  admin page and `app:reporting-database:configure` write it only to
  `.env.local`, keeping that file's permissions (`0600` when they create it).
  The password is never shown, logged, kept in the session or stored elsewhere
  by Aggregate. Error
  messages name views, columns and SQL states; a database's own message is shown
  only for connection and table changes, never for rejected rows, which could
  quote a value.

See the [privacy guide](PRIVACY-COMPLIANCE.md#reporting-database).

## Troubleshooting

| Message | What to do |
| --- | --- |
| "REPORTING_DATABASE_URL is not set" | Enter the connection on the admin page, or run `app:reporting-database:configure`. |
| The page shows a note instead of the connection form | The variable is set where `.env.local` cannot override it, or PHP cannot write `.env.local`. The note says where; change it there. |
| "names the application database" | Create a separate database for reporting; it can be on the same server. |
| "The reporting database could not be reached" | Check the host, port, database name, user name and password (encoded as in `DATABASE_URL`), and that the server accepts connections from the Aggregate server. |
| "cannot create and drop tables, which every sync does" | Grant the sync user the privileges [above](#create-the-database). |
| "The view … cannot be read in this database" | Run the database migrations; regenerate the custom views; turn on geography for `bi_anonymous_geo_events_v1`. |
| A lock time-out during the swap | A BI query held the table for over 60 seconds. The sync retries after 15 minutes; schedule long refreshes away from the sync. |
| BI users lose access after a sync | Their access was granted on single tables. Grant it on the database or schema [instead](#create-the-database). |
| "The scheduled sync is not running" | Set up the [scheduled task](SCHEDULED-TASKS.md#set-it-up); **Administration → Scheduled tasks** shows whether it runs. |
| "Sync now" is not available | The server cannot start background processes; run `php bin/console app:reporting-database:sync --force`. |
