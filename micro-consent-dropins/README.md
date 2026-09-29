# Standalone privacy choices

MicroConsent is an optional, independent banner and preferences dialog. It can
run on a static website without the server, the built-in consent manager, Google,
or Formspree. Optional adapters connect its choices to the tracker/tag manager or
to Google Consent Mode signals. Install one consent manager per page.

**These examples are engineering guidance, not legal advice.** Choosing a region
or installing a banner does not establish compliance. The
[regional guide](../docs/CONSENT-REGIONS.md) explains conservative configurations,
reviewed exceptions, and limits. This option is separate from the built-in
`public/consent.js` / `AggregateConsent` implementation.

## Files and static installation

| File | Purpose |
| --- | --- |
| `js/consent-ui.js` | Independent banner, preferences, remembered choices, optional request form |
| `css/consent-ui.css` | Standalone presentation; loaded relative to the core script |
| `js/aggregate-consent.js` | Optional tracker and tag-manager bridge |
| `js/gtm-consent-mode.js` | Optional Google Consent Mode signal adapter; never loads Google |

Host the core script and stylesheet with their relative directories intact.
Matching `.min.js` files are optional; the readable sources work without a build.
Set configuration **before** loading the core:

```html
<script>
window.MicroConsentConfig = {
  name: 'Example website',
  privacyPolicyUrl: 'https://www.example.com/privacy',
  formspreeEndpoint: '',
  categories: ['analytics', 'functional', 'marketing'],
  respectGpc: true,
  consentLifetimeDays: 180,
  revision: '1',
  storageKey: 'micro_consent_v2:example-website',
  aggregateNamespace: 'Aggregate'
};
</script>
<script src="/micro-consent-dropins/js/consent-ui.js" defer referrerpolicy="no-referrer"></script>
```

The core loads `../css/consent-ui.css` relative to its own script URL and carries
its nonce. If embedding the JavaScript inline, include the stylesheet explicitly.
Supply an appropriate nonce or hash for inline configuration under your Content
Security Policy; do not weaken that policy just to install a banner.

Every optional category starts denied. Visitors can accept, reject, save a
selection, or reopen preferences. The preference record uses the configured
local-storage key, revision and lifetime. The default lifetime is 180 days;
1–365 days is an **operational review interval, not a legal consent-expiry rule**.
Increase `revision` when purposes or the notice change so earlier permission is
not silently reused. Use a unique storage key for independent website instances.
Storage failure does not turn missing permission into a grant.

The core does not install trackers, block unrelated scripts, or infer a person's
country from their IP address or browser language. Its GPC support forces
`marketing: false` and `doNotSell: true` while a respected browser signal is active.
Neither the signal nor its absence grants analytics consent. Connect relevant
providers to the choice; a boolean alone does not fulfill every sale, sharing,
or targeted-advertising obligation.

## Connect the tracker and tag manager

Load the separate bridge when using those products:

```html
<!-- MicroConsentConfig and the core script appear first, as above. -->
<script src="/micro-consent-dropins/js/aggregate-consent.js" defer referrerpolicy="no-referrer"></script>
<script src="https://analytics.example.com/tms-lite/sites/SITE_ID/lib.js?min=1" defer referrerpolicy="no-referrer"></script>
```

Use your configured tracker namespace in `aggregateNamespace`. The adapter
establishes the initial tracker choice, forwards later enhanced-consent changes,
and supplies category choices to the tag manager. It does not load the tracker.
Do not also load the built-in consent-manager URL or another banner on that page.

For **no measurement before consent**, enable the tag manager and configure the
tracker script with `consent: analytics`. Remove direct tracker tags, including
copies installed through GTM. The complete
[global](examples/global.yaml), [EU/EEA](examples/eu-eea.yaml),
[UK](examples/uk.yaml), [Canada](examples/canada.yaml), and [US](examples/us.yaml)
per-site YAML examples all use this conservative gate. They intentionally use the
same opt-in behavior; their comments and the regional guide explain different
review obligations. They do not automatically detect or apply a legal regime.

The tag container can load before a choice, but its gated tracker tag cannot.
The tracker URL uses `consent=0`; an actual affirmative analytics choice supplied
by the bridge permits enhanced mode when the tracker loads. Do not use
`consent: none` when the promised behavior is no measurement before a choice.

**Withdrawal needs a distinction:** `setConsent(false)` on an already loaded
tracker removes its identifiers and returns it to anonymous collection. It does
not stop every event or erase history. To stop all measurement with the script
load gate, withdraw the choice and reload the page; the denied tag then remains
unloaded. Arrange that reload in your integration when promising an immediate
stop. Already loaded third-party code also needs provider-specific stop/cleanup
handling. The server's collection kill switch and sensitive-path exclusions
remain independent protections for both privacy modes.

## Optional Google Consent Mode signals

Load `js/gtm-consent-mode.js` **synchronously before any GTM/Google loader** so its
denied defaults are established first. Load the core normally afterward. For
example, if your site separately installs GTM:

```html
<!-- MicroConsentConfig is initialized before these lines. -->
<script src="/micro-consent-dropins/js/gtm-consent-mode.js" referrerpolicy="no-referrer"></script>
<!-- Your separately reviewed Google/GTM integration goes here, if used. -->
<script src="/micro-consent-dropins/js/consent-ui.js" defer referrerpolicy="no-referrer"></script>
```

This adapter sends default/update consent signals through `gtag`/`dataLayer`;
it **never downloads Google or a GTM container**. Signals are not a script blocker.
Loading Google with denied storage may still allow requests under the provider's
configuration. If no provider contact before permission is required, separately
gate the provider's loader. Review each tag's consent checks, network behavior,
retention and international transfers. This is not an IAB TCF implementation or
a certification that a provider honors the choices.

## Optional privacy-request form with Formspree

Leave `formspreeEndpoint: ''` to disable the request form. To enable it, create
your own Formspree form and supply its exact HTTPS endpoint:
`https://formspree.io/f/ID`, with the actual alphanumeric ID. URLs on other hosts,
query strings, fragments and credentials are rejected. No placeholder endpoint
is sent anywhere. This setting is public browser configuration, not a secret.

The form sends **email, request type, and message** only after a visitor submits
it. It does not automatically attach page URLs, tracker IDs, website tokens,
consent records, or event data. Visitors should not include passwords, financial
details or other unnecessary sensitive information in their message.

Formspree is a **third-party recipient** and handles the network request,
including ordinary connection metadata such as the submitter's IP address.
Disclose that recipient and review its terms, account retention, recipient
location, security, activation requirements and spam controls. Your site's CSP
must allow the configured submission origin if the form is enabled. Test your
own endpoint and delivery before offering it to visitors.

A successful submission means the request was sent. It does not verify the
requester's identity, erase analytics data, complete an access/export request,
or satisfy the operator's response duties. Your organization must receive,
authenticate where appropriate, evaluate, fulfill and respond to requests through
its own process. Do not treat sending this form as the sole way to change browser
choices: those controls work immediately without submitting to Formspree.

## JavaScript API

```javascript
// Fresh state: configured category booleans plus doNotSell and gpc.
const current = window.MicroConsent.getState();

// Replace category choices; only true grants permission. Omitted categories deny.
// Omitting doNotSell preserves an existing visitor opt-out.
window.MicroConsent.setConsent({analytics: true, functional: false, marketing: false});

// Receive later changes; inspect getState() separately for the initial state.
const unsubscribe = window.MicroConsent.subscribe(function (choices) {
  // Apply choices to your own explicitly connected libraries.
});
window.MicroConsent.open();
window.MicroConsent.openRequests(); // Request UI is available only with a valid endpoint.
unsubscribe();
```

Startup emits `micro-consent-ready` on `document`; changes emit `privacy_update`
on `window`. A respected active GPC signal prevents clearing `doNotSell` or
re-enabling marketing through the API. Legacy `consentWatchers` integrations from
the old prototype should migrate to the API above.

## Server-managed configuration and verification

**Setup → Install scripts** offers this standalone option separately from the
built-in CMP and can save/download its settings. The source is
`standalone_consent` in `config/tag-manager/sites/<site-id>.yaml`; see the
[configuration reference](../docs/CONFIGURATION.md#standalone-consent-settings).
The hosted `/standalone-cmp/sites/<site-id>/consent.js?min=1` response and Setup
JavaScript download bundle the UI, CSS and Aggregate adapter; do not add a second
copy of that adapter when using the configured bundle. Downloaded files are
snapshots: download and deploy again after changing settings. A standalone website
can instead use the camelCase browser configuration shown above without the
application.

Run the [optional JavaScript build](../docs/JS-BUILD.md) when compact assets are
needed. Test a fresh browser, remembered choices, expiry/revision changes, GPC,
withdrawal plus reload, blocked storage, keyboard controls and your request
endpoint. Inspect network requests before granting permission and after denial;
a banner's appearance is not evidence that provider requests are blocked.

Contributors follow the [branching strategy](../CONTRIBUTING.md#branching-strategy),
starting from and targeting `development`. Preserve the license notices when
redistributing the scripts and their corresponding source.
