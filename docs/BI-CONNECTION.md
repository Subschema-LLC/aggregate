# Connect BI tools and AI assistants

[BI labels and glossary](BI-GLOSSARY.md) · [Privacy guide](PRIVACY-COMPLIANCE.md#bi-exposure-and-suppression) · [Databases](DATABASE.md)

Aggregate has no reports of its own. Power BI, Tableau, Looker, any other tool
that reads SQL, or an AI assistant connects to versioned views in the Aggregate
database. This page lists those views, the queries that use them correctly, and
what to check before giving a reporting tool or an assistant access. Each
installation also serves a shorter version of this reference at
`/how-it-works/data-visualization`. Its column definitions are the built-in BI
glossary's, the same text BI tools read in `bi_glossary_columns_v1`.

## The reporting boundary

Routine BI users get `SELECT` on the approved views and nothing else:

| Views | Contents |
| --- | --- |
| `bi_anonymous_events_v1`, `bi_anonymous_daily_v1`, `bi_anonymous_daily_rollup_v1`, `bi_anonymous_goals_v1`, `bi_anonymous_geo_events_v1` | Counted anonymous events, with small cells withheld or pooled |
| `bi_anonymous_coverage_v1` | How much of each day's events the event views release, in bands |
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
allowed 2–1000) are absent. The hour is withheld in UTC because that is the
stored grain; its label is the reporting timezone.

| Column | Type | Meaning |
| --- | --- | --- |
| `website_token` | String | The website the events belong to. Website names and domains are kept outside the database, in `config/websites.yaml`. |
| `event_hour` | Date and time | Start of a completed hour in the [reporting timezone](#reporting-timezone), UTC unless that setting is changed. Anonymous event times are stored as a UTC hour; the view relabels that hour. The value may not carry a time zone marker. |
| `event_name` | String | `view` for page views, or a fixed, safe name for a measured interaction. |
| `page_path` | Text | Sanitized path: query strings and fragments removed, identifier-like segments redacted. |
| `referrer_channel` | Category | `direct`, `internal`, `search`, `social`, `email`, `referral` or `unknown`. |
| `device_class` | Category | `mobile`, `tablet`, `desktop`, `bot` or `unknown`. `bot` marks [recognized bots](PRIVACY-COMPLIANCE.md#bot-traffic); filter `device_class <> 'bot'` to leave them out. |
| `viewport_bucket` | Category | `small`, `medium`, `large` or `unknown`. |
| `event_count` | Integer | Number of events in the cell. Not a count of people. |

Use `SUM(event_count)` when a report drops a dimension. The total covers released
cells only: summing does not re-apply suppression at the broader level or bring
back withheld cells.

### `bi_anonymous_daily_v1`

One row per released daily cell by page group, referrer channel and device
class. It answers the same questions as the hourly view at a coarser grain, so a
low-traffic site keeps far more of its events: dropping the hour and the
viewport, and grouping many small pages, lets cells reach the threshold that
hourly cells miss. The current day in the [reporting timezone](#reporting-timezone)
is absent. UTC is that timezone unless an administrator changes it.

| Column | Type | Meaning |
| --- | --- | --- |
| `website_token` | String | The website the events belong to. |
| `event_day` | Date | A completed day in the [reporting timezone](#reporting-timezone). UTC unless that setting is changed. |
| `event_name` | String | `view` for page views, or a fixed, safe interaction name. |
| `page_group` | Text | The label of the first matching [page group](#page-groups) rule, or the sanitized page path when no rule matches. Labels never start with `/`. |
| `referrer_channel` | Category | `direct`, `internal`, `search`, `social`, `email`, `referral` or `unknown`. |
| `device_class` | Category | `mobile`, `tablet`, `desktop`, `bot` or `unknown`. `bot` marks [recognized bots](PRIVACY-COMPLIANCE.md#bot-traffic); filter `device_class <> 'bot'` to leave them out. |
| `event_count` | Integer | Number of events in the cell. Not a count of people. |

A cell is released when it reaches `anonymous_min_cell_count` **and** the part
of it that the hourly view withholds is either zero or at least that minimum.
Without the second rule, subtracting the released hourly rows of a day from its
daily row would reveal the withheld remainder, which could be a count of one or
two. The view rebuilds the hourly cells with the hourly view's own grouping and
threshold to apply it, and the two views are always regenerated together with
one threshold.

So a page with seven views in one hour and two in another publishes the hourly
row of seven and no daily row, while a page with two views in each of three
hours publishes no hourly row and a daily row of six. On a busy site, where most
hourly cells are released, more daily cells are withheld by the second rule; use
the hourly view there. To keep withheld cells in coarser rows instead, use
[`bi_anonymous_daily_rollup_v1`](#bi_anonymous_daily_rollup_v1).

#### Page groups

Page groups map sanitized paths to content labels, such as every vehicle page to
`inventory` or every branch page to `location`. They live in YAML as
`bi_page_groups`, or on **Reporting → Page groups** in the dashboard:

```yaml
bi_page_groups:
  - { pattern: '/inventory/**', group: inventory }
  - { pattern: '/locations/*', group: location }
  - { pattern: '/files/*.pdf', group: download }
```

- The first matching rule wins. A path that matches no rule keeps its own path.
- `/inventory/**` matches `/inventory` and every page below it; `/locations/*`
  matches exactly one more segment; `*` inside the last segment, as in
  `/files/*.pdf`, matches within that segment only. Wildcards are allowed only in
  the last segment, the same meanings as in `anonymous_excluded_paths`, limited to
  what every supported database matches exactly with `LIKE`.
- Labels are 1–64 characters, start with a letter or digit, and never start with
  `/`. They are published to routine BI users, so keep them non-identifying.
- Matching follows the database collation: case-insensitive on MySQL, MariaDB,
  SQL Server and SQLite with their default collations, case-sensitive on
  PostgreSQL. Write patterns in the case your paths use.
- Rules are compiled into the view's SQL. Saving the Page groups page
  regenerates the BI views; after editing YAML, run
  `php bin/console app:analytics:bi-views:regenerate`. Invalid rules stop
  regeneration and leave the existing views in place.

Grouping changes only the daily views. The hourly view keeps its paths, so
grouping does not reduce what the hourly view withholds.

### `bi_anonymous_daily_rollup_v1`

The daily cells, with the ones `bi_anonymous_daily_v1` withholds rolled up into
coarser rows instead of dropped. Use it when a report needs daily totals that
add up, such as page views per day for a small site.

| Column | Type | Meaning |
| --- | --- | --- |
| `website_token` | String | The website the events belong to. |
| `event_day` | Date | A completed day in the [reporting timezone](#reporting-timezone). UTC unless that setting is changed. |
| `event_name` | String | `view` or a fixed, safe interaction name. Every row keeps it. |
| `page_group` | Text | Page group label, sanitized path, or `(other)`. |
| `referrer_channel` | Category | Coarse source channel, or `(other)`. |
| `device_class` | Category | Coarse device category, or `(other)`. |
| `event_count` | Integer | Number of events in the row. Not a count of people. |
| `detail_level` | Category | Which dimensions are known: `page_channel_device`, `page_channel`, `page`, `channel` or `day`. |

Cells that cannot be released in full are pooled step by step, each step only
over what the previous one left: devices (`page_channel`), then channels
(`page`), then pages while keeping the channel (`channel`), then everything for
the event and day (`day`). A pooled value is `(other)`, meaning the part not
shown in more detailed rows, not every value. Whatever is still below the
threshold after the last step is left out.

- The `page_channel_device` rows are exactly the rows of `bi_anonymous_daily_v1`.
- Each event is in at most one row, so `SUM(event_count)` by day adds up to the
  day's released events, usually nearly all of them.
- Every row reaches `anonymous_min_cell_count` and follows the daily view's rule
  against the hourly view: the part of the row the hourly view withholds is zero
  or at least the threshold, so no subtraction reveals a small count.
- Filter on `detail_level` for detail. A device report should keep the
  `(other)` rows or note that some events have no device breakdown.

### `bi_anonymous_coverage_v1`

How complete each event view is. One row per website, completed day in the
[reporting timezone](#reporting-timezone) and event view, for days with at least
`anonymous_min_cell_count` anonymous events.

| Column | Type | Meaning |
| --- | --- | --- |
| `website_token` | String | The website. |
| `event_day` | Date | A completed day in the [reporting timezone](#reporting-timezone). UTC unless that setting is changed. |
| `view_name` | String | `bi_anonymous_events_v1`, `bi_anonymous_daily_v1` or `bi_anonymous_daily_rollup_v1`. |
| `coverage_band` | Category | Percent of the day's anonymous events the view releases: `90-100`, `50-90`, `10-50` or `0-10`. |

It reports a band, not a number: an exact share would reveal the day's total
and so the withheld remainder. Show it next to a report so a low number reads as
"withheld" rather than "broken", or use it to choose the view for a site. The
band still narrows the withheld remainder to a range, which is why days below the
threshold are left out.

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

**Keep the views separate.** They have different grains and are suppressed
independently. Joining them to each other, or to anything else, does not recover
withheld detail, and it can produce wrong totals. Choose the hourly or the daily
event view for a report rather than combining them.

## Reporting timezone

`reporting_timezone` is one IANA name for the installation, default `UTC`. Set
it under **Administration → General settings → Reporting timezone**, in YAML, or with
`app:analytics:reporting-timezone`. `REPORTING_TIMEZONE` overrides the saved
value. Saving, or `app:analytics:bi-views:regenerate`, compiles it into
`bi_anonymous_events_v1`, `bi_anonymous_daily_v1`, `bi_anonymous_daily_rollup_v1`
and `bi_anonymous_coverage_v1`.

```yaml
reporting_timezone: America/Chicago
```

- Use a name, not an offset or abbreviation. `UTC-5` and `EST` are rejected: a
  fixed differential is wrong for half the year where daylight-saving time applies.
- The default `UTC` leaves the views and existing models unchanged. Choosing
  another zone changes what `event_hour` and the event views' `event_day` mean
  for that installation. Refresh imported extracts and BigQuery copies afterwards;
  they keep the previous zone until then.
- Collection does not change. Anonymous `created_at` remains a server-generated
  UTC hour, and rows are not rewritten. The view relabels that hour. It is not
  the visitor's local time.
- For US, UK, EU and Japan zones, each UTC hour lands on one local hour. For
  India, Newfoundland and Nepal, the label is the local hour that contains the
  UTC hour's midpoint. A flat `+5:30` shift would mislabel the bucket.
- One stored UTC hour stays one cell, including the repeated local hour when
  daylight-saving time falls back. Do not add the hourly and daily views
  together; they can describe the same events.
- The hourly view still withholds the current UTC hour, because that hour is the
  stored grain and it is complete only when it ends. Daily event views withhold
  the current day in the reporting timezone, calculated when the view is queried.
- `bi_anonymous_goals_v1` and `bi_anonymous_geo_events_v1` stay on UTC days.
  Their archives have already dropped the hour, so shifting only the live half
  would split one local day across two dates.
- MySQL and MariaDB must have their time zone tables loaded before a named
  zone can be compiled. If `CONVERT_TZ` cannot convert the name, regeneration
  fails and the existing views stay. On the database server:

  ```bash
  mysql_tzinfo_to_sql /usr/share/zoneinfo | mysql -u root -p mysql
  ```

  MariaDB 10.11 and newer use `mariadb-tzinfo-to-sql` and `mariadb`; where the
  old names are symlinks, either form works. Plesk's administrator is `admin`,
  with the password in `/etc/psa/.psa.shadow`. Then run
  `php bin/console app:analytics:bi-views:regenerate`, or
  `php bin/console app:analytics:mysql-timezones:load`, which loads the tables
  when this server can administer MySQL and then regenerates. With this
  repository's Docker Compose, `make load-mysql-timezones` loads the running
  database container (`DOCKER_PROFILE=mysql` or `mariadb`); a new container
  loads them on first start. Restart the database server if the name still
  does not convert, then load or regenerate again.
  SQL Server uses a Windows time zone name; an unmapped IANA name is refused.
  SQLite has no time zone database, so regeneration compiles that zone's
  transitions into the view. Hours after the year 2100 keep the last compiled
  offset until the views are regenerated.
- Retention, archive keys, processing tasks and the audit trail stay in UTC.

A second local column is not added to these views. The daily views rebuild the
hourly cells and withhold a day when subtracting the released hours would reveal
a small count. Another time column would make that harder to keep and easier to
sum twice.

If different report audiences need different zones, leave the setting at `UTC`
and convert in Power BI or Tableau. One deployment has one reporting timezone.
A zone per website is not available yet.

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
become SQL `NULL`. By default the views also have `org_internal_traffic`
(`true` for your team's marked browsers, so reports can filter them out) and
`page_sequence` (page depth, while it is on); see
[built-in properties](DATA-MODEL.md#built-in-properties). These views show individual raw rows without
suppression, so they need separately approved raw-data access and are not part of
the routine BI contract. They include retained rows that were already archived,
but archived counts carry no custom properties, and deleting raw rows removes them
from these views. Saving the data model updates them; `app:analytics:views:regenerate`
does the same without the dashboard. See
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
| `custom_data` | Nullable JSON | Allowed scalar properties, optional `page_sequence`, and the organization-traffic flag `org_internal_traffic` (true or false). Anonymous rows keep only properties configured for collection without consent. Not included in the approved views or the archives. |
| `goal_event` | Nullable string (191) | An enabled goal code; kept on anonymous rows only when the goal allows anonymous use. |
| `created_at` | UTC date and time | The UTC hour for anonymous rows; the exact server time for enhanced rows. |
| `archived_at` | Nullable UTC date and time | Set when maintenance has counted the row into the [archive tables](DATABASE.md#analytics-archive-and-maintenance). The row stays until retention deletes it. |

**`anonymous_min_cell_count`** and **`anonymous_geo_min_cell_count`** in
`config/aggregate*.yaml` define the thresholds the approved views publish:

| Key | Type | Meaning |
| --- | --- | --- |
| `anonymous_min_cell_count` | Integer | Threshold for the hourly, daily and roll-up event views, the daily goal view, and the coverage view's minimum day; default 5, allowed 2–1000. |
| `anonymous_geo_min_cell_count` | Integer | Threshold for the daily geography view; default 25, allowed 10–1000. |

Dashboard saves under **Reporting → BI disclosure** write YAML and regenerate
`bi_anonymous_events_v1`, `bi_anonymous_goals_v1`, `bi_anonymous_geo_events_v1`,
`bi_anonymous_daily_v1`, `bi_anonymous_daily_rollup_v1` and `bi_anonymous_coverage_v1`;
after editing YAML directly, run `php bin/console app:analytics:bi-views:regenerate`.
Server databases replace the views in place, so the reporting account's grants
are kept. Environment overrides (`ANONYMOUS_MIN_CELL_COUNT`,
`ANONYMOUS_GEO_MIN_CELL_COUNT`) take precedence.

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
  and protect the exported files. [BigQuery sync](BIGQUERY.md) does this on a
  schedule, copying the approved views to a BigQuery dataset, and the
  [reporting database](REPORTING-DATABASE.md) copies them into a server
  database such as PostgreSQL or MySQL, where BI users get their own logins.
- Use a server database when a BI tool needs a live connection.

## Copy the views to a reporting database

The [reporting database](REPORTING-DATABASE.md) sync copies selected views into
same-named tables of a second database on a schedule. BI users then connect
there, with logins that cannot reach the application database, and their
queries run against plain tables, on another server if you like. The copies are
as fresh as their last sync. Private views can be added only by an explicit
opt-in, and the rules on this page apply to the copies too.

## Copy the views to BigQuery

For Looker Studio, Connected Sheets or other tools that read Google BigQuery,
[BigQuery sync](BIGQUERY.md) copies the approved views to a BigQuery dataset on a
schedule, replacing each table at every sync. Private views can be added only by
an explicit opt-in. The rules on this page apply to the copies too.

## Read the views over HTTP

The [reporting API](REPORTING-API.md) returns rows from the same views over
HTTPS with a token, for scripts, notebooks and tools that cannot open a database
connection. It serves approved views by default; private views only where an
administrator allows them. The rules on this page apply to what it returns.

## Model and refresh settings

- Treat `event_hour` as the reporting timezone, UTC unless
  [`reporting_timezone`](#reporting-timezone) is changed. The stored value may
  not carry a time zone marker.
- Model `event_day` as a date and `event_count` as a whole number, whatever
  physical type the connector reports. Event-view days use the reporting
  timezone; goal and geography days stay UTC.
- Expect new hourly data only after the UTC hour ends, daily event data only
  after the reporting-timezone day ends, and goal and geography data only after
  the UTC day ends.
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
  viewport_bucket, with event_count. event_hour is that hour labelled in the
  installation reporting timezone (UTC unless reporting_timezone was changed).
- bi_anonymous_daily_v1: one row per completed day in that same reporting
  timezone and combination of website_token, event_name, page_group,
  referrer_channel and device_class, with event_count. page_group is a content
  label, or a page path when it starts with /.
- bi_anonymous_daily_rollup_v1: like bi_anonymous_daily_v1, plus rows where
  page_group, referrer_channel or device_class is (other): events pooled
  because their own rows were too small. detail_level says which dimensions are
  known. Each event is in at most one row, so daily totals add up.
- bi_anonymous_coverage_v1: per website and day, the band of events each view
  releases (90-100, 50-90, 10-50 or 0-10 percent). Mention it when totals look low.
- bi_anonymous_goals_v1: one row per completed UTC day, website_token and
  goal_event, with event_count.
- bi_anonymous_geo_events_v1: one row per completed UTC day, website_token,
  event_name and geo_area, with event_count.
- bi_dim_* views: labels for codes. Left join them and fall back to the code.
Rules:
- Total with SUM(event_count), never COUNT(*).
- event_count counts events or goal occurrences, not people, visitors or visits.
- A missing row means the count was withheld or unavailable, not zero.
- Never join the views to each other; each is counted separately. Use the
  daily view for day-level questions, the roll-up for daily totals, and the
  hourly view for hour-level ones. (other) is a remainder, never a page,
  channel or device name.
- Event hours and event-view days use the installation reporting timezone,
  which is UTC unless reporting_timezone was changed. Goal and geography days
  are always UTC. The current UTC hour (events), the current reporting-timezone
  day (daily events) and the current UTC day (goals, geography) are not
  included yet. Do not treat a repeated local hour as two visits merged into
  one cell: one stored UTC hour is one cell.
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
