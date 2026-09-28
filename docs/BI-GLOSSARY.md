# BI glossary

The BI glossary publishes human-readable labels and definitions for reporting
codes and columns. It uses declared configuration and a built-in catalog, never
event values or counts. Connect Power BI or Tableau to its eight fixed views and
join them to the existing reporting code columns. Labels do not change the grain,
counts, suppression, or access restrictions of a fact view.

The glossary is deployment-wide. There are no page-path labels, content-grouping
rules, per-website dictionaries, or in-app analytics reports.

## Install and synchronize

Run migrations, then populate the glossary using the same active environment:

```bash
php bin/console doctrine:migrations:migrate --env=prod --no-interaction
php bin/console app:analytics:glossary:sync --env=prod
```

Migration `Version20260928000000` creates `analytics_glossary` and eight views.
They are empty until the first sync. Installation scripts, Makefile migration
targets, and the web installer run the sync after migrations. For manual SQL
installs, run the command after deploying configuration and applying any remaining
migrations. Sync uses only reads and transactional writes on `analytics_glossary`;
it does not need privileges to create or replace views. Label edits never change
view definitions. A view-column change requires a new versioned contract.

With no `bi_glossary` block, sync publishes English built-ins, every continent and
Intl country, configured goal labels, and saved custom-property descriptions.
Disabled goals remain in the glossary. Disable a retired goal instead of deleting
its definition, so historical reports keep its label.

```bash
php bin/console app:analytics:glossary:sync
php bin/console app:analytics:glossary:sync --dry-run
php bin/console app:analytics:glossary:sync --check
php bin/console app:analytics:glossary:sync --export > glossary.csv
php bin/console app:analytics:glossary:sync --export --missing-only > missing-translations.csv
```

| Mode | Effect |
| --- | --- |
| No option | Validate, resolve, compare, and synchronize all rows |
| `--dry-run` | Show numbers of rows to insert, change, and delete without writing |
| `--check` | Exit 0 when current, 1 when the database differs |
| `--export` | Write every resolved row as CSV to stdout without writing the database |
| `--export --missing-only` | Export rows whose label falls back to another locale or the code |

`--dry-run`, `--check`, and `--export` are mutually exclusive. Validation errors
exit 2 and identify fields such as `bi_glossary.values.device_class.tablett`.
`--missing-only` requires `--export`. CSV cells starting with `=`, `+`, `-`, or `@`
receive an apostrophe prefix so spreadsheet programs do not execute formulas.

An unchanged sync performs no writes and preserves `synced_at`. Changed contents
replace the complete table in one transaction; readers never see a partially
populated glossary. A deadlock, lock timeout, or competing replacement key
conflict is retried once before reporting failure. The summary includes rows written, dimensions, locales, and fallback
label counts per locale. These are metadata counts, not traffic counts.

Run sync after YAML edits. Changes to `config/goals.yaml` require clearing the
production cache first, then running sync in production. Successful
`app:analytics:views:regenerate` and its admin button also synchronize glossary
columns after deploying the saved model. **Manual sync uses the saved model's
column aliases; it does not inspect deployed view metadata.** Regenerate views
before manually syncing after a model change, or the glossary can describe saved
columns that are not yet deployed. Sync never runs on kernel boot, cache
warmup, ingestion, or a health check. Invalid glossary settings do not block
tracking, ingestion, or `/health` configuration validation.

## YAML reference

Place `bi_glossary` beside `custom_data_properties` in the active aggregate YAML:

```yaml
bi_glossary:
  default_locale: en
  locales: [en, es, fr-CA]
  values:
    referrer_channel:
      social:
        label: { es: 'Redes sociales' }
        group: { en: 'Organic', es: 'Orgánico' }
      search:
        group: { en: 'Organic', es: 'Orgánico' }
    event_name:
      newsletter_signup:
        label: { en: 'Newsletter sign-up', es: 'Suscripción al boletín' }
        description: { en: 'Sent after double opt-in confirmation.' }
        sort: 10
    # The code must already exist in config/goals.yaml:
    # goal_event:
    #   contact:
    #     label: { es: 'Solicitud de contacto' }
    #     description: 'Quote form submitted; excludes test submissions.'
    # The text alias must exist in custom_data_properties:
    # utm_medium:
    #   cpc: { label: 'Paid search' }
  columns:
    bi_anonymous_events_v1:
      event_count:
        label: { es: 'Eventos' }
```

The active environment file or `environments.<env>.bi_glossary` replaces the
**whole mapping**, just like `custom_data_properties`; it does not merge nested
translations. There is no uppercase environment-variable override. UI saves use
the same validated mapping and locked configuration writer, preserving unrelated
settings. They never edit `config/goals.yaml`.

| Setting | Validation |
| --- | --- |
| `default_locale` | Defaults to `en`; must be one of `locales` |
| `locales` | Defaults to `[en]`; at most 20 BCP 47 language tags with optional script and region, such as `en`, `fr-CA`, `zh-Hant`, `zh-Hant-TW`, or `es-419`; canonical case is saved |
| `label`, `group`, `description` | A string in the default locale, or a map from published locale to string; unpublished keys and underscores such as `es_MX` are errors |
| Text | Trimmed, non-empty, no control characters; labels/groups at most 191 bytes and descriptions at most 1,000 bytes |
| `sort` | Integer from 0 through 100,000; built-in enum order defaults to multiples of 10 |
| `values` | At most 2,000 declared entries, keyed by dimension then code |
| `columns` | At most 500 declared entries, keyed by known view then column; names are validated against code and the saved model, never used as SQL identifiers |

Only these dimensions are accepted:

| Dimension | Codes and default label source |
| --- | --- |
| `device_class` | `mobile`, `tablet`, `desktop`, `bot`, `unknown`; built-in catalog |
| `viewport_bucket` | `small`, `medium`, `large`, `unknown`; built-in catalog |
| `referrer_channel` | `direct`, `internal`, `search`, `social`, `email`, `referral`, `unknown`; built-in catalog |
| `geo_area` | Seven `continent:` codes and every `country:` code accepted by `GeoArea`; Intl supplies localized country names |
| `geo_level` | `country`, `continent`; built-in catalog |
| `privacy_mode` | `anonymous`, `enhanced`; built-in catalog; this adds no field to anonymous fact views |
| `goal_event` | Every code declared in `config/goals.yaml`, including disabled goals; that file's label is default-locale text |
| `event_name` | `view` is built in; other declared names must pass the server's safe-event-name validation |
| A modeled property's text column alias | Explicitly declared scalar codes, at most 191 bytes with no control characters or surrounding whitespace; numeric aliases are not value dimensions |

Core dimension names retain their enumerated meaning. Use a distinct text alias
for a modeled property with a different code list; changing a deployed alias
requires the existing reporting migration workflow.

SQL Server uses the database's default collation for the glossary so ordinary
joins remain compatible with existing reporting columns. Declared codes must be
distinct under that collation; a case-insensitive database cannot publish both
`cpc` and `CPC` in one dimension. An unresolvable key conflict leaves the previous
glossary intact and reports an error. Case-sensitive taxonomies require compatible
case-sensitive collations for the database and existing reporting columns;
coordinate any such changes through a reporting migration. MySQL and MariaDB use
binary glossary collation to preserve case-distinct codes.

Column entries cover the three `bi_anonymous_*` views, the eight glossary views,
and the three generated `analytics_custom_*` views. Custom text and numeric
column aliases use their property's description as default-locale text. Edit
that description in the Data model editor or `custom_data_properties`; glossary
translations supply other languages. Glossary column metadata does not authorize
routine BI access to the private custom views.

Goal definitions remain strict: only `label`, `enabled`, and `anonymous` belong in
`config/goals.yaml`. Put translations, descriptions, groups, and sort order under
`bi_glossary.values.goal_event`.

## Locale fallback

Resolution happens once at sync time, independently for label, group, and
description. For each published locale, try:

1. The requested locale, checking glossary overrides, source configuration, then
   the built-in catalog (including Intl country names).
2. Each shorter parent tag in that order: `zh-Hant-TW` → `zh-Hant` → `zh`.
3. The default locale, in the same source order. Goal labels and property
   descriptions count as text in this locale, regardless of their written language.
4. English, the catalog's base language.
5. For a label only, the code itself; unavailable groups and descriptions are null.

A YAML translation can only use a published locale key. To declare a `fr`
translation for `fr-CA` fallback, publish `fr` as well. Built-in parent catalog text
can supply fallback even when the parent is not published.

Every published locale gets the same entries, each with a nonblank label.
`label_locale` records the source language; it is null when the code supplies the
label. `description_locale` is recorded in the private table and CSV.
`is_fallback` is 1 when `label_locale` differs from `locale`, including null. Fields
resolve independently, so a Spanish label can have an English description.

For the example above, `social` is `Social` in `en`, `Redes sociales` in `es`, and
`Social` with `label_locale = en` and `is_fallback = 1` in `fr-CA`. Country names
come from Intl when it supports the locale: `country:DE` in `fr-CA` is `Allemagne`
with `label_locale = fr-CA`. Unsupported Intl locales do not receive a misleading
source-locale attribution. Continents sort first (10–70), then countries sort
alphabetically by their resolved label in each locale.

Contributors can add built-in text in `translations/bi_glossary.<locale>.yaml`
(domain `bi_glossary`). The catalog uses explicit definitions in each locale;
Symfony's translator fallback does not replace the resolver's chain. English
labels and descriptions are covered by completeness tests.

## Views and BI relationships

| View | Join key and content |
| --- | --- |
| `bi_dim_event_name_v1` | `event_name` plus prefixed label, group, description, and sort |
| `bi_dim_goal_event_v1` | `goal_event` plus prefixed label, group, description, and sort |
| `bi_dim_referrer_channel_v1` | `referrer_channel` plus prefixed label, group, description, and sort |
| `bi_dim_device_class_v1` | `device_class` plus prefixed label, group, description, and sort |
| `bi_dim_viewport_bucket_v1` | `viewport_bucket` plus prefixed label, group, description, and sort |
| `bi_dim_geo_area_v1` | `geo_area` plus prefixed label, group, description, sort, and `geo_level` |
| `bi_glossary_values_v1` | `dimension`, `code`, `locale`, `label`, `label_locale`, `is_fallback`, `group_label`, `description`, `sort_order`, `is_default_locale` |
| `bi_glossary_columns_v1` | `object_name`, `column_name`, `locale`, `label`, `label_locale`, `is_fallback`, `description`, `is_default_locale` |

The six dimension views have exactly one row per declared code, use
`default_locale`, and require no locale filter. For example,
`bi_dim_device_class_v1` provides `device_class`, `device_class_label`,
`device_class_group`, `device_class_description`, and `device_class_sort`.
Prefixed names avoid accidental relationships between generic label fields.

In Power BI, load the required dimension and fact views. Confirm a many-to-one,
single-direction relationship from `bi_anonymous_events_v1.device_class` to
`bi_dim_device_class_v1.device_class`; the equal names support relationship
auto-detection. Use `device_class_label` in the field list and sort it by
`device_class_sort`. Verify the detected relationship rather than connecting
label/group fields. In Tableau, relate the same views on `device_class` and use
the label as the displayed dimension. Keep the hourly, daily goal, and daily
geography facts separate: their different grains and independent suppression
cannot be safely joined to reconstruct additional detail.

For localized labels, filter `bi_glossary_values_v1` to **one dimension and one
locale before joining** `code` to the fact's matching column. Joining all locales
would multiply fact rows and counts. Custom-property values, `privacy_mode`, and
`geo_level` use this view; they have no dedicated `bi_dim_*` view. Parameterize the
locale in your connector and populate Power Query's allowed locale values from:

```sql
SELECT DISTINCT locale FROM bi_glossary_values_v1 ORDER BY locale;
```

Filtering to an unpublished locale such as `de` returns no rows. Use a published
locale or one of the default-locale dimension views. To display column help,
filter `bi_glossary_columns_v1` to one `object_name` and `locale`, then use its
`column_name`, `label`, and `description` in the BI tool's field documentation.

Always keep a left join and a code fallback. Undeclared event names and deleted
goals can still exist in retained history. Synthetic geographic pools
`country:other` and `continent:other` also have no ordinary `GeoArea` entry. An
inner join would drop their released fact rows. A native SQL query in Power Query
can use:

```sql
SELECT f.event_day, f.goal_event,
       COALESCE(d.goal_event_label, f.goal_event) AS goal_label,
       f.event_count
FROM bi_anonymous_goals_v1 f
LEFT JOIN bi_dim_goal_event_v1 d ON d.goal_event = f.goal_event;
```

For a Power Query merge, choose **Left Outer**, expand the label, and use
`if [goal_event_label] = null then [goal_event] else [goal_event_label]` in a
custom column (the M equivalent of `COALESCE(label, code)`). For a localized
Tableau relationship, use `IFNULL([label], [code])`, where `[code]` is the fact's
original code; for a default goal dimension use
`IFNULL([goal_event_label], [goal_event])`.

## Administration and publishing privacy

Administrators can open **Reporting → BI glossary** at
`/dashboard/data-model/glossary`, select locales, edit overrides, reset them, save,
and synchronize. Empty translation inputs show their resolved fallback. The page
can download the mapping as YAML and missing-label CSV. A save writes only
`bi_glossary`; if subsequent sync fails, the page warns that configuration saved
but the database needs `app:analytics:glossary:sync`. It is unavailable with
`dashboard_enabled: false`; YAML and CLI still provide the full configuration.

The explicit **Find unlabeled event names** action samples at most the latest
1,000 retained events for administrators. It shows names only, without counts;
adding one opens an unsaved row. Only saving that row declares the name for
publication. The resolver and sync never read events, archive tables, or reporting
facts. All countries and continents are published even when they have no traffic.

Treat all glossary text and codes as published metadata. Do not put personal or
identifying text in labels, groups, descriptions, event names, or custom codes.
Declaring codes for a property with `consent_required: true` publishes those codes
to every routine BI user, even though that property's event values are only
available through private data access. Publication does not change consent rules
or grant access to private facts.

The glossary contains no facts or counts and cannot reveal which declared values
occurred. Grouping visible fact cells by `group_label` still undercounts wherever
cells were withheld; a group does not restore suppressed counts. Thresholds count
events, not people, and neither grouping nor labeling proves legal anonymity.

## View-only grants

Grant the three approved fact views and only the glossary views a reporting
principal needs. Never grant routine BI users `analytics_glossary`, raw `events`,
archive tables, unsuppressed operational views, or `analytics_custom_*` views.
The following examples assume an existing dedicated reporting principal and the
appropriate database/schema. Review grants after migrations that replace views.

PostgreSQL (the view owner needs the underlying table permissions):

```sql
GRANT USAGE ON SCHEMA public TO bi_reader;
GRANT SELECT ON bi_anonymous_events_v1, bi_anonymous_goals_v1,
    bi_anonymous_geo_events_v1, bi_dim_event_name_v1, bi_dim_goal_event_v1,
    bi_dim_referrer_channel_v1, bi_dim_device_class_v1, bi_dim_viewport_bucket_v1,
    bi_dim_geo_area_v1, bi_glossary_values_v1, bi_glossary_columns_v1 TO bi_reader;
```

MySQL/MariaDB (replace `analytics` and the approved client host; the view definer
needs its underlying table permissions):

```sql
GRANT SELECT ON analytics.bi_anonymous_events_v1 TO 'bi_reader'@'bi-host';
GRANT SELECT ON analytics.bi_anonymous_goals_v1 TO 'bi_reader'@'bi-host';
GRANT SELECT ON analytics.bi_anonymous_geo_events_v1 TO 'bi_reader'@'bi-host';
GRANT SELECT ON analytics.bi_dim_event_name_v1 TO 'bi_reader'@'bi-host';
GRANT SELECT ON analytics.bi_dim_goal_event_v1 TO 'bi_reader'@'bi-host';
GRANT SELECT ON analytics.bi_dim_referrer_channel_v1 TO 'bi_reader'@'bi-host';
GRANT SELECT ON analytics.bi_dim_device_class_v1 TO 'bi_reader'@'bi-host';
GRANT SELECT ON analytics.bi_dim_viewport_bucket_v1 TO 'bi_reader'@'bi-host';
GRANT SELECT ON analytics.bi_dim_geo_area_v1 TO 'bi_reader'@'bi-host';
GRANT SELECT ON analytics.bi_glossary_values_v1 TO 'bi_reader'@'bi-host';
GRANT SELECT ON analytics.bi_glossary_columns_v1 TO 'bi_reader'@'bi-host';
```

SQL Server (use a common owner for the views and underlying table so the
ownership chain applies):

```sql
GRANT SELECT ON OBJECT::dbo.bi_anonymous_events_v1 TO bi_reader;
GRANT SELECT ON OBJECT::dbo.bi_anonymous_goals_v1 TO bi_reader;
GRANT SELECT ON OBJECT::dbo.bi_anonymous_geo_events_v1 TO bi_reader;
GRANT SELECT ON OBJECT::dbo.bi_dim_event_name_v1 TO bi_reader;
GRANT SELECT ON OBJECT::dbo.bi_dim_goal_event_v1 TO bi_reader;
GRANT SELECT ON OBJECT::dbo.bi_dim_referrer_channel_v1 TO bi_reader;
GRANT SELECT ON OBJECT::dbo.bi_dim_device_class_v1 TO bi_reader;
GRANT SELECT ON OBJECT::dbo.bi_dim_viewport_bucket_v1 TO bi_reader;
GRANT SELECT ON OBJECT::dbo.bi_dim_geo_area_v1 TO bi_reader;
GRANT SELECT ON OBJECT::dbo.bi_glossary_values_v1 TO bi_reader;
GRANT SELECT ON OBJECT::dbo.bi_glossary_columns_v1 TO bi_reader;
```

SQLite has no object grants. Giving a BI user a read-only database file still
exposes private source tables; use a trusted export of approved view results or a
server database with view-only permissions. A shared view exposes every website
represented in it; report filters are not tenant isolation.

Before release, execute migration, sync, and every view on the actual supported
PostgreSQL, MySQL, MariaDB, SQL Server, and SQLite versions, with a view-only role
where supported. Record versions and results in the PR. SQL assertions and
in-memory SQLite tests do not establish compatibility on the other engines.
Power BI and Tableau relationship checks likewise require those applications.
See [database setup](DATABASE.md), [the data model](DATA-MODEL.md), and
[privacy and suppression](PRIVACY-COMPLIANCE.md#bi-exposure-and-suppression).
