# Reporting API v1: design

**Status:** draft for review, implementation in progress on
`feature/reporting-api-v1`. Items under [later phases](#later-phases) are
proposals, not delivered features. Once v1 ships, the reference for users lives
in [Reporting API](../REPORTING-API.md) and the decision is recorded in
[design decisions](../DESIGN-DECISIONS.md#a-read-only-http-api-over-the-same-views).

## Summary

Add a read-only HTTP API that returns rows from the reporting views that BI
tools already read. Each view is a resource under `/api/v1/views/{view}`.
Query parameters filter, sort, select columns and page through rows. Callers
authenticate with a bearer token that an administrator creates in the dashboard
or with a console command. Tokens are stored hashed in a YAML file under
`config/secrets`. The API sits behind a new feature flag that starts off.

The API adds a transport, not a new data surface. It reads the same view
catalog as BigQuery sync and the reporting database sync, with the same rule:
approved views by default, each private view only when it is listed
separately, raw tables never.

## Goals

- Let scripts, notebooks, low-code tools and AI assistants read reporting
  views over HTTPS, without a database account or a network path to the
  database.
- Manage tokens both headlessly (CLI and YAML) and in the dashboard, through
  one service.
- Keep the [reporting boundary](../BI-CONNECTION.md#the-reporting-boundary):
  suppressed counts and labels by default, private views only on explicit
  opt-in per token.
- Publish a machine-readable description (OpenAPI) generated from the same
  catalog, so it cannot drift from what the API actually serves.
- Work the same on all five database engines.

## Non-goals

- **No writes.** Every endpoint is `GET` (and `HEAD`).
- **No aggregation, joins or report building.** Callers cannot group, sum or
  join views on the server. Re-aggregating released cells does not re-apply
  suppression, and joining views can produce wrong totals or help undo
  withheld detail (see [keep the three views separate](../BI-CONNECTION.md)).
  Aggregation happens in the caller's tool, as it does for SQL users.
- **No raw tables** (`events`, archive tables), ever.
- **No browser access.** No CORS headers. Tokens are for servers and tools,
  not for JavaScript on a web page.
- **No real-time data.** The views already release only completed hours and
  days.

## Endpoints

| Method and path | Returns |
| --- | --- |
| `GET /api/v1/views` | The views this token may read, with descriptions and links |
| `GET /api/v1/views/{view}` | Rows of one view, filtered, sorted and paged |
| `GET /api/v1/views/{view}/schema` | The view's columns: name, type, description, time zone, allowed filter operators |
| `GET /api/v1/openapi.json` | An OpenAPI 3.1 document describing the endpoints and the views this token may read |

`{view}` is the database view name, version suffix included:
`bi_anonymous_events_v1`, `bi_dim_device_class_v1`,
`analytics_custom_events_v1`.

### Why the view is in the path, not a query parameter

A view is the resource being read, and each one has its own columns, types
and filters. Putting it in the path:

- gives every view its own URL, schema and OpenAPI entry, so tools and
  assistants discover columns per view;
- makes authorization a lookup on the path (`may this token read this view?`)
  before any query parameter is parsed;
- keeps the existing version contract: `bi_anonymous_events_v2`, when it
  exists, is a new resource next to `_v1`. The `/v1` in the URL versions the
  API's own shape (parameters, envelope, errors), not the views.

`/api/v1/rows?view=...` was considered and rejected: one endpoint with a
schema that changes with a parameter is harder to document, cache, log and
authorize.

The name is never inserted into SQL as given. It must be a key of the
`ViewCatalog` allowlist, and it is quoted as an identifier by the database
platform.

## Query parameters

| Parameter | Example | Meaning |
| --- | --- | --- |
| `filter[col]` | `filter[device_class]=mobile` | Column equals value |
| `filter[col][op]` | `filter[event_day][gte]=2026-10-01` | Column compared with value |
| `fields` | `fields=event_day,page_group,event_count` | Columns to return, in this order (default: all) |
| `sort` | `sort=-event_day,page_group` | Sort columns; `-` for descending |
| `limit` | `limit=1000` | Rows per page, 1 to 10,000 (default 100) |
| `offset` | `offset=1000` | Rows to skip |

Any other parameter is a `400` error, so a typo such as `filters[...]` fails
loudly instead of returning unfiltered data. A token passed in the query string
(`token`, `access_token`, `api_key`) is refused with a message pointing to the
`Authorization` header, because query strings end up in server and proxy logs.

### Filter operators

Several operators on one column, and filters on several columns, are combined
with AND. There is no OR in v1; `in` covers the common case.

| Operator | Meaning | Column types |
| --- | --- | --- |
| `eq` (default) | equals | all |
| `ne` | not equal (rows where the column is null are excluded) | all |
| `gt`, `gte`, `lt`, `lte` | ranges | integer, number, date, timestamp |
| `in` | one of a comma-separated list (or repeated `[]` parameters), at most 100 values | string, integer, date |
| `starts_with` | text prefix, matched literally (`%` and `_` have no special meaning) | string |
| `null` | `true`: is null; `false`: is not null | all |

Values are validated against the column's type before the query runs: an
integer column only accepts an integer, a date column only `YYYY-MM-DD`. Every
value is a bound parameter.

Range operators are not offered on text: comparing text depends on each
engine's collation, so results would differ between installations.

### Time columns

`event_hour` and the event views' `event_day` are labelled in the
installation's [reporting timezone](../BI-CONNECTION.md#reporting-timezone)
and carry no offset; goal and geography days, and the custom views'
timestamps, are UTC. The API returns these values as the view labels them
(`2026-10-01T13:00:00`, no offset) and states the time zone of each time
column in the schema and in `meta.reporting_timezone`. Filter values use the
same form. A filter value with an offset (`Z`, `-05:00`) is rejected rather
than converted, because converting it could silently select different hours
than the caller meant, most visibly around daylight-saving changes.

### Ordering and paging

Paging uses `limit` and `offset`, and the response says whether more rows
exist (`meta.has_more`, found by reading one row beyond the limit) and gives a
`links.next` URL. There is no total count by default: counting means running
the view's query a second time.

Offset paging is only stable with a deterministic order, and the views have
no key column. Every query therefore ends with a default order that makes rows
unique:

- the custom views by `id`;
- every other view by all of its columns in view order (each released cell is
  unique on its dimensions).

A caller's `sort` comes first and the default order breaks ties. Keyset
(cursor) paging was considered; it needs a unique key that most views do not
have, so it is left for a later version if large views make offsets slow.

There is no default date window. A window would silently hide rows from a
caller who did not ask for one; the limit already bounds every response. The
documentation recommends filtering on the time column for large views.

## Responses

Rows are JSON objects keyed by column name:

```json
{
  "data": [
    {
      "website_token": "8f6c2d0b9a3e4f1c7d5b2a90",
      "event_day": "2026-10-01",
      "event_name": "view",
      "page_group": "/blog",
      "referrer_channel": "search",
      "device_class": "mobile",
      "event_count": 42
    }
  ],
  "meta": {
    "view": "bi_anonymous_daily_v1",
    "approved": true,
    "limit": 100,
    "offset": 0,
    "returned": 1,
    "has_more": false,
    "reporting_timezone": "America/Chicago"
  },
  "links": {
    "self": "https://analytics.example.com/api/v1/views/bi_anonymous_daily_v1?limit=100",
    "schema": "https://analytics.example.com/api/v1/views/bi_anonymous_daily_v1/schema",
    "next": null
  }
}
```

Values follow the catalog type of each column (`ViewCatalog::columnType`),
the same types BigQuery and the reporting database receive:

| Catalog type | API type | JSON value |
| --- | --- | --- |
| `STRING` | `string` | string |
| `INT64` | `integer` | number |
| `FLOAT64` | `number` | number |
| `BOOL` | `boolean` | `true` / `false` |
| `DATE` | `date` | `"YYYY-MM-DD"` |
| `TIMESTAMP` | `timestamp` | `"YYYY-MM-DDTHH:MM:SS"`, no offset (see [time columns](#time-columns)) |

Responses carry `Cache-Control: private, no-store`: they depend on the token
and must not be kept by a shared cache.

### Errors

Errors use [RFC 9457 problem details](https://www.rfc-editor.org/rfc/rfc9457)
(`application/problem+json`) with a stable `code` that callers can branch on:

```json
{
  "type": "https://github.com/Subschema-LLC/aggregate/blob/master/docs/REPORTING-API.md#errors",
  "title": "Unknown column",
  "status": 400,
  "code": "unknown_column",
  "detail": "bi_anonymous_daily_v1 has no column named event_hour."
}
```

| Status | Codes |
| --- | --- |
| 400 | `unknown_parameter`, `invalid_parameter`, `unknown_column`, `unsupported_operator`, `invalid_filter_value`, `token_in_query` |
| 401 | `missing_token`, `invalid_token` (unknown, revoked or expired; the response does not say which) |
| 403 | `view_not_permitted` |
| 404 | `unknown_view`, `not_found` |
| 429 | `rate_limited`, with `Retry-After` |
| 503 | `view_unavailable` (the view does not exist in this database, for example geography is off), `configuration_invalid` |

Messages name parameters, views and columns, never a value from the data.
While the feature flag is off, every `/api/v1` path returns a plain `404`, so
a disabled installation does not reveal that the API exists.

## Why not OData (or GraphQL)

OData's draw is that Power BI and Excel can read an OData feed directly. Two
of its core features work against Aggregate's privacy model:

- `$expand` follows relationships between entities, which here means joining
  views: the documentation tells BI users not to do this, because it gives
  wrong totals and can help undo withheld detail.
- `$apply` (group by, aggregate) re-aggregates released cells on the server
  without re-applying suppression, and presents the result as if it were a
  released figure.

A useful OData subset would leave out exactly those features, and the rest
(`$filter`'s expression grammar, `$metadata` CSDL, `$batch`) is a large grammar
to implement and test across five engines, with little maintained PHP support.
Power BI and Excel can still read this API through their Web connector, and
BI tools that want SQL already connect to the views directly.

GraphQL was rejected for the same reasons: its value is client-chosen joins
and nested selections, which are what the API must not offer.

The `filter[col][op]` style is a small, explicit subset that is easy to
validate per column type, generate documentation for, and extend later.
Nothing prevents adding an OData-compatible endpoint later for a concrete
need; it would map onto the same validated query object.

## Tokens

### Format and storage

A token looks like `agg_3f9a1c2e_<43 random characters>`:

- `agg_` makes leaked tokens easy to recognize and to add to secret scanners;
- `3f9a1c2e` is the token's public ID: it appears in the dashboard, the CLI,
  the audit trail and logs, so a token can be identified and revoked without
  its secret;
- the rest is 32 random bytes, base64url-encoded.

The full token is shown once, when it is created. Only its SHA-256 hash is
stored. A slow password hash is unnecessary for 256-bit random secrets and
would cost time on every request; a plain hash is what GitHub and most API
providers use for this. Comparison is constant-time.

Tokens are kept in `config/secrets/api-tokens.yaml`, written atomically with
owner-only permissions under a file lock. `config/secrets/**` is already an
operator path that updates never replace, and the file is git-ignored:

```yaml
# Reporting API tokens. Written by app:api:token:create and the dashboard.
# Only hashes are stored; a lost token cannot be recovered, only replaced.
tokens:
  3f9a1c2e:
    name: 'Power BI refresh'
    hash: 'sha256:9b74c9897bac770ffc029102a200c5de...'
    views: ~                 # ~ = every approved view, including future ones
    private_views: []        # row-level or unsuppressed views, each listed explicitly
    created_at: '2026-10-18T22:30:00+00:00'
    created_by: 'scott'
    expires_at: '2027-10-18T22:30:00+00:00'
```

A YAML file rather than a database table, because that is where Aggregate
keeps operator configuration, it works with the dashboard disabled, and a
deployment can provision the same file on every replica. The trade-off is that
the file is read on each API request; it is small, parsed through
`ParsedYamlCache`, and checked once per request.

### Scope

- `views: ~` (the default) allows every approved view, including approved
  views added by later releases. A list allows only those views.
- `private_views` lists row-level or unsuppressed views (`analytics_custom_*`,
  `analytics_archived_*`) one by one, mirroring `bigquery_private_views`. A
  private view is accepted only from this list, so it is never granted by
  accident, and creating such a token warns that it exposes individual rows.
- Tokens grant read access to views only. There are no admin or write scopes.

### Expiry and revocation

Tokens expire after one year by default; `--expires=90d`, a date, or `never`
can be chosen. Revoking deletes the entry. An expired or revoked token gets
`401 invalid_token`.

Last-used times are not recorded in v1: writing the YAML file on every request
would turn reads into locked writes. See [later phases](#later-phases).

### Commands

```bash
php bin/console app:api:token:create "Power BI refresh"
php bin/console app:api:token:create "Notebook" --view=bi_anonymous_daily_v1 --view=bi_dim_device_class_v1 --expires=90d
php bin/console app:api:token:create "Raw export" --private-view=analytics_custom_events_v1
php bin/console app:api:token:list
php bin/console app:api:token:revoke 3f9a1c2e
```

`create` prints the token once, with an example request. `--json` prints it as
JSON for scripts. Creating a token needs the feature flag on; listing and
revoking always work, so a token can be revoked even after the API is turned
off.

### Dashboard

**Reporting → API tokens** (`/dashboard/api-tokens`, shown in the menu only
when the feature flag is on): a form for name, views, private views and expiry,
and a list of tokens with their ID, scope, creation, expiry and a revoke
button. The page is administrator-only (`DashboardPages::ADMIN`), like Users
and Data transfer: a token grants data access outside the dashboard's own
permissions, so issuing one is closer to creating an account than to editing a
setting. The new token is shown once on the page that the create
form returns, with a copy button, and is never written to the session or a
flash message.

### Audit trail

Creating and revoking a token, from either the dashboard or the CLI, are
administrator actions in the `integrations` area (`api_token_created`,
`api_token_revoked`), with the token ID as the subject. API requests themselves
are not audit entries; they are ordinary reads.

## Feature flag and headless use

A new flag, `reporting_api`, starts off and hidden, as every new flag does. It
is the API's only switch: with it off, `/api/v1` returns 404 and the dashboard
page is unavailable; with it on, the API answers requests that carry a valid
token. There is no separate `reporting_api_enabled` setting, because having no
tokens already means nothing can read.

Everything works with the dashboard disabled: enable the flag in YAML, create
tokens with the CLI.

## Rate limiting

Each token is limited to 120 requests per minute (fixed window), using Symfony
RateLimiter with the application cache. Over the limit, the API answers
`429 rate_limited` with `Retry-After`. Requests without a valid token are not
counted per token; the existing web server or proxy limits apply to them.

## Documentation: OpenAPI, not Swagger UI

**Generate an OpenAPI 3.1 document from the view catalog, serve it at
`/api/v1/openapi.json`, and write the guide in the repository Markdown. Do not
bundle Swagger UI or an API documentation framework.**

- **Generated, so it cannot drift.** The document is built at request time from
  `ViewCatalog`, the views' actual columns (including custom columns from the
  data model) and the glossary descriptions. A hand-written spec, or one
  derived from controller annotations, would not know a view's columns.
- **Scoped to the token.** It lists only the views the caller may read, so a
  private view's column names are not published to every token holder.
- **Works with the tools people already use.** Postman, Insomnia, Bruno, Swagger
  Editor, Scalar, client generators, and AI tools that accept OpenAPI (custom
  GPT actions, MCP gateways) import it directly.
- **The repository Markdown is the only documentation** (see
  [one documentation source](../DESIGN-DECISIONS.md#one-documentation-source-linked-from-the-app)).
  `docs/REPORTING-API.md` explains tokens, parameters, errors and examples, and
  the dashboard links to it through `DocumentationLinks`.

Swagger UI was rejected for the installation itself: it is a sizeable
JavaScript bundle that would have to be vendored (no CDN, no build step on the
server), it would be another public page or another dashboard page to secure,
and its "Try it out" invites pasting tokens into a web page. NelmioApiDocBundle
and API Platform were rejected because they are large dependencies built around
entity or annotation-driven schemas, which do not fit views whose columns come
from configuration. If a rendered reference becomes worthwhile, a small static
page that renders the same generated document is the path, not a framework.

## Implementation map

| Piece | Responsibility |
| --- | --- |
| `Service/ReportingApi/ApiTokenStore` | Read, create and revoke tokens in `config/secrets/api-tokens.yaml`; validation shared by CLI and dashboard |
| `Service/ReportingApi/ApiToken` | One token's ID, name, scope, timestamps; `mayRead(view)` |
| `Service/ReportingApi/ApiAuthenticator` | Bearer header to token; constant-time hash comparison; expiry |
| `Service/ReportingApi/ViewQuery` | Parses and validates `filter`, `fields`, `sort`, `limit`, `offset` against a view's columns |
| `Service/ReportingApi/ViewQueryRunner` | Builds and runs the portable `SELECT` with bound values and platform limit syntax; converts values by catalog type |
| `Service/ReportingApi/ViewSchema` | Columns, types, descriptions and time zones of a view |
| `Service/ReportingApi/OpenApiDocument` | The OpenAPI document for a token |
| `Service/ReportingApi/ApiProblem` | Problem details exceptions and responses |
| `Controller/ReportingApiController` | The four endpoints |
| `Controller/ApiTokensController` + `templates/api_tokens/` | Dashboard page |
| `Command/ApiToken{Create,List,Revoke}Command` | CLI |
| `EventSubscriber/ReportingApiExceptionSubscriber` | Problem details for unmatched `/api/v1` routes and unexpected errors |

It reuses `ViewCatalog` for the allowlist and column types, `ViewReader`'s
column discovery, `BuiltinGlossaryCatalog` for descriptions,
`ReportingTimezone` for time labels, `FeatureFlags`, `AdminActions` and
`ConfigurationChanges` for the audit trail.

## Later phases

Proposals, in rough priority order; none are part of v1.

1. **A read-only database account for API queries.** An optional
   `REPORTING_API_DATABASE_URL` naming an account that can only `SELECT` the
   approved views (and any private views tokens may read), so database grants
   still protect the data if the application's allowlist had a bug. Not
   possible on SQLite.
2. **CSV and newline-delimited JSON** (`Accept: text/csv`), streamed, for
   spreadsheet users and bulk pulls.
3. **Conditional requests** (`ETag` / `If-None-Match`) so scheduled refreshes
   skip unchanged pages.
4. **Last-used time per token**, kept in `var/` rather than the YAML file.
5. **A configurable rate limit**, in YAML and on the API tokens page.
6. **An MCP server** over the API for AI assistants, as the
   [roadmap](../../ROADMAP.md#proposals-to-explore) proposes, with the same
   token scopes.
7. **Keyset paging** for the custom views, which have a unique `id`.

## Open questions

1. **Private views over HTTP.** v1 allows them per token, mirroring BigQuery
   sync. Should v1 offer approved views only, and add private views later?
2. **Default expiry.** One year, or require an explicit choice?
3. **Who manages tokens.** Administrators only (proposed, see
   [dashboard](#dashboard)), or also a grantable page so standard users can
   issue approved-view tokens?
4. **Maximum page size.** 10,000 rows keeps a response to a few megabytes. Is a
   larger limit needed for Power BI refreshes?
5. **OpenAPI without a token.** Should `openapi.json` also be available without
   a token, listing approved views only, so tools can be configured before a
   token is issued?
