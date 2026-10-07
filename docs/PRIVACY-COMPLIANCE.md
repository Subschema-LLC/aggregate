# Privacy and compliance guide

Aggregate Analytics provides technical privacy controls. Installing it does not, by itself, make a website compliant with GDPR, CCPA/CPRA, ePrivacy, PECR, COPPA, or any other law. The operator must determine the appropriate legal basis, disclosures, retention rules, consent behavior, and rights-request process for each deployment.

This document is engineering guidance, not legal advice. For conservative
per-region example configurations and their limits, see
[Regional consent examples](CONSENT-REGIONS.md).

[README](../README.md) · [Configuration](CONFIGURATION.md) · [Tracking and GTM](TRACKING.md) · [Contributing](../CONTRIBUTING.md)

- [Measurement model](#measurement-model)
- [Strict collection profile](#strict-collection-profile)
- [Anonymous-mode events](#anonymous-mode-events)
- [Custom properties and UTM consent](#custom-properties-and-utm-consent)
- [Optional page depth](#optional-page-depth)
- [Organization traffic](#organization-traffic)
- [Optional coarse geography](#optional-coarse-geography)
- [Stored data and reporting queries](#stored-data-and-reporting-queries)
- [BI exposure and suppression](#bi-exposure-and-suppression)
- [Administrative controls](#administrative-controls)
- [Consent and consent-manager integration](#enhanced-analytics-consent)
- [Independent consent controls and regional examples](#independent-consent-controls-and-regional-examples)
- [Suggested notice language](#suggested-notice-language)
- [Infrastructure and logging](#infrastructure-and-logging)
- [Retention and privacy rights](#retention-and-privacy-rights)
- [Upgrading older installations](#upgrading-older-installations)
- [Operator checklist](#operator-checklist)
- [Frequently asked questions](#frequently-asked-questions)

## Measurement model

All measurements use the `events` table and are separated explicitly by `privacy_mode`.

| Consent state | Stored mode | Page views, named events, and configured goals | IDs and enhanced details |
| --- | --- | --- | --- |
| Unknown | `anonymous` | One coarse, UTC-hour-bucketed row per event; permitted goals and explicitly configured consent-free properties may be retained | IDs, properties requiring consent, and exact dimensions are omitted |
| Enhanced analytics rejected | `anonymous` | Same anonymous event and goal behavior | Enhanced fields are omitted; existing SDK IDs are removed |
| Enhanced analytics granted | `enhanced` | One detailed row per event; enabled allowlisted goals may be retained | Visitor/session IDs, custom properties, and exact dimensions may be stored |

There is no visitor-facing "off" mode in the SDK. Coarse page-view and named-event rows, including configured goals and properties permitted anonymously, continue after enhanced analytics is rejected or withdrawn. An administrator can disable collection globally or exclude sensitive paths.

Use consent language that matches this behavior. A button may say **Reject enhanced analytics** or **Use privacy-minimized analytics**. Do not claim that a generic **Reject analytics** button stops all measurement if anonymous-mode events will continue.

### Insight without visit or session IDs

Coarse channels, sanitized page paths, named events and permitted goals support
useful measurement without a visit, visitor or session ID. For example, compare
channel activity on key pages, count interactions with calls to action, and track
changes in daily goal totals. Reviewed broad `utm_medium` values can add context
when explicitly allowed for anonymous collection; all UTMs require consent by default.

Here, **pathing** means aggregate page activity and fixed navigation events an
operator chooses to instrument. These aggregate counts do not establish a person's
ordered journey or attribute a later goal to an earlier arrival. URL medium is
read on the current page, with no automatic carry-over to later pages. Counts
measure event occurrences; one person can contribute several.

Optional [page depth](#optional-page-depth) adds a capped count of tracked pages
to each event, helping distinguish early-page interactions from deeper activity
without assigning a visitor or session ID.

Today, approved `bi_anonymous_*` views provide completed, thresholded event counts
by path and channel, and separate daily goal counts. Goals have no path/channel
breakdown in these views. Medium remains in private retained-event/custom-property
reporting and is absent from approved anonymous BI views and archive aggregates.
The [roadmap](../ROADMAP.md#proposals-to-explore) proposes examples and reviewed
reporting extensions for this approach. Preserve the
[BI disclosure boundaries](#bi-exposure-and-suppression); do not join existing
views to recover withheld detail.

## Strict collection profile

Set `collection_profile: strict` to reduce collection to what reporting a page view or named event requires. The default `standard` profile keeps the behavior described in the rest of this guide.

| | Standard | Strict |
| --- | --- | --- |
| Stored privacy mode | `anonymous`, or `enhanced` after consent | Always `anonymous`; consent choices are ignored |
| Kept for each event | Path, event name, goal, UTC hour, coarse referrer/device/viewport, optional geography, permitted properties | Sanitized path, event name, approved goal and UTC hour only |
| `referrer_channel`, `device_class`, `viewport_bucket` | Coarse categories | Stored as `unknown` |
| Geography | Optional local lookup | No lookup |
| Custom properties, page depth, organization marker | As configured | Not collected |

**In the browser**, the served tracker reads the current pathname to build the sanitized path and sends one request per event with credentials omitted and no referrer. It does not read screen or window size, `document.referrer` or the query string. It does not read, write or remove cookies, `localStorage` or `sessionStorage`, does not rewrite links or the address bar, and never sends consent state, identifiers or the organization marker. Identifiers or counters left in a browser by an earlier standard-profile visit are not removed, because removing them would itself touch storage; they are never read or sent.

**On the server**, strict applies to every request, including stale scripts and direct API calls. Submitted consent states, identifiers, properties, page depth, markers, referrers, device and viewport values are discarded, the User-Agent header is not classified, and no geographic lookup runs. The kill switch, path exclusions, website domain rules and the short-lived per-IP rate-limit bucket still apply.

**Configuration.** Choose the profile under **Collection controls**, in the active YAML configuration, or with the `COLLECTION_PROFILE` environment variable, which takes precedence. An unrecognized value fails closed: ingestion stops, and a served tracker treats anything other than `standard` as strict. Custom properties, page depth, geography and enhanced settings stay saved while strict is selected, so switching back restores them.

**Static and CDN copies** of `aggregate.js` have no injected profile and default to standard. Opt them in with `data-collection-profile="strict"`, an inline `collectionProfile: 'strict'`, or `configure({collectionProfile: 'strict'})`; page configuration can never loosen a served strict profile. The server enforces strict either way, but only a strict-configured script avoids the browser reads listed above.

**Other scripts are separate.** The optional consent drop-in, tag manager loader and organization-marker page keep their own documented storage. Strict changes only the Aggregate tracker and ingestion; review any consent tool or tags you still load.

**Reporting.** The BI contract is unchanged. From the switch onward, referrer, device and viewport are `unknown` and enhanced rows stop, so cells merge and fewer are withheld. Mark the switch date in reports that compare periods.

**What strict does not establish.** Strict minimizes what the tracker touches on a device; it does not make measurement consent-exempt everywhere. Under the EU ePrivacy Directive, the "strictly necessary" exemption concerns a service the visitor explicitly requested, and the EDPB treats scripts that instruct a browser to send information, including a request carrying the page path, as within Article 5(3) ([EDPB Guidelines 2/2023](https://www.edpb.europa.eu/system/files/documents/2024-10/edpb_guidelines_202302_technical_scope_art_53_eprivacydirective_v2_en_0.pdf)). Some regulators provide narrower audience-measurement exemptions with their own conditions, such as the [CNIL's](https://www.cnil.fr/fr/cookies-solutions-pour-les-outils-de-mesure-daudience) in France and the UK statistical-purposes exception added to PECR by the Data (Use and Access) Act 2025, which requires clear information and a simple, free way to object. Aggregate does not yet provide a visitor objection control; see the [roadmap](../ROADMAP.md). A site's own backend can also send anonymous events, so that no script runs on the device; see [server-side collection](SERVER-SIDE.md#does-it-avoid-the-need-for-consent). Treat strict as an input to the operator's assessment, not a conclusion.

## Anonymous-mode events

Before an anonymous-mode event leaves the browser, the SDK:

- accepts only fixed event names matching `[A-Za-z][A-Za-z0-9_.:-]{0,99}`, such as `view`, `button_click`, or `ui:menu_open`, and rejects identifier-like names;
- uses `location.pathname` for the page path;
- omits full query strings and fragments, extracting only configured query parameters whose destination properties permit anonymous collection;
- sends `cd.*` values from its own script URL with the first page view only when their properties permit anonymous collection (those values reach the analytics host in the script request whatever the consent state, so they should describe the page, not the visitor);
- redacts path segments resembling email addresses, UUIDs, numeric route IDs, hex IDs, or opaque tokens;
- converts the referrer to a coarse channel: `direct`, `internal`, `search`, `social`, `email`, `referral`, or `unknown`;
- sends only coarse device and viewport buckets;
- may send one requested fixed goal code for server-side allowlist validation;
- reads the configured team marker and sends only the boolean `org_internal_traffic`, `true` or `false`; it writes the marker only when a team member follows a [marking link](#organization-traffic);
- includes ordinary custom properties only when their model definition has `consent_required: false`, and generated page depth only when its separate setting is enabled;
- sends no visitor ID, session ID, cookie value, raw referrer, or exact screen width; and
- requests the collection endpoint with a `no-referrer` policy so the browser does not attach the page URL as an HTTP `Referer`.

The server enforces the event-name rules, configured goal allowlist, and per-property consent rules again. Client behavior is not a security boundary because callers can construct requests without using the SDK.

Each accepted event is stored as an individual `events` row with `privacy_mode = 'anonymous'`. The server sets `created_at` to the start of the current UTC hour; the client cannot supply it. Exact event timestamps are not used for anonymous-mode rows. The row retains the safe event name, sanitized path, coarse referrer channel, device class, viewport bucket, an optional allowlisted goal permitted for anonymous use, and—only when the operator enables it—coarse `geo_area`. Its `custom_data` may contain explicitly permitted properties and the `org_internal_traffic` flag. Identifiers, exact dimensions, and generalized User-Agent values remain null.

The word `anonymous` describes the product mode, not a guaranteed legal classification. An hour-bucketed row can still be personal data in context—for example, because a path is unique, an event is rare, a population is small, or the operator can combine it with outside information. Treat the raw `events` table as private and assess the deployment before describing its data as anonymous.

### Event-name and path limitations

Event names are analytics dimensions, not a place for user-entered values. Use a fixed taxonomy such as `navigation_click` or `checkout_started`; never construct a name from an email address, search term, form value, record ID, or free text. The same applies to [tracking attributes](TRACKING.md#track-clicks-and-forms-with-data-attributes) written into page templates: the tracker reads only those attributes, never an element's text, link addresses or form fields, so whatever a template puts in them is what is sent.

Goal names are also analytics dimensions. `config/goals.yaml` is the server-side source of truth: each YAML key is the stable stored code, `enabled` controls future collection, and `anonymous` controls whether that code may be retained in anonymous mode. Payload matching is exact and case-sensitive; whitespace and case variants are not normalized. Unknown, invalid, disabled, and anonymous-disallowed goals are removed while the underlying event is still accepted. The API returns only the generic advisory code `goal_not_allowed`; the SDK writes a generic console warning and never echoes the rejected value.

The goal allowlist is a guardrail, not a sensitive-data detector. A configured code such as `booking` may still reveal sensitive behavior when combined with a medical path, small audience, or time bucket. Never derive a goal from user input or identifiers. Review each goal's purpose and context, use `anonymous: false` when enhanced consent is appropriate, and exclude sensitive routes entirely when even a coarse event or goal would be excessive.

Generic path redaction cannot recognize every username, account number, document title, medical term, or other sensitive value an application may put in a URL. Avoid personal data in URLs and add sensitive route families to `anonymous_excluded_paths`, for example:

```yaml
anonymous_excluded_paths:
  - /account/*
  - /checkout/**
  - /patients/**
  - /reset-password/*
```

Review application routes before enabling measurement. Exclusions are enforced by the server for both privacy modes, including callers that bypass the SDK.

## Custom properties and UTM consent

Administrators configure the custom data model in YAML or **Data model**. A property's `consent_required` defaults to `true`; setting it to `false` permits that key in anonymous-mode events, including after rejection or withdrawal. The rule applies to `emit()` properties, to properties from tracking attributes and to mapped URL parameters. The SDK filters before transmission and the server independently filters before persistence. Collection changes affect future events; changing a definition does not erase historical values.

The six standard UTM properties—`utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`, and `utm_id`—all require consent by default. For anonymous attribution, the recommendation is **no more granular than `utm_medium`**, using reviewed channel codes such as `email`, `social`, or `cpc`. This recommendation is advisory: each property can be allowed separately. More detailed UTMs can reveal campaign membership, search text, identifiers, or sensitive context. Review those values and their purpose before overriding the recommendation. The setting allows a key, not a fixed list of values; even `utm_medium` can contain unexpected or personal text if an implementation puts it there.

Properties use bounded scalar values; nested objects and arrays are discarded. Only configured query parameters are extracted from the current page URL, with no attribution cookie or session storage. The `org_internal_traffic` flag is derived separately and cannot be set through a query parameter or event property. See the [data model guide](DATA-MODEL.md) for configuration and [tracking guide](TRACKING.md#utm-and-custom-data-collection) for precedence and SDK overrides.

## Optional page depth

`page_sequence_enabled` defaults to `false` and is managed through Data model or
active YAML. Enabling it adds the reserved integer `page_sequence` to event JSON
in both modes, including after enhanced consent is rejected or withdrawn. Values
run from `1` to `20`, where `20` means **20 or more**. All events emitted on a page
share its depth; page views advance it. The server enforces opt-in and numeric
bounds, but cannot verify a client's claimed page count.

`page_sequence_method` chooses how the number reaches the next page:

- `session_storage` (default) keeps a bounded number in tab-scoped `sessionStorage`,
  keyed by the public website token. Reloads count again; duplicated or restored
  tabs may preserve the number. Blocked storage falls back to memory for the
  current document. Disabling page depth while this method remains selected clears
  the current token's counter when the tracker next runs with updated settings.
- `url_parameter` reads `aggregate_page_sequence` from the page URL and adds the
  next capped number to ordinary same-origin links when activated. It performs
  no cookie or Web Storage operations for the counter and leaves earlier stored
  counters untouched. After capture, it removes the parameter with
  `history.replaceState()` while preserving existing history state and other URL
  parts. Reloading the cleaned URL starts at 1; back/forward restoration may
  retain the in-memory number. Cleanup cannot hide the initial request from logs
  or earlier scripts, and can fail if the History API is unavailable. Event page
  information remains query-free even then. Review canonical URLs, SEO, caching,
  router integrations and destinations that reject extra parameters, including
  signed links.

Neither method creates a person/session identifier, path history or stored
timestamp. The kill switch and path exclusions prevent disabled/excluded counter
collection; URL mode also checks link destinations. Independent consent-manager,
organization-marker and SDK identifier-cleanup behavior still applies.

Browser-storage rules can apply even without an identifier, and avoiding counter
Web Storage does not itself establish a consent exemption. Assess requirements
and disclose the selected method before enabling it; leave it off where separate
consent would be required. The cap reduces precision, but is not proof of legal
anonymity or permission to reconstruct individual journeys.
It remains private `custom_data`: approved anonymous BI views and archive
aggregates do not expose it. See [configuration and examples](DATA-MODEL.md#optional-page-depth).

## Organization traffic

Organization traffic can be labeled for filtering in reports without dropping the underlying events. A shared marker identifies a browser as belonging to a team member; it is independent of the `internal` referrer category for navigation within a website.

Team membership can add context to otherwise coarse events. Include this separate browser-storage choice in deployment notices.

**What every event carries.** Under the standard collection profile, every event the tracker sends includes `"org_internal_traffic": true` when the browser holds the marker on that website and `"org_internal_traffic": false` otherwise. The server stores the same boolean in `events.custom_data` under that fixed key, in both anonymous and enhanced modes. Only the JSON value `true` counts; a string such as `"true"`, a number, or a property of the same name in `customData` cannot set it, and requests that omit it are recorded as `false`. The [strict profile](#strict-collection-profile) reads no cookies or browser storage, so its events carry no `org_internal_traffic` key at all. No database migration is required.

**The browser marker.** The marker itself is a cookie or local storage entry, by default **`orgInternalTraffic=true`**. Its name, value, storage and cookie domain are configurable; the JSON key is not, so renaming the marker never changes your reports. Configure it on **Organization traffic** at `/dashboard/internal-traffic` or in the active environment in `config/aggregate.yaml` (or its environment-specific file):

```yaml
internal_traffic_storage: cookie       # cookie or local_storage
internal_traffic_name: orgInternalTraffic
internal_traffic_value: "true"          # Quote this string in YAML.
internal_traffic_cookie_domain: ""      # Optional; e.g. example.com to share one cookie across sibling subdomains.
internal_traffic_share_token: ""        # Generated during installation; empty disables sharing.
```

Uppercase environment variables override these YAML settings and lock the corresponding UI controls, including an explicit empty sharing token.

### Marking a browser on each website

A browser keeps every website's cookies and local storage separate. A marker saved while visiting the analytics server applies only to that server, so it must be written on each tracked website by the tracker running there. The Organization traffic page and the share page list your [websites](CONFIGURATION.md#website-domains) with **Mark** and **Remove** links, and **Mark this browser on all websites** visits each website in turn:

1. The link opens the website's home page with a short-lived code after `#aggregate-org-traffic=`. A fragment is not sent to the website's server.
2. The tracker on that page removes the code from the address bar, then asks the analytics server's `/internal-traffic/verify` endpoint whether it is valid. That request carries the code and the public website token, without cookies or a referrer.
3. When the code is valid, the tracker writes (or removes) its configured marker in the website's own cookie or local storage, sends no page view for this visit, and returns the browser to `/internal-traffic/continue` on the analytics server, which opens the next website and finally lists the result for each one.

Codes are signed with the application secret and the current share token, expire after 30 minutes and carry no identifier: every team member receives the same marker. The pages renew them on reload. Rotating or revoking the share link stops any code not yet used. An invalid or expired code changes nothing, and the website records its page view as usual.

For marking to work, the tracker served by this installation must load on each website's home page under the standard profile, and the website's domain in **Websites** must be its public host name (`https://` is used, or `http://` for `localhost`). If a website does not bring the browser back, its home page does not load the tracker: return to the marking page, use the links for the remaining websites, and use the [downloadable marker page](#marker-pages-and-the-share-link) for that website. If you route the collection endpoint through a proxy on your own domain, forward `/internal-traffic/verify` the same way.

**Cookie scope.** The tracker sets the cookie for the website's configured domain when the page is on it or one of its subdomains, so `www.example.com` and `example.com` share one marker; when `internal_traffic_cookie_domain` contains the page, that domain is used instead. Otherwise the cookie belongs to the page's host only. Cookies last one year, use `Path=/` and `SameSite=Lax`, and set `Secure` on HTTPS. Removing the marker also clears copies on the host and its parent domains. Local storage belongs to one exact origin (scheme, host and port) and lasts until removed or cleared.

The tracker writes the marker it reads on every event, including any [snippet overrides](#trackers-served-from-a-static-host), so reading and writing always agree. Rejecting enhanced analytics clears visitor and session identifiers but keeps this independently chosen team marker.

### Marker pages and the share link

The web installer and `php bin/console app:install` generate a random 64-character sharing token and save it in YAML, preserving any existing token. `install.sh` generates separate random tokens for each environment when creating initial YAML, including headless installs. Existing installations can generate one from the Organization traffic page. Copy the resulting `/internal-traffic/<token>` link to teammates; they can open it without signing in and use the marking links. The admin UI can rotate or revoke the link. Revocation disables the link and outstanding codes; it does not remove markers already installed.

Both pages also offer buttons that mark only the analytics server itself, and a **Download marker page** link. Host the downloaded HTML on a website whose home page does not load the tracker and open it there; opening the file locally marks nothing. The download contains the marker settings but no sharing token or marking codes; restrict its hosted URL separately if needed.

Sharing, continue and verification responses carry `noindex, nofollow`, `no-store`, and a no-referrer policy; the pages load no third-party assets. The token is never included in the tracking script, marking links or event JSON. Keep the link within your team; anyone holding it can open the page and mark a browser for the next 30 minutes. Changing the marker's name, value or storage requires teammates to mark their browsers again; remove an old marker before changing its settings if you want to clear it too.

### Trackers served from a static host

The supplied Apache, nginx, and FrankenPHP configurations route `/aggregate.js` through Symfony so YAML/UI changes are included. If you serve `public/aggregate.js` directly from a static host or CDN, configure matching values in the site's snippet:

```html
<script>
  window.Aggregate = {
    endpoint: 'https://analytics.example.com/api/receive',
    websiteToken: 'your-website-token',
    internalTraffic: { storage: 'cookie', name: 'orgInternalTraffic', value: 'true' }
  };
</script>
<script src="https://analytics.example.com/aggregate.js" async referrerpolicy="no-referrer"></script>
```

Per-script `data-internal-traffic-storage`, `data-internal-traffic-name`, and `data-internal-traffic-value` attributes also override the defaults. The tracker finds the verification endpoint next to its `endpoint`: `https://analytics.example.com/api/receive` becomes `https://analytics.example.com/internal-traffic/verify`.

### Filtering organization traffic in reports

**Power BI / Tableau:** extract `custom_data.org_internal_traffic` as a boolean calculated column and filter out `true`. `false` means the browser held no marker; a missing key or null JSON means the event was not checked, as with the strict profile. These SQL expressions identify organization traffic:

| Database | Internal-traffic expression |
| --- | --- |
| PostgreSQL | `COALESCE(custom_data::jsonb ->> 'org_internal_traffic', 'false') = 'true'` |
| MySQL / MariaDB | `COALESCE(JSON_UNQUOTE(JSON_EXTRACT(custom_data, '$.org_internal_traffic')), 'false') = 'true'` |
| SQL Server | `COALESCE(JSON_VALUE(custom_data, '$.org_internal_traffic'), 'false') = 'true'` |
| SQLite | `COALESCE(json_extract(custom_data, '$.org_internal_traffic'), 0) = 1` |

For example, an approved PostgreSQL extract can derive a field for the report filter:

```sql
SELECT website_token, created_at, event_name,
       COALESCE(custom_data::jsonb ->> 'org_internal_traffic', 'false') = 'true'
           AS is_internal_traffic
FROM events;
```

Earlier versions stored the flag under the configured marker name (default `orgInternalTraffic`) and only when it was true. Those rows are not converted; include the old key in your filter, or update the stored JSON, if reports span the change.

Keep raw-event access within your existing reporting policy. To expose the flag as a column, add `org_internal_traffic` as a boolean property in the [data model](DATA-MODEL.md) and regenerate the private `analytics_custom_*_v1` views. For anonymous reporting, apply the internal-traffic filter before aggregation and disclosure thresholds in a controlled export. Existing `bi_anonymous_*` views and archive tables do not expose `custom_data` and cannot distinguish internal traffic. Archives continue to combine both traffic types; once raw rows are deleted, this flag cannot be recovered from archives. Prepare filtered reporting datasets while the raw JSON is retained. This browser-supplied label is for reporting, not authorization.

## Optional coarse geography

Geography is disabled by default. When enabled, the ingestion boundary uses the request IP transiently with a local GeoLite2-Country-compatible MMDB and retains only one normalized value:

- `continent:XX` for `anonymous_geo_level: macro_region` (recommended); or
- `country:XX` for `anonymous_geo_level: country`.

The server first requires a valid public IP. Private, reserved, malformed, and unsupported addresses produce no geography. The raw IP and full MMDB record are not put in the event, message queue, or application error logs. Missing or unreadable database files and lookup failures also leave `geo_area` empty without rejecting the analytics event. Aggregate does not bundle or download the MMDB and does not contact a hosted geolocation service; the operator must follow the database provider's license, attribution, and update terms. City, subdivision/state, postcode, latitude, and longitude are never retained.

The coarse area may be stored on accepted anonymous and enhanced events. The prefixes make historical rows interpretable if the configured level changes. Keep the MMDB current, mount it read-only, and grant read access only to the ingestion process. Configure trusted proxies narrowly and require them to strip or overwrite client-supplied forwarding headers; otherwise geography can be spoofed.

The value represents approximate network-exit geography, not a person's precise residence or physical location. VPNs, mobile carriers, corporate gateways, and database errors can map a request to the wrong area. Do not use or describe this field as precise location data.

This design minimizes retained data; it does not make the lookup legally invisible. An IP address is personal data in many contexts, even when used only transiently, and a country or continent can increase singling-out risk when combined with a rare event, small population, path, or time bucket. Document the purpose and legal basis, update notices and records of processing, and consult counsel for the deployment. Prefer macro-region and leave geography off on sensitive or low-traffic sites.

## Stored data and reporting queries

**`events`** is the unified private storage table. `privacy_mode` separates `anonymous` and `enhanced` rows; page views use `event_name = 'view'`.

- Shared dimensions include `website_token`, `event_name`, sanitized path in `url`, coarse channel in `referrer`, `device_class`, `viewport_bucket`, optional `geo_area`, optional allowlisted `goal_event`, `privacy_mode`, and `created_at`.
- Anonymous-mode rows are individual events whose server-generated `created_at` is truncated to a UTC hour. An enabled `goal_event` may be present only when its definition permits anonymous use; identifier, exact-dimension, and generalized User-Agent columns remain null. `custom_data` may contain properties explicitly configured with `consent_required: false`, separately enabled `page_sequence`, and the `org_internal_traffic` flag (`true` or `false`); other event properties are omitted.
- Enhanced rows may include `screen_width`, `visitor_id`, `session_id`, `consent_state`, `custom_data`, `goal_event`, `generalized_user_agent`, and an exact server timestamp.

Do not grant routine BI users access to raw `events`. Hour bucketing and missing IDs reduce risk, but anonymous-mode rows can still be personal data in context.

Older rows can be rolled into the private, unsuppressed `analytics_archive_events`, `analytics_archive_goals`, and `analytics_archive_geo_events` tables. The operational `analytics_archived_events_v1`, `analytics_archived_pageviews_v1`, and `analytics_archived_goals_v1` views are likewise private and unsuppressed; aggregation alone does not make their cells anonymous. The thresholded `bi_anonymous_*` views transparently combine eligible live and archived anonymous counts and remain the supported routine-BI surface.

Custom-property reporting uses separate `analytics_custom_events_v1`, `analytics_custom_pageviews_v1`, and `analytics_custom_goals_v1` views generated from the data model. They expose individual retained raw rows in both privacy modes, with modeled scalar JSON properties as text columns. They apply no suppression, omit archive aggregate cells, and lose coverage when raw rows are deleted. Restrict grants to explicitly approved raw-data reporting workflows. Generating these views does not add custom dimensions to `bi_anonymous_*` or preserve them in archives.

Routine anonymous reporting should query approved views:

```sql
SELECT * FROM bi_anonymous_events_v1;
SELECT * FROM bi_anonymous_goals_v1;
SELECT * FROM bi_anonymous_geo_events_v1;
```

If your reporting policy separately permits raw enhanced-data access, restrict it explicitly:

```sql
SELECT * FROM events WHERE privacy_mode = 'enhanced' AND event_name = 'view';
SELECT * FROM events WHERE privacy_mode = 'enhanced' AND event_name != 'view';
```

The view dimensions and suppression rules are described below. [Connect BI tools and AI assistants](BI-CONNECTION.md) lists every column with safe query patterns, a connection checklist and AI assistant setup, and the [database guide](DATABASE.md) covers connection strings, engine-specific setup, and migration compatibility. Website registrations remain in `config/websites.yaml`, outside the event tables.

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

The view groups anonymous-mode rows into hourly cells and exposes a cell only when `event_count` reaches `anonymous_min_cell_count`. It withholds the current UTC hour, so current-hour events do not appear immediately. Completed cells can still change through delayed processing or retention; suppression is not a guarantee against inference across successive reports. The default threshold is `5`; the allowed range is `2`–`1000`.

Cell suppression reduces the exposure of rare combinations but does not prove anonymity. Restrict the raw table, review the available dimensions, and consider a higher threshold for low-traffic or sensitive sites.

Use `bi_anonymous_goals_v1` for routine anonymous conversion reporting. It exposes only:

- `website_token`
- `event_day`
- `goal_event`
- `event_count`

The view includes only anonymous rows with a retained goal, groups them by UTC day, withholds the current UTC day, and applies `anonymous_min_cell_count` (default `5`, range `2`–`1000`). It counts goal occurrences, not unique people or unique converters. Goal labels are presentation metadata in `config/goals.yaml`; the stable goal code is the reporting value. Do not join this completed-day view to the hourly event view or private event rows to recover more detail.

When coarse geography is enabled, use the separate `bi_anonymous_geo_events_v1` view. It exposes only:

- `website_token`
- `event_day`
- `event_name`
- `geo_area`
- `event_count`

It groups anonymous-mode rows into UTC-day cells, withholds the current UTC day, and applies `anonymous_geo_min_cell_count` (default `25`, range `10`–`1000`). Page path, referrer, device, viewport, and identifiers are deliberately absent. Do not join row-level geography into the detailed hourly view.

Areas below the threshold are pooled as `country:other` or `continent:other`, and the pool is released only when its combined event count also reaches the threshold. If exactly one area is suppressed, the view pools the smallest otherwise-visible area too. This secondary suppression makes direct subtraction from a partition total more difficult while preserving a useful coarse remainder.

Both thresholds count occurrences, not distinct people, because anonymous-mode events intentionally have no stable person identifier. A single person can generate enough events or goals to meet a threshold. Secondary suppression cannot prevent inference across every extract, time period, or outside data source. Suppression therefore does not establish k-anonymity. Use the minimum necessary BI access, consider a threshold above the default, review extracts for small populations, and do not present any view as guaranteed anonymous data.

Archiving does not weaken this routine-BI contract: the thresholded `bi_anonymous_*` views transparently combine eligible live and archived anonymous counts. The underlying `analytics_archive_events`, `analytics_archive_goals`, and `analytics_archive_geo_events` tables are private and unsuppressed. The operational `analytics_archived_events_v1`, `analytics_archived_pageviews_v1`, and `analytics_archived_goals_v1` views are also private and unsuppressed. Aggregation can reduce exposure, but a rare cell may still identify or single out a person in context; never grant these tables or operational views to routine BI roles by default.

### Declared BI glossary

Routine BI roles may also read the seven `bi_dim_*_v1` views plus
`bi_glossary_values_v1` and `bi_glossary_columns_v1`. These publish declared
metadata, not event observations: the resolver and sync never read events,
archives, or fact views, and all Intl countries and all continents are published
regardless of traffic. Keep the backing `analytics_glossary` table private. See
[the glossary contract and grants](BI-GLOSSARY.md#view-only-grants).

Labels, groups, descriptions, and codes must not contain personal or identifying
text. Declaring codes for a property with `consent_required: true` publishes
those codes to routine BI users even when its event values live only in private
views. Neither labels nor column definitions authorize collection or access to
private facts. The optional administrator event-name finder samples only the
latest 1,000 retained events, displays names without counts, and persists nothing
until the administrator explicitly saves a declaration.

Join one label row per code; filter localized metadata to one dimension and
locale before joining. Keep a left join with a code fallback for undeclared names
and deleted goal definitions. Summing visible cells by a glossary group still
undercounts where suppression withheld cells; metadata cannot reconstruct hidden
counts or establish legal anonymity. Invalid glossary configuration affects only
metadata save/sync, not tracking, ingestion, or health checks.

## Administrative controls

```yaml
collection_profile: standard
anonymous_tracking_enabled: true
anonymous_excluded_paths: []
anonymous_geo_enabled: false
anonymous_geo_level: macro_region
anonymous_geo_database_path: ""
```

- `collection_profile: strict` limits every event to the sanitized path, event name and approved goal in anonymous mode; see [Strict collection profile](#strict-collection-profile). `COLLECTION_PROFILE` overrides it.
- `anonymous_tracking_enabled: false` is the global collection kill switch for both privacy modes.
- `anonymous_excluded_paths` lists paths or recursive globs rejected in both privacy modes.
- `anonymous_geo_enabled` opts into local coarse geography for accepted events; a database path by itself does not enable it.
- `anonymous_geo_level` accepts `macro_region` (continent-level) or `country`.
- `anonymous_geo_database_path` points to a locally managed GeoLite2-Country-compatible MMDB. An absolute path is recommended; a relative path must resolve inside the project root. URI/stream schemes, relative escapes, UNC/network shares, and Windows device paths are rejected.

Only administrators can change these runtime controls through the CSRF-protected dashboard form. For API-only deployments, manage them in `config/aggregate.yaml` and restrict write access to that file.

The admin **Data model** page manages `custom_data_properties` and `query_parameter_mappings`, including each property's consent rule and reporting column. These settings are also available in the active YAML configuration. Review consent-free properties separately from the SQL columns needed for reporting; exposing a column does not authorize its collection.

Goal definitions are deploy-time controls in `config/goals.yaml`:

```yaml
parameters:
    app.goal_events:
        purchase:
            label: 'Purchase'
            anonymous: true
            enabled: true
```

Codes must match `[A-Za-z][A-Za-z0-9_.:-]{0,99}` and remain fixed and non-identifying. Set `anonymous: false` for an enhanced-only goal and `enabled: false` to stop future collection without erasing the definition's historical meaning. Restrict write access to this file and clear the production cache after a change, then run `php bin/console app:analytics:glossary:sync --env=prod` to update published goal labels. Disable goals instead of deleting definitions when historical labels should remain available.

The BI disclosure thresholds are stored directly in the singleton `analytics_privacy_settings` database row and have their own CSRF-protected admin form:

- `anonymous_min_cell_count` controls grouped hourly cells in `bi_anonymous_events_v1` and completed daily cells in `bi_anonymous_goals_v1` (default `5`, range `2`–`1000`).
- `anonymous_geo_min_cell_count` controls completed daily cells in `bi_anonymous_geo_events_v1` (default `25`, range `10`–`1000`).

All three views read the database row directly, so dashboard changes take effect immediately without a YAML mirror or synchronization command. The migrations create the row with safe defaults. API-only operators must use controlled database administration to change it, and routine BI roles must remain read-only.

Archiving and deletion policy is separate and can be managed by administrators at `/dashboard/data-lifecycle`, in `config/aggregate.yaml`, or with uppercase environment-variable overrides. Environment-controlled values are read-only in the UI. Both actions are disabled by default, settings are strictly range-checked, and unsafe archive/retention ordering is rejected. Schedule `php bin/console app:analytics:maintain` externally; a saved policy does not run maintenance from a web request.

Malformed YAML, invalid ingestion-control types, or an unrecognized collection profile fail closed: ingestion is disabled and the health endpoint reports a generic configuration error. Out-of-range database thresholds also fail closed because the views require values within their documented ranges.

## Enhanced analytics consent

Enhanced analytics may use:

- `aggregate_session`, a 30-minute `SameSite=Lax` session cookie with `Secure` on HTTPS;
- a visitor ID in `localStorage`;
- a session ID in `sessionStorage` and the session cookie;
- exact screen width;
- custom properties whose definitions require consent, including all six UTMs by default;
- configured goals whose definitions do not permit anonymous use;
- generalized browser/device information; and
- an exact server event timestamp.

The visitor and session IDs are random version 4 UUIDs from the browser's
cryptographic random source. A browser without one sends enhanced events
without the missing ID rather than a guessable one.

Consent is not a reason to accept arbitrary payloads. Use a documented property allowlist and avoid form contents, emails, account identifiers, search terms, or other free text unless the deployment has a specific, reviewed need and legal basis.

Enable these fields only after an affirmative choice:

```javascript
window.Aggregate.setConsent(true);
```

Reject or withdraw enhanced analytics with:

```javascript
window.Aggregate.setConsent(false);
```

That call immediately removes the Aggregate visitor ID, session ID, and session cookie from the browser. Later `emit(...)` calls continue to create coarse, hour-bucketed anonymous-mode rows. Properties requiring consent and exact dimensions are omitted; configured consent-free properties and goals marked `anonymous: true` may continue. It does **not** delete data previously collected by the server. Do not describe it as a deletion request or promise that it erases all data.

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

This direct-loading example permits anonymous measurement before consent.
Use the [separate script gate](CONSENT-REGIONS.md#what-denial-and-withdrawal-actually-do)
when all measurement must wait for permission. Adapt the API calls to your consent
manager. For a direct installation, test these cases in a new browser profile:

1. Before a choice, a page view and `emit('button_click', {...}, 'signup')` create anonymous-mode rows with no IDs and only explicitly permitted custom properties; the goal is retained only when its enabled definition permits anonymous use.
2. Anonymous `created_at` values are UTC hour boundaries rather than exact event times.
3. With page depth disabled (the default), rejecting creates no Aggregate cookie, `localStorage` value, or `sessionStorage` value. With page depth enabled, the session-storage method may create its bounded numeric tab counter; the URL method uses no counter Web Storage. Neither creates visitor/session IDs. Review the separate organization marker and CMP storage according to their documented behavior.
4. Named events continue after rejection; properties requiring consent are absent, while configured consent-free properties and anonymous goals may remain.
5. Accepting creates IDs and permits enhanced event details.
6. Withdrawing removes all three browser-side identifiers and strips consent-required details from later events; only explicitly permitted custom properties remain.
7. Globally disabling collection or visiting an excluded route produces no event row.
8. An unknown, disabled, or disallowed goal leaves the underlying event intact, returns `goal_not_allowed`, and produces a generic console warning that does not contain the submitted value.

## Independent consent controls and regional examples

The optional [MicroConsent banner](../micro-consent-dropins/README.md) is separate
from the built-in CMP. It can run without this application or connect through
optional adapters. Its settings live in each site's `standalone_consent` YAML;
**Setup** offers it as a distinct installation choice. Optional categories start
denied. A respected active GPC signal denies marketing and signals a do-not-sell
choice; it never grants enhanced analytics, identifies applicable law, or controls
an unconnected provider. The optional Google Consent Mode adapter sends signals
only and never downloads Google; signals do not guarantee that denied providers
send no network requests.

The [global, EU/EEA, UK, Canada and US examples](CONSENT-REGIONS.md) use a
conservative tracker tag with `consent: analytics`. Before the first grant, the
tracker stays unloaded, including its anonymous mode. Remove direct tracker
installations and duplicates for this gate to work. After a tracker has loaded,
withdrawal still invokes the existing `setConsent(false)` behavior described
above; reload after withdrawal so the denied tag stays unloaded. Already loaded
providers need their own cleanup. No browser banner can erase past server events.

Region names are documentation choices, not automatic legal policies. No visitor
IP lookup or browser-language inference selects them. The UK's current PECR
statistical exception has explicit limits; retaining raw individual events is a
material consideration. National ePrivacy rules, Canadian consent/Quebec
activation duties, and US state/purpose rules differ. The examples do not certify
an exemption or legal anonymity. Keep geography and page depth off and UTMs
consent-gated unless separately assessed.

MicroConsent stores a preference record with a revision and configurable review
lifetime; the default 180 days is not a statutory consent period or authenticated
server-side consent receipt. An optional Formspree endpoint enables explicit
user-submitted requests containing email, request type and message, without
automatically attaching page URLs, tracking IDs, website tokens or event data.
Formspree is a third-party recipient and also handles ordinary connection
metadata. Disclose and review its processing, account retention and security.
Submission does not verify identity, process a right, or delete data; the operator
must manage and respond to requests separately. Browser consent choices do not
require a Formspree submission.

## Suggested notice language

Adapt this text to the deployment and have counsel review it:

> We use self-hosted analytics to understand page usage, named interactions such as button clicks, and [configured goal categories such as signup or purchase]. Unless you accept enhanced analytics, each event is limited to a fixed event name, [an allowlisted goal category, when applicable], sanitized page path, coarse traffic-source and device categories, [specifically listed custom properties collected without enhanced consent, such as campaign medium], [a country/continent category derived locally from the request IP, if enabled], and a server-generated UTC hour bucket. The analytics event does not retain the source IP or use an analytics cookie or visitor/session identifier. This privacy-minimized measurement continues when enhanced analytics is rejected, except on routes we exclude from measurement.

With the strict collection profile, adapt this shorter text instead:

> We use self-hosted analytics that records, for each page view or named interaction, only the page address without query strings or identifier-like segments, a fixed event name, [an allowlisted goal category, when applicable], and the UTC hour in which it occurred. Our analytics script does not read screen size or where you came from, does not store or read cookies or other browser storage, and does not use identifiers. [Describe any separate consent tool or tags you load.]

If page depth is enabled with tab session storage, also disclose its storage and meaning:

> We keep a page-depth number in this tab's session storage and include it with events, even when enhanced analytics is rejected. It counts tracked page views up to 20, with 20 meaning 20 or more. This counter contains no visitor identifier or page history. Browser-restored or duplicated tabs may retain it.

For the URL method, use language matching that behavior instead:

> We carry a page-depth number in the `aggregate_page_sequence` URL parameter on ordinary internal links and include it with events, even when enhanced analytics is rejected. It ranges from 1 to 20, with 20 meaning 20 or more, and uses no cookies or Web Storage for this counter. It contains no visitor identifier or page history. After reading it, we remove the parameter from the address bar when the browser allows this; it is not included in event page information. The initial request and earlier scripts may still expose it, including in server logs. Reloads after cleanup restart the count, and shared or edited URLs can make it inaccurate.
>
> If you accept enhanced analytics, we also use short-lived session information, a returning-visitor identifier, custom interaction details, and exact server timestamps. You can withdraw that consent at any time. Withdrawal stops future enhanced collection and removes analytics identifiers from this browser; it does not automatically erase records already collected. Contact us at [privacy contact] to exercise applicable privacy rights.

Do not call anonymous-mode rows "completely anonymous." Hour bucketing and identifier removal reduce risk, but the operator must assess paths, event and goal names, traffic volume, access, logs, and reasonably available means of relating data to a person.

## Infrastructure and logging

The HTTP request passes through a network stack even when the stored analytics row is privacy-minimized. Reverse proxies, CDNs, web servers, WAFs, load balancers, tracing systems, and application logs may independently record IP addresses, User-Agent strings, exact request times, or headers.

The `/api/receive` endpoint applies the website token's [domain rules](CONFIGURATION.md#website-domains) to both privacy modes. Restricted policies check `Origin`, falling back to `Referer` only when `Origin` is absent, against exact hostnames or explicit wildcard subdomains. Existing registrations without a policy retain their primary domain and all subdomains. An explicit `mode: all` permits any source, including clients without origin headers; a valid website token and all other collection/consent checks remain required. Invalid explicit rules fail closed. Restricted rules reject ordinary cross-site browser submissions but cannot authenticate a person or stop callers from constructing requests with forged headers. Keep website registration and admin access separate from the public ingestion token.

The built-in abuse limiter defaults to 100 requests per IP per minute, configurable through `rate_limit_per_minute`. It derives a keyed, per-minute bucket from the request IP and opportunistically removes expired files in `var/rate_limit/`. It does not put the raw address into analytics, but operators should still treat rate-limit storage as short-lived security metadata and prefer an upstream limiter with enforced TTLs at scale.

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

Use the [deployment guide](../DEPLOYMENT.md#production-considerations) for HTTPS, process supervision, backups, and monitoring, and the [configuration reference](CONFIGURATION.md) for proxy trust and runtime settings.

## Retention and privacy rights

Define retention separately for:

- anonymous-mode event rows;
- enhanced event rows;
- private archived event, pageview, goal, and geography cells;
- BI extracts and caches;
- queues and failed messages;
- logs and traces; and
- backups.

The built-in lifecycle policy provides separate raw anonymous, raw enhanced, and archive periods. Archiving first rolls older raw rows into private aggregate cells; it does not itself delete raw data. Retention then irreversibly removes eligible raw rows and archived cells when enabled. If both features are enabled, the validator requires each raw retention period to be at least the archive-after period, preserving a window in which rows can be archived before deletion. Whenever retention is enabled, archive retention must be at least the longer raw period so an aggregate cell cannot expire while its marked source row is still excluded from reporting.

Retention can be enabled without archiving when the intended policy is complete deletion rather than historical aggregation. In that mode, eligible unarchived raw rows are removed without preserving their counts.

Before enabling retention, back up the database, run `php bin/console app:analytics:maintain --dry-run`, confirm that the supported reporting views return expected counts, and document approval of the periods. Run and monitor the real maintenance command at least daily. Database backups, replicas, exports, BI caches, queues, failed messages, application/proxy logs, and downstream systems are outside this deletion job and need independently enforced periods. A backup-restoration procedure must avoid silently reintroducing data past its approved lifetime.

Anonymous-mode rows intentionally contain no visitor lookup key, so the system cannot reliably find them by visitor ID. That does not automatically remove them from privacy-law scope. Document the limitation and establish a rights-request approach appropriate to the deployment.

Enhanced rows may be linkable through visitor or session IDs. Enhanced archive cells intentionally omit those identifiers and custom properties, but retain reporting dimensions such as the sanitized page path and coarse referrer channel. This can make person-level lookup or deletion impossible while a potentially identifying rare cell remains, so document archiving as a separate transformation and rights-handling decision. Operators should provide authenticated procedures for applicable access, deletion, correction, portability, restriction, objection, and opt-out requests. `setConsent(false)` is a prospective consent control, not that server-side workflow.

## Upgrading older installations

Before applying the `Version20260724*` privacy migrations, back up the database, pause `/api/receive`, and stop every async worker. These migrations intentionally and irreversibly remove `daily_ip_hash`, delete legacy enhanced rows that do not contain `consent_state = 'granted'`, and delete Doctrine-transport `TrackEventMessage` envelopes so older payloads cannot be processed by the new code.

The queue cleanup can only match envelopes visible as plain text in the Doctrine transport. Inspect and purge tracker messages from failed, externally hosted, or encoded/base64 transports before resuming ingestion. Legacy rows already stored as `granted` are retained, but older releases could infer that value solely from a session ID; audit or purge those rows if you cannot establish their consent provenance. Resume ingestion and workers only after the migrations succeed.

See [deployment updates](../DEPLOYMENT.md#updates) and the [database migration guide](DATABASE.md#migration-and-compatibility) for the rest of the upgrade procedure.

## Operator checklist

- [ ] Inventory every anonymous and enhanced field actually collected.
- [ ] Use a fixed, reviewed event-name taxonomy with no user-derived values.
- [ ] Review every `config/goals.yaml` definition; keep codes fixed, use `anonymous: false` where appropriate, and never treat rejection warnings as sensitive-data detection.
- [ ] Review each consent-free custom property's actual values; prefer no more UTM detail than medium and document any override.
- [ ] Review paths for personal or sensitive data and configure exclusions.
- [ ] Decide whether anonymous-mode measurement is appropriate in each jurisdiction and context, and whether the strict collection profile better fits that assessment.
- [ ] If you rely on strict collection, serve `aggregate.js` from this installation or opt static copies into strict, and review any consent tool or tags that still store data.
- [ ] Document the legal basis for each processing purpose.
- [ ] Use consent wording that distinguishes privacy-minimized measurement from enhanced analytics.
- [ ] Make acceptance and rejection of enhanced analytics equally clear.
- [ ] Do not enable enhanced analytics before affirmative consent where consent is required.
- [ ] Publish retention periods and implement their enforcement.
- [ ] Dry-run, schedule, and monitor `app:analytics:maintain`; verify backup and downstream deletion separately.
- [ ] Provide a server-side rights-request process for enhanced data.
- [ ] Restrict routine BI users to the approved `bi_anonymous_*` fact views and `bi_dim_*`/`bi_glossary_*` metadata views they need; keep raw `events` and `analytics_glossary` private.
- [ ] Review glossary codes and text for identifying information, especially declarations for consent-gated properties.
- [ ] Validate `anonymous_min_cell_count` against event and goal volumes and re-identification risk.
- [ ] If geography is enabled, document its legal basis and notice, prefer macro-region, and verify that only the local MMDB is used.
- [ ] Restrict geography BI users to `bi_anonymous_geo_events_v1` and validate `anonymous_geo_min_cell_count`; remember that it counts events, not people.
- [ ] Review proxy, CDN, application, queue, error, and backup retention.
- [ ] Execute data-processing agreements where required.
- [ ] Perform a DPIA or other risk assessment where required.
- [ ] Apply appropriate rules for children and sensitive contexts before collection.
- [ ] Re-test consent and privacy controls after every SDK, route, or schema change.

## Frequently asked questions

### Does anonymous-mode measurement require consent?

There is no universal answer. The design removes browser IDs, unapproved custom properties, full URLs/raw referrers, and exact analytics timestamps, but it still stores individual hour-bucketed event rows and may retain configured goal categories and consent-free properties. The operator must assess applicable law, regulator guidance, purpose, context, infrastructure metadata, and promises made to visitors. Consult qualified counsel.

### Can a configuration make Aggregate "strictly necessary"?

Not by itself. That classification depends on the purpose and the jurisdiction, not only on how little is collected. The [strict collection profile](#strict-collection-profile) minimizes what the tracker touches on a device, which can support a narrower audience-measurement exemption where one exists, but site measurement generally is not a service the visitor asked for. Consult qualified counsel for each market.

### What does Reject mean?

It must mean **reject enhanced analytics**. The SDK removes browser identifiers and omits properties requiring consent, exact dimensions, and exact analytics timestamps, while coarse hour-bucketed event rows and configured goals and properties allowed anonymously continue. An interface promising to reject all analytics is incompatible with this model unless the site declines to load the SDK for that visitor or the operator disables collection.

### Can an anonymous-mode event be personal data?

Yes, contextually. A sanitized path, rare event or goal name, hour bucket, small population, or outside information may allow a person to be singled out or related to the row. Use exclusions, restricted raw-table access, retention limits, BI grouping, and low-count suppression.

### Is country or continent data anonymous?

Not inherently. A coarse area is less identifying than city, postcode, or coordinates, but the source IP is processed during lookup and the retained area can become identifying in combination with other facts. Prefer continent-level macro-regions, use the dedicated daily suppressed view, and assess the actual traffic population and context. Do not claim that the threshold proves anonymity: it counts events rather than distinct people.

### Does withdrawing consent delete past data?

No. `setConsent(false)` removes browser-side identifiers and stops future enhanced collection. Handle deletion or other rights requests through the operator's authenticated server-side process.

### Is self-hosting sufficient for compliance?

No. Self-hosting provides control over infrastructure and recipients, but the operator still needs an appropriate legal basis, transparency, security, retention, consent behavior, rights handling, and organizational controls.
