# Setup and website drop-ins

[Configuration](CONFIGURATION.md) · [Tracking](TRACKING.md) · [Tag manager](TAG-MANAGER.md) · [Privacy](PRIVACY-COMPLIANCE.md) · [Regional consent examples](CONSENT-REGIONS.md)

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

- An HTML snippet for the selected website and installation method.
- A configured JavaScript file for the selected consent option, readable or compact
  when a current build exists; the standalone option also offers its YAML settings.
- A small tag loader JavaScript file that retrieves the selected website's
  current `/tms-lite/sites/<site-id>/lib.js` from the installation.

In **Setup → Install scripts**, choose consent controls separately from the
tracker installation method:

| Consent option | Behavior |
| --- | --- |
| Simple built-in banner | Existing self-hosted `AggregateConsent` category chooser, when enabled for the site; no external request forms |
| Standalone banner + optional Formspree requests | Independent `MicroConsent` banner, separate settings, GPC handling and optional user-submitted request form |
| My own consent manager | Omit a supplied banner and connect the existing CMP yourself |

The standalone choice opens **Configure standalone option** at
`/dashboard/setup/standalone/<site-id>` and offers script/YAML downloads. Saving
that mapping preserves built-in CMP and tag settings. Choose one banner per
page. Review the [regional examples](CONSENT-REGIONS.md); they are engineering
examples, not legal advice or automatic policy selection.

**Setup** and **Websites** offer these tracker installation methods. The table
below describes the existing built-in consent selection; Setup can substitute the
standalone bundle or omit the CMP when another manager is selected:

| Method | Generated installation code |
| --- | --- |
| Window configuration (default) | Inline `window` settings, the website's enabled CMP, and the tracker. |
| URL query parameters | The website's enabled CMP and a tracker URL containing its endpoint, public token, and `consent=0` default. |
| Tag manager | The website's enabled CMP and its tag container. Configure the scripts that container should load separately. |

Tag-manager mode includes no inline tracker configuration or direct tracker
script. It offers a separate copyable tracker URL for adding the tracker as a
tag, which the Tag manager page also shows and can add for you. Choosing a
snippet does not add tags or enable the container; see
[loading the tracker through the manager](TAG-MANAGER.md#load-the-tracker-through-the-manager).
The choices and generated code work without JavaScript; clipboard buttons need
JavaScript, or you can select and copy the displayed code.

Paste the snippet once into the website's `<head>`, retaining the script order
and `defer` attributes. Remove duplicate tracker installations. The snippet
contains only public website configuration and URLs, never administrator credentials,
organization sharing tokens, or unrelated settings.

The standalone hosted URL is
`/standalone-cmp/sites/<site-id>/consent.js?min=1`. It includes the independent UI,
CSS and Aggregate bridge, using that site's `standalone_consent` mapping. Its
configured download is also self-contained; deploy it again after settings
changes. The static core and optional bridges can be hosted independently of the
server using [their installation guide](../micro-consent-dropins/README.md).

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
and its allowed domains in `config/websites.yaml`. Choose one installation method
below, substituting your public URL, namespace, website token, and `SITE_ID`.

### Gate all initial measurement with the standalone option

Use the complete per-site examples in the [regional guide](CONSENT-REGIONS.md).
They disable the built-in CMP, enable the tag manager, and configure its tracker
script with `consent: analytics`. Load the standalone bundle before the container:

```html
<script src="https://analytics.example.com/standalone-cmp/sites/SITE_ID/consent.js?min=1" defer referrerpolicy="no-referrer"></script>
<script src="https://analytics.example.com/tms-lite/sites/SITE_ID/lib.js?min=1" defer referrerpolicy="no-referrer"></script>
```

Remove direct tracker installations and duplicates in other tag managers.
The gated tracker remains unloaded until analytics is accepted. Once loaded,
withdrawal removes identifiers but can resume anonymous events; reload the page
after withdrawal so the denied tag stays unloaded. This is a script-load gate,
not a new SDK all-collection stop API. Review provider-specific cleanup too.

The direct-installation examples below allow coarse anonymous measurement before
an enhanced-analytics choice. Use them only when that behavior matches your
reviewed policy and notice.

### Direct tracker with window configuration

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
```

### Direct tracker with query parameters

The URL alternative supplies the same public endpoint and website token without
an inline configuration block. URL-encode the parameter values and use `&amp;`
between parameters in HTML:

```html
<script src="https://analytics.example.com/cmp-lite/sites/SITE_ID/consent.js?min=1" defer referrerpolicy="no-referrer"></script>
<script src="https://analytics.example.com/aggregate.js?min=1&amp;endpoint=https%3A%2F%2Fanalytics.example.com%2Fapi%2Freceive&amp;token=REPLACE_WITH_PUBLIC_WEBSITE_TOKEN&amp;consent=0" defer referrerpolicy="no-referrer"></script>
```

The configured SDK response supplies the namespace. `consent=0` is a denied
default: an affirmative choice placed in the configured `window` namespace by
the CMP before the tracker loads takes precedence. Later choices use
`setConsent`. The URL itself does not obtain consent.

### Tag manager installation

This installs the website's tag container and enabled CMP:

```html
<script src="https://analytics.example.com/cmp-lite/sites/SITE_ID/consent.js?min=1" defer referrerpolicy="no-referrer"></script>
<script src="https://analytics.example.com/tms-lite/sites/SITE_ID/lib.js?min=1" defer referrerpolicy="no-referrer"></script>
```

Set `tag_manager.enabled: true` in the site's YAML and add the scripts it should
load. To collect analytics this way, add the
[tracker script action](TAG-MANAGER.md#load-the-tracker-through-the-manager) and
remove any separate tracker installation. Script actions load asynchronously;
their order in YAML does not establish library dependencies.

Omit the CMP line in these examples when that website's built-in CMP is disabled.
Both public manager routes work with the dashboard disabled.
`public/consent.js` is the readable CMP source and has
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

The built-in CMP is a small, self-hosted category chooser. It makes no network
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
either script loads first. Loading the CMP before the direct tracker or tag
container lets it establish the tracker's initial choice before the automatic
page view. A tracker tag configured with `consent: analytics` waits for that
category before loading; `consent: none` permits anonymous-mode collection while
analytics consent is denied.

Withdrawing analytics consent removes SDK identifiers and stops future enhanced
details. Coarse anonymous events, explicitly permitted properties, and permitted
goals may continue. Withdrawal does not delete stored event history. Withdrawing
a tag category prevents future tag actions for that category; JavaScript already loaded
may keep running until the page is reloaded, and third-party cookies or data are
not erased. Tags marked `none` remain eligible after reload.

The built-in CMP does not implement Google Consent Mode, IAB TCF, provider-level consent
records, or rights-request workflows. Its presence is not a compliance
certification. The operator must supply appropriate disclosures and operational
procedures for the actual deployment.

## Standalone consent behavior

The optional independent banner has its own `MicroConsent` API and
`micro_consent_v2:<site-id>` preference record. Optional categories default denied;
GPC, when respected and active, forces marketing off and a do-not-sell choice.
It never grants analytics or controls an unconnected provider. A configurable
1–365-day lifetime (default 180) is an operational review interval, not a legal
consent rule; a changed revision requires a fresh choice.

The endpoint and download include an optional bridge to the tracker/tag manager.
Google Consent Mode is a separate signal-only adapter in the standalone source
directory: it does not load Google and does not block provider requests by itself.
The original built-in CMP and its stored choices remain separate.

Formspree forms are disabled when the endpoint is blank. Enabling an exact
`https://formspree.io/f/ID` endpoint sends a visitor's email, request type and
message to that third party only when they submit. Explain that recipient and
its handling of connection metadata. Submission does not verify identity,
fulfill a rights request or erase event history. Browser choices work without
sending a request. See [standalone setup and limits](../micro-consent-dropins/README.md)
and the [settings reference](CONFIGURATION.md#standalone-consent-settings).

## Verify before collecting real traffic

Use a fresh browser profile to check denied, granted, and withdrawn states,
including each configured tag category and `none` tags. Inspect network requests
and browser identifiers. Test storage restrictions, your allowed domains, and
excluded paths. The setup wizard lists these checks but does not mark them
verified. Exercise the [beta checklist](BETA-TESTING.md#test-checklist), backup and
restore procedures, and routine BI permissions before inviting live traffic.
