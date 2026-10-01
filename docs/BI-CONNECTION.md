# Connect BI tools and AI assistants

[BI labels and glossary](BI-GLOSSARY.md) · [Privacy guide](PRIVACY-COMPLIANCE.md#bi-exposure-and-suppression) · [Databases](DATABASE.md)

Aggregate has no reports of its own. Power BI, Tableau, Looker, any other tool
that reads SQL, or an AI assistant connects to versioned views in the Aggregate
database. This page lists those views, the queries that use them correctly, and
what to check before giving a reporting tool or an assistant access. Each
installation also serves a shorter version of this reference at
`/how-it-works/data-visualization`.

## The reporting boundary

Routine BI users get `SELECT` on the approved views and nothing else:

| Views | Contents |
| --- | --- |
| `bi_anonymous_events_v1`, `bi_anonymous_goals_v1`, `bi_anonymous_geo_events_v1` | Counted anonymous events, with small cells withheld |
| The seven `bi_dim_*_v1` views, `bi_glossary_values_v1`, `bi_glossary_columns_v1` | Labels and definitions for codes, columns and websites; no traffic |

Do not grant them the raw `events` table, the archive tables, the
`analytics_glossary` table, the operational `analytics_archived_*` views or the
`analytics_custom_*` views. Those contain individual rows or unsuppressed counts.
The grant statements for each database engine are in
[view-only grants](BI-GLOSSARY.md#view-only-grants).

The approved views include archived counts when archiving is on, and they keep
their column names and meanings; a change in meaning would ship as a new version
(`_v2`) through a migration.

## Approved views

### `bi_anonymous_events_v1`

One row per released hourly cell, grouped by every column except `event_count`.
The current UTC hour and cells below `anonymous_min_cell_count` (default 5,
allowed 2–1000) are absent.

| Column | Type | Meaning |
| --- | --- | --- |
| `website_token` | String | The website the events belong to. Website names and domains are kept outside the database, in `config/websites.yaml`. |
| `event_hour` | UTC date and time | Start of a completed UTC hour. Anonymous event times are truncated to this hour. |
| `event_name` | String | `view` for page views, or a fixed, safe name for a measured interaction. |
| `page_path` | Text | Sanitized path: query strings and fragments removed, identifier-like segments redacted. |
| `referrer_channel` | Category | `direct`, `internal`, `search`, `social`, `email`, `referral` or `unknown`. |
| `device_class` | Category | `mobile`, `tablet`, `desktop`, `bot` or `unknown`. |
| `viewport_bucket` | Category | `small`, `medium`, `large` or `unknown`. |
| `event_count` | Integer | Number of events in the cell. Not a count of people. |

Use `SUM(event_count)` when a report drops a dimension. The total covers released
cells only: summing does not re-apply suppression at the broader level or bring
back withheld cells.

### `bi_anonymous_goals_v1`

One row per released daily goal cell, from anonymous events that kept an allowed
goal code. The current UTC day and cells below `anonymous_min_cell_count` are
absent.

| Column | Type | Meaning |
| --- | --- | --- |
| `website_token` | String | The website the goals belong to. |
| `event_day` | UTC date | A completed UTC day. |
| `goal_event` | String | Stable goal code from `config/goals.yaml`. Labels come from `bi_dim_goal_event_v1`. |
| `event_count` | Integer | Number of goal occurrences. Not people or unique converters. |

The view deliberately leaves out event name, path, referrer, device, viewport,
geography and identifiers. A configured goal can still be sensitive in context,
such as a goal that only a few people reach.

### `bi_anonymous_geo_events_v1`

Present when [optional coarse geography](CONFIGURATION.md#optional-coarse-geography)
is on. One row per released daily geography cell. The current UTC day is absent,
and areas below `anonymous_geo_min_cell_count` (default 25, allowed 10–1000) are
pooled rather than shown.

| Column | Type | Meaning |
| --- | --- | --- |
| `website_token` | String | The website the events belong to. |
| `event_day` | UTC date | A completed UTC day. |
| `event_name` | String | `view` for page views, or a fixed, safe interaction name. |
| `geo_area` | Category | `continent:XX`, `country:XX`, or a pool: `continent:other` or `country:other`. |
| `event_count` | Integer | Number of events in the cell. Not a count of people. |

Small areas are combined into an `other` pool, which is itself released only when
it reaches the threshold. When exactly one area would be hidden, the smallest
visible area joins the pool too, so the hidden one cannot be worked out by
subtraction. Path, referrer, device, viewport and identifiers are not in this
view, and events without a geography value are left out, so its totals will not
match the hourly view.

**Keep the three views separate.** They have different grains and are suppressed
independently. Joining them to each other, or to anything else, does not recover
withheld detail, and it can produce wrong totals.

## Labels and definitions

The `bi_dim_*_v1` views turn codes into labels such as "Tablet" or "Germany",
and `bi_glossary_columns_v1` describes each column. They hold declared
configuration and built-in definitions, never observed traffic. Administrators
publish them with `app:analytics:glossary:sync`, or by saving under
**Reporting → BI glossary**.

- Join each dimension view to the matching fact column (for example,
  `device_class`) as a many-to-one relationship, and display the label.
- Use a left join and fall back to the code: undeclared event names, deleted goals
  and the `other` geography pools have no label row.
- For translated labels, filter `bi_glossary_values_v1` to one dimension and one
  locale before joining, or every fact row is multiplied.

```sql
SELECT f.event_day, f.goal_event,
       COALESCE(d.goal_event_label, f.goal_event) AS goal_label,
       f.event_count
FROM bi_anonymous_goals_v1 f
LEFT JOIN bi_dim_goal_event_v1 d ON d.goal_event = f.goal_event;
```

Step-by-step Power BI and Tableau instructions, locale parameters and the full
column lists are in [BI labels and glossary](BI-GLOSSARY.md#views-and-bi-relationships).
Everything in these views is visible to every routine BI user, so keep labels and
descriptions free of personal or identifying text.

## Custom reporting views

Properties defined in the [data model](DATA-MODEL.md) can be turned into columns
in three generated views:

- `analytics_custom_events_v1`: every event name, in both privacy modes;
- `analytics_custom_pageviews_v1`: page views only;
- `analytics_custom_goals_v1`: events that kept a goal.

Each modeled property becomes a text column, and numeric properties can also get
a number column for calculations; missing values, JSON `null`, arrays and objects
become SQL `NULL`. These views show individual raw rows without
suppression, so they need separately approved raw-data access and are not part of
the routine BI contract. They include retained rows that were already archived,
but archived counts carry no custom properties, and deleting raw rows removes them
from these views. Regenerate them with `app:analytics:views:regenerate` or under
**Reporting → Reporting views**. See
[custom data reporting views](DATABASE.md#custom-data-reporting-views).

## Private source tables

These tables explain how the views are built. They are not for routine BI access:
`events` holds individual rows, which may include enhanced identifiers and
properties, and even anonymous rows can be personal data in context.

**`events`** is the single event store for both privacy modes. Types are logical;
the physical type differs between database engines.

| Column | Type | Purpose |
| --- | --- | --- |
| `id` | Integer | Generated key for one event row. |
| `website_token` | String (191) | Website, in both modes. |
| `event_name` | String (191) | Event name; `view` by default. |
| `url` | Text | Sanitized page path; published as `page_path`. |
| `referrer` | Nullable text | Referrer channel; published as `referrer_channel`. |
| `privacy_mode` | String (20) | `anonymous` (default) or `enhanced`. |
| `device_class` | String (20) | Coarse device category, in both modes. |
| `viewport_bucket` | String (20) | Coarse viewport category, in both modes. |
| `geo_area` | Nullable string (16) | `continent:XX` or `country:XX` only; the `other` pools exist only in the geography view. |
| `generalized_user_agent` | Nullable string (191) | Enhanced only; always null in anonymous mode. |
| `screen_width` | Nullable integer | Enhanced only; always null in anonymous mode. |
| `visitor_id` | Nullable string (191) | Enhanced only; always null in anonymous mode. |
| `session_id` | Nullable string (191) | Enhanced only; always null in anonymous mode. |
| `consent_state` | Nullable string (20) | `granted` for enhanced rows; null in anonymous mode. |
| `custom_data` | Nullable JSON | Allowed scalar properties, optional `page_sequence`, and the organization-traffic marker. Anonymous rows keep only properties configured for collection without consent. Not included in the approved views or the archives. |
| `goal_event` | Nullable string (191) | An enabled goal code; kept on anonymous rows only when the goal allows anonymous use. |
| `created_at` | UTC date and time | The UTC hour for anonymous rows; the exact server time for enhanced rows. |

**`analytics_privacy_settings`** holds the thresholds the approved views read, in
a single row with `id = 1`:

| Column | Type | Meaning |
| --- | --- | --- |
| `id` | Integer | Always 1; the views read this row. |
| `anonymous_min_cell_count` | Integer | Threshold for the hourly event and daily goal views; default 5, allowed 2–1000. |
| `anonymous_geo_min_cell_count` | Integer | Threshold for the daily geography view; default 25, allowed 10–1000. |
| `updated_at` | UTC date and time | When the row last changed. |

If the row is missing or a value is out of range, the affected views return no
rows. Change the thresholds under **Reporting → BI disclosure**. BI accounts
should not be able to read this table.

**There is no `websites` table.** Website names, domains and tokens live in
`config/websites.yaml`. If reports need friendly names, keep a token-to-name
mapping in the BI model or in a separately governed reporting dataset.

## Safe query patterns

Pass the website token as a parameter from your BI tool, select explicit columns,
and name results as released counts: enhanced events and withheld cells are not in
them.

Released page views by hour:

```sql
SELECT event_hour, page_path, SUM(event_count) AS released_page_view_count
FROM bi_anonymous_events_v1
WHERE website_token = 'replace-with-site-token'
  AND event_name = 'view'
GROUP BY event_hour, page_path
ORDER BY event_hour, page_path;
```

Released goals by day:

```sql
SELECT event_day, goal_event, event_count AS released_goal_occurrence_count
FROM bi_anonymous_goals_v1
WHERE website_token = 'replace-with-site-token'
ORDER BY event_day, goal_event;
```

Released page views by area:

```sql
SELECT event_day, geo_area, SUM(event_count) AS released_page_view_count
FROM bi_anonymous_geo_events_v1
WHERE website_token = 'replace-with-site-token'
  AND event_name = 'view'
GROUP BY event_day, geo_area
ORDER BY event_day, geo_area;
```

Use `SUM(event_count)`, not `COUNT(*)`: counting rows counts cells, not the events
in them.

## Connection checklist

### PostgreSQL, MySQL, MariaDB and SQL Server

- Create a dedicated database account for reporting; never reuse the
  application's credentials.
- Grant only what connecting needs plus `SELECT` on the approved views you use
  ([grant statements](BI-GLOSSARY.md#view-only-grants)). Do not grant access to
  source tables.
- Use TLS, and allow the account to connect only from where the BI service runs.
- Check the grants after upgrades: migrations can recreate views.
- A view contains every website's rows. Filtering by `website_token` in a BI tool
  is not tenant isolation.

### SQLite

- SQLite has no database users or per-view permissions. Anyone who can read the
  database file can read the private `events` table.
- Have a trusted process export explicit columns from the approved views instead,
  and protect the exported files.
- Use a server database when a BI tool needs a live connection.

## Model and refresh settings

- Treat `event_hour` as UTC; the stored value may not carry a time zone.
- Model `event_day` as a date and `event_count` as a whole number, whatever
  physical type the connector reports.
- Expect new hourly data only after the hour ends, and goal and geography data
  only after the UTC day ends.
- Treat a missing cell as withheld or unavailable, not as zero.
- `event_count` counts events or goal occurrences, not visitors, people or unique
  converters.
- Review stored BI extracts on their own schedule: raising a threshold later does
  not remove data already exported.

## Connect an AI assistant

An AI assistant or agent that can run SQL can answer questions from the same
views as a BI tool: "Which pages gained the most views last week?", "How did
sign-ups trend by day this month?" or "Which countries did most page views come
from?" Aggregate does not include an assistant. Use any tool that connects a
model to a SQL database, such as a database connector (an MCP server) for your
assistant or a notebook, with the settings below. A packaged, read-only
integration is a [roadmap proposal](../ROADMAP.md#proposals-to-explore).

### Give it the reporting account

Connect with the dedicated read-only account from the
[connection checklist](#connection-checklist), which can read the approved views
and nothing else. The assistant then gets the same protection as any BI user:
completed periods only, small cells withheld, and no identifiers or raw rows.

- Never give it the application's database credentials, the raw `events` table,
  the `analytics_custom_*` views or a SQLite database file. On SQLite, give it an
  export of the approved views instead.
- Prefer a connector that can only read, and limit how many rows one query can
  return.

### Give it the data dictionary

The glossary views describe every column and label every code, which is the
context a model needs to write correct SQL instead of guessing. Load the column
descriptions into the assistant's instructions, or let it query them:

```sql
SELECT object_name, column_name, label, description
FROM bi_glossary_columns_v1
WHERE is_default_locale = 1
ORDER BY object_name, column_name;
```

Publish the glossary first with `app:analytics:glossary:sync` (see
[BI labels and glossary](BI-GLOSSARY.md#install-and-synchronize)). The
`bi_dim_*_v1` views give it readable labels for codes such as `device_class`.

### Tell it the rules

Assistants write plausible SQL that can still be wrong for these views. Add rules
like these to its instructions:

```text
You can query these read-only SQL views about website traffic:
- bi_anonymous_events_v1: one row per completed UTC hour and combination of
  website_token, event_name, page_path, referrer_channel, device_class and
  viewport_bucket, with event_count.
- bi_anonymous_goals_v1: one row per completed UTC day, website_token and
  goal_event, with event_count.
- bi_anonymous_geo_events_v1: one row per completed UTC day, website_token,
  event_name and geo_area, with event_count.
- bi_dim_* views: labels for codes. Left join them and fall back to the code.
Rules:
- Total with SUM(event_count), never COUNT(*).
- event_count counts events or goal occurrences, not people, visitors or visits.
- A missing row means the count was withheld or unavailable, not zero.
- Never join the three views to each other; each is counted separately.
- All times are UTC. The current hour (events) and day (goals, geography) are
  not included yet.
- Filter by website_token to report on one website.
```

### What leaves your servers

The model provider receives your questions and the query results: released
counts, page paths, event names and labels, not visitor records. Paths and event
names can still be sensitive in context, such as a path that names a medical
condition. Check the provider's terms for how long it keeps prompts and whether
it trains on them, or use a model you host yourself to keep everything in-house.

### Limits

An assistant can run many queries quickly, and comparing many results can narrow
down withheld numbers, just as a determined BI user could. Thresholds count
events, not people. Give assistant access only to people who may already see these
reports, consider a higher threshold for low-traffic or sensitive sites, keep the
connector's query log if it has one, and check important answers against the SQL
the assistant ran.

## What suppression does not do

Thresholds count events, not people, so one person can meet a threshold alone.
Suppression reduces the chance of exposing rare combinations, but it does not
establish k-anonymity or make the released data legally anonymous, and it cannot
prevent every inference across reports, time periods or outside data. Apply
retention, access control and review to BI tools, AI assistants and their
extracts as well. The [privacy guide](PRIVACY-COMPLIANCE.md#bi-exposure-and-suppression)
explains the suppression rules in full.
