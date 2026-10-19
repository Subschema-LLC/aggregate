# MCP server for AI assistants

[Reporting API](REPORTING-API.md) · [Connect BI tools and AI assistants](BI-CONNECTION.md) · [Privacy guide](PRIVACY-COMPLIANCE.md#ai-assistants-mcp) · [Feature flags](FEATURE-FLAGS.md)

Aggregate includes a read-only [Model Context Protocol](https://modelcontextprotocol.io)
(MCP) server, so an AI assistant can answer questions about your traffic from
the reporting views: "Which pages gained the most views last week?", "How did
sign-ups trend by day this month?" The assistant lists, describes and queries
the same approved views a BI tool reads, with the same protections: small
counts withheld, completed periods only, no visitor identifiers. It never gets
a database connection, raw rows or SQL.

The server is generic: any assistant that supports remote MCP servers can
connect, either by signing in, where an administrator approves it on the
dashboard, or with an API token in a header.

- [What an assistant can do](#what-an-assistant-can-do)
- [Turn it on](#turn-it-on)
- [Connect an assistant](#connect-an-assistant)
- [Tools](#tools)
- [Reading results correctly](#reading-results-correctly)
- [Sign-in](#sign-in)
- [Managing access](#managing-access)
- [Usage log](#usage-log)
- [Limits](#limits)
- [Privacy and security](#privacy-and-security)
- [Protocol](#protocol)
- [Troubleshooting](#troubleshooting)

## What an assistant can do

| Tool | What it does |
| --- | --- |
| `list_views` | The views the assistant may read, with what a row represents |
| `describe_view` | A view's columns: meaning, type, time zone, filter operators, the codes and labels of category columns, and notes on reading it |
| `query_view` | Rows of one view, filtered, sorted and paged, or `event_count` summed by the columns in `group_by` |
| `get_coverage` | How much of each day an event view releases after withholding, in bands |

Every tool is read-only. There are no tools that change settings, write data,
run SQL or reach anything but the reporting views.

The server also offers **resources** an assistant can attach as context: a
guide to reading the views (`aggregate://guide`) and one description per
readable view (`aggregate://views/<view>`), the same text `describe_view`
returns.

## Turn it on

The server is behind the `mcp_server` [feature flag](FEATURE-FLAGS.md), off and
hidden by default. While it is off, `/mcp` and every sign-in address are an
ordinary 404. Turn it on under **Administration → Feature flags**, or in the
active YAML:

```yaml
feature_flags:
  mcp_server:
    enabled: true
    hide_from_navigation: false
```

It is separate from the `reporting_api` flag, so you can offer assistants
without the HTTP API or the API without assistants. Both are managed on
**Reporting → API tokens**.

Serve the installation over **HTTPS**. Assistants send a credential with every
request, and the data they read travels back the same way.

## Connect an assistant

The server's address is your installation's address followed by `/mcp`, for
example `https://analytics.example.com/mcp`. The **API tokens** page shows it
with a copy button.

### Sign in

For assistants such as Claude and ChatGPT, add a custom connector (also called
a remote MCP server or app) with the server's address and no credentials. When
the assistant first connects:

1. It finds the sign-in details at the address (they are published under
   `/.well-known/`) and registers itself.
2. Your browser opens Aggregate's **Connect an assistant** page. Sign in to the
   dashboard as an administrator if you are not already.
3. The page names the assistant and where the sign-in returns to. Choose the
   views it may read and when the connection expires, then **Connect**. Or
   **Deny**.
4. The assistant receives its own tokens and can use the tools.

Each assistant gets its own connection, listed on the API tokens page under
**AI assistant connections**, where it can be revoked. Only administrators can
approve one: a connection grants data access outside the dashboard's
[permissions](USERS-AND-PERMISSIONS.md), like an API token.

Sign-in needs the dashboard. On an installation with `DASHBOARD_ENABLED=0`,
the sign-in addresses return 404; give assistants a token instead.

### With a token

Assistants that let you set a request header, such as developer tools and
command-line assistants, can use an API token instead:

1. On **Reporting → API tokens**, create a token and tick **AI assistants may
   use this token on the MCP server**, or run
   `php bin/console app:api:token:create "Gemini CLI" --mcp --expires=90d`.
2. Add a remote (HTTP) MCP server with the address and the header
   `Authorization: Bearer <token>`.

For example, in Claude Code:

```bash
claude mcp add --transport http aggregate https://analytics.example.com/mcp \
  --header "Authorization: Bearer agg_3f9a1c2e_…"
```

Most tools accept the same in a JSON configuration file:

```json
{
  "mcpServers": {
    "aggregate": {
      "url": "https://analytics.example.com/mcp",
      "headers": { "Authorization": "Bearer agg_3f9a1c2e_…" }
    }
  }
}
```

A token is only accepted by the MCP server when it has that grant, so a token
created for a script or a BI refresh does not silently become an assistant's
credential. The grant does not widen what the token may read.

### Which assistants work

The server follows the MCP specification rather than any one vendor. As of
October 2026:

| Assistant | How it connects | Notes |
| --- | --- | --- |
| Claude (web, desktop, mobile) | Sign-in | Added as a custom connector. A fixed header is in beta for some organizations only. |
| Claude Code | Sign-in or token | |
| ChatGPT | Sign-in | ChatGPT does not accept fixed API keys, so sign-in is the only way. |
| Microsoft Copilot Studio | Sign-in (it registers itself) or token | |
| Gemini CLI | Sign-in or token | Whether the Gemini app itself accepts custom servers could not be confirmed. |
| Cursor, VS Code with GitHub Copilot, and other MCP clients | Token, or sign-in where the client supports it | |

The server was tested end to end with the official MCP Python SDK, by token and
by sign-in. Vendors change their connector options; check each assistant's own
documentation.

**Where the installation has to be reachable from.** Assistants that run in
their vendor's cloud (Claude on the web, ChatGPT, Copilot Studio) call the
server from that vendor's servers, so the installation must be reachable from
the internet over HTTPS. Desktop and command-line tools call it from the
computer they run on, so an installation on an internal network works for them.

## Tools

Arguments are JSON. The tool definitions an assistant receives describe them
too.

### `list_views`

No arguments. Returns each readable view's name, description, whether it is
private, and what a row represents, plus the installation's reporting timezone.
Labels and definitions (the `bi_dim_*` and glossary views) are always included.

### `describe_view`

`{"view": "bi_anonymous_daily_rollup_v1"}`. Returns the view's grain and time
column, whether it can be grouped, and each column's name, type, label,
description, time zone and filter operators. Columns that hold a glossary
dimension (`device_class`, `referrer_channel`, `website_token` and the others)
list their codes and labels from the published glossary, so the assistant can
filter on `mobile` and report "Mobile". It ends with notes on reading the view.

### `query_view`

```json
{
  "view": "bi_anonymous_daily_rollup_v1",
  "date_range": {"from": "2026-10-01", "to": "2026-10-14"},
  "filters": [{"column": "device_class", "operator": "eq", "value": "mobile"}],
  "group_by": ["event_day"],
  "order_by": ["event_day"],
  "limit": 100
}
```

| Argument | Meaning |
| --- | --- |
| `view` | Required. A view from `list_views` |
| `columns` | Columns to return. Default: every column, or the `group_by` columns and `event_count` |
| `filters` | `{column, operator, value}` conditions, combined with AND. Operators as in the [reporting API](REPORTING-API.md#filter-operators): `eq`, `ne`, `gt`, `gte`, `lt`, `lte`, `in` (a list), `starts_with`, `null` (`true` for empty) |
| `date_range` | `from` and/or `to`, whole days as `YYYY-MM-DD`, on the view's time column. On the hourly view the last day's hours are included |
| `group_by` | Sum `event_count` for each combination of these columns. `[]` returns one total |
| `order_by` | Columns to sort by; `-event_count` sorts descending |
| `limit`, `offset` | Rows per call, 100 by default and at most 1,000; and rows to skip |

The result has the rows, the columns, `has_more` and `next_offset` for the next
page. For the event views it adds a coverage summary for the days asked about,
or a reminder to check coverage when no days were given.

Grouping sums released rows only. The view has already withheld small cells,
so a sum never contains them, and a grouped total with nothing released is
`null`, not 0. Only views with `event_count` can be grouped.

Each query is validated exactly like a reporting API request: unknown views,
columns and operators are refused, every value is checked against its column's
type and bound, never written into SQL.

### `get_coverage`

```json
{"view": "bi_anonymous_daily_v1", "date_range": {"from": "2026-10-01", "to": "2026-10-31"}, "website_token": "…"}
```

For `bi_anonymous_events_v1`, `bi_anonymous_daily_v1` or
`bi_anonymous_daily_rollup_v1`, returns one row per website and day with the
share of that day's anonymous events the view releases: `90-100`, `50-90`,
`10-50` or `0-10` percent. Days with too little traffic for a band are left
out; they may be entirely withheld. It reads `bi_anonymous_coverage_v1`, so the
credential needs that view too. At most 366 days per call.

### Errors

A call the assistant can correct, such as an unknown column, a view it may not
read or a bad date, comes back as a tool result marked as an error, with a
message saying what to change. The model sees it and can try again. Codes
include `invalid_argument`, `unknown_view`, `unknown_column`,
`unsupported_operator`, `invalid_filter_value`, `invalid_parameter`,
`view_not_permitted`, `private_views_disabled` and `view_unavailable`.

## Reading results correctly

The server tells the assistant these rules when it connects, in the guide
resource and in each view's notes. They are the same rules as for
[BI tools](BI-CONNECTION.md):

- **`event_count` counts events**, such as page views, interactions and goal
  occurrences. It never counts people, visits or sessions.
- **A missing row means withheld or not complete yet, never zero.** Small
  counts are withheld for privacy, and the current hour and day are never
  included.
- **Check coverage before reporting a total or a trend**, so a low number
  reads as "withheld" rather than a real drop.
- **Each view stands alone.** Results from different views are never added,
  subtracted or joined, and the server offers no way to do so.
- **Times are labels** in the time zone each column states, without an offset.
- **For small sites, prefer `bi_anonymous_daily_rollup_v1`.** Its daily totals
  add up, and its `(other)` rows pool what more detailed rows withhold.

An assistant can still misread a result. Treat its answers like any analyst's
first draft and check the ones that matter.

## Sign-in

Sign-in follows the [MCP authorization specification](https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization):
OAuth 2.1 with PKCE, where Aggregate is both the authorization server and the
protected resource. Assistants find everything from the server's address.

| Address | Purpose |
| --- | --- |
| `/.well-known/oauth-protected-resource/mcp` (also without `/mcp`) | Protected resource metadata (RFC 9728), which a `401` from `/mcp` points to |
| `/.well-known/oauth-authorization-server` (also `/.well-known/openid-configuration`) | Authorization server metadata (RFC 8414) |
| `/oauth/register` | Dynamic client registration (RFC 7591) |
| `/dashboard/oauth/authorize` | The approval page |
| `/oauth/token` | Exchanges an approved code, and renews tokens |
| `/oauth/revoke` | Lets an assistant revoke its own tokens (RFC 7009) |

**Assistants are public clients.** They prove themselves with PKCE (`S256`),
never with a secret. An assistant can register itself, or use a client ID
metadata document: an HTTPS URL as its `client_id` that describes it. Aggregate
fetches that document from public addresses only, within five seconds and
64 KiB, without following redirects.

**The approval page is careful about where it sends you.** A sign-in request
whose assistant or return address cannot be verified is answered on the page,
never redirected. The page shows the return address's host, marks an address on
the same computer (an assistant running locally), and cannot be framed by
another site.

**Codes and tokens.** An approval returns a code that lives 60 seconds, works
once, and only for the assistant, return address and PKCE verifier it was
issued for. The assistant exchanges it for an access token, valid for an hour,
and a refresh token. Renewing replaces both. A refresh token that comes back
after it was replaced revokes the connection, because it may have been copied;
a retry within 30 seconds, as after a lost response, is accepted. Tokens are
only valid for this installation's `/mcp`: a `resource` naming anything else is
refused.

**Storage.** Sign-in state is kept in `config/secrets/mcp-oauth.yaml`, written
the same way as `api-tokens.yaml`: only SHA-256 hashes of codes and tokens,
under a lock, atomically, readable only by the application's user, and kept by
updates. It is written when an assistant registers, is approved, exchanges its
code and renews its tokens, which is about once an hour per connected
assistant. Expired codes and connections, and registrations nothing uses after
a day, are removed on each write. An invalid file authenticates nothing until
it is corrected, and nothing is saved over it.

```yaml
clients:
  aggc_0123456789abcdef:
    name: 'Claude'
    redirect_uris: ['https://claude.ai/api/mcp/auth_callback']
    created_at: '2026-10-19T02:00:00+00:00'
codes: {}
connections:
  8b7c6d5e:
    client_id: 'aggc_0123456789abcdef'
    client_name: 'Claude'
    views: ~                 # every approved view; or a list
    private_views: []
    approved_by: scott
    created_at: '2026-10-19T02:00:30+00:00'
    expires_at: '2027-01-17T02:00:00+00:00'   # ~ for never
    access_hash: 'sha256:…'
    access_expires_at: '2026-10-19T03:00:30+00:00'
    refresh_hash: 'sha256:…'
    previous_refresh_hash: ~
    rotated_at: ~
```

**Web server.** The sign-in metadata lives under `/.well-known/`. The Apache
rules in `public/.htaccess` already send it to Aggregate. The
[nginx example](nginx/aggregate-analytics.conf) has a `location ^~ /.well-known/`
block before the rule that hides dotfiles; add it to an older nginx
configuration, or assistants cannot find the sign-in. Install Aggregate at the
root of its host name: under a subfolder, assistants that only look for
metadata at the host's root cannot find it.

## Managing access

**Reporting → API tokens** lists both kinds of credential:

- **Tokens** marked **MCP** may be used by assistants. Create, list and revoke
  them as described in the [reporting API guide](REPORTING-API.md#tokens).
- **AI assistant connections** lists each approved assistant: its name and
  host, its views, who approved it and when, and its expiry, with **Revoke**.

Without the dashboard:

```bash
php bin/console app:api:token:create "Gemini CLI" --mcp --expires=90d
php bin/console app:api:token:list            # MCP tokens are marked [MCP]
php bin/console app:mcp:connection:list
php bin/console app:mcp:connection:revoke 8b7c6d5e
```

Revoking a token or a connection takes effect on the next request. Listing and
revoking work while the `mcp_server` flag is off. Approving, revoking and
creating tokens are recorded in the [audit trail](AUDIT-TRAIL.md) under
**Integrations**.

Private views follow the reporting API's rules: `reporting_api_private_views`
must be on, and each private view is granted to each token or connection one
by one, with a confirmation. See
[private views](REPORTING-API.md#private-views) before granting one to an
assistant: whatever it reads is sent to its provider.

## Usage log

Each tool call is recorded in the [audit trail](AUDIT-TRAIL.md) under **AI
assistant requests** (`assistant`): when, which token or connection, which tool
and view, whether it succeeded, how many rows came back and how long it took.
The results, filter values and conversation are never recorded; the server
never sees the conversation, only the tool calls. Entries follow the audit
trail's retention, and the area can be turned off on the Audit trail page. The
API tokens page links to these entries.

## Limits

| Limit | Value |
| --- | --- |
| Requests per token or connection | 120 a minute, then `429` with `Retry-After` |
| Sign-in requests per client address | 30 a minute |
| Rows per `query_view` call | 1,000, or the reporting API's maximum page size when smaller |
| Days per `get_coverage` call | 366 |
| Request body | 1 MiB |
| Connections kept | 100; registrations, 200 |

Queries have no time limit of their own beyond PHP's and the database's. Use
`date_range` on large views.

## Privacy and security

- **What leaves the server is what the assistant reads.** The assistant's
  provider receives the released counts, labels and descriptions the assistant
  asks for, never more. Choosing an assistant is choosing where those go, as
  choosing a BI tool is.
- **Nothing is sent anywhere by the server itself.** It calls no third party,
  except to fetch an assistant's client ID metadata document during sign-in.
- **The same protections as BI users.** Suppression, current-period withholding
  and coverage come from the views. The tools cannot reach raw tables, the
  `events` table or any object outside the view catalog. The approved views
  hold anonymous counts only; enhanced-mode rows are reachable only through a
  private view an administrator granted to that assistant.
- **Credentials only in the header.** A credential in the address is refused.
  The server uses no cookies and sends no CORS headers, so a web page cannot
  use a visitor's browser to call it.
- **Bearer credentials.** Anyone holding a token or an assistant's tokens can
  use them until they expire or are revoked. Prefer expiries, narrow scopes and
  sign-in, whose tokens last an hour.
- **No independent audit** of the MCP server is claimed. Report problems as
  described in [SECURITY.md](../SECURITY.md).

## Protocol

- Streamable HTTP at `/mcp`, without sessions or server streams: each `POST`
  carries one JSON-RPC message and gets one JSON answer, which suits ordinary
  PHP hosting. `GET` and `DELETE` answer `405`. Batches are not supported.
- Protocol revisions `2025-11-25`, `2025-06-18` and `2025-03-26`. An
  `MCP-Protocol-Version` header naming another revision is answered `400`.
  Clients that try the newer 2026-07-28 revision first fall back to these, as
  the official SDKs do.
- Methods: `initialize`, `ping`, `tools/list`, `tools/call`, `resources/list`,
  `resources/templates/list` and `resources/read`. Notifications are accepted
  with `202`.
- Tool results carry the data as JSON text and as `structuredContent`.

## Troubleshooting

| Symptom | Cause |
| --- | --- |
| `404` at `/mcp` | The `mcp_server` flag is off |
| `401` with a token | The token lacks the MCP grant, or is mistyped, revoked or expired |
| The assistant cannot find sign-in, or says it cannot reach the server | The installation is not reachable from the assistant's servers, `/.well-known/` is blocked by the web server, or the dashboard is disabled |
| "This connection cannot continue" on the approval page | The assistant's registration expired or its return address does not match; remove and add the connector again |
| The assistant reconnects every time | Its refresh token was refused, for example after the connection was revoked or a replaced refresh token came back |
| `view_not_permitted` | The token or connection was not granted that view, or `get_coverage` needs `bi_anonymous_coverage_v1` |
| `view_unavailable` | The view does not exist on this installation, for example geography is off |
