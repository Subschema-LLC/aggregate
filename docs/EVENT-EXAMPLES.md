# Event examples and ecommerce

[Data model](DATA-MODEL.md) · [Tracking](TRACKING.md) · [Beta testing](BETA-TESTING.md)

## Generate from the saved model

Open **Collection → Event examples** to generate anonymous and enhanced request
JSON from the active saved model. Use **Copy anonymous JSON** or **Copy enhanced
JSON**, or download the complete bundle with its consent/type notes. Unsaved
model edits do not affect these examples. Clipboard access normally requires
HTTPS; if the browser refuses access, the page selects the text for manual copy.

The same read-only generator is available with the dashboard disabled:

```bash
php bin/console app:analytics:examples > event-examples.json
php bin/console app:analytics:examples --mode=anonymous
php bin/console app:analytics:examples --mode=enhanced
php bin/console app:analytics:examples --example=ecommerce > ecommerce-examples.json
```

The command writes JSON to stdout and errors to stderr. The generator reads no
events or website registrations, sends no requests, and changes no settings.
Normal Symfony/Doctrine startup still requires infrastructure configuration;
provide the documented `DATABASE_URL` and server version for your database.
Examples use fixed synthetic values, never descriptions or observed values.
Invalid model or collection configuration prevents saved-model generation.

The versioned bundle includes `schema_version`, `synthetic`, `properties`, notes,
and `examples.anonymous.payload` / `examples.enhanced.payload`. Send only a
`payload` as the request body. Replace the public website-token placeholder and
use an Origin registered for that website. Do not use an administrator or
organization sharing token. Copying `consentState: "granted"` does not obtain
consent; wire enhanced collection to an actual affirmative choice.

Anonymous examples contain modeled properties explicitly allowed without
consent and `page_sequence: 2` when the separate [page-depth setting](DATA-MODEL.md#optional-page-depth)
is enabled. The same generated count appears in enhanced and ecommerce examples.
Enhanced examples illustrate permitted modeled properties; unlisted ordinary
scalar properties still require enhanced consent. Reserved marker and legacy
reporting-only keys are identified but omitted from `eventData`; disabled page
depth is also omitted. The server supplies timestamps and optional geography.
Goals have their own allowlist and are omitted. Kill switches and path exclusions
still apply.

When the [strict collection profile](PRIVACY-COMPLIANCE.md#strict-collection-profile)
is active, the complete bundle begins with `examples.strict.payload`, containing
only `websiteToken`, `eventName` and `pagePath`, and a note that the server keeps
nothing else. The anonymous and enhanced examples remain for reference; the page
shows a matching notice. `--mode=anonymous` and `--mode=enhanced` are unchanged.

## A flat ecommerce starting point

The UI's **Ecommerce recipe** and `--example=ecommerce` provide a proposed model
and synthetic purchase payloads. **Ecommerce purchase JSON** opens first, with
copy and download controls; **Anonymous collection JSON** shows the request
without consent-dependent properties. **Recommended ecommerce model (YAML)**
contains the matching property configuration. This is a recommendation, not your
saved model; generation never installs it. Review and merge the proposed
definitions into your existing model, then regenerate saved-model examples and
any new reporting columns. All proposed ecommerce properties require enhanced
consent by default.

Keep event properties flat. For a purchase, use a total, currency, item count,
and broad product category. Avoid customer/order/cart identifiers, email
addresses, delivery information, payment details, and arrays of purchased items.
Do not place identifying data in event names or page paths. A fixed `purchase`
event name does not automatically record the separately configured purchase goal.

The [complete ecommerce purchase JSON](examples/ecommerce-purchase.json) is a
synthetic request body for `POST /api/receive`, separate from the model YAML.
Replace the public website-token placeholder and connect enhanced tracking to
explicit analytics consent. The visitor/session identifiers below are fixed
examples, not identifiers to reuse for actual visitors. Copying `"granted"` does
not obtain consent. Anonymous requests omit these identifiers and all proposed
ecommerce properties by default.

```json
{
  "websiteToken": "REPLACE_WITH_PUBLIC_WEBSITE_TOKEN",
  "eventName": "purchase",
  "pagePath": "/checkout/complete",
  "referrerChannel": "direct",
  "deviceClass": "desktop",
  "viewportBucket": "large",
  "internalTraffic": false,
  "consentState": "granted",
  "visitorId": "synthetic-visitor",
  "sessionId": "synthetic-session",
  "screenWidth": 1440,
  "eventData": {
    "currency": "USD",
    "total_minor": 4999,
    "tax_minor": 400,
    "shipping_minor": 500,
    "item_count": 2,
    "discount_rate": 0.1,
    "product_category": "accessories",
    "checkout_step": "complete"
  }
}
```

Here `total_minor` is the final charged total, including tax and shipping;
the component fields are a breakdown, not additional amounts to add again.
For USD, `4999` represents $49.99. Use the minor unit appropriate to each
currency, and group calculations by currency. Never sum unlike currencies.
Integers preserve money amounts without binary floating-point rounding within
the supported safe-integer range. Use `double`/`float` for approximate ratios or
measurements such as `discount_rate`, not exact monetary amounts.

Define types using YAML `type` or the model editor's **JSON value type**. JSON
strings are quoted, while numbers and booleans are not. The SDK and server omit
mismatched typed values; they do not convert `"4999"` into `4999`, or `49.99`
into `49`. See the [type rules](DATA-MODEL.md#property-types-and-numeric-calculations).

Emit a purchase once when checkout actually completes; page refreshes and retries
can create duplicate events. This recipe does not add order-based deduplication.
For item-level analysis, consider a separate fixed event per broad item category
only when there is a concrete need and a reviewed privacy policy. Do not create
tables per event type or duplicate order totals in each item event.

## Numeric views for external calculations

Keep a property's existing `column` when a BI connection expects text. Add a
distinct `numeric_column` for arithmetic instead of silently changing its type.
Integer projections reject fractions and out-of-range values; float/double
projections preserve fractions with approximate double precision. Numeric
strings in historical rows remain `NULL` in numeric projections.

These projections belong to private, unsuppressed `analytics_custom_*` views.
Use an appropriately authorized reporting connection for enhanced ecommerce
analysis. Routine anonymous BI users must remain limited to approved
`bi_anonymous_*` views, with their existing completed buckets and suppression.
Do not combine raw or typed custom projections with those views to recover
withheld cells. Visualization and report delivery remain in external tools.
