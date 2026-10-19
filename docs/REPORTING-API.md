# Reporting API

[Connect BI tools and AI assistants](BI-CONNECTION.md) · [Privacy guide](PRIVACY-COMPLIANCE.md#reporting-api) · [Feature flags](FEATURE-FLAGS.md)

The reporting API is a read-only HTTP interface to the same reporting views BI
tools read from the database. Scripts, notebooks, low-code tools and AI
assistants can fetch rows over HTTPS with a token, without a database account
or a network path to the database. It adds no new data: approved views are
served by default, private views only where an administrator allows them, and
raw tables never.

- [Turn it on](#turn-it-on)
- [Tokens](#tokens)
- [Endpoints](#endpoints)
- [Reading rows](#reading-rows)
- [Responses](#responses)
- [Time columns](#time-columns)
- [Errors](#errors)
- [Private views](#private-views)
- [Rate limits](#rate-limits)
- [Settings](#settings)
- [OpenAPI document](#openapi-document)
- [Examples](#examples)

## Turn it on

The API is behind the `reporting_api` [feature flag](FEATURE-FLAGS.md), off and
hidden by default. While it is off, every `/api/v1` address is an ordinary 404
and no token works. Turn it on under **Administration → Feature flags**, or in
the active YAML:

```yaml
feature_flags:
  reporting_api:
    enabled: true
    hide_from_navigation: false
```

Then create a token. Serve the installation over HTTPS only: the token travels
with every request.

## Tokens

Every request carries a token in the `Authorization` header:

```text
Authorization: Bearer agg_3f9a1c2e_k3Jx…
```

A token is `agg_`, an eight-character public ID, and a secret. The ID appears in
lists, the audit trail and logs, so a token can be recognized and revoked
without its secret. The full token is shown once, when it is created; only its
SHA-256 hash is stored, in `config/secrets/api-tokens.yaml`. A lost token can be
revoked and replaced, never shown again.

A token sent in the address (`?token=`, `?access_token=`, `?api_key=` and
similar) is refused, because addresses are written to server and proxy logs.
Revoke any token that was sent that way.

### Create, list and revoke tokens

Administrators manage tokens under **Reporting → API tokens**
(`/dashboard/api-tokens`). The menu entry appears while the feature flag is on.
The page is for administrators only: a token grants data access outside the
dashboard's own [permissions](USERS-AND-PERMISSIONS.md), so issuing one is
treated like creating an account.

Without the dashboard, use the commands:

```bash
php bin/console app:api:token:create "Power BI refresh"
php bin/console app:api:token:create "Notebook" --view=bi_anonymous_daily_v1 --view=bi_dim_device_class_v1 --expires=90d
php bin/console app:api:token:create "Script" --expires=2027-06-30 --json
php bin/console app:api:token:list
php bin/console app:api:token:revoke 3f9a1c2e
```

Each token has:

- **A name**, unique, saying what it is for.
- **Approved views**: every approved view (the default, including approved views
  later releases add), or a list.
- **Private views**: none by default. See [private views](#private-views).
- **An expiry**: one year by default. Any duration (`30d`, `12w`, `6m`, `5y`),
  any future date, or `never` can be chosen; there is no upper limit. A token
  that never expires keeps working if it leaks, until someone revokes it, so the
  dashboard and the command warn when one is created.

Creating a token needs the feature flag on. Listing and revoking always work,
so a token can be revoked before the API is turned back on. Creating and
revoking tokens are recorded in the [audit trail](AUDIT-TRAIL.md) under
**Integrations**, with the token's ID; API requests themselves are not.

To provision the same tokens on several servers, copy
`config/secrets/api-tokens.yaml`; it holds hashes only:

```yaml
tokens:
  3f9a1c2e:
    name: 'Power BI refresh'
    hash: 'sha256:9b74c9897bac770ffc029102a200c5de…'
    views: ~                 # every approved view; or a list
    private_views: []
    created_at: '2026-10-18T22:30:00+00:00'
    created_by: scott
    expires_at: '2027-10-18T22:30:00+00:00'   # ~ for never
```

An invalid file authenticates nothing until it is corrected, and nothing is
saved over it.

## Endpoints

| Method and path | Returns |
| --- | --- |
| `GET /api/v1/views` | The views this token may read, with descriptions and links |
| `GET /api/v1/views/{view}` | A page of rows from one view |
| `GET /api/v1/views/{view}/schema` | The view's columns: name, type, description, time zone and filter operators |
| `GET /api/v1/openapi.json` | An [OpenAPI 3.1 document](#openapi-document) for this token |

`{view}` is the view's database name, version included:
`bi_anonymous_events_v1`, `bi_dim_device_class_v1`. The views and their columns
are described in [Connect BI tools and AI assistants](BI-CONNECTION.md#approved-views).
A later `_v2` view is a new address next to `_v1`; `/v1` in the path versions
the API's own parameters and responses.

## Reading rows

```text
GET /api/v1/views/bi_anonymous_daily_v1?filter[event_day][gte]=2026-10-01&filter[device_class]=mobile&fields=event_day,page_group,event_count&sort=-event_day&limit=1000
```

| Parameter | Example | Meaning |
| --- | --- | --- |
| `filter[col]` | `filter[device_class]=mobile` | The column equals the value |
| `filter[col][op]` | `filter[event_day][gte]=2026-10-01` | The column compared with the value |
| `fields` | `fields=event_day,event_count` | Columns to return, in this order (default: all) |
| `sort` | `sort=-event_day,page_group` | Sort columns; `-` sorts descending |
| `limit` | `limit=1000` | Rows per page: 100 by default, at most the [maximum page size](#settings) (10,000 unless changed) |
| `offset` | `offset=1000` | Rows to skip |

Any other parameter is an error, so a mistyped `filters[...]` fails instead of
returning unfiltered rows. URL-encode values: `+` means a space in a query
string. With curl, add `-g` (`--globoff`) so the brackets are sent as written.

### Filter operators

Filters are combined with AND; several operators on one column make a range.

| Operator | Meaning | Column types |
| --- | --- | --- |
| `eq` (default) | equals | all |
| `ne` | does not equal; rows where the column is empty are left out too | all |
| `gt`, `gte`, `lt`, `lte` | greater or less than | integer, number, date, timestamp |
| `in` | one of up to 100 comma-separated values, or repeated `filter[col][in][]=` parameters | string, integer, date, timestamp |
| `starts_with` | text that starts with the value; `%` and `_` are ordinary characters | string |
| `null` | `true`: the column is empty; `false`: it has a value | all |

Each value is checked against the column's type before the query runs: whole
numbers for integers, `YYYY-MM-DD` for dates, `true` or `false` for booleans.
The schema endpoint lists each column's operators.

Text comparisons, including `eq` and `starts_with`, follow the database's
collation: case-insensitive on MySQL, MariaDB and SQL Server by default,
case-sensitive on PostgreSQL and SQLite. Ranges are not offered on text for the
same reason.

### Paging

Every response says whether more rows exist (`meta.has_more`) and gives the
next page's address in `links.next`; follow it until it is `null`. Rows are
always fully ordered, by your `sort` and then by columns that make each row
unique, so pages do not overlap or skip rows while the data stays the same.
The released views change once an hour, as hours complete; page through a view
within one refresh, or filter on its time column, for a consistent copy.

There is no total count, and no default date range. For large views, filter on
the time column rather than paging through everything.

## Responses

```json
{
  "data": [
    {"event_day": "2026-10-01", "page_group": "/blog", "event_count": 42}
  ],
  "meta": {
    "view": "bi_anonymous_daily_v1",
    "approved": true,
    "limit": 1000,
    "offset": 0,
    "returned": 1,
    "has_more": false,
    "reporting_timezone": "America/Chicago"
  },
  "links": {
    "self": "https://analytics.example.com/api/v1/views/bi_anonymous_daily_v1?…",
    "schema": "https://analytics.example.com/api/v1/views/bi_anonymous_daily_v1/schema",
    "next": null
  }
}
```

| Column type | JSON value |
| --- | --- |
| `string` | text |
| `integer` | number |
| `number` | number |
| `boolean` | `true` or `false` |
| `date` | `"YYYY-MM-DD"` |
| `timestamp` | `"YYYY-MM-DDTHH:MM:SS"`, without an offset; see [time columns](#time-columns) |

Empty values are `null`. The types are the ones BigQuery and the reporting
database receive for the same columns.

Rows are streamed as the database returns them, so a large page costs the
server no extra memory. A response that ends before its closing `}` was
interrupted; discard it and request the page again.

Responses carry `Cache-Control: private, no-store`.

## Time columns

`event_hour` and the event views' `event_day` are labelled in the installation's
[reporting timezone](BI-CONNECTION.md#reporting-timezone); goal and geography
days, archives and the private views' stored times are UTC. The schema states
each time column's zone, and every page repeats the reporting timezone in
`meta.reporting_timezone`.

Times are returned exactly as the view labels them, without an offset, and
filters use the same form (`2026-10-01`, `2026-10-01T13:00` or
`2026-10-01T13:00:00`). A filter value with an offset (`Z`, `-05:00`) is
refused rather than converted: converting it could select different hours
than you meant, most visibly around daylight-saving changes.

## Errors

Errors are [RFC 9457 problem details](https://www.rfc-editor.org/rfc/rfc9457)
(`application/problem+json`) with a stable `code`:

```json
{
  "type": "https://subschema-llc.github.io/aggregate/reporting/reporting-api#errors",
  "title": "Unknown column",
  "status": 400,
  "code": "unknown_column",
  "detail": "bi_anonymous_daily_v1 has no column named event_hour."
}
```

| Status | Code | Meaning |
| --- | --- | --- |
| 400 | `unknown_parameter` | A query parameter the endpoint does not take |
| 400 | `invalid_parameter` | A malformed `fields`, `sort`, `limit`, `offset` or filter |
| 400 | `unknown_column` | The view has no such column |
| 400 | `unsupported_operator` | The column does not accept that filter operator |
| 400 | `invalid_filter_value` | The value does not fit the column's type |
| 400 | `token_in_query` | A token was sent in the address; use the header and revoke it |
| 401 | `missing_token` | No `Authorization: Bearer` header |
| 401 | `invalid_token` | The token is mistyped, revoked or expired; the response does not say which |
| 403 | `view_not_permitted` | The token may not read this view |
| 403 | `private_views_disabled` | A private view, while private views are turned off |
| 404 | `unknown_view` | Not a view the API serves |
| 404 | `not_found` | Nothing at this address |
| 405 | `method_not_allowed` | The API is read-only |
| 429 | `rate_limited` | Too many requests; see `Retry-After` |
| 503 | `view_unavailable` | The view does not exist in this database, for example geography is off or the custom views have not been generated |
| 503 | `configuration_invalid` | The token file or API settings need correcting by an administrator |
| 500 | `internal_error` | An unexpected failure, logged on the server |

Messages name parameters, views and columns, never a value from the data or a
token.

## Private views

Private views hold individual retained events (`analytics_custom_*`) or
unsuppressed counts (`analytics_archived_*`). They are not part of the routine
BI contract, and serving them over HTTP widens who can reach row-level data.

They are turned off for the API by default. While
`reporting_api_private_views` is off, no token can be given a private view, and
private views already granted are not served (`403 private_views_disabled`);
they work again when it is turned back on. With it on, each private view must
still be granted to each token one by one, and the dashboard asks for
confirmation.

Before turning them on, consider what a token that can read them means:

- **A token is a bearer credential.** Anyone holding it, from a laptop, a
  notebook shared with a colleague or a script in a repository, can download
  every row the view holds. Treat a token with private views like a database
  password for the raw events table.
- **Suppression does not apply.** These views have no minimum counts.
  Enhanced rows can carry visitor and session identifiers, and even anonymous
  rows can be personal data in context.
- **Copies leave Aggregate's retention and deletion.** Rows a client downloads
  stay wherever it keeps them. Deleting raw events in Aggregate does not delete
  those copies, so your records of processing, data processing terms and access
  requests need to cover the client too.
- **Use short expiries and narrow scopes.** Grant only the private views a
  workflow needs, with an expiry, and revoke tokens when the workflow ends.
- **Prefer a database grant where it fits.** A BI tool that needs raw rows can
  use a database account limited to those views, which the database enforces
  and audits separately; see [the reporting boundary](BI-CONNECTION.md#the-reporting-boundary).

See also the [privacy guide](PRIVACY-COMPLIANCE.md#reporting-api).

## Rate limits

Each token can make 120 requests per minute. Beyond that the API answers
`429 rate_limited` with `Retry-After` (seconds) and `X-RateLimit-Limit`.
Requests without a valid token are not counted per token; limit them at the web
server or proxy as for any public address.

## Settings

The API's settings are on the API tokens page, under **Settings**, and in the
active YAML. An uppercase environment variable of the same name takes
precedence and locks the dashboard field.

| Setting | Default | Meaning |
| --- | --- | --- |
| `reporting_api_private_views` | `false` | Whether tokens may be granted, and may read, [private views](#private-views) |
| `reporting_api_max_page_size` | `10000` | The largest `limit` a request may ask for, from 1 to 1,000,000. Responses are streamed; larger pages take longer to query and download |

```yaml
reporting_api_private_views: false
reporting_api_max_page_size: 10000
```

The feature flag decides whether the API exists at all. There is no separate
on/off setting: with no tokens, nothing can read.

## OpenAPI document

`GET /api/v1/openapi.json` returns an OpenAPI 3.1 document built from the views
this token may read, with each view's columns, types, descriptions and filter
parameters. It needs a token like every other endpoint, and lists only that
token's views, so a private view's columns are not shown to every token holder.

Import it into Postman, Insomnia, Bruno, Swagger Editor or Scalar, generate a
client from it, or give it to an AI tool that accepts OpenAPI. Aggregate does
not bundle an interactive documentation page.

## Examples

**curl:**

```bash
export AGGREGATE_API_TOKEN='agg_…'
curl -g -H "Authorization: Bearer $AGGREGATE_API_TOKEN" \
  "https://analytics.example.com/api/v1/views/bi_anonymous_daily_v1?filter[event_day][gte]=2026-10-01&limit=1000"
```

**Python**, reading every page:

```python
import os, requests

url = "https://analytics.example.com/api/v1/views/bi_anonymous_daily_v1"
params = {"filter[event_day][gte]": "2026-10-01", "limit": 5000}
headers = {"Authorization": f"Bearer {os.environ['AGGREGATE_API_TOKEN']}"}
rows = []
while url:
    page = requests.get(url, params=params, headers=headers, timeout=60)
    page.raise_for_status()
    body = page.json()
    rows += body["data"]
    url, params = body["links"]["next"], None
```

**Power BI or Excel (Power Query)**, with the token in a parameter named
`ApiToken`. The fixed base address and `RelativePath` keep the query refreshable
in the Power BI service:

```text
let
    Page = (offset as number) => Json.Document(Web.Contents(
        "https://analytics.example.com",
        [
            RelativePath = "api/v1/views/bi_anonymous_daily_v1",
            Query = [limit = "10000", offset = Number.ToText(offset)],
            Headers = [Authorization = "Bearer " & ApiToken]
        ])),
    Pages = List.Generate(
        () => [offset = 0, page = Page(0)],
        each [page] <> null,
        each if [page][meta][has_more] then [offset = [offset] + 10000, page = Page([offset] + 10000)] else [offset = [offset], page = null],
        each [page][data]),
    Rows = Table.FromRecords(List.Combine(Pages))
in
    Rows
```

Choose **Anonymous** as the data source's credentials: the token travels in the
header. Power BI can also connect to the views directly with a database
account, which is usually simpler for large views; see
[Connect BI tools and AI assistants](BI-CONNECTION.md).
