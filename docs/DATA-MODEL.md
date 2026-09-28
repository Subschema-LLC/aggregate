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
```

The default is `false`. This deployment-wide setting has no environment-variable
override and uses the same validation for UI and YAML. It automatically adds the
reserved numeric `eventData.page_sequence` property to page views, named events
and goals in both privacy modes. No custom-property definition is needed to
collect it. Per-event-type settings remain [planned work](../ROADMAP.md#after-beta-feedback).

The first tracked page is `1`, the next is `2`, and the counter stops at `20`,
meaning **20 or more**. Other events, including asynchronous callbacks, reuse the
page depth at the time they are emitted. Queue processing preserves that number;
it does not increment or recalculate it. This helps compare early-page activity
with deeper engagement without a visitor or session ID. It counts tracked page
views, including reloads, rather than distinct pages or people.

The tracker stores only this bounded number in tab-scoped `sessionStorage`,
separately for each public website token and origin. It stores no path history,
timestamp or identifier for this feature. Tab storage usually ends when the tab
closes, but duplicated or restored tabs may inherit it. If storage is unavailable,
counting continues in memory for the current document and starts over on a new
page load. Disabling the feature removes its current token's stored counter when
the tracker next runs with the updated configuration. Excluded paths do not
advance or expose the counter; the collection kill switch also disables it.

**Anonymous-mode storage is still storage.** Enabling this option permits it
before a consent choice and after rejection or withdrawal of enhanced analytics.
Review applicable browser-storage consent requirements and disclosures before
enabling it; leave it disabled where separate consent would be required. A bounded
count does not guarantee legal anonymity or establish a person's ordered journey.

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
The SDK does not install automatic navigation listeners. See the
[tracking guide](TRACKING.md#page-depth-and-single-page-apps) for an example.

The SDK ignores supplied `page_sequence` event properties and URL mappings.
The server independently strips the property in both modes when disabled and
accepts only JSON integers from `1` through `20` when enabled. Query mappings to
it are invalid; an organization marker using this name must be renamed before
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
