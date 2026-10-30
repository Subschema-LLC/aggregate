# Forensic database

[Reporting database](REPORTING-DATABASE.md) · [Configuration](CONFIGURATION.md#forensic-database) · [Privacy guide](PRIVACY-COMPLIANCE.md#forensic-database) · [Scheduled tasks](SCHEDULED-TASKS.md)

Aggregate can copy its raw events, its archive tables and its private
reporting views into a separate database on a schedule, for the people who
investigate problems: a bug in collection, a consent setting that behaves
unexpectedly, a goal that stopped arriving, or what one browser did on a site.
They query complete, read-only copies there, with every column, instead of
running long queries on the application database while it collects events.

Visitor and session IDs never reach the forensic database. Aggregate replaces
each one with a hash made with a new random key at every sync. The key exists
only in memory while the copy is written and is then wiped, so no one, including
the server's administrators, can work out which browser a hash belongs to or
match hashes from two syncs.

The dashboard page holds the settings only. It never shows forensic rows: they
are read in the forensic database itself, with its own read-only accounts.

- [When to use it](#when-to-use-it)
- [What is copied](#what-is-copied)
- [How identifiers are hashed](#how-identifiers-are-hashed)
- [Create the database](#create-the-database)
- [Restrict and mark the database](#restrict-and-mark-the-database)
- [Connect Aggregate to it](#connect-aggregate-to-it)
- [Schedule the sync](#schedule-the-sync)
- [Settings without the dashboard](#settings-without-the-dashboard)
- [Privacy and data protection](#privacy-and-data-protection)
- [Troubleshooting](#troubleshooting)

## When to use it

- **Investigating a problem needs the raw rows.** The
  [reporting database](REPORTING-DATABASE.md) and the BI views hold counts with
  small cells withheld. A forensic copy holds every retained event with every column, so
  you can see exactly what was recorded and in what order.
- **Investigations should not load the server that collects events.** Put the
  forensic database on another server; long queries run there. Only the sync
  reads the application database, once per interval.
- **Investigators should have no login on the application database.** They
  read copies, not the live tables, and cannot change anything Aggregate uses.

It is not a replica. Copies are as fresh as their last sync, and Aggregate never
reads from the forensic database. It is also not a tool for answering
individual data requests: a hash cannot be found from a browser's ID, by design.

## What is copied

Each copy is a table of the same name in the forensic database, replaced whole
at every sync.

| Copy | Source | Contents |
| --- | --- | --- |
| `forensic_events_v1` | `events` | Every retained event in both privacy modes, every column: `id`, `website_token`, `event_name`, `url`, `referrer`, `generalized_user_agent`, `privacy_mode`, `device_class`, `viewport_bucket`, `geo_area`, `screen_width`, `visitor_hash`, `session_hash`, `consent_state`, `custom_data` (the stored JSON, as text), `goal_event`, `created_at`, `archived_at` |
| `forensic_archive_events_v1` | `analytics_archive_events` | Archived hourly event cells, without suppression |
| `forensic_archive_geo_events_v1` | `analytics_archive_geo_events` | Archived daily geography cells, without suppression |
| `forensic_archive_goals_v1` | `analytics_archive_goals` | Archived daily goal cells, without suppression |
| `analytics_custom_*_v1`, `analytics_archived_*_v1` | The [private reporting views](DATA-MODEL.md#reporting-contract) | As they are |

All are selected by default; choose fewer on the admin page or with
`forensic_database_tables` and `forensic_database_views`. Approved views are not
offered here: they belong in the reporting database.

Column names follow the source tables, so a row can be compared with the
application database by `id`. Anonymous events have no visitor or session ID,
so their hash columns are empty. Column types follow the same catalog types as
the [reporting database](REPORTING-DATABASE.md#column-types).

The forensic copies are never offered to the reporting database, BigQuery, the
[reporting API](REPORTING-API.md) or the [MCP server](MCP.md).

## How identifiers are hashed

When a sync copies `forensic_events_v1`, Aggregate makes 32 random bytes as that
copy's key and replaces each `visitor_id` and `session_id` with
HMAC-SHA-256 of the identifier under that key, written as 64 hexadecimal
characters in `visitor_hash` and `session_hash`. This happens in Aggregate as
each row is read from the application database, before the row is written to
the temporary export file or the forensic database.

- **Within one copy,** the same browser always gets the same hash, so its
  events can be followed through the copy, and its session's events grouped.
- **Across copies,** every hash changes: the next sync uses a new key. A hash
  noted in a ticket matches only the copy it came from.
- **Backwards,** nothing leads from a hash to a browser. The key is never
  written to disk, logged, stored in the database or shown; it is wiped from
  memory when the copy is written. Because the IDs are random, a hash of a known
  ID cannot be computed without the key either.
- **Visitor and session IDs** are hashed in separate domains, so an equal
  session and visitor ID get different hashes.

The row `id` is copied as it is. Anyone who can read both the forensic and the
application databases could therefore join them by `id`, but such a person can
already read the application database's IDs; the copy adds no new way back.

Because every copy has new hashes, `forensic_events_v1` is rewritten at every
sync, even when no event changed.

## Create the database

Create a database of its own for forensic copies, on the same server as the
application database or another one. Aggregate refuses the application database
and the reporting database. Create two users: one Aggregate syncs with, which
can create and drop tables, and a read-only one for each person or team that
investigates.

**PostgreSQL**

```sql
CREATE ROLE aggregate_forensic_sync LOGIN PASSWORD 'a-long-random-password';
CREATE DATABASE aggregate_forensic OWNER aggregate_forensic_sync;
CREATE ROLE forensic_reader LOGIN PASSWORD 'another-long-random-password';

\c aggregate_forensic
REVOKE ALL ON DATABASE aggregate_forensic FROM PUBLIC;
GRANT CONNECT ON DATABASE aggregate_forensic TO forensic_reader;
GRANT USAGE ON SCHEMA public TO forensic_reader;
ALTER DEFAULT PRIVILEGES FOR ROLE aggregate_forensic_sync IN SCHEMA public GRANT SELECT ON TABLES TO forensic_reader;
```

**MySQL or MariaDB**

```sql
CREATE DATABASE aggregate_forensic CHARACTER SET utf8mb4;

CREATE USER 'aggregate_forensic_sync'@'app.example.net' IDENTIFIED BY 'a-long-random-password';
GRANT SELECT, INSERT, CREATE, DROP, ALTER ON aggregate_forensic.* TO 'aggregate_forensic_sync'@'app.example.net';

CREATE USER 'forensic_reader'@'support.example.net' IDENTIFIED BY 'another-long-random-password';
GRANT SELECT ON aggregate_forensic.* TO 'forensic_reader'@'support.example.net';
```

**SQL Server**

```sql
CREATE DATABASE aggregate_forensic;
CREATE LOGIN aggregate_forensic_sync WITH PASSWORD = 'A-long-random-password-1';
ALTER AUTHORIZATION ON DATABASE::aggregate_forensic TO aggregate_forensic_sync;

CREATE LOGIN forensic_reader WITH PASSWORD = 'Another-long-random-password-2';
USE aggregate_forensic;
CREATE USER forensic_reader FOR LOGIN forensic_reader;
GRANT SELECT ON SCHEMA::dbo TO forensic_reader;
```

**SQLite**

No setup is needed; the first sync creates the file (default
`var/forensic.db`). Anyone who can read the file reads every copy, so keep it
outside `public/` and limit the file's permissions to the people who
investigate.

Grant access on the database or schema, not on single tables: each sync
recreates the tables, which drops per-table grants.

## Restrict and mark the database

Aggregate writes forensic copies only into a database that says it is
restricted, and checks this at every sync:

- **MariaDB 10.5 or later, PostgreSQL.** Put the word `aggregate-forensic-copy`
  in the database's own comment, followed by `source=` and the name of the
  application database, once only the people who investigate can read it:

  ```sql
  -- PostgreSQL, as the database's owner
  COMMENT ON DATABASE aggregate_forensic
      IS 'Forensic copy, support team only [aggregate-forensic-copy source=aggregate]';

  -- MariaDB
  ALTER DATABASE aggregate_forensic
      COMMENT 'Forensic copy, support team only [aggregate-forensic-copy source=aggregate]';
  ```

  An installation whose application database has another name refuses to
  write there, so two installations cannot replace each other's copies.
- **MySQL, SQL Server, SQLite.** These engines keep no database comment Aggregate
  reads, so they cannot be marked. Confirm instead, on the admin page under
  **1. Restrict access**, or with `forensic_database_access_restricted: true`,
  that only the people who investigate can read the database.

On an engine that keeps comments, the confirmation is not enough: the database
must be marked. A database marked `aggregate-reporting-copy` (a
[read-only reporting copy for BI users](REPORTING-DATABASE.md#mark-it-as-a-read-only-reporting-copy))
is always refused. Until the mark or the confirmation is in place, every copy
fails with the reason and nothing is written.

## Connect Aggregate to it

Open **Reporting → Sync** and choose the **Forensic database** tab
(administrators only). Under **2. Connect the forensic database**, choose the
database type and enter the server, port, database name, user and password.
**Test and save** connects, creates and drops a small table, checks the mark or
the confirmation, and only then saves the address to `.env.local` as
`FORENSIC_DATABASE_URL`. The password is never shown again.

From the command line:

```bash
php bin/console app:forensic-database:configure --type=pgsql --host=db.example.net \
    --database=aggregate_forensic --user=aggregate_forensic_sync
```

The password is asked for, or read with `--password-stdin`. Add `--enable` to
turn the sync on in the same step, for the tables and views selected (all of
them unless changed), and `--interval=1440` to choose how often it copies them;
the scheduled task then makes the first copy within five minutes. Hosting panels
such as PRAGMA run it this way. You can also set
`FORENSIC_DATABASE_URL` yourself in `.env.local` or the server environment,
written like `DATABASE_URL`, and check it with
`php bin/console app:forensic-database:check`.

## Schedule the sync

Turn the sync on and choose the interval under **4. Schedule**. The server's one
[scheduled task](SCHEDULED-TASKS.md), `app:scheduler:run` every five minutes,
makes the copies. The default interval is once a day (1440 minutes): each sync
reads every selected table whole from the application database and rehashes
every identifier, so a longer interval keeps that load low. **Sync now** copies
everything at once.

| Command | Purpose |
| --- | --- |
| `app:forensic-database:sync` | Copy what is due; exits with an error when a copy fails |
| `app:forensic-database:sync --force` | Copy everything now |
| `app:forensic-database:sync --view=forensic_events_v1` | Limit a run to selected copies (repeatable) |
| `app:forensic-database:check` | Show the settings and status, then test the connection (`--no-connect` skips the test) |
| `app:forensic-database:configure` | Test a connection and save it to `.env.local`; `--enable [--interval=…]` also turns the sync on ([above](#connect-aggregate-to-it)) |

A sync writes each copy to a temporary file in `var/forensic-database` before it
loads it, so leave free space there for the largest table; the file is deleted
after each copy. Every copy is a processing task, shown on the admin page and
recorded in the audit trail with its row count, never its rows.

## Settings without the dashboard

Every setting except the connection lives in the active environment of
`config/aggregate.yaml`. Uppercase environment variables take precedence and
lock the matching admin fields; lists in the environment are comma-separated.

```yaml
forensic_database_enabled: true
forensic_database_tables:                  # Raw-table copies
  - forensic_events_v1
  - forensic_archive_events_v1
  - forensic_archive_geo_events_v1
  - forensic_archive_goals_v1
forensic_database_views:                   # Private views, as they are
  - analytics_custom_events_v1
forensic_database_interval_minutes: 1440   # 15, 30, 60, 120, 180, 360, 720 or 1440
forensic_database_access_restricted: false # Engines that cannot be marked only
```

| Setting | Environment variable | Default |
| --- | --- | --- |
| — | `FORENSIC_DATABASE_URL` | not set |
| `forensic_database_enabled` | `FORENSIC_DATABASE_ENABLED` | `false` |
| `forensic_database_tables` | `FORENSIC_DATABASE_TABLES` | every table copy |
| `forensic_database_views` | `FORENSIC_DATABASE_VIEWS` | every private view |
| `forensic_database_interval_minutes` | `FORENSIC_DATABASE_INTERVAL_MINUTES` | `1440` |
| `forensic_database_access_restricted` | `FORENSIC_DATABASE_ACCESS_RESTRICTED` | `false` |

Invalid settings stop the sync with an error instead of guessing.

## Privacy and data protection

- **Pseudonymous, not anonymous.** Hashing removes the browser's own ID, but
  one browser's events stay linked within a copy, next to page paths, a
  generalized user agent, screen width, coarse geography, consent state and
  enhanced custom properties. Treat the copies as personal data, protected like
  the application database, and describe the purpose (investigating problems)
  and who may read them in your records of processing. Whether this use is
  lawful for your installation is a decision for your privacy owner and legal
  counsel.
- **Access.** Only the people who investigate should hold an account on the
  forensic database. The mark or the confirmation records that decision; it
  does not enforce it, so keep the grants in step.
- **Linking back.** Nothing in Aggregate can link a hash to a browser. Exact
  enhanced-mode timestamps and page paths can still be matched against other
  records, such as web server logs that keep IP addresses; keep those apart
  from the forensic database's readers, or shorten their retention.
- **Retention.** Each sync replaces the copies, so data Aggregate deletes,
  including by retention, leaves the copy at the next sync. Copies you deselect,
  or all of them after the sync is turned off, stay until you drop them; so do
  the forensic database's own backups. Include them in your retention and
  deletion procedures.
- **Individual requests.** Because a browser's ID cannot be turned into its
  hash, the forensic database cannot be searched for one person's events. Handle
  access and deletion requests in the application database; the copy follows at
  the next sync.
- **Credentials.** `FORENSIC_DATABASE_URL`, with the sync user's password, lives
  in `.env.local` or the server environment, like `DATABASE_URL`, and is never
  shown, logged or stored elsewhere by Aggregate.

## Troubleshooting

| Message | What to do |
| --- | --- |
| "is not marked as a forensic copy" | Add `aggregate-forensic-copy source=<application database>` to the database's comment ([above](#restrict-and-mark-the-database)). |
| "keeps no database comment, so the forensic database cannot be marked" | On MySQL, SQL Server or SQLite, confirm restricted access under **1. Restrict access** or set `forensic_database_access_restricted: true`. |
| "marked as a read-only reporting copy for BI users" | That database is for BI users, who read every table. Use a separate database for forensic copies. |
| "marked as the forensic copy of the database …, not of this installation's database" | The database belongs to another installation. Create one of its own, or change `source=` in its comment if it now belongs to this one. |
| "names the application database" or "names the reporting database" | Point `FORENSIC_DATABASE_URL` at a database of its own. |
| "The table … cannot be read" | Run the database migrations (`php bin/console doctrine:migrations:migrate`). |
| "The view … cannot be read in this database" | Regenerate the custom views; see the [reporting database](REPORTING-DATABASE.md#troubleshooting). |
| Investigators lose access after a sync | Their access was granted on single tables. Grant it on the database or schema [instead](#create-the-database). |
