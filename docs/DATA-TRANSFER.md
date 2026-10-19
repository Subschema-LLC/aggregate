# Data transfer

Move collected rows to another installation of Aggregate. This copies data. It
does not copy settings, and it is not a live replica: collection on the source
does not continue on the target unless you point the sites at the target
afterwards.

Use it to change database engines, move to another server, or combine websites
onto one installation. The dashboard page is **Administration → Data transfer**.
The same operations run from the command line, which is the only way when the
dashboard is off.

## What moves

| Included when you ask | Left behind |
| --- | --- |
| `events` | YAML configuration, `.env`, and secrets |
| `analytics_archive_events`, `analytics_archive_goals`, `analytics_archive_geo_events` | Reporting views, which the target rebuilds |
| `users`, only with the account option: username, roles, password hash and creation time | The glossary, which the target rebuilds from its own YAML |
| | Each user's display name, email, permission groups and grants; a standard user has no access on the target until it is granted ([users and permissions](USERS-AND-PERMISSIONS.md)) |
| | Processing tasks and the audit trail, except the record of this transfer |

Anonymous and enhanced events both move, including enhanced identifiers. An
anonymous row that still has an identifier, an exact URL, an exact screen width,
or a full referrer is refused. It is not rewritten into an anonymous row. An
enhanced row without granted consent is refused too. Fix or remove that row,
then run again.

Archive cells are counts, not people. Their cell keys stay the same, so a second
import can recognise a cell it has already copied.

## Before you start

1. Pause collection on the source if the copy must be one moment in time. A
   transfer does not stop new events from arriving.
2. On the target, install the same application version and run
   `php bin/console doctrine:migrations:migrate -n`. The transfer does not create
   or change tables.
3. Create the target's websites, or plan to map the old website tokens to new
   ones. A token is not configuration; it is the value stored on each row.
4. Rebuild reporting views and the glossary on the target after the data is
   there. Those come from the target's YAML, not from the source.
5. Keep the package, and any id map, as private as the database. A package can
   contain enhanced identifiers and, if you asked for them, password hashes.

## Export and import

Export writes a directory under `var/data-transfer/<name>`. The files are mode
`0600`. Copy that directory to the same place on the target, by a private
channel, then import it there.

```bash
php bin/console app:data:export --package=move-1
php bin/console app:data:import --package=move-1
```

`--dataset=events` or `--dataset=archives` limits the copy. Repeat the option to
include both. Leaving it off copies both. `--website-token=` limits the copy to
those websites; repeat it for more than one. The token is the value stored on
the rows, not a website name.

`--on-conflict` chooses what happens when a row is already on the target:

| Value | Result |
| --- | --- |
| `fail` | Stop that table. This is the default. |
| `skip` | Leave the existing row and continue. |
| `replace` | Overwrite the existing row and continue. |

Each table is committed on its own. If a later table stops, the tables already
copied stay. Run again with `--on-conflict=skip` to continue.

`--map-token=old=new` rewrites a website token as rows are written. The new
token must be one this installation can collect. Mapping does not add archive
counts together: if two cells would become the same reported cell, that archive
table is not imported.

`--new-ids` assigns new ids to events and users and writes
`var/data-transfer/id-maps/id-map-*.jsonl`, mapping each old id to the new one.
Archive cell keys are not changed. Use this when the target already has rows
and the source ids would collide. Event ids are not a reporting join, so new
ids do not change a BI report.

`--dry-run` validates and counts without writing. `--force` replaces an existing
package of the same name. `--consistent` reads inside one transaction. Pause
collection first: on some databases that read can block writers until it
finishes.

## Copy straight into another database

When both databases are reachable from one server, skip the package:

```bash
AGGREGATE_TRANSFER_TARGET_URL='postgresql://user:pass@host:5432/analytics' \
  php bin/console app:data:copy
```

The URL uses the same form as `DATABASE_URL`. Put it in the command's
environment. Do not pass it as an option, and do not paste it into the
dashboard: a URL contains a password, and the page does not ask for it. The
dashboard can start the copy only when that variable is already set for the
server. It never shows the URL.

The target must be a different database. If the command cannot prove that, it
copies nothing. The target must already have the tables.

`app:data:copy` accepts the same `--dataset`, `--website-token`, `--map-token`,
`--on-conflict`, `--new-ids`, `--dry-run` and `--consistent` options as import.

## User accounts

Leave accounts out unless the target should have the same people. The option is
`--include-users`, or **Include user accounts** on the page. It copies the
username, roles, and password hash, so those people can sign in on the target
with their current password. It does not copy sessions. A hash is still a
secret: treat the package as private, and prefer creating new accounts on the
target when the people are different.

## The record of a run

Every command records one row in `processing_tasks` with the type
`data_transfer`. Finishing it, successfully or not, also writes `audit_trail`.
The dashboard starts those same commands, so a page run is recorded too, with
the administrator as the actor. Starting one from the page is also recorded as
a manual run (`data_export_started`, `data_import_started` or
`data_copy_started`), as described in the
[audit trail guide](AUDIT-TRAIL.md#what-is-recorded). The details are the
counts and the safe summary. They do not include row values, identifiers, or the target URL.

A second transfer waits while one is running. A run that is killed can hold
that lock for up to 24 hours; the next start then marks it abandoned. A run
that fails on its own releases the lock and records the failure.

The page shows the latest of these records. That is the log. The command's own
output is the same summary, written to `var/data-transfer/last-output.log` when
the dashboard starts it.

## After the copy

1. Rebuild the reporting views and glossary on the target.
2. Compare counts with the summary. The summary is also the audit entry.
3. Point the sites at the target only after the counts match.
4. Delete the package and any id map when you no longer need them. The transfer
   does not delete the source rows.

## Limits

- This is a copy, not synchronisation. New events after the read are not
  included. Run again with `--on-conflict=skip`, or pause collection for a
  consistent read.
- A bad row stops its table. The transfer does not skip it silently and does
  not rewrite it.
- SQLite is what the automated tests run against. A MySQL copy, including the
  id sequence after an explicit id, was checked the same way. PostgreSQL and
  SQL Server use the same statements with their own identity handling, and a
  production move should still be rehearsed on the engines involved.
- The application must be able to open the target database with its PHP
  driver. A URL for an engine whose driver is not installed is refused.
