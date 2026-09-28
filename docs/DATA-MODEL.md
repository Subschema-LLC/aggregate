# Custom data model and reporting

[Configuration](CONFIGURATION.md) · [Tracking](TRACKING.md) · [Privacy](PRIVACY-COMPLIANCE.md)

The **Collection → Data model** admin page at `/dashboard/data-model` defines custom properties, their consent requirements, query-parameter mappings, and reporting columns. Expand one property to edit its fields. Model editing, event examples, observed-property discovery, and reporting SQL have separate pages linked from the editor. Download the saved YAML to share the contract with analytics implementation developers. These settings use the active `config/aggregate.yaml` environment or `config/aggregate_<environment>.yaml`; they have no uppercase environment-variable overrides.

## Configure collection

The default model defines `utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`, and `utm_id`. Each maps from the identically named page query parameter, has a reporting column with the same name, and **requires enhanced consent**. Views are created only when you explicitly regenerate them.

Select **Allow without consent** for individual properties to whitelist them for anonymous events. The server enforces this independently of the tracker. Unlisted properties continue to require enhanced consent; the model does not restrict which properties enhanced events may contain.

**Recommendation:** keep anonymous attribution no more granular than `utm_medium`, using broad values such as `email`, `social`, or `cpc`. This is advisory: administrators may override it for any property. Detailed source, campaign, term, content, or ID values can reveal specific campaigns, search text, or identifiers, especially alongside paths, geography, and small audiences. Review actual values and disclosures before making an exception. Even `utm_medium` can contain identifying free text; whitelisting a key does not make its values anonymous. The UI shows this advice and warns when the saved model allows more detailed UTM inputs, including aliases.

This complete model whitelists only the coarse medium and maps two additional query names to `utm_campaign`:

```yaml
custom_data_properties:
  utm_source: { description: 'Campaign source.', consent_required: true, column: utm_source }
  utm_medium: { description: 'Broad channel: email, social, or cpc.', consent_required: false, column: utm_medium }
  utm_campaign: { description: 'Campaign name; no personal identifiers.', consent_required: true, column: utm_campaign }
  utm_term: { consent_required: true, column: utm_term }
  utm_content: { consent_required: true, column: utm_content }
  utm_id: { consent_required: true, column: utm_id }
  plan: { description: 'Published pricing tier, supplied by emit().', consent_required: true, column: plan_name }
  orgInternalTraffic: { description: 'Shared organization marker.', consent_required: true, column: organization_traffic }
query_parameter_mappings:
  utm_source: utm_source
  utm_medium: utm_medium
  utm_campaign: utm_campaign
  campaign: utm_campaign
  campaign_name: utm_campaign
  utm_term: utm_term
  utm_content: utm_content
  utm_id: utm_id
```

Changing `utm_campaign.consent_required` to `false` deliberately overrides the recommendation and allows all its mapped inputs without consent. No additional bypass flag is required.

The organization marker is collected separately as a coarse boolean in either mode. Its `consent_required` setting governs ordinary property collection if that name later stops being the configured marker; it does not restrict the active marker. Keep it `true` so renaming the marker does not implicitly whitelist its former key. Query parameters and submitted event properties cannot set the active marker.

Rules:

- Each top-level YAML mapping replaces its previous value. A supplied `custom_data_properties` model replaces the built-in model. When `query_parameter_mappings` is absent, only UTM keys present in that model receive default mappings. Set `query_parameter_mappings: {}` to disable automatic query capture; set both mappings to `{}` for no modeled properties or query capture.
- Definitions support `description` (default empty, at most 1,000 bytes), `consent_required` (a YAML boolean, default `true`), `column` (optional text reporting alias), `type` (optional collection type), and `numeric_column` (optional numeric reporting alias). Empty reporting aliases collect the property without projecting it in custom reporting views.
- Property keys and parameter names are case-sensitive, start with a letter, and contain up to 64 letters, digits, underscores, dots, or hyphens. Prototype-related names are rejected. Dots are literal top-level keys, not nested paths.
- Up to 50 properties and 100 parameter mappings are supported. Each source maps to one defined property; multiple sources can share a destination. Configuration order determines priority: the first nonblank parameter value wins, including repeated occurrences of one parameter. Explicit scalar `emit()` properties override URL values, including `false`, `0`, and `null`.
- The SDK reads the current page URL for each event. Attribution is not persisted, carried to later pages, or derived from the referrer. Selected values go into `eventData` and are stored in `events.custom_data`; full query strings and fragments are still discarded.
- Properties remain flat scalars or null, with at most 50 retained keys and strings limited to 500 UTF-8 bytes. Nested values are discarded. Anonymous protections for identifiers, exact dimensions, and UTC hour timestamps remain in place.
- Reporting aliases use up to 63 lowercase letters, digits, or underscores, starting with a letter. They must be unique and cannot collide with built-in columns. Historical marker names outside normal property grammar can remain as reporting-only columns with `consent_required: true`; they cannot become query destinations.
- Collection changes apply to future requests and do not erase historical data. Malformed models prevent the tracker from being served and cause ingestion to fail closed. Synchronize the active YAML across application replicas.

Static/CDN copies need matching public `customData` settings as described in the [tracking guide](TRACKING.md). Browser overrides cannot expand the server whitelist.

## Optional page depth

Enable **Data model → Page depth → Include page depth on all events**, or merge
this setting into the active aggregate YAML configuration:

```yaml
page_sequence_enabled: true
page_sequence_method: session_storage
```

Collection defaults to `false`, and the method defaults to `session_storage`.
Choose **Carry page depth between pages** in the UI to use either tab session
storage or URL parameter passing. These deployment-wide settings have no
environment-variable overrides and use the same validation for UI and YAML.
Unknown methods fail closed. Enabling page depth automatically adds the
reserved numeric `eventData.page_sequence` property to page views, named events
and goals in both privacy modes. No custom-property definition is needed to
collect it. Per-event-type settings remain [planned work](../ROADMAP.md#after-beta-feedback).

Both methods use numbers from `1` through `20`, with `20` meaning **20 or more**.
Other events, including asynchronous callbacks, reuse the
page depth at the time they are emitted. Queue processing preserves that number;
it does not increment or recalculate it. This helps compare early-page activity
with deeper engagement without a visitor or session ID. Page depth is an
approximate client-supplied count, not a count of distinct pages or people.

### Tab session storage

The `session_storage` method stores only this bounded number in tab-scoped `sessionStorage`,
separately for each public website token and origin. It stores no path history,
timestamp or identifier for this feature. The first tracked page is `1`; each new
page load, including a reload, advances it. Tab storage usually ends when the tab
closes, but duplicated or restored tabs may inherit it. If storage is unavailable,
counting continues in memory for the current document and starts over on a new
page load. Disabling page depth while `session_storage` remains selected removes
its current token's stored counter when the tracker next runs with the updated
configuration. Excluded paths do not
advance or expose the counter; the collection kill switch also disables it.

**Anonymous-mode storage is still storage.** Enabling this option permits it
before a consent choice and after rejection or withdrawal of enhanced analytics.
Review applicable browser-storage consent requirements and disclosures before
enabling it; leave it disabled where separate consent would be required. A bounded
count does not guarantee legal anonymity or establish a person's ordered journey.

### URL parameter passing

Select **URL parameter passing** in the UI, or use:

```yaml
page_sequence_enabled: true
page_sequence_method: url_parameter
```

The tracker reads the fixed `aggregate_page_sequence` parameter from the current
page URL. One integer written as `1` through `20` is accepted; missing, duplicate
or malformed values start at `1`. A page opened as
`/example?aggregate_page_sequence=2` sends `eventData.page_sequence: 2` on its
initial view and subsequent asynchronous events.

On an allowed page, the tracker captures this number in memory as soon as its
configuration is initialized, then removes only `aggregate_page_sequence` from
the address bar with `history.replaceState()`. It preserves the existing history
state, other query parameters and the fragment, without reloading or adding a
history entry. The count is not written into history state. Missing or failing
History API support leaves the URL visible but does not stop event collection.
Disabled or excluded pages are not rewritten.

The event's `pagePath` contains only the sanitized pathname, even if URL cleanup
fails. The server also strips queries and fragments from supplied page paths in
both privacy modes. Only the intended numeric `eventData.page_sequence` is sent;
the transport parameter is not included in event page information or ordinary
query-property mappings.

When an ordinary internal link is activated, the tracker adds or replaces that
parameter with the next number, capped at `20`, before native navigation. For
example, a link to `/pricing?plan=team#details` on page 2 becomes
`/pricing?plan=team&aggregate_page_sequence=3#details`. Other query parameters and
the fragment are preserved. Only same-origin HTTP(S) links are eligible, and both
the source and destination must pass sensitive-path exclusions. The feature's
enablement and the collection kill switch still apply.

This applies to unmodified primary-button or keyboard activations targeting the
current tab. Downloads, external links, fragment-only/same-document jumps,
modified or new-tab clicks, forms, programmatic location/history changes and
clicks already canceled by a router are not decorated. The tracker neither
cancels navigation nor sets canonical tags. Explicit SPA page views still advance
the in-memory counter; the SDK does not install route/history listeners or add
the count to the current address bar.

The URL method performs no cookie or Web Storage operations **for the counter**.
It also leaves any old session-storage counter untouched. Other independent
features, including consent-manager storage, organization markers and SDK
identifier cleanup, retain their own behavior. A fresh reload or opening a copied
clean URL starts over at `1`. Back/forward navigation may restore an existing
document with its in-memory count or load a new one; continuity is not guaranteed.
Links copied before cleanup, edited URLs or failed cleanup can still carry
inaccurate depth. The initial numeric value is unverified.

**URL tradeoffs:** cleanup happens after the page request and tracker startup.
The initial server request, logs, earlier scripts or early resource referrers can
still see the parameter. It may briefly appear in the address bar, and remains
visible when JavaScript or history replacement is unavailable. Extra query
variants can complicate SEO and caching; configure canonical URLs without this
parameter where appropriate. Destinations must accept the extra parameter;
signed URLs can become invalid. Test router integrations that wrap
`replaceState()`. This is useful for ordinary link navigation, but less reliable
than tab storage for reloads and history navigation. Avoiding counter Web Storage
is not a legal anonymity or consent exemption guarantee.

### Payload and reporting

For example, with page depth enabled and `utm_medium` separately allowed without
consent, a page view on the second tracked page can send:

```json
{
  "websiteToken": "REPLACE_WITH_PUBLIC_WEBSITE_TOKEN",
  "eventName": "view",
  "pagePath": "/example",
  "referrerChannel": "direct",
  "deviceClass": "desktop",
  "viewportBucket": "large",
  "internalTraffic": false,
  "consentState": "denied",
  "eventData": {
    "utm_medium": "email",
    "page_sequence": 2
  }
}
```

For single-page applications, call `Aggregate.trackView()` after a virtual page
change. It sends a page view and advances the counter. `emit('view')` also advances
it; use one call per page change. Normal `emit()` calls reuse the current number.
The SDK does not install automatic SPA route/history listeners. See the
[tracking guide](TRACKING.md#page-depth-and-single-page-apps) for an example.

The SDK ignores supplied `page_sequence` event properties and ordinary URL mappings.
Only the URL method reads the reserved `aggregate_page_sequence` parameter.
The server independently strips the property in both modes when disabled and
accepts only JSON integers from `1` through `20` when enabled. Query mappings to
it, or from `aggregate_page_sequence` to another property, are invalid; an
organization marker using `page_sequence` must be renamed before
enabling page depth. Direct API clients must supply their own bounded number;
the server validates its shape and permission, not the claimed navigation history.

Page depth stays in the existing `custom_data` JSON. To add an optional private
numeric reporting column, merge this definition into your existing
`custom_data_properties` mapping:

```yaml
page_sequence:
  description: 'Tracked page depth; 20 means 20 or more.'
  type: integer
  consent_required: false
  numeric_column: page_sequence_number
```

Regenerate custom views using the existing UI/CLI workflow after reviewing column
compatibility. Approved `bi_anonymous_*` views and archive aggregates do not gain
this dimension. Custom views remain private and unsuppressed; raw-row deletion
removes their page-depth detail.

**Existing models:** `page_sequence` is now reserved for collection. Existing
definitions can remain for historical reporting while the feature is disabled;
incoming event properties under this key are stripped. Enabling the counter
requires any definition to use `type: integer` and `consent_required: false`.
If the old key meant something else, plan a data/reporting migration before using
it for page depth; keep historical column meanings stable for consumers.
Historical JSON is not rewritten, and older values do not become trustworthy
page counts merely because the feature is enabled. The separate organization
marker remains supported under its existing name while page depth is disabled.
The `aggregate_page_sequence` query source is also reserved; remove any ordinary
query mapping from that name before using this version.

## Property types and numeric calculations

Missing `type`, or `type: scalar`, preserves existing flat-scalar collection.
Explicit types apply in the SDK and on the server. A mismatched property is
omitted; the underlying event can still be accepted. Null remains allowed for
each type, and existing consent and scalar bounds still apply.

| YAML type | Accepted JSON values | Example |
| --- | --- | --- |
| `scalar` | String, finite number, boolean, or null | `"email"`, `12.5`, `true` |
| `string` | String or null, without converting other values | `"USD"` |
| `integer` | Whole number within ±9,007,199,254,740,991, or null | `4999` |
| `float` / `double` | Finite number or null | `12.5`, `0.1` |
| `boolean` | Boolean or null | `true` |

JSON has one number type; it does not carry a float32/double64 distinction.
Both `float` and `double` use approximate double precision in the portable
numeric projections. They do not provide exact decimal-money arithmetic.
Integers use JavaScript's safe-integer range, and fractional values are never
truncated to integers. Numeric strings such as `"12.5"`, and truthy strings such
as `"false"`, do not satisfy numeric or boolean declarations.
Checks operate on decoded numbers: digits already lost through floating-point
rounding or underflow during JSON parsing cannot be recovered. Supply exact
money amounts as integer minor units at the source.

URL parameters are strings. A parameter mapped to a numeric or boolean typed
property is therefore omitted unless an explicit correctly typed `emit()` value
overrides it. Supply numbers/booleans through `emit()` or direct JSON requests;
use a separate text property when you need the original URL value. The active
organization marker remains a controlled boolean regardless of model settings.
The reserved page-depth URL parameter uses its own bounded integer parser; it
does not change these ordinary query-mapping rules.

```yaml
custom_data_properties:
  currency: { type: string, consent_required: true, column: currency }
  total_minor: { type: integer, consent_required: true, column: total_minor_text, numeric_column: total_minor_number }
  discount_rate: { type: double, consent_required: true, numeric_column: discount_rate_number }
query_parameter_mappings: {}
```

Merge these definitions into your existing model; do not overwrite unrelated
properties or mappings. `column` retains its existing text semantics.
`numeric_column` adds a separate numeric alias for an explicitly declared
`integer`, `float`, or `double` property. Aliases must be unique across both
kinds of columns. Numeric aliases follow the existing text aliases in generated
views. Regeneration preserves deployed column order and rejects incompatible
type changes; plan a database/reporting migration when a deployed contract must
change. Do not change an existing text alias into a numeric alias in place.
Because generated text aliases precede numeric aliases, adding a text alias
after numeric aliases are already deployed also requires a planned migration;
regeneration rejects the resulting reorder.

Numeric projections return SQL `NULL` for missing/null values, strings,
booleans, structured data, and numbers outside their supported range. Integer
projections also return `NULL` for fractional values. They do not reinterpret
old numeric strings. Existing retained rows are not rewritten when a model type
changes. Keep all `analytics_custom_*` access private; typed columns do not
expand the approved anonymous BI contract.

See [event examples and ecommerce](EVENT-EXAMPLES.md) for copyable JSON, headless
exports, and the recommended integer-minor-unit money representation.

## Discover and regenerate

1. Open **Data model → Observed properties → Refresh observed properties** to sample up to 1,000 latest retained events with custom data. The page shows keys, types, and occurrence counts without sample values; this is not a complete historical inventory. Opening the model editor does not query observed events or reporting SQL.
2. Select **Review in model editor**, choose the new row's consent/type/reporting policy, then save. Ordinary new properties start with enhanced consent required. The reserved `page_sequence` row starts as an anonymous-permitted Integer; its separate switch still controls collection. The link only opens an unsaved row and never saves automatically.
3. Open **Reporting → Reporting views**, review the saved SQL preview, and click **Regenerate views from saved model**. Saving and view replacement are separate operations.
4. Download YAML or open **Event examples** for synthetic JSON. Exports omit website tokens, sharing tokens, and application secrets.

Headless commands:

```bash
php bin/console app:analytics:views:regenerate --discover --sample-size=1000
php bin/console app:analytics:views:regenerate --export-model > aggregate-data-model.yaml
php bin/console app:analytics:views:regenerate --dry-run
php bin/console app:analytics:views:regenerate
```

`--discover`, `--export-model`, and `--dry-run` are mutually exclusive read-only modes. Edit YAML before using the CLI to regenerate.

## BI labels and column definitions

The [BI glossary](BI-GLOSSARY.md) uses each saved property's `description` as its
column definition in the default locale; this editor and YAML remain the source
for that text. Add translations and display labels under `bi_glossary.columns`,
and declare value labels under the property's **text reporting alias** in
`bi_glossary.values`. Numeric aliases receive column metadata but are not value
dimensions. Declaring a value publishes its code to routine BI readers, including
when the property's actual event values require consent and stay private.

Successful view regeneration also synchronizes glossary metadata. A plain model
save does not regenerate views or automatically publish their column changes;
use the existing separate regeneration step. Manual glossary sync reads saved
aliases without inspecting deployed SQL, so regenerate first after a model change
to avoid publishing metadata for columns that are not yet deployed.
If glossary synchronization fails
after views are deployed, correct the metadata and run
`php bin/console app:analytics:glossary:sync`. Generated custom views remain private,
and the glossary never adds custom dimensions to the anonymous fact views.

## Reporting contract

| View | Retained rows |
| --- | --- |
| `analytics_custom_events_v1` | All events in both privacy modes |
| `analytics_custom_pageviews_v1` | Events named `view` |
| `analytics_custom_goals_v1` | Events with a non-null approved goal |

Each view contains built-in context plus configured text columns followed by optional numeric columns. Existing `column` aliases project strings, numbers, and booleans as text; booleans use `true`/`false`. Missing keys, JSON null, arrays, and objects produce SQL `NULL`. The additive `numeric_column` behavior is described above.

**These are private, unsuppressed views of individual retained events.** Apply raw-table access restrictions and appropriate aggregation/disclosure controls before sharing results. Existing `bi_anonymous_*` views retain their grouped and suppressed contracts. Custom columns are separate because archives discard JSON and geographic BI views deliberately restrict dimensions.

Rows marked `archived_at` remain in custom views while retained; archive aggregates are not unioned into them. Once retention deletes a raw row, its properties disappear from these views. Regeneration can project a newly modeled key from older retained JSON, but cannot reconstruct values never collected or already deleted.

PostgreSQL, MySQL/MariaDB, SQL Server, and SQLite with JSON functions are supported. Run migrations first. Use the intended reporting-view owner, particularly on MySQL/MariaDB where the invoking account becomes the view definer, with the required read/view privileges.

Keep existing aliases and their order; append new columns at the end. Regeneration preserves server-database grants through replace/alter and rejects removal, renaming, or reordering of deployed columns. Changing the JSON key behind an alias changes its meaning for every retained row; coordinate this with report consumers. Removing or renaming a deployed column needs a separately planned database/report migration.

PostgreSQL, SQL Server, and SQLite replacements are transactional. MySQL/MariaDB commit each view separately; queries are preflighted first, but later DDL failures can leave some views updated. Correct the issue and rerun. A dry run prints SQL without checking live schema, grants, or data.
