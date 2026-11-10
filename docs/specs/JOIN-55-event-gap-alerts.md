# I think it’s time to add in an alerts feature. It is now being requested by Geektown USA, period. What I would like is a section where we can set up alerts for different thresholds of events not comin

_Draft for JOIN-55, written by the team in Slack: each section names who wrote it. JOIN keeps it until an approver saves it to the repository._

## Product Owner

This is the roadmap's planned "Alerts rules engine" (`ROADMAP.md` lines 97–124). Nothing for it has been built yet: no pull request touches alerts, and nothing in `src/` sends webhooks. The v1 for Geektown USA is a set of **threshold rules on completed UTC hours**, delivered by **signed generic JSON webhooks and email**, set up by administrators on an Alerts page, in YAML and on the command line. This section has been updated with the teammate's answers in the thread. The anchor `docs/features/event-gap-alerts.md` needs the same changes before it is approved (see "Anchor changes" below).

**Decisions from the thread**
- Only administrators can set up alerts in v1.
- The page writes rules to YAML. Rules written by hand in YAML load and can be edited on the page, but comments in `config/aggregate.yaml` are not kept, as on every other settings page. `docs/ALERTS.md` says so.
- Events are counted across all privacy modes by default. A rule can narrow the count with a filter (see the rule shape below).
- Email is in v1, through `TransactionalMailer`, along with webhooks.
- The webhook payload is one generic signed JSON contract. There are no native Slack or PagerDuty formats.
- **The v1 webhook body is flat and small**, as agreed with the teammate for Geektown's hub (see "Webhook payload v1" below). Richer fields (website name, group, installation name, filter, observed count, window start) wait for a later `schema_version`.
- **A broken consent banner is caught with "fewer than N enhanced events" in v1.** A ratio rule (enhanced below X% of all events) is the next increment.
- Webhooks to private or loopback addresses are always blocked. There is no `allow_private_webhooks` exception.
- A test email can go to any recipient on the list.
- Firing state is kept in `processing_tasks.fingerprint`, and each delivery is recorded in `audit_trail` with `always: true`. There is **no new table**.
- The payload identifies the website and the installation it comes from, so the receiving hub needs no lookup of its own. The installation URL reuses `app_host`, with no new `alerts.*` keys.

**Webhook payload v1**
One request per alert. The body is a JSON array that always holds **exactly one** alert object, so retries, signatures and de-duplication stay per alert and the hub's array parser never changes.

```
[
  {
    "schema_version": "1",
    "id": "97987789878yuhu", // unique id per alert 
    "incident":"789787g7ddhj", // incident id to connect alerts to a single incident
    "type": "firing",
    "rule": "geektown-weekly",
    "website": "898s098gs098sg098gs98",
    "host": "some.domain.com",
    "installation": "https://analytics.example.com",
    "alert_condition": "fewer_than_5_per_week",
    "window_end": "2025-06-02T14:00:00Z"
  }
]
```

- `schema_version`: the string `"1"`. Fields are only ever added in a new version, never renamed or removed within one.
- `id`: identifies **the alert, not the attempt**. It is the same on every retry of the same alert and equals the `Idempotency-Key` header, so the hub de-duplicates on it. Two different alerts (another rule, website, window or type) never share an id; in particular **a `firing` and the `recovered` that closes it always have different ids**. Every test send gets a new id. It is opaque: the hub must not parse it.
- `type`: `firing`, `recovered` or `test`. A `test` never reflects or changes a rule's state, and receivers should not change state because of it.
- `rule`: the rule's id from YAML. A `recovered` alert carries the same `rule` and `website` as the `firing` alert it closes.
- `website`: the website token from `config/websites.yaml` (already public in every tracking snippet).
- `host`: the website's domain from `config/websites.yaml`, a bare lower-case hostname with no scheme. It is not the installation's address.
- `installation`: the installation's URL from `app_host`, with scheme, sent **lower-case in scheme and host and with no trailing slash** (a sub-folder path, if any, is kept as configured). Receivers should still compare scheme and host case-insensitively and ignore a trailing slash, because an operator can change `app_host`.
- `alert_condition`: a **display string**. Receivers show it as-is and must not parse it; it has no fixed list of values because N is part of it. The forms used in v1 are `no_events_per_<window>`, `fewer_than_<N>_per_<window>` and `more_than_<N>_tracking_failures_per_<window>`, where window is `hour`, `day` or `week`, but that is informative only. It does not carry the filter; the `rule` id is how the hub tells a consent-banner rule from a plain one. Anything a receiver needs to act on goes in a structured field in a later schema.
- `window_end`: the end of the completed UTC window, in ISO 8601 with `Z`, on a whole hour.
- **Ordering:** for one `installation` + `rule` + `website`, the alert with the later `window_end` is the current state; a late retry of an older alert is stale. A `firing` and its `recovered` never share a `window_end`. `test` alerts take no part in ordering.
- **Signing and replay:** the signature and a send timestamp travel in the `X-Aggregate-Signature` header (`t=<unix seconds>,v1=<HMAC-SHA256 of "t.body">`), never in the body. The body has no `sent_at`. Each attempt, including a retry, is signed with its own current timestamp, and keeps the same `id`. `docs/ALERTS.md` recommends that receivers reject a timestamp more than 5 minutes from their clock and de-duplicate on `id`.
- The body never holds event rows, page paths, property values, visitor data, recipient addresses or secrets.

**Rule shape (v1)**
A rule reads: *alert when [websites or website group] record [no / fewer than N] [events matching filter] in the last [window] of completed UTC hours.*
- **Source**, one of two:
  - `events`, counted from `events`.
  - `tracking_failures`, the failures already recorded in `processing_tasks` as `tracking_event_store` and `tracking_ingest` (see `src/Service/TrackingFailureRetryRunner.php`). These are what the tracking failure status shows on Collection controls (`app_tracking_failure_status`). For this source the condition is reversed: *more than N failures*. It is the same rule engine with a different source, **not** a new database view, because none exists.
- **Filter**, optional, for `events` only:
  - `privacy_mode`: anonymous, enhanced or all. The default is all.
  - `event_name`: a single name, such as `page_view`.

  These two cover the named failures:
  - *Tag removed*: no events (all modes) in the window.
  - *Consent banner broken*: fewer than N **enhanced** events in the window while the site is still sending events.
  - *No / too few events*: the plain rule.
- **Window**: 1 hour, 1 day (24 h) or 1 week (168 h). Windows are always whole completed UTC hours. Each window slides and is checked every hour.
- Still for the next increment: "dropped below normal" (baselines or anomaly detection), a ratio rule for consent, quiet hours and day-of-week schedules, and goal-specific shortcuts. Goal events can be matched with `event_name` already.

**Acceptance criteria**
1. An administrator can create, edit and delete a rule with a source, condition, N, optional filter, window, covered websites or group, and destinations. This works on the Alerts page, in YAML and through `app:alerts:*` commands. All three reject the same invalid rule with the same message. A rule written by hand in YAML shows up on the page and can be edited there.
2. When a covered website breaches a rule over a window of completed hours, an alert is sent **within 15 minutes after that hour ends**, without anyone opening the dashboard, and with the dashboard disabled. An hour still in progress never triggers an alert.
3. A continuing breach sends **one** firing alert, then **one** recovery when the rule is no longer breached. A brand-new website or a newly enabled rule fires if its first completed window is breached. Turning off an audit-trail area or purging old audit rows causes no duplicate alerts and does not hide recent alerts.
4. Group rules send one alert per website, not one combined alert.
5. Each webhook destination receives an HTTPS POST whose body is a JSON array holding exactly one object with exactly the nine fields in "Webhook payload v1": `schema_version`, `id`, `type`, `rule`, `website`, `host`, `installation`, `alert_condition` and `window_end`, with the meanings given there. A firing and its recovery have different `id`s and the recovery's `window_end` is later. A retried delivery carries the same `id` as its first attempt, and that `id` equals the `Idempotency-Key` header. `installation` has no trailing slash and a lower-case scheme and host.
6. Every webhook request carries `X-Aggregate-Signature` with a timestamp and an HMAC-SHA256 over the timestamp and body, under a secret kept in `config/secrets`. A retry has a fresh timestamp. Using only `docs/ALERTS.md`, a receiver can verify the signature, reject a replayed request outside the tolerance, de-duplicate on `id`, and order alerts by `window_end`.
7. Each email destination receives the same facts in plain language through `TransactionalMailer`, with the purpose `alert`.
8. A failed delivery is retried a bounded number of times, then recorded as failed. It never blocks other destinations, other rules or other scheduled jobs.
9. An administrator can send a test to any destination, including any listed email recipient. Tests are labelled as tests (`type: "test"` in webhooks), get a fresh `id`, and never change a rule's state.
10. The Alerts page shows each rule's state (OK, firing since a time in UTC, waiting for the first full hour, or off), the time of the last check, and the 20 most recent alerts with their result (sent, suppressed or failed) and a plain reason.
11. Alerts, payloads, logs, `processing_tasks` and `audit_trail` never contain event rows, page paths, custom property values or visitor data. Recipient addresses, webhook URLs (beyond the host on the page) and secrets never appear in logs, task details or audit details, including in error messages.
12. Webhook URLs must be HTTPS. Private, loopback and link-local destinations are rejected when saved and when sent. Redirects are not followed.
13. The feature is behind a feature flag that is off by default. Its routes are administrator-only in `DashboardPages`. Its menu entry appears only when the flag is on, through `shown_when`.
14. It works on PostgreSQL, MySQL, MariaDB, SQL Server and SQLite. A week-long window on a busy site does not need a full table count, because counting stops at N.

**Anchor changes needed** (for `docs/features/event-gap-alerts.md`, before it is approved)
- Remove "dropped below normal" from Problem.
- Add the tracking-failures source, the privacy-mode and event-name filters, and day and week windows to "Done when".
- Record the decisions above under Decisions, including the flat v1 payload, its signing and replay rule, and the ordering rule.

**Risks**
- Low-traffic sites will alert falsely on short windows. The page warns about this, and the day and week windows are the answer for quiet sites. If Geektown still gets flooded, quiet hours come next, not in v1.
- A consent-banner rule set by count alone fires when overall traffic is low, too. Pair it with a "no events" rule, or use a window long enough to be meaningful. The docs should show a worked example.
- Tracking-failure counts depend on how many `processing_tasks` attempts are kept per subject. The Architect must confirm that a window count is possible from what is retained. If it is not, v1 for this source falls back to "any new failure since the last check".
- Data lifecycle settings that remove events newer than a week would make week-long windows read as breached. The validator should reject a window longer than the live retention.
- The flat payload leaves the observed count and filter out. A hub that wants to show "3 of 5 events" must wait for schema 2; adding it later is additive and does not break v1 receivers.
- Replay rejection depends on the receiver's clock. A hub with a badly wrong clock will reject valid alerts; the tolerance and this risk go in `docs/ALERTS.md`.

**Open questions**
- Answer 4 is read here as "tracking failures become a rule source, using the same filter-driven engine". If you meant something else, such as a filter on the existing tracking-failure status itself, please say so.

_By -Product Owner-._

_By -Product Owner- (claude-opus-5-5)._

## UX/UI Designer

The Product Owner covered scope, so I add only the Alerts page design. It is one page modelled on templates/email_settings/index.html.twig. The page has a status banner, a rules table, a destinations section and a recent-alerts list. Rules read as sentences. The page never shows a recipient address or a full webhook URL. It adds one menu entry in config/navigation.yaml under Administration, next to Scheduled tasks, with a shown_when for the flag. Feature flags stays always visible, so people can turn the flag on.

**Approach**
- Page order: (1) a status banner in the Email page style, such as 'Alerts are on. 3 rules, 1 firing now', with 'As of the last check, 14:05 UTC' so the page never implies it is live. (2) Rules table with Name, Covers, Condition and Status columns plus 'Add rule'. (3) Destinations. (4) Recent alerts. Each section is an open `details` box with an h2. Each rule is edited on its own page to keep the main page short.
- The rule form reads as one sentence: 'Alert when [websites] record [no events / fewer than N events] in [1 / 3 / 6 / 24] UTC hours.' The N field is enabled only for 'fewer than'. When a reporting time zone is set, the window label adds 'These are UTC hours, not your reporting time zone.' This answers the Product Owner's window-boundary risk. Field names match the YAML keys.
- Row status is text plus an icon, never colour alone: 'OK', 'Firing since 14:00 UTC', 'Waiting for the first full hour' or 'Off'.
- The form shows a non-blocking warning on a 'no events' rule with a window under 6 hours: 'Quiet sites often have empty hours overnight, so this may alert often.' This helps with false alerts without needing quiet hours in v1.
- Destinations need a label, such as 'Ops chat'. The table shows the label and, for webhooks, the host only. Rules choose destinations by label with checkboxes. Recent alerts name a destination by its label, which keeps the address and path private.
- The webhook secret copies the Email credential field: a write-only password input with 'Saved; leave empty to keep it' and a note that it is stored in config/secrets. A 'Generate a secret' button shows the value once in a read-only field with a Copy button and an `aria-live` note, 'Shown once. Copy it now.' A Ui:DocsLink 'Check the signature' goes to docs/ALERTS.md.
- Each destination has a 'Send a test' button. It posts to a confirmation step that says 'This sends a test to <label>'. The result appears in a `role=status` region ('Test sent' or 'Failed: the receiver answered 404'). Tests are labelled Test in the recent list and never change a rule's status. This meets the test-alert criterion.
- Recent alerts is a captioned table with `scope` headers: Time (UTC), Rule, Website, Type (Firing, Recovered or Test), Destination, and Result with a plain reason. Example reasons: 'Already alerted for this gap', 'Hour not finished yet', 'No answer after 3 tries'. It shows the last 20 rows and links to Audit trail. Its empty state is 'No alerts yet'. It also has a 'Older entries were removed' state.
- Other states. Flag off: a direct visit shows 'Alerts are off' with a link to Feature flags. An email destination while email is off: a warning with a link to the Email page, and a disabled test button tied to the reason with `aria-describedby`. No rules: 'No alert rules yet' with a primary 'Add your first rule'. Invalid input: a focused `role=alert` summary linking to each field, using the same validator messages as the CLI.
- Keyboard and focus: tab order follows the visual order. After save or delete, focus moves to the success message. Delete is a separate confirmation page, not a script dialog. Every control has a visible label, and help text is linked with `aria-describedby`.
- Acceptance coverage: the rules pages meet the create, edit and delete criterion on the dashboard side. The destinations and test sections meet the webhook, email and test criteria. The status column and recent list show 'one alert, then one recovery'. The menu entry and flag-off state meet the flag and permission criterion.

**Risks**
- Status and the recent list read audit_trail, which Data lifecycle prunes. A pruned history would show 'OK' during an open gap. The page must show 'Unknown' rather than a status it cannot verify. The Architect must say where firing state lives.
- If the page rewrites YAML, hand-written rules and comments can be lost. Rules defined only in YAML may need to show as read-only.
- A passing test alert may make people think rules are working when the scheduler has stopped. The banner should warn when the last Scheduled tasks run is late.

**Open questions**
- Is the page administrator-only? If standard users can edit rules, website choices must follow DashboardAccess, and webhooks and secrets should stay administrator-only. I am designing for administrators now.
- Should the page write rules to YAML, or only show what YAML defines? This decides whether hand-edited rules are editable or read-only on the page.
- Should the email test go only to the signed-in user, as on the Email page, or to any listed recipient?

_By -UX Designer-._

**Second opinion:**

**What I would change or sharpen (most important first)**
- **Break the rule form into three predictable steps so `source` switching cannot cause threshold or filter mistakes:** A single inline sentence breaks down once `source` (`events` vs `tracking_failures`), `filter` (`privacy_mode`, `event_name`), and `window` (`hour`, `day`, `week`) are added, because switching to `tracking_failures` flips the threshold direction (*fewer than N* to *more than N*) and makes filters inapplicable. Instead, show a live plain-language summary banner at the top of the form (*"Alert when **Geektown USA** records **fewer than 5 enhanced events** in **1 week (168 completed UTC hours)**"*) and three stacked fieldsets:
  1. **What to watch (`id`, `covers`, `source`):** Rule ID (`rule` slug, e.g. `geektown-weekly`), covered websites or website group, and source (`Events` or `Tracking failures`).
  2. **Filter which events count (`privacy_mode`, `event_name` — shown only when `source` is `events`):** Privacy mode (`All privacy modes` by default, `Enhanced only (consent granted)`, `Anonymous only`) and optional `Event name` (e.g. `page_view`). When `source` is `tracking_failures`, this fieldset is hidden (`hidden` + disabled inputs) with a short note that tracking failures count `tracking_event_store` and `tracking_ingest` errors across all events.When `Enhanced only` is chosen, show an inline tip: *"If all site traffic stops, this rule also fires. Pair it with an 'All privacy modes' rule to tell a broken consent banner apart from a removed tag."*
  3. **Threshold and window (`condition`, `threshold`, `window`):**
     - When `source = events`: condition choices are `No events (0)` and `Fewer than N events` (which enables the integer `N` input, `min="1"`).
     - When `source = tracking_failures`: condition locks to `More than N tracking failures` (with integer `N` input, `min="0"`), so no one can accidentally set "fewer than N failures".
     - Window choices match the v1 spec: `1 hour (1 completed UTC hour)`, `1 day (24 completed UTC hours)`, and `1 week (168 completed UTC hours)`. Always state the exact hour count in the option label, and keep the UTC timezone reminder below it. Show the quiet-site warning when `1 hour` is chosen on `events`.
- **Let administrators test any listed email recipient in the test confirmation step while keeping `audit_trail` and the main table redacted:** To satisfy AC9 and AC11 together, the Destinations summary table on `/dashboard/alerts` shows only the destination **label** and recipient count (e.g. `Ops email · 3 recipients`) or webhook **host** (`hooks.geektown.example`). Clicking **Send a test** on an email destination opens the confirmation step with a `<select name="recipient">` listing that destination's configured addresses (or `"All listed recipients"` / a specific address) so the administrator can pick which listed recipient gets the test. The resulting `processing_tasks` row, `audit_trail` entry, and **Recent alerts** table still record only the destination label (`Ops email`), never the email address.
- **Protect the Rule ID (`rule`) after creation to avoid orphaned incidents:** Because webhook receivers correlate `firing` and `recovered` alerts on `rule` (and `incident` hashes the rule ID), editing a rule's ID while it is firing would orphan the open incident in Geektown's hub. Make the Rule ID read-only when editing an existing rule, with helper text: *"Used as `rule` in webhook payloads. To change this ID, delete the rule and add a new one."* Also add a standard YAML note above the Save button: *"Saving updates `config/aggregate.yaml`. Hand-written YAML comments are not preserved."*
- **Drop the `'Unknown'` status state:** Since firing state is stored in `processing_tasks.fingerprint` (`ok` or `firing:<since_iso>`) and preserved across retention purges, every enabled rule has a known state: `OK`, `Firing since YYYY-MM-DD HH:00 UTC`, `Waiting for the first full hour`, or `Off`.

_By -UX/UI Designer-._

## Back-end Architect/Engineer

I agree with the Product Owner's scope and the UX/UI Designer's page. My part is where firing state lives, how rules are evaluated and how webhooks are delivered safely. Firing and recovery are worked out from the events table on each run, so they don't depend on audit_trail, which can be pruned, and they don't need an alerts table. Webhooks reuse the public-address-only HTTP client already used in `src/Service/Mcp/OAuth/ClientResolver.php`. Secrets follow the pattern of `EmailSecrets` and `ApiTokenStore`. I found no merged or open PR that builds alerts or webhooks.

**Approach**
- New `src/Service/Alerts/AlertsJob.php` implements `ScheduledJob` (`src/Service/Scheduler/ScheduledJob.php`). It gets an `alerts` entry in `SchedulerSetup` and is due once per completed UTC hour, so the 5-minute scheduler sends an alert within one run after the hour closes. It records each run through `ProcessingTasks` and each send, suppression or failure through `AuditTrail` (`src/Service/Operations/`).
- No stored firing state, which answers the Product Owner's retention risk and the UX/UI Designer's 'Unknown' status. For each completed hour h, a rule is breached when the count over [h−W, h) is below N. It fires when hour h is breached and hour h−1 was not, and recovers in the reverse case. 'Firing since' comes from walking back hour by hour (capped at 7 days). The page and the job use the same `AlertEvaluator`, so a cache clear or an audit_trail prune can't make them disagree.
- Missed runs: the cursor is the last successful `alerts` processing task. Catch-up covers at most 24 hours and sends only the newest transition per rule and website, so a long outage doesn't flood receivers. If the cursor is missing, only the latest hour is evaluated.
- Counting: add `EventRepository::countEventsUpTo(token, from, to, cap)` (`src/Repository/EventRepository.php`) as `SELECT COUNT(*) FROM (SELECT 1 … WHERE website_token=? AND created_at>=? AND created_at<? LIMIT cap)`. It uses the existing (website_token, created_at) index, and the row limit is applied per platform through Doctrine for SQL Server. Capping at N means busy sites are never fully counted. The query runs once per website, like `findLastEventDatesByTokens`. The DBA needs to confirm only that this index serves the query on all five engines. No schema change.
- Config: `AlertSettings` plus one `AlertRuleValidator`, used by the page, `app:alerts:*` commands and YAML loading. Rules and destinations live under `alerts:` in `config/aggregate.yaml`, and website groups resolve through `WebsiteConfigManager`. Webhook secrets go in `config/secrets/alerts.yaml` through a new `AlertSecrets` built on `EmailSecrets` and `SecretFileEncryption`. They're already preserved by `UpdatePaths`.
- Delivery: `WebhookSender` wraps `HttpClientInterface` in `NoPrivateNetworkHttpClient`. It allows HTTPS only, no redirects, a 5 s timeout and reads at most 4 KiB of the response. Headers: `X-Aggregate-Signature: t=…,v1=HMAC-SHA256(t.body)` and an `Idempotency-Key` built from rule, website, hour and type. It makes 3 attempts within the run. A hard failure is recorded and the job moves on. Before sending, it checks audit_trail for a sent record with the same key, so a re-run doesn't send twice. Email goes through `TransactionalMailer` with an `alert` purpose.
- Building on the Product Owner's point about `WebsiteActivityService`: it measures in days, from the last event, and doesn't use thresholds. Alerts reuse only its website list and its UTC basis. The page links to it but doesn't rewrite it.
- Controller `src/Controller/AlertsController.php`: routes go in `DashboardPages` as administrator-only for v1, with a flag in `config/feature_flags` and `shown_when` in `config/navigation.yaml`. Test sends run synchronously, one request each, and are labelled `test`.

**Risks**
- Retries happen inside the run. A receiver that's down across several hours gets only the newest transition, and older ones are recorded as failed. This is deliberate, but it needs to be documented in `docs/ALERTS.md`.
- `NoPrivateNetworkHttpClient` blocks on-prem receivers that Geektown may host internally. An `alerts.allow_private_webhooks` YAML switch, off by default, would be the only exception.
- The derived state depends on recent events staying in `events`. If data lifecycle archives or deletes rows newer than 7 days, past 'firing' would read as OK. The DBA should confirm the minimum retention.
- With many websites times many rules, a run could take a long time. One count query per rule and website per hour is fine at tens of sites, but not at thousands.

**Open questions**
- Should events be counted as all privacy modes together, or anonymous and enhanced separately? A broken consent banner shows up only as a drop in enhanced events, and the answer changes the query and the payload.
- Does Geektown host any webhook receiver on a private network? If so, the private-address exception is needed in v1.
- Are evaluation-only edits allowed through the page, meaning the page writes YAML through the existing settings writer, or are rules read-only there? This decides whether `AlertSettings` needs a writer, which also resolves the UX/UI Designer's question.

_By -Architect-._

**Second opinion:**

**What I would change or dispute**
- **`Scheduler` vs `SchedulerSetup`:** `SchedulerSetup` (`src/Service/Scheduler/SchedulerSetup.php`) only builds cron and Plesk command lines. Jobs are wired as constructor arguments in `src/Service/Scheduler/Scheduler.php` and `config/services.yaml`; register `AlertsJob` there.
- **Deriving transitions only from `[h-W, h)` vs `[h-1-W, h-1)`:** On a multi-hour window (`W > 1`), a site that had events in hour `h-W`, went dead for `W` hours, and gets a single event in hour `h` still falls below `N`, so comparing adjacent windows misses that traffic returned, or re-fires when an earlier spike ages out of the sliding window. Worse, a newly created website or freshly enabled rule has 0 events in `h-1` and `h`, so `[h-1-W, h-1)` is already breached and it will *never* fire. Instead, store the per-`rule:website` state fingerprint (`ok` or `firing:<since_iso>`) in `processing_tasks.fingerprint` (already supported by `ProcessingTasks::succeed()` and preserved across retention purges by `ProcessingTasks::purgeBefore()`), and use `events` only to evaluate the current window.
- **SQL Server subquery:** SQL Server rejects `SELECT TOP (cap) 1` inside a derived table unless you alias the column (`SELECT TOP (cap) 1 AS c FROM events …`). Use `$platform->modifyLimitQuery('SELECT 1 AS c FROM events …', $cap)` inside `SELECT COUNT(*) FROM (…) AS t`.

**What it missed**
- **Redacting webhook URLs on failure:** `Symfony\Component\HttpClient` exception messages include the full request URL. Like `TransactionalMailer::redact()` (`src/Service/Email/TransactionalMailer.php`), `WebhookSender` must strip the webhook URL and secret from exception messages before passing them to `ProcessingTasks`, `AuditTrail`, or logs.
- **Audit trail toggle:** `AuditTrail::record()` skips entries when an area is disabled in `AuditTrailSettings`, unless `$always = true` (`src/Service/Operations/AuditTrail.php`). Alert deliveries Either need `$always = true` or must read recent alerts from `processing_tasks` so turning off an audit switch doesn't break the UI or deduplication.

**What I agree with**
- Using `NoPrivateNetworkHttpClient`, `config/secrets/alerts.yaml` via `SecretFileEncryption`, `TransactionalMailer`, and bounded `IDX_EVENTS_TOKEN_CREATED` lookups without adding an alerts table fits the architecture cleanly.

## To do

_The work this spec plans, as `- [ ] …` items, each naming the role that does it. Whoever finishes an item checks it off in the same commit as the work: `- [x] … (done in JOIN-41)`._

- [ ] Product Owner: revise the anchor `docs/features/event-gap-alerts.md` with the v1 rule shape, sources, filters, windows and the thread's decisions
- [ ] Architect: confirm that tracking-failure counts per window can be read from retained `processing_tasks`, and update the back-end section for sources, filters and day and week windows
- [ ] DBA: confirm the index for counting capped events by `website_token` and `created_at` with optional `privacy_mode` and `event_name` filters on all five engines
- [x] UX/UI Designer: add the source, filter, and day and week windows to the rule form, and show the email test to any listed recipient (done in JOIN-55)
- [ ] Back end: write `AlertSettings`, `AlertRuleValidator`, the YAML writer and the `app:alerts:*` commands
- [ ] Back end: write `AlertEvaluator` and `AlertsJob` with the fingerprint state in `processing_tasks`, wired in `Scheduler` and `config/services.yaml`
- [ ] Back end: write `WebhookSender` (HTTPS only, private addresses blocked, signed, URL redacted from errors) and alert email through `TransactionalMailer`
- [ ] Front end: build the Alerts page, the feature flag, the `shown_when` menu entry and the `DashboardPages` entries
- [ ] Docs: write `docs/ALERTS.md` with the payload contract and signature check, a `DocumentationLinks` topic, an update to `ROADMAP.md`, and an entry in `docs/DESIGN-DECISIONS.md`
- [ ] Tests: privacy and redaction regressions, fire, recover and no-duplicate behavior, and the five engines

_By -Product Owner- (claude-opus-5-5)._
