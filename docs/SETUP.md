# Setup and website drop-ins

[Configuration](CONFIGURATION.md) · [Tracking](TRACKING.md) · [Tag manager](TAG-MANAGER.md) · [Privacy](PRIVACY-COMPLIANCE.md)

Administrators can open **Setup** for four steps: select a registered website,
review collection controls, install scripts, and verify behavior. **Show me this
step** highlights the relevant buttons with explanatory callouts. Keyboard users
can move through the tips, close them with Escape, and return to the walkthrough
button. The numbered steps and downloads remain usable without JavaScript.

The wizard links to existing settings pages; it does not change settings or
certify an installation automatically. Choose the website whose public token
belongs in the snippet. Check the application host URL, tracker namespace,
allowed domains, excluded paths, property model, and privacy notice before using
the code on a real website.

## Copy and download

The **Install scripts** step offers:

- A complete HTML snippet for the selected website, with an optional tag manager.
- A configured consent manager JavaScript file, readable or compact when a
  current build exists.
- A small tag loader JavaScript file that retrieves the selected website's
  current `/tms-lite/sites/<site-id>/lib.js` from the installation.

Paste the snippet once into the website's `<head>`, retaining the script order
and `defer` attributes. Remove duplicate tracker installations. The snippet
contains a public website token and public URLs, never administrator credentials,
organization sharing tokens, or unrelated settings.

The hosted `/cmp-lite/sites/<site-id>/consent.js` URL receives the saved namespace,
website name, and that website's enabled tag consent categories each time it is
requested. Manage each site's CMP and tags in **Collection → Tag manager**, backed
by `config/tag-manager/sites/<site-id>.yaml`. A downloaded CMP
file is a snapshot of those settings: download and deploy it again when they
change. The downloaded tag loader continues to retrieve current tag YAML; update
the loader if the installation's public URL changes.

The **Build browser scripts** button runs the same optional minifier as:

```bash
php bin/console app:assets:build-js
```

The build machine needs Node and the pinned Terser dependency. Missing or stale
builds fall back to current configured source. Prepared releases include built
files and do not need Node to serve them. See [JavaScript builds](JS-BUILD.md).

## Headless installation

The wizard adds no mandatory UI dependency. Register a website with
`php bin/console app:create-website`, then run `php bin/console app:tag-manager:sites`
to list its script instance ID and YAML path. Configure its tags/CMP in that file
and its allowed domains in `config/websites.yaml`. Install this example with your
public URL, namespace, website token, and `SITE_ID` substituted:

```html
<script>
  window.Aggregate = {
    endpoint: 'https://analytics.example.com/api/receive',
    websiteToken: 'REPLACE_WITH_PUBLIC_WEBSITE_TOKEN',
    consent: false
  };
</script>
<script src="https://analytics.example.com/cmp-lite/sites/SITE_ID/consent.js?min=1" defer referrerpolicy="no-referrer"></script>
<script src="https://analytics.example.com/aggregate.js?min=1" defer referrerpolicy="no-referrer"></script>
<script src="https://analytics.example.com/tms-lite/sites/SITE_ID/lib.js?min=1" defer referrerpolicy="no-referrer"></script>
```

Omit the last line if you do not use tags. Both public manager routes work with
the dashboard disabled. `public/consent.js` is the readable CMP source and has
generic standalone defaults; use the site's configured route or download.
Install one CMP/tag-manager configuration per page. Analytics collection settings
and data models still use the deployment-wide aggregate YAML.

With an existing CMP, omit the supplied consent manager and connect that CMP
to the tracker and tag manager. Only an affirmative choice should produce
boolean `true`; strings such as `"false"` are not grants. Initialize the tracker
before it loads with the appropriate analytics choice and send later updates
through its `setConsent` method. See the [tracker integration guide](PRIVACY-COMPLIANCE.md#enhanced-analytics-consent)
and the [tag category API](TAG-MANAGER.md#install-and-connect-consent).

Under a Content Security Policy, allow the installation's script origin and each
configured provider, or provide the appropriate nonce on scripts. The tag loader
forwards its script nonce, and the CMP applies its script nonce to its generated
stylesheet. Your site's inline initialization must also satisfy its CSP; the
generated snippet does not invent or weaken a policy.

## Consent manager behavior

The supplied CMP is a small, self-hosted category chooser. It makes no network
submissions and does not use Formspree. It always includes **Enhanced analytics**
and lists the other consent categories used by that website's enabled tags when
its tag manager is enabled. Category names come from the same per-site YAML;
there is no provider registry or second settings table. Each checkbox starts
denied unless the browser has a remembered affirmative choice for that category.

Visitors can accept all optional categories, reject all optional categories, or
save a selection. **Privacy choices** remains available to reopen the controls.
Tags can load a script or call a named library method when their trigger and
consent requirement match. Event triggers skipped before consent are not replayed.
Tags explicitly configured with `consent: none` can run before any choice and
after rejection when their trigger occurs. The tag manager itself can load
without consent. Review each
tag's requirement and disclose its purpose and providers; an operator label does
not establish a legal basis. The analytics collection switch and excluded paths
do not control independent third-party tag behavior.

The CMP stores only a JSON map of category booleans under
`analytics_consent_v1:<namespace>:<site-id>` in the website's local storage, so
site instances do not share choices. The optional shared CMP uses
`analytics_consent_v1:<namespace>`. It does not
store a visitor identifier, consent receipt, timestamp, or server-side consent
record. Choices last until changed or browser storage is cleared. Boolean
values grant only analytics, and newly introduced categories remain denied and
prompt review. Browser storage errors default to denied when restoring choices;
an unsaved choice still applies to the current page and is announced to the
visitor. If persistent storage cannot be changed or removed, an old stored
choice can remain, so operators should test their supported browser/storage
environments. Other tabs receive updates through the browser's storage event.

The JavaScript API is:

```javascript
// Returns a fresh map; only configured categories are included.
window.AggregateConsent.getState(); // {analytics: false, marketing: false}

// Replace category choices; omitted, inherited, and invalid grants are denied.
window.AggregateConsent.setConsent({analytics: true, marketing: false});

// Boolean shorthand affects analytics only, preserving other choices.
window.AggregateConsent.setConsent(false);

// Deny every optional category.
window.AggregateConsent.setConsent({});

var unsubscribe = window.AggregateConsent.subscribe(function (choices) {
  // Receives later updates. Read getState() for the initial state.
});
window.AggregateConsent.open();
unsubscribe();
```

Initialization dispatches `aggregate:consent-ready` on `document`. The CMP sends
only `choices.analytics` to the configured tracker and the full boolean map to
`window.AggregateTags.setConsent`. The tag manager also discovers the CMP if
either script loads first. The recommended ordered snippet establishes the
tracker's initial state before its automatic page view.

Withdrawing analytics consent removes SDK identifiers and stops future enhanced
details. Coarse anonymous events, explicitly permitted properties, and permitted
goals may continue. Withdrawal does not delete stored event history. Withdrawing
a tag category prevents future tag actions for that category; JavaScript already loaded
may keep running until the page is reloaded, and third-party cookies or data are
not erased. Tags marked `none` remain eligible after reload.

This CMP does not implement Google Consent Mode, IAB TCF, provider-level consent
records, or rights-request workflows. Its presence is not a compliance
certification. The operator must supply appropriate disclosures and operational
procedures for the actual deployment.

## Verify before collecting real traffic

Use a fresh browser profile to check denied, granted, and withdrawn states,
including each configured tag category and `none` tags. Inspect network requests
and browser identifiers. Test storage restrictions, your allowed domains, and
excluded paths. The setup wizard lists these checks but does not mark them
verified. Exercise the [beta checklist](BETA-TESTING.md#test-checklist), backup and
restore procedures, and routine BI permissions before inviting live traffic.
