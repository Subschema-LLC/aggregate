# Experimental privacy consent prototype

This directory contains an older, incomplete consent UI experiment. It is not
the drop-in offered by **Setup**. Use the [current self-hosted consent manager](../docs/SETUP.md#consent-manager-behavior)
for working category controls and tracker/tag integration without external
submissions. The prototype below uses Formspree, and `gtm-consent-mode.js` is
empty: the proposed Google Consent Mode integration and watcher behavior
described below are not implemented. These notes describe the intended prototype,
not a production integration or compliance assurance.

## File Structure

* gtm-consent-mode.js is an empty placeholder for proposed Google Consent Mode v2 default states and privacy_update integration
* consent-ui.js injects the consent banner, settings modal, and Formspree submission logic into the DOM

## Formspree Setup

* Create an account at formspree.io and create a new form for your privacy requests
* Locate your form endpoint URL or form ID in the Formspree integration settings
* Open consent-ui.js and replace the YOUR_FORM_ID placeholder in the form action attribute with your specific ID
* Submit a test request through the UI to trigger Formspree's initial activation email
* Verify your email address through the Formspree notification to begin receiving user requests
* Adjust the form settings in Formspree to disable form archive storage if you want to minimize third-party data retention for privacy requests

## Implementation

The load order in your HTML document is critical. The consent defaults must fire before the Google Tag Manager container loads. The UI can load asynchronously or at the end of the document body.

1. Place the contents of gtm-consent-mode.js in the head of your document above the GTM container snippet
2. Replace the YOUR_FORM_ID placeholder in consent-ui.js with your actual Formspree endpoint ID
3. Load consent-ui.js just before the closing body tag to prevent render blocking

## DataLayer Events

The UI script dispatches a privacy_update CustomEvent. The empty integration
script does not catch it or push consent_updated into the dataLayer; that behavior
was proposed but is not implemented.

## Custom Library Integration

The proposed direct integration with third-party scripts requires a global
watcher array that executes callbacks when consent changes; the empty
integration script does not provide one.

To register a custom library, push a callback function to the window.consentWatchers array.

### Example Integration with Aggregate Analytics

Place this snippet in your document so that it loads after both your Aggregate tracking script and gtm-consent-mode.js.

```javascript
window.consentWatchers.push(function(consentState) {
  if (window.Aggregate && typeof window.Aggregate.setConsent === 'function') {
    const isGranted = consentState.analytics_storage === 'granted';
    window.Aggregate.setConsent(isGranted);
  }
});
```

## Contributing

This module follows the repository's [branching strategy](../CONTRIBUTING.md#branching-strategy).
Create a `feature/...`, `issue/...`, or other descriptive working branch from
`development` and open a PR back to `development`. Maintainers then promote changes
through PRs from `development` to `uat` for user acceptance testing, then from
`uat` to `master` for production release.
