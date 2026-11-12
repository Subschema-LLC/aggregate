# Alerts

[Scheduled tasks](SCHEDULED-TASKS.md) · [Email](EMAIL.md) · [Feature flags](FEATURE-FLAGS.md) · [Configuration](CONFIGURATION.md)

Aggregate can watch each website or website group for an unexpected drop in
recorded events or a spike in tracking failures, and notify you by signed
generic JSON webhook or email when a completed UTC hour breaches a threshold
and when it recovers.

- [Turn alerts on](#turn-alerts-on)
- [How rules are evaluated](#how-rules-are-evaluated)
- [Rule sources, filters, conditions and windows](#rule-sources-filters-conditions-and-windows)
  - [Catching a broken consent banner](#catching-a-broken-consent-banner)
- [Rules that cannot be checked](#rules-that-cannot-be-checked)
- [YAML configuration](#yaml-configuration)
  - [Signing secrets](#signing-secrets)
- [CLI commands](#cli-commands)
- [Webhook payload v1](#webhook-payload-v1)
- [Check the signature](#check-the-signature)
- [Retries, catch-up and deduplication](#retries-catch-up-and-deduplication)
- [Privacy and redaction](#privacy-and-redaction)

## Turn alerts on

1. Turn on the `alerts` feature flag on **Administration → Feature flags**
   (`/dashboard/feature-flags`) or in `config/aggregate.yaml`:

   ```yaml
   feature_flags:
     alerts:
       enabled: true
       hide_from_navigation: false
   ```

2. Ensure [scheduled tasks](SCHEDULED-TASKS.md) (`php bin/console app:scheduler:run`
   every five minutes) are running on the server. The `alerts` job evaluates
   enabled rules once per completed UTC hour.
3. Open **Administration → Alerts** (`/dashboard/alerts`) or use the
   `app:alerts:*` commands to configure destinations, a webhook signing secret,
   and alert rules.

## How rules are evaluated

Anonymous events are bucketed to the UTC hour when collected, so an in-progress
hour is incomplete by design. Alert rules therefore evaluate only **completed
UTC hours**:

- At UTC hour boundary `T` (minutes and seconds equal to `00`), a rule with
  window `W` (`1h`, `24h`, or `168h`) checks the half-open interval
  `[T − W, T)`.
- For the `events` source, `EventRepository::countEventsUpTo()` counts matching
  rows up to the rule's threshold `N` (or `1` for `no_events`), using the
  `(website_token, created_at)` index so busy websites never scan the full
  window.
- For the `tracking_failures` source, `ProcessingTasks::countTrackingFailuresUpTo()`
  counts failed `tracking_event_store` and `tracking_ingest` rows in
  `processing_tasks` in `[T − W, T)` up to `N + 1`.
- Each rule's per-website state is stored in `processing_tasks.fingerprint`
  (`task_type = 'alerts'`, `subject = '<rule>:<website>'`): `ok`,
  `firing:<since>` or `recovered:<window_end>:<since>`. The state names the
  current alert. Each destination's last delivered alert ID is stored the same
  way (`task_type = 'alerts_delivery'`, `subject = '<rule>:<destination>:<website>'`).
  `ProcessingTasks::purgeBefore()` keeps the latest of each, so retention
  purges never lose them.
- A continuing breach sends **one** `firing` alert, then **one** `recovered`
  alert when a completed window is no longer breached. A destination that
  never received the `firing` alert is not sent its `recovered` alert either.
- A rule that covers a website group or all websites evaluates each member
  website on its own and sends one alert per website.
- A rule that is turned off, or no longer covers a website, closes the open
  incident with a `recovered` alert at the next hourly check (not in the hour
  it fired, so the recovery's `window_end` is always later). A rule that is
  deleted while firing has no destinations left: its state is reset, so a new
  rule with the same ID starts fresh, and the audit trail notes that no
  recovery was sent.

## Rule sources, filters, conditions and windows

| Field | Values | Meaning |
| --- | --- | --- |
| `source` | `events` (default), `tracking_failures` | Whether to count recorded rows in `events` or failed ingest/store attempts in `processing_tasks`. |
| `privacy_mode` | `all` (default), `enhanced`, `anonymous` | Optional filter for the `events` source. Ignored/rejected for `tracking_failures`. |
| `event_name` | Safe event name (e.g. `page_view`) or empty | Optional filter for the `events` source. Leave empty to count all event names. |
| `condition` | `no_events`, `fewer_than`, `more_than` | `no_events` (`0` events) or `fewer_than` (`< N` events) for `events`; `more_than` (`> N` failures) for `tracking_failures`. |
| `threshold` | Integer `N`, at most 100,000 | `0` for `no_events`; `>= 1` for `fewer_than`; `>= 0` for `more_than`. |
| `window` | `hour` (`1h`), `day` (`24h`), `week` (`168h`) | Sliding window of completed UTC hours (`[T − W, T)`). Must not exceed the live retention period. |

### Catching a broken consent banner

When a consent banner breaks and stops granting consent, anonymous events keep
arriving while enhanced events drop to zero. Pair two rules on the same website
or group to distinguish a broken consent banner from a removed tracker script:

1. **Consent banner watch**: `source: events`, `privacy_mode: enhanced`,
   `condition: no_events`, `window: day`. Fires when no consented events arrive
   for 24 completed UTC hours.
2. **Whole-site tracker watch**: `source: events`, `privacy_mode: all`,
   `condition: no_events`, `window: day`. Fires only when all traffic (both
   anonymous and enhanced) stops.

If the first rule fires while the second stays `OK`, the tracker is still
sending anonymous events and the consent signal is what stopped.

Counting stops at `N`, so a busy website is not counted in full. An
`event_name` filter is the exception to watch: no index includes the event
name, so a rule such as "fewer than 5 `purchase` events per week" on a busy
website reads that website's rows for the whole window until it has found
five, every hour. Prefer a day window for rare events on busy websites.

### A cluster server that stops pulling its configuration

In a [cluster](CONFIGURATION.md#configuration-across-servers), the administration
server also alerts when another server has not pulled its configuration for
`cluster_config_max_age_minutes` (5 by default), and again when it pulls
again. These alerts need no rule: they go to `cluster_alert_destinations`, or
every destination, as the same payload with the rule `cluster-config`, an
empty `website` and the server's name as `host`.

## Rules that cannot be checked

A rule keeps loading when something it depends on changes elsewhere, so one
stale rule never stops the others. The Alerts page and `app:alerts:status`
show it as **Not checked**, with the reason, and it is skipped until it is
fixed or deleted:

- the website or website group it covers was removed;
- its window became longer than the live event retention (or
  `processing_tasks_retention_days` for tracking failures);
- it covers a group with no websites.

Saving a rule on the page or with `app:alerts:rule` still checks all of these.

## YAML configuration

Alert destinations and rules live under `alerts` in `config/aggregate.yaml`.
Webhook signing secrets live in `config/secrets/alerts.yaml` or the
environment, never in `config/aggregate.yaml` (see
[signing secrets](#signing-secrets)).
Saving on the Alerts page or via `app:alerts:*` rewrites `config/aggregate.yaml`;
hand-written YAML comments are not preserved.

```yaml
alerts:
  destinations:
    - id: geektown-hub
      label: 'Geektown Hub'
      type: webhook
      url: 'https://hooks.example.com/alerts/ingest'
    - id: ops-email
      label: 'Ops email'
      type: email
      recipients:
        - ops@example.com
        - oncall@example.com
  rules:
    - id: geektown-weekly
      enabled: true
      covers: 'website:019c7e21-89ab-7cde-8f01-23456789abcd'
      source: events
      privacy_mode: all
      condition: fewer_than
      threshold: 5
      window: week
      destinations:
        - geektown-hub
        - ops-email
```

### Signing secrets

A webhook destination signs with its own secret when it has one, and with the
shared secret otherwise:

| Secret | Environment | `config/secrets/alerts.yaml` |
| --- | --- | --- |
| A destination's own | `ALERTS_WEBHOOK_SECRET_<ID>` (ID upper-cased, `-` as `_`) | `webhook_<id>` |
| Shared | `ALERTS_WEBHOOK_SECRET` | `webhook_secret` |

For each, the environment wins over the file. The Signing column on the Alerts
page and in `app:alerts:status` names the secret each destination uses, so
rotating the shared secret is never mistaken for rotating a destination's own.
Set, generate or remove a destination's own secret on its Edit page or with
`app:alerts:destination secret --id=<id>`. Destination IDs that differ only by
`-` and `_` are refused, because they would share a secret.

## CLI commands

Every action on the Alerts page is available from the command line and shares
the same validation:

```bash
# View rules, statuses, redacted destinations, and recent alerts
php bin/console app:alerts:status
php bin/console app:alerts:status --json

# Create, edit, list, or delete destinations and webhook signing secrets
php bin/console app:alerts:destination create --id=geektown-hub --label="Geektown Hub" --type=webhook --url="https://hooks.example.com/alerts/ingest" --generate-secret
php bin/console app:alerts:destination create --id=ops-email --label="Ops email" --type=email --recipient=ops@example.com
php bin/console app:alerts:destination secret --generate-secret                   # shared secret
php bin/console app:alerts:destination secret --id=geektown-hub --generate-secret   # its own secret
php bin/console app:alerts:destination secret --id=geektown-hub --use-shared        # back to the shared one

# Create, edit, list, or delete rules
php bin/console app:alerts:rule create --id=geektown-weekly --website=019c7e21-89ab-7cde-8f01-23456789abcd --source=events --condition=fewer_than --threshold=5 --window=week --destination=geektown-hub
php bin/console app:alerts:rule edit --id=geektown-weekly --threshold=10
php bin/console app:alerts:rule delete --id=geektown-weekly

# Send a test alert (does not change any rule's status)
php bin/console app:alerts:test geektown-hub
php bin/console app:alerts:test ops-email --recipient=ops@example.com

# Evaluate completed UTC hours now
php bin/console app:alerts:evaluate
```

## Webhook payload v1

Each webhook request is an HTTPS `POST` with `Content-Type: application/json`,
`Idempotency-Key: <alert.id>`, and `X-Aggregate-Signature: t=<unix>,v1=<hmac>`.
The body is a JSON array containing one alert object:

```json
[
  {
    "schema_version": "1",
    "id": "a1b2c3d4e5f60718293a4b5c",
    "incident": "9f8e7d6c5b4a392817263544",
    "type": "firing",
    "rule": "geektown-weekly",
    "website": "019c7e21-89ab-7cde-8f01-23456789abcd",
    "host": "some.domain.com",
    "installation": "https://analytics.example.com",
    "alert_condition": "fewer_than_5_per_week",
    "window_end": "2025-06-02T14:00:00Z"
  }
]
```

| Field | Description |
| --- | --- |
| `schema_version` | Always `"1"` for this contract. |
| `id` | Stable idempotency key for this transition (same across retries of the same transition; fresh on every `test` alert). |
| `incident` | Shared identifier linking a `firing` alert and the `recovered` alert that closes the same gap. |
| `type` | `"firing"`, `"recovered"`, or `"test"`. |
| `rule` | Rule ID slug from `alerts.rules`. |
| `website` | Website token from `config/websites.yaml`. |
| `host` | Bare website hostname (no scheme or path). |
| `installation` | Normalized `app_host` of this Aggregate installation (lowercase scheme and host, no trailing slash). |
| `alert_condition` | Readable condition summary (`no_events_per_<window>`, `fewer_than_<N>_per_<window>`, or `more_than_<N>_tracking_failures_per_<window>`). |
| `window_end` | Completed UTC hour boundary (`YYYY-MM-DDTHH:00:00Z`). Order alerts per `(rule, website)` by `window_end`, not arrival time. |

## Check the signature

Aggregate signs every webhook attempt with HMAC-SHA256 using the secret in
`config/secrets/alerts.yaml` (or `ALERTS_WEBHOOK_SECRET`). The
`X-Aggregate-Signature` header has the form:

```text
X-Aggregate-Signature: t=1748872800,v1=5d41402abc4b2a76b9719d911017c592...
```

To verify a webhook in your receiver:

1. Split `X-Aggregate-Signature` on `,` and read the integer timestamp `t` and
   hex digest `v1`.
2. Reject the request if `t` is more than **5 minutes (300 seconds)** away from
   your server's current UTC clock. Keep NTP enabled on both servers so clock
   skew does not reject valid deliveries.
3. Compute `hash_hmac('sha256', $t . '.' . $rawBody, $secret)` over the exact
   raw request body bytes.
4. Compare the computed hex digest with `v1` using a constant-time comparison
   (`hash_equals()` in PHP, `hmac.compare_digest()` in Python,
   `crypto.timingSafeEqual()` in Node.js).
5. De-duplicate deliveries by `id` (or the `Idempotency-Key` header) and order
   state transitions per `(rule, website)` by `window_end`.

## Retries, catch-up and deduplication

- **Within a run**: `WebhookSender` allows up to 5 seconds per attempt, follows
  no HTTP redirects and reads at most 4 KiB of the response body. It makes up
  to 3 attempts, waiting 1 and then 3 seconds, and only retries what can
  succeed later: no answer, a 5xx, 408, 425 or 429. A redirect or another 4xx
  (such as 401 or 404) fails at once. Each attempt signs a fresh `t` timestamp
  while keeping the same `id` and `Idempotency-Key`.
- **Across runs**: an alert a destination did not receive is retried at each
  hourly check until its `window_end` is 24 hours old, then given up; the
  Recent alerts list says which. A newer alert for the same rule and website
  replaces it. An email destination counts as delivered when at least one
  listed recipient got the message, so working recipients are not sent it
  again.
- **Missed scheduler runs**: If the scheduler was stopped and resumes later,
  `AlertEvaluator` catches up across at most 24 completed UTC hours and sends
  only the net change per rule and website: an incident that opened and closed
  during the outage sends nothing, and one that closed and opened again sends
  the closing `recovered` and then the new `firing`.
- **Deduplication**: a destination's delivery record holds the last alert ID it
  received, so an alert is never sent to it twice. If the process stops between
  a send and that record, the alert is sent again at the next check with the
  same `id`, so receivers should de-duplicate on `id`. Every delivery is
  recorded in `audit_trail` with `always: true`; a continuing breach adds no
  rows, so Recent alerts keeps showing the alerts that were sent.
- **Scheduling**: the `alerts` job runs first in each scheduler run, so a long
  maintenance run or sync does not delay it.

## Privacy and redaction

- Alert payloads, emails, logs, `processing_tasks`, and `audit_trail` contain
  only rule metadata and completed UTC hour timestamps — never event rows, page
  paths, custom property values, or visitor data.
- Webhook URLs are validated to require `https://` and block localhost,
  loopback, link-local, and private network addresses both at configuration time
  and on connection (`NoPrivateNetworkHttpClient`).
- The Alerts page shows only the webhook **host** or the email **recipient
  count**. Full webhook URLs, recipient email addresses, and signing secrets are
  redacted from logs, task details, and audit trail entries, including error
  messages.
