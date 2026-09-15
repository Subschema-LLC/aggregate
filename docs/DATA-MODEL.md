# Custom data model and reporting

[Configuration](CONFIGURATION.md) · [Tracking](TRACKING.md) · [Privacy](PRIVACY-COMPLIANCE.md)

The **Data model** admin page at `/dashboard/data-model` defines custom properties, their consent requirements, query-parameter mappings, and reporting columns. Download the saved YAML to share the contract with analytics implementation developers. These settings use the active `config/aggregate.yaml` environment or `config/aggregate_<environment>.yaml`; they have no uppercase environment-variable overrides.

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
- Definitions support `description` (default empty, at most 1,000 bytes), `consent_required` (a YAML boolean, default `true`), and `column` (default empty). An empty column collects the property without projecting it in custom reporting views.
- Property keys and parameter names are case-sensitive, start with a letter, and contain up to 64 letters, digits, underscores, dots, or hyphens. Prototype-related names are rejected. Dots are literal top-level keys, not nested paths.
- Up to 50 properties and 100 parameter mappings are supported. Each source maps to one defined property; multiple sources can share a destination. Configuration order determines priority: the first nonblank parameter value wins, including repeated occurrences of one parameter. Explicit scalar `emit()` properties override URL values, including `false`, `0`, and `null`.
- The SDK reads the current page URL for each event. Attribution is not persisted, carried to later pages, or derived from the referrer. Selected values go into `eventData` and are stored in `events.custom_data`; full query strings and fragments are still discarded.
- Properties remain flat scalars or null, with at most 50 retained keys and strings limited to 500 UTF-8 bytes. Nested values are discarded. Anonymous protections for identifiers, exact dimensions, and UTC hour timestamps remain in place.
- Reporting aliases use up to 63 lowercase letters, digits, or underscores, starting with a letter. They must be unique and cannot collide with built-in columns. Historical marker names outside normal property grammar can remain as reporting-only columns with `consent_required: true`; they cannot become query destinations.
- Collection changes apply to future requests and do not erase historical data. Malformed models prevent the tracker from being served and cause ingestion to fail closed. Synchronize the active YAML across application replicas.

Static/CDN copies need matching public `customData` settings as described in the [tracking guide](TRACKING.md). Browser overrides cannot expand the server whitelist.

## Discover and regenerate

1. Open **Data model → Refresh observed properties** to sample up to 1,000 latest retained events with custom data. The page shows keys, types, and occurrence counts without sample values; this is not a complete historical inventory.
2. Add selected properties, choose their consent policy and reporting alias, then save. Discovery never enables collection or publishes columns automatically.
3. Review the saved SQL preview and click **Regenerate views from saved model**. Saving and view replacement are separate operations.
4. Download YAML for developers. It contains only the model and query mappings, without website tokens, sharing tokens, or application secrets.

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

Each view contains built-in context plus configured scalar columns. Strings, numbers, and booleans are projected as text; booleans use `true`/`false`. Missing keys, JSON null, arrays, and objects produce SQL `NULL`.

**These are private, unsuppressed views of individual retained events.** Apply raw-table access restrictions and appropriate aggregation/disclosure controls before sharing results. Existing `bi_anonymous_*` views retain their grouped and suppressed contracts. Custom columns are separate because archives discard JSON and geographic BI views deliberately restrict dimensions.

Rows marked `archived_at` remain in custom views while retained; archive aggregates are not unioned into them. Once retention deletes a raw row, its properties disappear from these views. Regeneration can project a newly modeled key from older retained JSON, but cannot reconstruct values never collected or already deleted.

PostgreSQL, MySQL/MariaDB, SQL Server, and SQLite with JSON functions are supported. Run migrations first. Use the intended reporting-view owner, particularly on MySQL/MariaDB where the invoking account becomes the view definer, with the required read/view privileges.

Keep existing aliases and their order; append new columns at the end. Regeneration preserves server-database grants through replace/alter and rejects removal, renaming, or reordering of deployed columns. Changing the JSON key behind an alias changes its meaning for every retained row; coordinate this with report consumers. Removing or renaming a deployed column needs a separately planned database/report migration.

PostgreSQL, SQL Server, and SQLite replacements are transactional. MySQL/MariaDB commit each view separately; queries are preflighted first, but later DDL failures can leave some views updated. Correct the issue and rerun. A dry run prints SQL without checking live schema, grants, or data.
