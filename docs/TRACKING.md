# Tracking and Google Tag Manager

[README](../README.md) · [Configuration](CONFIGURATION.md) · [Privacy and compliance](PRIVACY-COMPLIANCE.md)

This guide covers the browser SDK, custom events, and GTM setup. Register a website in the dashboard or with `php bin/console app:create-website` first, and replace the example host and public website token with your values. The sharing token used for organization marker pages is a different token and must never be placed in a tracking snippet.

Configure [allowed event-source domains](CONFIGURATION.md#website-domains) on the
Websites page or in `config/websites.yaml`. New registrations default to the exact
primary hostname. Add explicit hosts or wildcard subdomains to share one token
across approved hosts, or choose **Allow all domains**. No tracker changes are
needed when a registration's domain rules change.

- [JavaScript integration](#javascript-integration)
- [Custom event tracking](#custom-event-tracking)
- [Page depth and single-page apps](#page-depth-and-single-page-apps)
- [Strict collection profile](#strict-collection-profile)
- [UTM and custom data collection](#utm-and-custom-data-collection)
- [Health and ingestion checks](#health-and-ingestion-checks)
- [Google Tag Manager](#google-tag-manager-gtm-integration)
- [Troubleshooting](#troubleshooting)

## JavaScript integration

**Websites** and the **Setup** wizard offer window configuration (the default),
query parameters, or tag-manager installation. The
[setup guide](SETUP.md#headless-installation) includes complete headless examples
with the website's optional CMP. For a direct tracker installation with your own
consent wiring, add this once to your website:

```html
<script>
  window.Aggregate = {
    endpoint: 'https://your-host/api/receive',
    websiteToken: 'your-website-token',
    consent: false
  };
</script>
<script src="https://your-host/aggregate.js?min=1" defer referrerpolicy="no-referrer"></script>
```

Alternatively, supply the public configuration in the SDK URL:

```html
<script src="https://your-host/aggregate.js?min=1&amp;endpoint=https%3A%2F%2Fyour-host%2Fapi%2Freceive&amp;token=your-website-token&amp;consent=0" defer referrerpolicy="no-referrer"></script>
```

URL-encode parameter values. `consent=0` supplies a denied default; an explicit
analytics choice initialized by the CMP in the configured `window` namespace
before the SDK loads takes precedence, and later choices use `setConsent`.
Load the configured CMP before the tracker when using the built-in chooser.

The **Tag manager** choice loads the website's CMP when enabled and its tag
container only. It offers the tracker URL separately for a script action;
you must add that action and enable the container yourself. See
[loading the tracker through Aggregate Tag Manager Lite](TAG-MANAGER.md#load-the-tracker-through-the-manager).
Use one tracker installation per page: direct, through that manager, or through
GTM. Loading it twice can send duplicate page views. Managed scripts load
asynchronously; listing method calls after a script tag does not make them wait
for that library.

An anonymous-mode page-view row is recorded automatically when the script loads. Full query strings, fragments, raw referrers, cookie values, visitor IDs, and session IDs are not sent. You may also call `emit(...)`: before enhanced consent, each safe event name and its coarse context are retained, configured goals marked `anonymous: true` may be retained, and ordinary custom properties require `consent_required: false`. The separately enabled [page-depth counter](#page-depth-and-single-page-apps) may also be sent.

## Custom event tracking

Use a fixed event taxonomy. Names must match `[A-Za-z][A-Za-z0-9_.:-]{0,99}` and must not contain user-entered or identifier-like values.
Even with enhanced consent, keep event properties purpose-limited and avoid emails, account IDs, form contents, search terms, or other free text.

Call SDK methods after the script has loaded. The following sequence illustrates the API; connect the consent calls to actual consent-manager choices rather than running the whole sequence on page load. The SDK does not queue calls made before it exists.

```javascript
// Anonymous mode records the safe event name, coarse context, and the allowlisted
// `signup` goal. The custom property is omitted unless configured consent-free.
window.Aggregate.emit('signup_click', { plan_type: 'pro' }, 'signup');

// Accept enhanced analytics to include properties, identifiers, and exact dimensions.
window.Aggregate.setConsent(true);
window.Aggregate.emit('signup_click', { plan_type: 'pro' }, 'signup');

// Reject or withdraw enhanced analytics. Coarse named-event rows and configured
// anonymous goals and consent-free properties continue.
window.Aggregate.setConsent(false);
```

The tracker is authored directly in [public/aggregate.js](../public/aggregate.js), with no required build step. The configured `/aggregate.js` response uses the same BSD-3-Clause license and retains its notice. [Optional minification](JS-BUILD.md) provides `/aggregate.js?min=1` with the same configuration. Use the supplied server routing so YAML collection settings, marker settings, and the JavaScript namespace reach the browser. [Namespace overrides](CONFIGURATION.md#customizing-the-javascript-namespace) and [organization marker setup](PRIVACY-COMPLIANCE.md#organization-traffic) are documented separately.

## Page depth and single-page apps

The optional [page-depth setting](DATA-MODEL.md#optional-page-depth) adds
`eventData.page_sequence` to all events. Enable it in Data model or YAML after
reviewing the selected method's storage or URL implications. It counts from `1`
through `20` (`20+`), with interactions and asynchronous events sharing the current
page's number. The counter does not create a visitor or session ID.

The SDK sends the initial page view automatically. For a single-page app, call
this after your router changes the current page URL:

```javascript
window.Aggregate.trackView();
// Later interactions on this virtual page keep its page depth.
window.Aggregate.emit('signup_click', null, 'signup');
```

`emit('view')` also advances the page depth; use either that call or `trackView()`
once per virtual page. Other event names do not advance it. An event emitted
before the automatic initial page view can initialize the counter; the automatic
view then reuses it. Calls still require the SDK to be loaded.

For full-page navigation, `page_sequence_method` selects `session_storage`
(the existing default) or `url_parameter`. URL mode reads
`?aggregate_page_sequence=2`, captures numeric `page_sequence: 2` in memory,
removes the parameter from the current address bar with `history.replaceState()`,
and decorates ordinary same-origin links with the next bounded count before
native navigation. Only its parameter is removed; other query parameters,
fragments and existing history state are preserved. History API failure leaves
tracking functional, and event `pagePath` still contains no query string.
It uses no counter cookies or Web Storage. Reloading a cleaned URL starts at 1;
the initial request and earlier scripts can still see the incoming parameter.
It does not intercept forms, programmatic route changes, clicks already canceled
by a router, modified/new-tab clicks or downloads. Read the
[URL behavior and tradeoffs](DATA-MODEL.md#url-parameter-passing) before enabling it.

Served tracker responses include `customData.pageSequenceEnabled`,
`customData.pageSequenceMethod` and, when
enabled, `customData.pageSequenceExcludedPaths` from the effective server
configuration. The collection kill switch disables the counter, and excluded
paths do not read, advance or expose it; URL mode also checks link destinations.
Browser configuration can disable the feature or add exclusions, but cannot
enable a server-disabled counter, switch the server-selected method or remove
server exclusions. Static/CDN copies must supply these same effective controls
in `window.Aggregate.customData` before loading; keep them synchronized with
server settings. The server independently enforces ingestion permissions.

## Strict collection profile

When the server's `collection_profile` is `strict`, the served `aggregate.js` sends only the page path, event name and goal:

```json
{"eventName": "view", "pagePath": "/pricing", "websiteToken": "REPLACE_WITH_PUBLIC_WEBSITE_TOKEN"}
```

`Aggregate.emit(name, properties, goal)` still works, but `properties` are not sent, and `setConsent()` changes nothing. The tracker does not read screen size, the referrer or the query string, and does not read, write or remove cookies or Web Storage. The server enforces the same limits for any client, including direct API requests. See the [privacy guide](PRIVACY-COMPLIANCE.md#strict-collection-profile).

A static or CDN copy has no injected profile. Opt it into strict before it loads, in any of these ways:

```html
<script src="https://static.example.com/aggregate.js" data-endpoint="https://analytics.example.com/api/receive" data-website-token="REPLACE_WITH_PUBLIC_WEBSITE_TOKEN" data-collection-profile="strict" async></script>
<!-- or, before the script loads -->
<script>window.Aggregate = {endpoint: 'https://analytics.example.com/api/receive', websiteToken: 'REPLACE_WITH_PUBLIC_WEBSITE_TOKEN', collectionProfile: 'strict'};</script>
```

`Aggregate.configure({collectionProfile: 'strict'})` switches later events to strict. Page configuration can opt into strict but never out of a strict profile served by the installation.

## UTM and custom data collection

The tracker reads `utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`, and `utm_id` from the current page URL and maps them to matching `custom_data` keys. All six require enhanced consent by default. Administrators can define additional query parameter mappings, consent requirements, and optional JSON value types in **Collection → Data model** or the active YAML configuration; see the [data model guide](DATA-MODEL.md).

`query_parameter_mappings` maps each source parameter to a defined JSON property. Multiple parameters may target the same property. Configuration order determines priority: the first source with a nonblank value wins; repeated occurrences use the first nonblank value. Names match exactly, including case. Explicit scalar values passed to `emit()` take precedence, including `false`, `0`, an empty string, and `null`.

Mapped values accompany page views and named events when consent permits. The tracker reads the current page query for each event, never the tracker script URL or the referrer, and does not persist attribution in cookies or browser storage. A subsequent page without the parameter has no carried-forward campaign value. Unmapped parameters and URL fragments are omitted.

Set a property's `consent_required` to `false` to permit it before a choice, after rejection, and after withdrawal. The server independently enforces this property allowlist for API clients as well as the SDK. For anonymous UTM collection, the recommendation is **no more detail than `utm_medium`**, using reviewed channel codes such as `email`, `social`, or `cpc`. This is advisory: each UTM property can be enabled separately. Enabling source, campaign, term, content, or ID can expose campaign details, search text, or identifiers. Review actual values before overriding the recommendation; permitting a key does not restrict its values to a fixed vocabulary.

The server's `/aggregate.js` and `/aggregate.js?min=1` responses include the saved model's public collection settings, including declared types. For a static/CDN copy of the tracker, supply the matching public settings before loading it. This example assumes the saved model allows anonymous `utm_medium` and declares the shown types; the ecommerce properties still require enhanced consent:

```javascript
window.Aggregate = {
  endpoint: 'https://analytics.example.com/api/receive',
  websiteToken: 'your-website-token',
  customData: {
    consentFreeProperties: ['utm_medium'],
    propertyTypes: {currency: 'string', total_minor: 'integer', discount_rate: 'double'}
  }
};
```

After loading, `Aggregate.configure({customData: {...}})` accepts the same settings. `consentFreeProperties` is a list of JSON keys, `queryParameters` is a source-to-key mapping, and optional `propertyTypes` maps JSON keys to `scalar`, `string`, `integer`, `float`, `double`, or `boolean`. Supplying a field replaces that field; `{queryParameters: {}}` disables URL collection. Omitted fields retain their current settings. Browser overrides cannot weaken server-side consent or type checks. Descriptions and reporting aliases are not needed in browser configuration.

Properties are flat scalars: strings, finite numbers, booleans, or null. At most 50 properties are sent, strings are bounded to 500 UTF-8 bytes, and nested arrays/objects are omitted. Property keys use `[A-Za-z][A-Za-z0-9_.-]{0,63}`; reserved prototype names and the configured organization marker cannot be supplied as custom properties. Dots in keys are literal, not nesting.

Without a declared type, existing scalar behavior is preserved. Declared types omit mismatched values in both the SDK and server; null remains allowed. Integers must be whole numbers within JavaScript's safe-integer range, without fractional truncation. `float` and `double` both accept finite JSON numbers; they do not imply distinct JSON encodings or exact decimal arithmetic. Numeric and boolean strings are not converted. URL parameters are strings, so numeric/boolean typed properties need correctly typed `emit()` values instead of URL capture. Keep a separate text property if the original query value is needed. See the [complete type rules](DATA-MODEL.md#property-types-and-numeric-calculations).

Use **Collection → Event examples** or `php bin/console app:analytics:examples` for synthetic anonymous/enhanced JSON from the saved model. The [ecommerce recipe](EVENT-EXAMPLES.md#a-flat-ecommerce-starting-point) recommends a flat purchase payload with integer minor units for money; generating or copying examples does not save a model or obtain visitor consent.

## Health and ingestion checks

For the checked-in Docker setup:

```bash
curl http://localhost:9001/api/health
```

In browser DevTools, look for `/api/receive` requests and inspect the response body as well as its HTTP status. HTTP 202 can indicate a recorded anonymous event, an accepted enhanced event, or intentionally ignored collection on a disabled/excluded route. An enhanced event uses the configured Messenger transport; a worker is needed for asynchronous delivery. See [worker setup](../DEPLOYMENT.md#worker-process-setup).

## Google Tag Manager (GTM) integration

The [Aggregate GTM tag template](https://github.com/Subschema-LLC/aggregate-gtm-tag-template)
is under development in a separate repository. Follow that repository for the
template's progress, availability, and setup guidance as it becomes available.
Repository access is currently required because it is private.

The following steps cover page views and custom events using GTM Custom HTML
tags. Connect enhanced analytics to an affirmative consent choice as described
in [Step 3](#step-3-consent-management-integration).

### Step 1: Install the Tracking Pixel in GTM

1. **Create a Custom HTML Tag**
   - In GTM, go to **Tags** → **New**
   - Click **Tag Configuration** → **Custom HTML**
   - Name it: "Analytics Tracking Pixel"

2. **Add the Tracking Script**

   Choose one of these configuration methods:

   **Option A: Inline Configuration (Recommended)**
   ```html
   <script>
     window.Aggregate = {
       endpoint: 'https://your-analytics-host.com/api/receive',
       websiteToken: 'your-website-token-here',
       consent: false
     };
   </script>
   <script src="https://your-analytics-host.com/aggregate.js" async referrerpolicy="no-referrer"></script>
   ```

   **Option B: Data Attributes (No inline JS)**
   ```html
   <script
     src="https://your-analytics-host.com/aggregate.js"
     data-endpoint="https://your-analytics-host.com/api/receive"
     data-website-token="your-website-token-here"
     data-consent="0"
     referrerpolicy="no-referrer"
     async>
   </script>
   ```

   **Option C: URL Parameters**
   ```html
   <script src="https://your-analytics-host.com/aggregate.js?min=1&amp;endpoint=https%3A%2F%2Fyour-analytics-host.com%2Fapi%2Freceive&amp;token=your-website-token-here&amp;consent=0" async referrerpolicy="no-referrer"></script>
   ```

3. **Set the Trigger**
   - Click **Triggering** → **Choose a trigger**
   - Select **All Pages** (for view tracking on every page)
   - Or create a custom trigger for specific pages

4. **Save and Publish**
   - Click **Save**
   - Submit changes and publish your GTM container

### Step 2: Track Custom Events from GTM

Named custom events may be stored in anonymous mode. Before `setConsent(true)`, ordinary custom properties require `consent_required: false`; the optional page-depth counter uses its separate enablement setting. A goal may be retained only when its fixed code is enabled and marked `anonymous: true` in `config/goals.yaml`.

**Method A: Using GTM's Custom HTML Tag for Specific Events**

1. Create a new **Custom HTML Tag**
2. Name it based on the event (e.g., "Track Button Click - CTA")
3. Add this code:
   ```html
   <script>
     if (window.Aggregate && window.Aggregate.emit) {
       window.Aggregate.emit('cta_click', {
         button_text: 'Get Started',
         location: 'homepage_hero'
       }, 'signup');
     }
   </script>
   ```
4. Set a trigger (e.g., click on specific button/element)

**Method B: Using GTM Variables for Dynamic Event Tracking**

1. Create a **Custom HTML Tag**
2. Name it: "Analytics Custom Event - Generic"
3. Add this code:
   ```html
   <script>
     (function() {
       if (window.Aggregate && window.Aggregate.emit) {
         var eventName = {{Event Name Variable}};
         var eventData = {
           category: {{Event Category}},
           label: {{Event Label}},
           value: {{Event Value}}
         };
         var goalEvent = {{Goal Event Variable}};
         window.Aggregate.emit(eventName, eventData, goalEvent);
       }
     })();
   </script>
   ```
4. Create corresponding **User-Defined Variables** in GTM:
   - `Event Name Variable` (e.g., Data Layer Variable: `eventName`)
   - `Event Category`, `Event Label`, `Event Value`
   - `Goal Event Variable` (optional; e.g., Data Layer Variable: `goalEvent`)

   Map `Event Name Variable` to an approved fixed taxonomy and `Goal Event Variable` to a key from `config/goals.yaml`. Never populate either one from click text, URLs, form fields, or other user-provided values.

5. Trigger this tag using **Custom Events** or **Click Triggers**

### Step 3: Consent Management Integration

Use your consent manager to control enhanced analytics. Coarse page-view and named-event rows continue after rejection unless an administrator disables collection or excludes the current path.

Initialize a previously recorded choice before loading the tracker when possible, so its first page view uses that choice. Later consent tags must run after the SDK is available; a guard such as `if (window.Aggregate)` otherwise skips the call. GTM finishing a Custom HTML tag that injects an `async` script is not proof that the downloaded SDK has loaded. See the [consent-manager integration example](PRIVACY-COMPLIANCE.md#consent-manager-integration) for the intended lifecycle.

1. **Create a Tag for Consent Opt-in**
   - Tag Type: **Custom HTML**
   - Name: "Analytics Accept Enhanced Consent"
   - Code:
     ```html
     <script>
       if (window.Aggregate && window.Aggregate.setConsent) {
         window.Aggregate.setConsent(true);
       }
     </script>
     ```
   - **Trigger**: Fire when user accepts cookies/consent
     - Example: `consentGranted` Custom Event
     - Or use your CMP's (Consent Management Platform) built-in triggers

2. **If consent was already granted, initialize it via a data attribute**
   ```html
   <script
     src="https://your-analytics-host.com/aggregate.js"
     data-endpoint="https://your-analytics-host.com/api/receive"
     data-website-token="your-website-token-here"
     data-consent="1"
     referrerpolicy="no-referrer"
     async>
   </script>
   ```

3. **Handle rejection or withdrawal**
   ```html
   <script>
     if (window.Aggregate && window.Aggregate.setConsent) {
       window.Aggregate.setConsent(false);
     }
   </script>
   ```
   This removes the SDK's visitor/session identifiers and stops sending properties requiring consent and exact dimensions. Safe event names, configured consent-free properties, and goals marked `anonymous: true` continue as individual anonymous-mode rows. It does not erase data already held by the server; handle deletion requests through your documented data-subject process.

### Step 4: Common Event Tracking Examples

The `{{...}}` placeholders below are GTM variables, not JavaScript or Twig placeholders to use on a normal page. Map names, labels, IDs, and page categories to reviewed values; do not forward form contents, personalized click text, or URLs containing identifiers. These supplied properties require enhanced consent unless their definition explicitly sets `consent_required: false`; valid event names and permitted goals can be retained anonymously. Let the SDK generate `page_sequence` when enabled; do not supply it as a GTM event property.

**Track Form Submissions**
```html
<script>
  window.Aggregate.emit('form_submit', {
    form_name: {{Form Name}},
    form_id: {{Form ID}}
  }, 'lead');
</script>
```
- **Trigger**: Form Submission trigger for your target form

**Track Button Clicks**
```html
<script>
window.Aggregate.emit('button_click', {
  button_code: {{Approved Button Code}},
  page_category: {{Approved Page Category}}
});
</script>
```
- **Trigger**: Click - All Elements, filter by Click Classes/IDs

**Track Scroll Depth**
```html
<script>
window.Aggregate.emit('scroll_depth', {
  depth_percentage: {{Scroll Depth Threshold}},
  page_category: {{Approved Page Category}}
});
</script>
```
- **Trigger**: Scroll Depth (e.g., 25%, 50%, 75%, 100%)

**Track Video Views**
```html
<script>
  window.Aggregate.emit('video_interaction', {
    video_title: {{Video Title}},
    video_action: {{Video Status}},  // 'start', 'pause', 'complete'
    video_duration: {{Video Duration}},
    video_percent: {{Video Percent}}
  });
</script>
```
- **Trigger**: YouTube Video or Video trigger in GTM

**Track a Purchase**
```html
<script>
  window.Aggregate.emit('purchase_completed', {
    currency: {{Currency Code}},
    total_minor: {{Order Total in Minor Units}},
    item_count: {{Item Count}},
    product_category: {{Broad Product Category}}
  }, 'purchase');
</script>
```
- **Trigger**: Your completed-purchase Custom Event from the Data Layer

Use numeric Data Layer values for `total_minor` and `item_count`, with matching `integer` declarations in the saved model. Do not pass formatted prices or numeric strings. `total_minor` is the final charged total in the currency's minor unit, including tax and shipping; for USD, `4999` means $49.99. Emit once per completed checkout and avoid order/customer identifiers. The [full ecommerce example](EVENT-EXAMPLES.md) explains consent, duplicate-event limits, and separate numeric reporting aliases.

### Step 5: Testing Your GTM Setup

1. **Enable GTM Preview Mode**
   - In GTM, click **Preview**
   - Enter your website URL

2. **Check Tag Firing**
   - Verify "Analytics Tracking Pixel" fires on page load
   - Verify custom event tags fire when triggered

3. **Monitor Network Requests**
   - Open browser DevTools → Network tab
   - Look for POST requests to `/api/receive`
   - Verify the response status and body; HTTP 202 can also mean intentionally ignored collection

4. **Check Analytics Backend**

   For asynchronous enhanced events, inspect worker output:

   ```bash
   make logs-worker
   ```

   Anonymous events are written synchronously. Verify accepted rows through authorized database access; approved BI views show only completed buckets that meet their reporting thresholds.

### Troubleshooting

**Pixel not loading:**
- Check GTM Preview mode to see if tag fires
- Verify `https://your-analytics-host.com/aggregate.js` is accessible
- Check browser console for errors

**Events not tracking:**
- Verify `window.Aggregate.emit` is available in browser console
- Ensure tracking pixel loaded before custom event tags fire
- Ensure the downloaded SDK has finished loading before custom event or consent tags call it

**403 Forbidden errors:**
- Verify your domain is correctly set in `config/websites.yaml`
- Check that `Origin` (or `Referer` when `Origin` is absent) matches the website's [domain rules](CONFIGURATION.md#website-domains). Explicit rules require listing subdomains or a wildcard; only legacy registrations without a policy auto-allow all subdomains.

**429 Too Many Requests:**
- Increase `rate_limit_per_minute` in `config/aggregate.yaml` (or via dashboard settings)
- Check for infinite loops in your event tracking code

### Advanced: Using dataLayer for Event Tracking

Push events to GTM's dataLayer, then capture with a single generic tag:

```javascript
// On your website
window.dataLayer = window.dataLayer || [];
dataLayer.push({
  'event': 'customAnalyticsEvent',
  'eventName': 'signup_click',
  'goalEvent': 'signup',
  'eventData': {
    'plan': 'pro',
    'source': 'pricing_page'
  }
});
```

**GTM Tag Configuration:**
1. Create trigger: Custom Event = `customAnalyticsEvent`
2. Create tag:
   ```html
   <script>
     if (window.Aggregate && window.Aggregate.emit) {
       window.Aggregate.emit(
         {{DLV - eventName}},
         {{DLV - eventData}},
         {{DLV - goalEvent}}
       );
     }
   </script>
   ```
3. Create Data Layer Variables:
   - `DLV - eventName` → Data Layer Variable Name: `eventName`
   - `DLV - eventData` → Data Layer Variable Name: `eventData`
   - `DLV - goalEvent` → Data Layer Variable Name: `goalEvent` (optional)
