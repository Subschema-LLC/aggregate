# Privacy Consent Module

This module provides a vanilla JavaScript drop-in solution for cookie consent management and data subject access requests via Formspree, integrated directly with Google Consent Mode v2.

## File Structure

* gtm-consent-mode.js initializes Google Consent Mode v2 default states and listens for the custom privacy_update event
* consent-ui.js injects the consent banner, settings modal, and Formspree submission logic into the DOM

## Implementation

The load order in your HTML document is critical. The consent defaults must fire before the Google Tag Manager container loads. The UI can load asynchronously or at the end of the document body.

1. Place the contents of gtm-consent-mode.js in the head of your document above the GTM container snippet
2. Replace the YOUR_FORM_ID placeholder in consent-ui.js with your actual Formspree endpoint ID
3. Load consent-ui.js just before the closing body tag to prevent render blocking

## DataLayer Events

When a user saves their preferences, the UI script dispatches a privacy_update CustomEvent. The integration script catches this and pushes a standard consent_updated event to the dataLayer alongside the current consent state. You can use consent_updated as a Custom Event trigger in Google Tag Manager for tags that require consent resolution before firing.

## Custom Library Integration

You can integrate third-party scripts or custom analytics libraries directly without routing them through Google Tag Manager. The module exposes a global watcher array that executes callback functions whenever a user updates their consent settings.

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
