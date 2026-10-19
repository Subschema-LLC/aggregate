# MCP server v1: design

**Status:** reviewed; v1 implemented on `feature/mcp-server`. Based on the
draft spec of 2026-10-18 (Scott Fillman), with the review's changes recorded
under [changes from the draft](#changes-from-the-draft) and the open questions
answered under [decisions](#decisions). The reference for users is
[MCP server for AI assistants](../MCP.md), and the decision is recorded in
[design decisions](../DESIGN-DECISIONS.md#ai-assistants-through-a-read-only-mcp-server-in-the-application).

## Purpose

Give AI assistants a native, read-only way to query Aggregate's approved
reporting views through the Model Context Protocol, so an assistant can answer
questions about a site's traffic without a database connection and without ever
seeing visitor-level data. This completes the product's claim: metrics as
curated SQL views that an AI can query, with privacy enforced in the database,
not promised in a prompt. It is the last feature flagged as critical before
treating the feature set as a stable baseline.

## Principles

1. **Read-only, always.** No write, configuration or admin tools.
2. **Approved views only, by default.** MCP reaches exactly the views the
   reporting API can reach for the same credential. Never the raw `events`
   table or base tables; private views only by explicit grant, as for API
   tokens.
3. **Suppression is inherited, never re-implemented.** Small-count
   suppression, current-bucket withholding and coverage live in the views. The
   MCP layer does not query around them and never explains a missing cell as
   zero.
4. **Bring your own model.** Aggregate serves data; the operator chooses the
   assistant and provider. The assistant receives released counts only.
5. **Off by default**, behind the `mcp_server` feature flag.
6. **Headless-first.** Works with `DASHBOARD_ENABLED=0` through tokens; every
   setting has a YAML or CLI equivalent. Sign-in, which needs an administrator
   to approve, needs the dashboard.
7. **Same rules as BI.** `event_count` is events, not people; the current hour
   and day are never released; times are labels in each column's zone.

## Architecture

- **In the application**, at `/mcp`, calling the reporting API's services
  (`ViewSchema`, `ViewQuery`, `ViewQueryRunner`, `ViewCatalog`,
  `ReportingApiSettings`) in-process, never over HTTP to itself.
- **Transport:** MCP Streamable HTTP without sessions or server-sent events.
  Each `POST` carries one JSON-RPC message and gets one `application/json`
  answer. `GET` and `DELETE` answer `405`. Protocol revisions `2025-11-25`,
  `2025-06-18`, `2025-03-26`. Implemented in the application (`McpServer`)
  rather than with an SDK: the subset needed is small, and PHP hosting cannot
  hold streams open.
- **Credentials**, both bearer tokens in the `Authorization` header:
  - a reporting API token holding the separate **MCP grant** (`mcp: true` in
    `config/secrets/api-tokens.yaml`);
  - the access token of an **assistant connection** an administrator approved
    through sign-in (`config/secrets/mcp-oauth.yaml`).
- **Rate limits:** 120 requests a minute per token or connection; 30 a minute
  per address on the sign-in endpoints.
- **Usage log:** each tool call in the existing `audit_trail` table, new area
  `assistant`, through `McpUsageLog`. No new table.

## Tools

| Tool | Arguments | Returns |
| --- | --- | --- |
| `list_views` | none | Readable views with description, privacy, grain; reporting timezone |
| `describe_view` | `view` | Grain, time column, whether it can be grouped, columns (type, label, description, zone, operators, codes and labels), notes |
| `query_view` | `view`, `columns`, `filters`, `date_range`, `group_by`, `order_by`, `limit` (≤ 1,000), `offset` | Rows, columns, `has_more`, `next_offset`, group columns and a note when grouped, and a coverage summary for the event views |
| `get_coverage` | `view` (one of the three event views), `date_range` (≤ 366 days), `website_token` | Coverage bands per website and day |

Every query becomes a `ViewQuery` and is validated exactly like an API request.
Errors the model can fix are tool results with `isError`. Resources: a guide
(`aggregate://guide`) and one description per readable, non-label view
(`aggregate://views/<view>`). Prompts are not offered.

## Sign-in

OAuth 2.1 per the MCP authorization specification, with Aggregate as both
authorization server and protected resource:

- Protected resource metadata at `/.well-known/oauth-protected-resource/mcp`
  (and without `/mcp`), named in the `WWW-Authenticate` header of a `401`.
- Authorization server metadata at `/.well-known/oauth-authorization-server`
  and `/.well-known/openid-configuration`.
- Public clients only, with PKCE `S256`: dynamic client registration at
  `/oauth/register`, or client ID metadata documents fetched from public
  addresses (5 s, 64 KiB, no redirects).
- The authorization endpoint is the dashboard page `/dashboard/oauth/authorize`,
  administrators only: the client and redirect are verified before anything
  else and errors about them are shown, never redirected; the page shows the
  redirect's host, marks loopback redirects, refuses framing, and asks for
  views and expiry like the token form. Approving redirects with `code`,
  `state` and `iss`.
- `/oauth/token`: `authorization_code` (single-use code, 60 s, bound to client,
  redirect and verifier) and `refresh_token` (rotated on every use; a replaced
  token coming back revokes the connection, except a retry within 30 s).
  Access tokens last an hour. `resource` must name this `/mcp`.
- `/oauth/revoke` (RFC 7009) for clients revoking their own tokens.
- State in `config/secrets/mcp-oauth.yaml`: clients, waiting codes and
  connections, hashes only, written under a lock and atomically, pruned on
  every write.

## Changes from the draft

1. **Sign-in is in v1.** The draft deferred OAuth. Bearer tokens alone reach
   developer tools but not the assistants most buyers use: ChatGPT accepts no
   fixed key, and Claude's hosted apps accept one only in a limited beta. The
   draft's setup examples for those assistants would not have worked.
2. **Sign-in state in YAML.** Following the existing paradigm for API tokens,
   at the owner's request, rather than a new table.
3. **Grouping is shared with the reporting API.** The draft's `group_by` was new
   work: the API had no aggregation. It was built once in `ViewQuery` and
   `ViewQueryRunner`, sums `event_count` over released rows only, and is
   offered as the API's `group` parameter too.
4. **Usage goes to the audit trail.** The draft did not say where; the
   repository's rule is no status tables of their own. The API itself still
   does not log reads.
5. **Labels for every credential.** The `bi_dim_*_v1` and glossary views are
   readable with any token or connection, and the API's schema endpoint now
   reads the published glossary (an administrator's edits and custom columns)
   and lists each category column's codes and labels. `describe_view` and the
   resources use the same service.
6. **Corrections.** API tokens live in `config/secrets/api-tokens.yaml`, not a
   table. Coverage is a band per website, day and event view, for days above
   the threshold, not a record of where cells were withheld. Queries report
   whether more rows exist rather than a total. The transport is stateless,
   JSON-only.
7. **Private views.** The draft both kept the reporting API's private-view rule
   and said no enhanced-mode data through MCP; the private views include
   enhanced rows, so the two conflict. v1 keeps the API's rule: off by
   default, then granted per credential with a confirmation, and documented as
   sending row-level data to the assistant's provider.
8. **The API tokens page serves both flags.** A menu link can now name several
   features and appears while any is on.

## Decisions

1. **Structured `query_view`, no SQL tool**, as the draft recommended.
2. **A separate `mcp_server` flag**, as recommended. Tokens can be created
   while either flag is on; every token works on the API while the API is on,
   and on the MCP server only with the MCP grant.
3. **In core, flag off**, as recommended.
4. **Usage log retention** follows the audit trail's existing retention for
   non-job entries, and the area can be switched off.
5. **No MCP library.** The protocol subset is implemented in the application.

## Acceptance

- [x] With the flag off, nothing changes and `/mcp` and the sign-in addresses
      are 404.
- [x] An administrator can enable MCP, grant a token or approve a sign-in, and
      connect a real MCP client using only `docs/MCP.md` (tested with the
      official MCP Python SDK, both ways).
- [x] Clients list and describe exactly the views their credential may read.
- [x] Queries return released cells only; contract tests cover filters,
      grouping and paging attempts to reach withheld cells or raw tables.
- [x] Revoking a token, its MCP grant or a connection cuts access on the next
      request.
- [x] Every tool call appears in the usage log with credential, tool, view and
      row count.
- [x] The fresh-install CI matrix exercises the API's grouping, MCP queries and
      the full sign-in on every database engine.

## Later

- The 2026-07-28 protocol revision (`server/discover`, stateless requests).
- Enterprise single sign-on for approvals, and approvals by standard users
  limited to their own permissions.
- Per-query time limits on each engine.
- Last-used times for tokens and connections, kept outside the YAML files.
