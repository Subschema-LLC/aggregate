# Privacy Deployment Guide

Aggregate Analytics provides technical privacy controls. Installing it does not, by itself, make a website compliant with GDPR, CCPA/CPRA, ePrivacy, PECR, COPPA, or any other law. The operator must determine the appropriate legal basis, disclosures, retention rules, consent behavior, and rights-request process for each deployment.

This document is engineering guidance, not legal advice.

## Measurement model

All measurements use the `events` table and are separated explicitly by `privacy_mode`.

| Consent state | Stored mode | Page views and named events | IDs and enhanced details |
| --- | --- | --- | --- |
| Unknown | `anonymous` | One coarse, UTC-hour-bucketed row per event | Omitted |
| Enhanced analytics rejected | `anonymous` | One coarse, UTC-hour-bucketed row per event | Omitted; existing SDK IDs are removed |
| Enhanced analytics granted | `enhanced` | One detailed row per event | Visitor/session IDs, properties, goals, and exact dimensions may be stored |

There is no visitor-facing "off" mode in the SDK. Coarse page-view and named-event rows continue after enhanced analytics is rejected or withdrawn. An administrator can disable collection globally or exclude sensitive paths.

Use consent language that matches this behavior. A button may say **Reject enhanced analytics** or **Use privacy-minimized analytics**. Do not claim that a generic **Reject analytics** button stops all measurement if anonymous-mode events will continue.

## Anonymous-mode events

Before an anonymous-mode event leaves the browser, the SDK:

- accepts only fixed event names matching `[A-Za-z][A-Za-z0-9_.:-]{0,99}`, such as `view`, `button_click`, or `ui:menu_open`, and rejects identifier-like names;
- uses `location.pathname`, never the full URL;
- omits query strings and fragments;
- redacts path segments resembling email addresses, UUIDs, numeric route IDs, hex IDs, or opaque tokens;
- converts the referrer to a coarse channel: `direct`, `internal`, `search`, `social`, `email`, `referral`, or `unknown`;
- sends only coarse device and viewport buckets;
- sends no visitor ID, session ID, cookie value, custom property, goal, raw referrer, or exact screen width; and
- requests the collection endpoint with a `no-referrer` policy so the browser does not attach the page URL as an HTTP `Referer`.

The server enforces this allowlist again. Client behavior is not a security boundary because callers can construct requests without using the SDK.

Each accepted event is stored as an individual `events` row with `privacy_mode = 'anonymous'`. The server sets `created_at` to the start of the current UTC hour; the client cannot supply it. Exact event timestamps are not used for anonymous-mode rows. The row retains the safe event name, sanitized path, coarse referrer channel, device class, viewport bucket, and—only when the operator enables it—coarse `geo_area`. Enhanced-only columns remain null.

The word `anonymous` describes the product mode, not a guaranteed legal classification. An hour-bucketed row can still be personal data in context—for example, because a path is unique, an event is rare, a population is small, or the operator can combine it with outside information. Treat the raw `events` table as private and assess the deployment before describing its data as anonymous.

### Event-name and path limitations

Event names are analytics dimensions, not a place for user-entered values. Use a fixed taxonomy such as `navigation_click` or `checkout_started`; never construct a name from an email address, search term, form value, record ID, or free text.

Generic path redaction cannot recognize every username, account number, document title, medical term, or other sensitive value an application may put in a URL. Avoid personal data in URLs and add sensitive route families to `anonymous_excluded_paths`, for example:

```yaml
anonymous_excluded_paths:
  - /account/*
  - /checkout/**
  - /patients/**
  - /reset-password/*
```

Review application routes before enabling measurement. Exclusions are enforced by the server for both privacy modes, including callers that bypass the SDK.

## Optional coarse geography

Geography is disabled by default. When enabled, the ingestion boundary uses the request IP transiently with a local GeoLite2-Country-compatible MMDB and retains only one normalized value:

- `continent:XX` for `anonymous_geo_level: macro_region` (recommended); or
- `country:XX` for `anonymous_geo_level: country`.

The server first requires a valid public IP. Private, reserved, malformed, and unsupported addresses produce no geography. The raw IP and full MMDB record are not put in the event, message queue, or application error logs. Missing or unreadable database files and lookup failures also leave `geo_area` empty without rejecting the analytics event. Aggregate does not bundle or download the MMDB and does not contact a hosted geolocation service; the operator must follow the database provider's license, attribution, and update terms. City, subdivision/state, postcode, latitude, and longitude are never retained.

The coarse area may be stored on accepted anonymous and enhanced events. The prefixes make historical rows interpretable if the configured level changes. Keep the MMDB current, mount it read-only, and grant read access only to the ingestion process. Configure trusted proxies narrowly and require them to strip or overwrite client-supplied forwarding headers; otherwise geography can be spoofed.

The value represents approximate network-exit geography, not a person's precise residence or physical location. VPNs, mobile carriers, corporate gateways, and database errors can map a request to the wrong area. Do not use or describe this field as precise location data.

This design minimizes retained data; it does not make the lookup legally invisible. An IP address is personal data in many contexts, even when used only transiently, and a country or continent can increase singling-out risk when combined with a rare event, small population, path, or time bucket. Document the purpose and legal basis, update notices and records of processing, and consult counsel for the deployment. Prefer macro-region and leave geography off on sensitive or low-traffic sites.

## BI exposure and suppression

Routine Tableau and Power BI users should query approved views, not the raw `events` table. Enforce that boundary with view-only grants on PostgreSQL, MySQL, MariaDB, or SQL Server. SQLite has no table/view privilege system, so giving a user or desktop BI tool its database file also exposes raw `events`; use a controlled export of approved view results or a server database instead. `bi_anonymous_events_v1` exposes:

- `website_token`
- `event_hour`
- `event_name`
- `page_path`
- `referrer_channel`
- `device_class`
- `viewport_bucket`
- `event_count`

The view groups anonymous-mode rows into hourly cells and exposes a cell only when `event_count` reaches `anonymous_min_cell_count`. It withholds the current UTC hour, so a released cell is normally immutable and cannot be polled for each new event. The default threshold is `5`; the allowed range is `2`–`1000`.

Cell suppression reduces the exposure of rare combinations but does not prove anonymity. Restrict the raw table, review the available dimensions, and consider a higher threshold for low-traffic or sensitive sites.

When coarse geography is enabled, use the separate `bi_anonymous_geo_events_v1` view. It exposes only:

- `website_token`
- `event_day`
- `event_name`
- `geo_area`
- `event_count`

It groups anonymous-mode rows into UTC-day cells, withholds the current UTC day, and applies `anonymous_geo_min_cell_count` (default `25`, range `10`–`1000`). Page path, referrer, device, viewport, and identifiers are deliberately absent. Do not join row-level geography into the detailed hourly view.

Areas below the threshold are pooled as `country:other` or `continent:other`, and the pool is released only when its combined event count also reaches the threshold. If exactly one area is suppressed, the view pools the smallest otherwise-visible area too. This secondary suppression makes direct subtraction from a partition total more difficult while preserving a useful coarse remainder.

Both thresholds count events, not distinct people, because anonymous-mode events intentionally have no stable person identifier. A single person can generate enough events to meet a threshold. Secondary suppression cannot prevent inference across every extract, time period, or outside data source. Suppression therefore does not establish k-anonymity. Use the minimum necessary BI access, consider a threshold above the default, review extracts for small populations, and do not present either view as guaranteed anonymous data.

## Administrative controls

```yaml
anonymous_tracking_enabled: true
anonymous_excluded_paths: []
anonymous_geo_enabled: false
anonymous_geo_level: macro_region
anonymous_geo_database_path: ""
```

- `anonymous_tracking_enabled: false` is the global collection kill switch for both privacy modes.
- `anonymous_excluded_paths` lists paths or recursive globs rejected in both privacy modes.
- `anonymous_geo_enabled` opts into local coarse geography for accepted events; a database path by itself does not enable it.
- `anonymous_geo_level` accepts `macro_region` (continent-level) or `country`.
- `anonymous_geo_database_path` points to a locally managed GeoLite2-Country-compatible MMDB. An absolute path is recommended; a relative path must resolve inside the project root. URI/stream schemes, relative escapes, UNC/network shares, and Windows device paths are rejected.

Only administrators can change these runtime controls through the CSRF-protected dashboard form. For API-only deployments, manage them in `config/aggregate.yaml` and restrict write access to that file.

The BI disclosure thresholds are stored directly in the singleton `analytics_privacy_settings` database row and have their own CSRF-protected admin form:

- `anonymous_min_cell_count` controls grouped hourly cells exposed by `bi_anonymous_events_v1` (default `5`, range `2`–`1000`).
- `anonymous_geo_min_cell_count` controls completed daily cells in `bi_anonymous_geo_events_v1` (default `25`, range `10`–`1000`).

Both views read the database row directly, so dashboard changes take effect immediately without a YAML mirror or synchronization command. The migrations create the row with safe defaults. API-only operators must use controlled database administration to change it, and routine BI roles must remain read-only.

Malformed YAML or invalid ingestion-control types fail closed: ingestion is disabled and the health endpoint reports a generic configuration error. Out-of-range database thresholds also fail closed because the views require values within their documented ranges.

## Enhanced analytics consent

Enhanced analytics may use:

- `aggregate_session`, a 30-minute `SameSite=Lax` session cookie with `Secure` on HTTPS;
- a visitor ID in `localStorage`;
- a session ID in `sessionStorage` and the session cookie;
- exact screen width;
- custom properties and goal names;
- generalized browser/device information; and
- an exact server event timestamp.

Consent is not a reason to accept arbitrary payloads. Use a documented property allowlist and avoid form contents, emails, account identifiers, search terms, or other free text unless the deployment has a specific, reviewed need and legal basis.

Enable these fields only after an affirmative choice:

```javascript
window.Aggregate.setConsent(true);
```

Reject or withdraw enhanced analytics with:

```javascript
window.Aggregate.setConsent(false);
```

That call immediately removes the Aggregate visitor ID, session ID, and session cookie from the browser. Later `emit(...)` calls continue to create coarse, hour-bucketed anonymous-mode rows, but their properties and goals are omitted. It does **not** delete data previously collected by the server. Do not describe it as a deletion request or promise that it erases all data.

The SDK does not persist the consent choice itself. Your consent-management platform should remember and communicate the visitor's current choice.

## Consent-manager integration

Initialize enhanced consent only from a recorded affirmative choice:

```html
<script>
  // Adapt this lookup to your CMP. Return true/false for a recorded choice and
  // undefined when the visitor has not chosen yet.
  var priorEnhancedConsent = consentManager.getConsent('enhanced-analytics');
  window.Aggregate = {
    endpoint: 'https://analytics.example.com/api/receive',
    websiteToken: 'your-website-token'
  };
  if (typeof priorEnhancedConsent === 'boolean') {
    window.Aggregate.consent = priorEnhancedConsent;
  }
</script>
<script src="https://analytics.example.com/aggregate.js" defer referrerpolicy="no-referrer"></script>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    consentManager.onChange('enhanced-analytics', function (granted) {
      window.Aggregate.setConsent(granted);
    });
  });
</script>
```

Adapt the API calls to your consent manager. Test these cases in a new browser profile:

1. Before a choice, a page view and `emit('button_click', {...})` create anonymous-mode rows with no IDs or properties.
2. Anonymous `created_at` values are UTC hour boundaries rather than exact event times.
3. Rejecting creates no Aggregate cookie, `localStorage` value, or `sessionStorage` value.
4. Named events continue after rejection without properties or goals.
5. Accepting creates IDs and permits enhanced event details.
6. Withdrawing removes all three browser-side identifiers and strips details from later events.
7. Globally disabling collection or visiting an excluded route produces no event row.

## Suggested notice language

Adapt this text to the deployment and have counsel review it:

> We use self-hosted analytics to understand page usage and named interactions such as button clicks. Unless you accept enhanced analytics, each event is limited to a fixed event name, sanitized page path, coarse traffic-source and device categories, [a country/continent category derived locally from the request IP, if enabled], and a server-generated UTC hour bucket. The analytics event does not retain the source IP, use an analytics cookie or visitor/session identifier, or include event properties and goals. This privacy-minimized measurement continues when enhanced analytics is rejected, except on routes we exclude from measurement.
>
> If you accept enhanced analytics, we also use short-lived session information, a returning-visitor identifier, custom interaction details, and exact server timestamps. You can withdraw that consent at any time. Withdrawal stops future enhanced collection and removes analytics identifiers from this browser; it does not automatically erase records already collected. Contact us at [privacy contact] to exercise applicable privacy rights.

Do not call anonymous-mode rows "completely anonymous." Hour bucketing and identifier removal reduce risk, but the operator must assess paths, event names, traffic volume, access, logs, and reasonably available means of relating data to a person.

## Infrastructure and logging

The HTTP request passes through a network stack even when the stored analytics row is privacy-minimized. Reverse proxies, CDNs, web servers, WAFs, load balancers, tracing systems, and application logs may independently record IP addresses, User-Agent strings, exact request times, or headers.

The built-in abuse limiter derives a keyed, per-minute bucket from the request IP and opportunistically removes expired files. It does not put the raw address into analytics, but operators should still treat rate-limit storage as short-lived security metadata and prefer an upstream limiter with enforced TTLs at scale.

If coarse geography is enabled, the application also reads the request IP long enough to perform a local MMDB lookup. No analytics implementation can erase IP handling performed independently by the network stack or upstream systems. Do not use a hosted lookup API without separately assessing its recipient/transfer, contract, security, retention, and consent implications.

For the collection endpoint:

- disable or redact access logs where practical;
- never log request bodies or analytics identifiers;
- set short, documented log retention;
- restrict log and database access;
- encrypt backups and define their deletion lifecycle;
- configure TLS;
- audit BI extracts and exports; and
- verify that queues, error handlers, and monitoring tools do not retain rejected payload fields.

The application secret used for security controls must be unique and protected. It must not be used to create event-level visitor fingerprints.

## Retention and privacy rights

Define retention separately for:

- anonymous-mode event rows;
- enhanced event rows;
- BI extracts and caches;
- queues and failed messages;
- logs and traces; and
- backups.

Anonymous-mode rows intentionally contain no visitor lookup key, so the system cannot reliably find them by visitor ID. That does not automatically remove them from privacy-law scope. Document the limitation and establish a rights-request approach appropriate to the deployment.

Enhanced rows may be linkable through visitor or session IDs. Operators should provide authenticated procedures for applicable access, deletion, correction, portability, restriction, objection, and opt-out requests. `setConsent(false)` is a prospective consent control, not that server-side workflow.

## Operator checklist

- [ ] Inventory every anonymous and enhanced field actually collected.
- [ ] Use a fixed, reviewed event-name taxonomy with no user-derived values.
- [ ] Review paths for personal or sensitive data and configure exclusions.
- [ ] Decide whether anonymous-mode measurement is appropriate in each jurisdiction and context.
- [ ] Document the legal basis for each processing purpose.
- [ ] Use consent wording that distinguishes privacy-minimized measurement from enhanced analytics.
- [ ] Make acceptance and rejection of enhanced analytics equally clear.
- [ ] Do not enable enhanced analytics before affirmative consent where consent is required.
- [ ] Publish retention periods and implement their enforcement.
- [ ] Provide a server-side rights-request process for enhanced data.
- [ ] Restrict routine BI users to `bi_anonymous_events_v1`; keep raw `events` private.
- [ ] Validate `anonymous_min_cell_count` against traffic volumes and re-identification risk.
- [ ] If geography is enabled, document its legal basis and notice, prefer macro-region, and verify that only the local MMDB is used.
- [ ] Restrict geography BI users to `bi_anonymous_geo_events_v1` and validate `anonymous_geo_min_cell_count`; remember that it counts events, not people.
- [ ] Review proxy, CDN, application, queue, error, and backup retention.
- [ ] Execute data-processing agreements where required.
- [ ] Perform a DPIA or other risk assessment where required.
- [ ] Apply appropriate rules for children and sensitive contexts before collection.
- [ ] Re-test consent and privacy controls after every SDK, route, or schema change.

## Frequently asked questions

### Does anonymous-mode measurement require consent?

There is no universal answer. The design removes browser IDs, properties, goals, raw URLs/referrers, and exact analytics timestamps, but it still stores individual hour-bucketed event rows. The operator must assess applicable law, regulator guidance, purpose, context, infrastructure metadata, and promises made to visitors. Consult qualified counsel.

### What does Reject mean?

It must mean **reject enhanced analytics**. The SDK removes browser identifiers and omits properties, goals, exact dimensions, and exact analytics timestamps, while coarse hour-bucketed event rows continue. An interface promising to reject all analytics is incompatible with this model unless the site declines to load the SDK for that visitor or the operator disables collection.

### Can an anonymous-mode event be personal data?

Yes, contextually. A sanitized path, rare event name, hour bucket, small population, or outside information may allow a person to be singled out or related to the row. Use exclusions, restricted raw-table access, retention limits, BI grouping, and low-count suppression.

### Is country or continent data anonymous?

Not inherently. A coarse area is less identifying than city, postcode, or coordinates, but the source IP is processed during lookup and the retained area can become identifying in combination with other facts. Prefer continent-level macro-regions, use the dedicated daily suppressed view, and assess the actual traffic population and context. Do not claim that the threshold proves anonymity: it counts events rather than distinct people.

### Does withdrawing consent delete past data?

No. `setConsent(false)` removes browser-side identifiers and stops future enhanced collection. Handle deletion or other rights requests through the operator's authenticated server-side process.

### Is self-hosting sufficient for compliance?

No. Self-hosting provides control over infrastructure and recipients, but the operator still needs an appropriate legal basis, transparency, security, retention, consent behavior, rights handling, and organizational controls.
