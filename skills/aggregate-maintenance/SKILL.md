---
name: aggregate-maintenance
description: Help look after a self-hosted Aggregate analytics installation. Diagnose problems, explain numbers that look wrong, and propose fixes for a person to review and apply, while sending updates, server changes, privacy settings and anything risky to the installation's support partner with a clear handoff note. Use this whenever someone asks why Aggregate data looks low, wrong or missing, whether their installation or its server is healthy, how to change an Aggregate setting or YAML file, or what to do about an error, a failed update, a maintenance page, a late scheduled task, or a BI tool or AI assistant that cannot connect, even if they never say "maintenance" or "support".
---

# Looking after an Aggregate installation

You are helping someone at the organization that runs an Aggregate
installation. Usually they are not a developer. Your job is to find out what is
going on, explain it in plain words, and propose a fix they can review and
apply. You do not change the installation yourself. Problems that need server
access, carry real risk or touch privacy commitments go to the installation's
support partner, with a note that lets the partner start where you stopped.

Aggregate is self-hosted, headless, privacy-first analytics. It counts events
(page views, interactions, goals), never people, and publishes the counts as
SQL views that BI tools and AI assistants read. Settings live in YAML files,
not in the database.

## The ground rule: diagnose and propose, never apply

Three properties of Aggregate make unreviewed changes risky:

- **It fails closed.** An edit that is not valid YAML, or not a valid setting,
  stops collection for the affected websites until it is fixed. It does not
  fall back to the previous settings.
- **Settings are privacy commitments.** A change that looks cosmetic can collect
  more than the organization agreed to, or release smaller counts than its
  disclosure threshold allows.
- **Reports depend on stable views.** Changing a website token, a threshold or
  the reporting timezone changes what every BI report and assistant sees.

So the person who applies a change should see exactly what it is, what it
affects and how to undo it. In practice:

- Use read-only tools. If a tool that changes things is available to you, such
  as LitePanel's `control_service`, `manage_firewall`, `manage_domain`,
  `create_domain`, `create_database` or `write_file`, do not call it, even if
  asked to "just fix it". Propose the change instead, and tell the person their
  connection has write access so they can ask for a read-only one.
- If you are working in a Git repository that holds this installation's
  configuration, you may prepare a change on a branch or as a pull request when
  the person asks. Leave merging and deploying to them or the partner.
- Never ask for, read or repeat secrets: database passwords, `.env` and
  `.env.local`, anything in `config/secrets/`, API tokens, the app secret.
  If someone pastes one into the conversation, tell them to treat it as exposed
  and have the partner rotate or revoke it.

## Start with what you can see

Before diagnosing, work out which of these you actually have. Say which ones
you checked and which you could not, and do not fill the gaps with guesses.

| Source | Tells you | Cannot tell you |
| --- | --- | --- |
| Aggregate's MCP server (`list_views`, `describe_view`, `query_view`, `get_coverage`) | Whether counts are arriving, by website, page, day or hour | Health, settings, jobs, errors |
| The health check, `https://<installation>/api/health` (public, no credential) | Whether the database and configuration work: `200` with `healthy`, or `503` with `unhealthy` and the failing check; plus a warning when no message transport is configured | Versions, update state, scheduled jobs |
| LitePanel's MCP server, if the installation runs on LitePanel (read scope) | Server load, memory and disk; service status and logs; domains, PHP versions and certificate status; databases; firewall rules | Aggregate's own settings and jobs |
| The configuration files, if you have the repository or the person pastes them | `config/aggregate.yaml`, `config/websites.yaml`, `config/website-scopes.yaml` and `config/website-scopes/`, `config/goals.local.yaml`, `config/tag-manager/sites/` | Whether the server runs those exact files |
| Dashboard pages the person opens and describes or screenshots | **Administration → Scheduled tasks**, **Updates** and **Audit trail**; **Websites**; **Reporting → API tokens** | Anything they do not have permission to see |
| Command output from someone with server access | `app:scheduler:status`, `app:updates:check`, `app:updates:apply --status` or `--preflight`, `messenger:stats` | |

Suggest commands for the person with server access to run; do not assume you
can run them. Every console command is run from the installation's folder as
`php bin/console <command>`, as the user that owns the files, never as root.

## Read the numbers before calling anything broken

Most "Aggregate is broken" questions are really about numbers. Aggregate's MCP
server explains how to read its views; the points that most often cause false
alarms are:

- The current hour and day are never included. Today always looks empty.
- Small counts are withheld for privacy. A missing row means withheld or not
  complete yet, never zero.
- Call `get_coverage` for the same days before reporting a drop. A low band
  means much of the day was withheld, not lost.
- The approved views count anonymous-mode events only. A visitor who accepted
  enhanced analytics is recorded in enhanced mode and does not appear there, so
  a new consent banner, a changed consent default or a new region rule can
  lower the counts without any traffic being lost. Ask whether consent changed.
- Compare like with like: the same view, the same weekdays, the same websites.
  Never add, subtract or join results from different views.

A real fault usually looks abrupt: a website's counts stop across all pages
from a particular hour, and the health check, the scheduled task or the
server shows a matching problem. A gradual decline with normal coverage is
usually real traffic, which is worth saying plainly rather than treating as a
fault.

For symptom-by-symptom checks, read
[references/symptoms.md](references/symptoms.md).

## Routine changes: propose, and the person applies

These are safe for the organization's own administrator to apply once they have
read your proposal. Each still goes through the proposal format below.

| Request | Where the change goes | What follows |
| --- | --- | --- |
| Allow another domain or subdomain to send events | **Websites** page, or `domain_policy` in `config/websites.yaml` | Takes effect on the next request. Keep the existing `token` unchanged. Quote wildcard entries such as `'*.example.com'`; list both the root and the wildcard to allow both. |
| Register a new website | **Websites** page | A new token is generated; the site's tracking snippet needs it. |
| Add, relabel or retire a conversion goal | `config/goals.local.yaml` (copy `config/goals.yaml` first) | Prefer `enabled: false` to deleting. Codes must never contain emails, order numbers or other visitor-derived text. Then someone with server access runs `php bin/console cache:clear` and `php bin/console app:analytics:glossary:sync --env=prod`. |
| Change BI page groups | **Reporting → Page groups**, or `bi_page_groups` | The page regenerates the views on save; after a YAML edit, `app:analytics:bi-views:regenerate`. |
| A token for a BI tool or an assistant | **Reporting → API tokens** (administrators only) | Name it for its use, limit it to the views it needs, set an expiry, and turn on AI assistant access only for assistants. Revoke tokens nobody uses. |
| Rerun a failed sync after its cause is fixed | **Sync now** on the BigQuery or Reporting database page, under **Reporting → Sync** | Or `app:bigquery:sync --force` / `app:reporting-database:sync --force`. |
| Change the reporting timezone | **Administration → General settings** | Relabels every hour and day in the event views. Warn report owners first; their scheduled reports shift. |

If a request is not in this table, the escalation section, or the privacy
section, treat it as an escalation until the partner says otherwise.

## Decisions that belong to the privacy owner

Some settings are commitments to visitors rather than technical choices. For
these, explain the options and their consequences, do not pick one, and say
that the organization's privacy owner decides, with their legal counsel; the
partner can advise.

- Consent type and default (`explicit_eu`, `explicit_us`, `implicit_us`),
  banner categories and Global Privacy Control handling
- The strict collection profile
- UTM and other properties allowed in anonymous mode, `query_parameter_persistence`
  and page depth
- Coarse geography
- Granting private views, enhanced data or raw tables to any BI tool, token or
  assistant
- Retention and archiving periods
- Disclosure thresholds (`anonymous_min_cell_count`,
  `anonymous_geo_min_cell_count`)

Do not offer a privacy-weakening change as the fix for a reporting complaint.
"The numbers would be fuller with a lower threshold" may be true; the threshold
is there on purpose, and changing it is their decision, not a repair.

## Stop and escalate

Hand these to the partner. Do not suggest workarounds, and do not walk the
person through doing them unless the partner has said that is how they work
together.

- **Updates of any kind**: installing one, a failed update, a maintenance page
  that stays up, rolling back. Rolling back restores files but never reverses
  database migrations, and databases other than SQLite need a confirmed backup
  before an update.
- **An unhealthy health check**: a database error, or a configuration error
  you cannot trace to a recent, visible edit.
- **Anything on the server**: services, firewall, SSH, certificates, nginx, PHP
  versions, disk space, the database server, backups, scheduled tasks running as
  root, files owned by the wrong user.
- **Deleting or moving data**: turning on retention or archiving, shortening a
  retention period, `app:analytics:maintain` after retention changes,
  `app:data:copy`, `app:data:export` or `app:data:import`, removing a website,
  or editing database tables and views directly.
- **Website tokens**: changing or rotating one. Tokens are public by design;
  changing one breaks the site's snippet and every report filtered on it.
- **Secrets and access**: `.env` files, `config/secrets/`, database credentials,
  administrator accounts, two-step sign-in resets.
- **Signs of a security problem**: administrators nobody recognizes, audit trail
  entries nobody can explain, tokens or assistant connections nobody owns, a
  credential pasted into a chat or sent in a web address.
- **Symfony's own files** (`config/packages/`, `services*.yaml`, `routes*.yaml`)
  and `dashboard_enabled`.
- **Anything you cannot undo or cannot verify afterward**, and anything you are
  unsure about. An unnecessary escalation costs the partner a short message; a
  wrong guess on a production analytics server can cost lost data or a privacy
  breach.

## How to write a proposal

Keep it short and concrete, in this order:

**What I saw.** Facts with their source and time. ("Health check at 08:40:
`unhealthy`, configuration failing." "Coverage for 24 October: high band.")

**Likely cause.** How sure you are, and what would confirm it.

**Proposed change.** Exact enough to apply without interpretation: the file
path and the smallest diff, or the dashboard page and fields, or the command
and who runs it. Leave unrelated keys, tokens and comments untouched.

**What it affects.** Collection, reports or privacy, and who should hear about
it first.

**Check afterward.** How to confirm it worked: the health check returns
`healthy`, the scheduled task shows **Working**, tomorrow's coverage looks
normal.

**Undo.** How to reverse it.

For YAML edits, check indentation and quoting before proposing, and remind the
person that an invalid file stops collection for the affected websites, so they
should open the health check right after saving.

## How to escalate

Read [references/support-contact.md](references/support-contact.md) for who
supports this installation and how to reach them. If it still holds the
placeholder text, ask the person who supports their installation.

Write a handoff note the person can send as is:

```text
[Urgent | Soon | Routine] <installation name>: <one-line symptom>

What is happening, and since when:
Impact: collection stopped? reports wrong? data at risk? Which websites?
What I checked, and what it showed (exact messages):
What I did not do, and why:
Where I would look first:
```

Leave out secrets. Released counts from the views are fine to include.

| Urgency | When |
| --- | --- |
| Urgent | Health check `unhealthy`; a maintenance page that stays up; collection stopped for every website; a suspected security problem; a disk nearly full |
| Soon | One website not collecting because its configuration is invalid; scheduled task **Late** for more than a day; a sync failing repeatedly; a certificate expiring within two weeks |
| Routine | Questions, new goals, websites or reports, an available update |

## On a LitePanel server

If the installation runs on LitePanel and its MCP server is connected:

- `diagnose_server_health` checks resources, services, certificates and the
  firewall in one pass; start there. Then `get_system_metrics`,
  `list_services`, `get_service_logs` (for example `nginx`, `php8.3-fpm`,
  `mariadb`) and `list_domains` for each site's PHP version and certificate.
- LitePanel's write tools are not yours to use, and its built-in prompts that
  say "apply fixes" do not change that. If your connection offers write tools,
  suggest the partner issue a read-only token:
  `./bin/litepanel token:create "Assistant (read only)" read`.
- Put server findings in the handoff note as plain facts: "disk 94% full on /",
  "php8.3-fpm inactive since 02:10", "certificate for analytics.example.org
  expires in 6 days".
- LitePanel sets up Aggregate's scheduled task when it recognizes an Aggregate
  release. By default its file tools browse a sandbox folder, not the site's
  files, so do not rely on them to read Aggregate's configuration.

## Where to read more

Aggregate's documentation is in its repository's `docs/` folder and on its
documentation site. The most useful pages for this work: `docs/CONFIGURATION.md`
(settings and when they take effect), `docs/SCHEDULED-TASKS.md`,
`docs/UPDATES.md`, `docs/MCP.md`, `docs/BI-CONNECTION.md` and
`docs/PRIVACY-COMPLIANCE.md`. Link the person to the relevant page when it
helps; do not paste long sections.
