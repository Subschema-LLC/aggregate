# Optional tag manager

## Aggregate Tag Manager Lite

The tag manager loads HTTPS scripts or calls methods on libraries already loaded
on the page. Each tag has a trigger and consent requirement, with optional named
variables from a data layer. The manager itself can load without consent. Each
tag defaults to analytics consent; choose another category or explicitly use
`none` for an action that needs no consent.

The manager is disabled by default. Each registered website has its own YAML,
CMP settings, and remotely hosted scripts. Nothing is stored in a tag database
table, and script serving works with `dashboard_enabled: false`.

Open **Collection → Tag manager**, choose a website, and edit its CMP, variables,
actions, triggers, and consent categories. Save to add another row, disable tags
without deleting them, or select **Remove this tag when saving**. **Setup** copies
or downloads the selected website's installation snippet and drop-ins.

## Website instances and remote hosting

The UI shows each website's public script instance ID. Headless operators can
list IDs and YAML paths with:

```bash
php bin/console app:tag-manager:sites
```

The ID is the first 24 hexadecimal characters of SHA-256 of the website's public
registration token. It identifies configuration; it is not a visitor identifier
or authentication secret. Changing that token changes the instance ID. Move the
intended YAML and regenerate snippets deliberately when rotating it. Duplicate
registration tokens cannot serve an instance.

The Aggregate server hosts each site's scripts at:

```text
https://analytics.example.com/tms-lite/sites/<site-id>/lib.js
https://analytics.example.com/cmp-lite/sites/<site-id>/consent.js
```

These are generated responses under separate URL directories, not copies of
operator YAML in the public web root. Each website references its own scripts
remotely. If Aggregate runs in a subdirectory, include it in `app_host`; generated
URLs retain that prefix. The URL selects the instance explicitly rather than
inferring it from a visitor's domain or Referer. Configuration is public, so the
instance ID is not an access-control boundary. Install one CMP/tag-manager
instance per page.

The downloaded `tag-loader.js` retrieves that site's live hosted library. YAML
changes apply on the next page load without downloading the loader again. The
downloaded CMP is a snapshot of its categories, namespace, name and instance ID;
download it again after changing those settings, or use the hosted CMP URL.

## YAML configuration

Store each site's configuration in
`config/tag-manager/sites/<site-id>.yaml`. The UI writes that source and offers
**Download YAML** for its active settings. These operator files are ignored by
Git and excluded from release ZIPs. Preserve the directory during deployments.

The same [environment precedence](CONFIGURATION.md#application-settings) applies:
`<site-id>_prod.yaml` takes precedence in `prod`; otherwise the main file can
contain an `environments` mapping. UI saves preserve other environments,
unrelated keys, and neighboring site files. Tag/CMP settings have no uppercase
environment overrides. New instances start with disabled tags and do not inherit
shared configuration.

The supplied CMP defaults to enabled, using the registered website's name. Its
optional categories come from that site's enabled tags, plus analytics for the
tracker. Disable it when integrating another CMP; disabling grants no consent.
Names contain 1–120 UTF-8 bytes without control characters.

```yaml
consent_manager:
  enabled: true
  name: Example shop
tag_manager:
  enabled: true
  variables:
    order_total: ecommerce.total_minor
    first_sku: ecommerce.items[0].sku
  tags:
    - id: site-helper
      type: script
      src: 'https://scripts.example.com/site-helper.js'
      consent: none
      trigger: {type: window_load}
    - id: product-helper
      type: script
      src: 'https://scripts.example.com/product.js?sku={{first_sku}}'
      consent: functional
      trigger: {type: data_layer, event: product_view}
    - id: record-purchase
      type: call
      method: Aggregate.emit
      args: [purchase, {total_minor: {$var: order_total}, sku: {$var: first_sku}}]
      consent: analytics
      trigger: {type: data_layer, event: purchase}
```

Defaults are `enabled: false`, `variables: {}`, and `tags: []` for the manager;
individual tags default to `enabled: true`, `type: script`, `consent: analytics`,
and `trigger: {type: dom_ready}`. Booleans must be YAML `true` or `false`, not
quoted strings or numbers. An invalid entry prevents that instance from serving
executable tags until corrected; other sites remain independent. Disabled
entries are validated too.

The list supports up to 20 tags. IDs are unique, begin with a letter, and contain
1–64 letters, digits, underscores or hyphens. Script URLs are unique, absolute
HTTPS URLs of at most 2048 bytes, without credentials, fragments, spaces,
backslashes or control characters. URL templates may substitute declared
`{{alias}}` values only in the path or query, keeping the scheme and host fixed.

The optional **Shared configuration** selection uses `tag_manager` in the active
`config/aggregate.yaml` instead, served at `/lib.js` with `/consent-manager.js`.
It is a separate instance; website instances never inherit its tags. Generated
website snippets always use the website-specific paths above. Tracker collection
settings and data models still use their existing deployment-wide configuration.

## Actions and arguments

`type: script` inserts the configured `src` asynchronously once per page after
its trigger and consent requirement are met. A URL variable is URL-encoded;
a missing, null, oversized or nonscalar value skips the tag.

`type: call` invokes an already available API such as `Aggregate.emit`. Use your
configured tracker namespace when it differs. The method's receiver is preserved,
so APIs using `this` work. Method paths contain library and method identifiers;
calls, expressions, bracket notation, constructors, prototype traversal and
browser execution APIs are rejected. Only own data properties are resolved;
getters and inherited methods are unsupported. No JavaScript strings are evaluated.

Arguments are a YAML list of JSON-compatible literals, with nested maps/lists.
An exact `{$var: alias}` object inserts a named variable's scalar value while
preserving its type. Missing references skip the whole call. The UI edits these
arguments as JSON, for example:

```json
["purchase", {"total_minor": {"$var": "order_total"}, "currency": "USD"}]
```

Calls support at most 10 arguments, four nested containers, 32 entries per
container and 128 total values including containers. Strings are at most 2048
UTF-8 bytes. Numbers must be finite and integers inside JavaScript's safe range.
The selected method retains normal website privileges; this is not a sandbox
for untrusted libraries.

Scripts are asynchronous and there is no dependency ordering, polling, or retry
timer. A call does not wait for another tag's script download. Load its library
before the manager or dispatch a provider-ready event after the API exists.
A missing method skips the action; a later matching event can try again.

A call to `Aggregate.emit` preserves the tracker's consent and property rules.
Granting a method tag's `marketing` category does not grant enhanced analytics.
Calls made while analytics consent is denied retain only the anonymous fields
permitted by the tracker and server. See the [data model](DATA-MODEL.md).

## Trigger and browser-event dictionary

| YAML trigger | Browser equivalent | When it runs |
| --- | --- | --- |
| `{type: dom_ready}` | `document.addEventListener('DOMContentLoaded', ...)` | Once markup is ready, including an already ready document. Default. |
| `{type: window_load}` | `window.addEventListener('load', ...)` | Once resources finish loading, including an already completed load. |
| `{type: document_event, event: click}` | `document.addEventListener('click', ...)` | Any matching document event; no selector filtering. |
| `{type: document_event, event: 'cart:updated'}` | `document.dispatchEvent(new CustomEvent('cart:updated', {detail: ...}))` | An explicit application event. |
| `{type: window_event, event: hashchange}` | `window.addEventListener('hashchange', ...)` | A URL fragment change. |
| `{type: window_event, event: popstate}` | `window.addEventListener('popstate', ...)` | History traversal; `pushState()` alone does not dispatch it. |
| `{type: data_layer, event: purchase}` | `window.dataLayer.push({event: 'purchase', ...})` | Each object whose own `event` matches. |

Other named document/window events work similarly, including `submit`,
`visibilitychange` and custom events. Use `dom_ready` for document readiness and
`window_load` for page load; `document.onload` is not `DOMContentLoaded`.
Event names are case-sensitive and use 1–100 letters, digits, underscores, dots,
colons or hyphens, beginning with a letter. Listeners do not cancel the original
event or automatically read form values.

Consent is checked when events are received and again before execution. Denied
events are not replayed after consent is granted. Lifecycle tags can become
eligible after a grant when readiness already holds. Script tags run once per
page; lifecycle method calls run once, while event method tags can run repeatedly.

## Data layer and variables

Use the familiar array and push syntax before or after loading the manager:

```javascript
window.dataLayer = window.dataLayer || [];
window.dataLayer.push({
  event: 'purchase',
  ecommerce: {total_minor: 1299, items: [{sku: 'example-sku'}]}
});
```

Queued objects are processed in order. Later pushes update an in-memory model:
plain objects merge recursively, arrays replace, and `null` clears a branch.
Only an `event` on the new object triggers tags; a retained event name does not.
Each event uses an immutable snapshot of its values and consent state. Existing
`push` behavior and return values are preserved. Keep the same array after
installation; replacing `window.dataLayer` bypasses its wrapper.

This is a small data-layer subset, not GTM container compatibility. Function
entries, `gtag()` command arrays, GTM internal events, automatic click/form
variables and Google Consent Mode are not interpreted. The data layer does not
transmit events to Aggregate on its own; configure a method tag to do so.

Define up to 32 named variables in YAML or the UI's name/path rows:

```yaml
variables:
  order_total: ecommerce.total_minor
  first_sku: ecommerce.items[0].sku
  selected_plan: event.detail.plan
```

Paths use dot-separated keys and numeric bracket indexes, with at most 16
segments, 256 characters, and array indexes up to 10000. No expressions or
arbitrary globals are read. Prototype keys, getters, inherited values and quoted
bracket keys are unsupported. References resolve scalars; choose a deeper path
to select a property from an object or array.

For document/window triggers, `event.type` and an explicitly supplied
`CustomEvent.detail` are available alongside the latest data-layer model:

```javascript
document.dispatchEvent(new CustomEvent('plan:selected', {
  detail: {plan: 'team'}
}));
```

For data-layer triggers, `event` remains the model's event-name string. No element
text, form fields or identifiers are extracted automatically. Snapshots are
bounded and recursive event loops are cut off; do not use the data layer as
unbounded application storage. Every page script can read it, so keep secrets
out and review any data sent to providers.

## Install and connect consent

Use **Setup** for the selected website's ordered CMP, tracker and optional tag
snippet. For only the tag library, substitute that site's public ID:

```html
<script src="https://analytics.example.com/tms-lite/sites/SITE_ID/lib.js?min=1" defer referrerpolicy="no-referrer"></script>
```

Categories contain 1–32 lowercase letters, digits, underscores or hyphens,
beginning with a letter. Common names are `analytics`, `functional` and `marketing`.
Only explicit boolean `true` grants a category. The reserved `none` bypasses
consent for that action; it can run on its trigger before any choice and after
rejection. The operator must determine whether that is appropriate.

The built-in CMP works in either script load order. For another CMP, pass the
complete category state after loading the manager and whenever it changes:

```javascript
window.AggregateTags.setConsent({
  analytics: consentManager.getConsent('analytics') === true,
  functional: consentManager.getConsent('functional') === true,
  marketing: consentManager.getConsent('marketing') === true
});
```

Maps replace all grants; missing, inherited or invalid values are denied.
Boolean calls change only analytics, preserving other categories. Pass `{}` to
revoke all named categories. Revocation prevents future actions in those
categories, but cannot undo executed code, cancel requests already sent, stop a
running provider, remove its cookies, or erase history. Integrate provider
withdrawal controls and reload as appropriate. `none` actions remain eligible.

The built-in integration uses `AggregateConsent.getState()` and
`AggregateConsent.subscribe(callback)`. A later CMP announces readiness with
`document.dispatchEvent(new Event('aggregate:consent-ready'))`. The CMP stores
choices per origin, namespace and website instance; the tag manager itself
writes no cookies or persistent browser storage. It does not grant tracker
consent or change collection permissions. The event collection kill switch and
excluded paths do not disable independently configured tag actions.

Script URLs, method arguments and variable paths are public. External libraries
have normal website-script privileges. Review providers and allow their script
origins under your CSP. The loader forwards its script nonce to inserted scripts;
provider requests use `referrerpolicy="no-referrer"`, but running providers may
still read page and browser information.

## Optional minification

Use **Setup → Build browser scripts** or:

```bash
php bin/console app:assets:build-js
```

Request the hosted scripts with `?min=1`. Builds contain configuration
placeholders; the server inserts current site settings on every response.
Missing, stale or corrupt builds fall back to current source. PHP serves the
same build templates for every website; a YAML edit does not require rebuilding.
Static `public/tag-manager.js` retains disabled defaults; install the configured
route or downloaded site loader. See [JavaScript builds](JS-BUILD.md).

Verify each trigger, category, rejection, withdrawal and missing-variable case
with synthetic values. Test provider cleanup separately. The manager cannot
enforce a provider's behavior after its code has executed.
