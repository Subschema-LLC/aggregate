/*! SPDX-License-Identifier: AGPL-3.0-only; see LICENSE in the source repository. */
/*
 * MicroConsent settings: the one place to configure the standalone banner.
 *
 * Load this file before the banner script:
 *
 *   <script src="/micro-consent-dropins/consent-config.js" defer></script>
 *   <script src="/micro-consent-dropins/js/consent-ui.js" defer referrerpolicy="no-referrer"></script>
 *
 * Every setting is optional: delete it, or leave it commented out, to use its
 * default. A misspelled or invalid setting keeps every optional category denied
 * and shows a configuration error in the banner, so check the page after
 * editing. Keep your edited copy when you update the other drop-in files.
 */
window.MicroConsentConfig = {
  // Shown in the title wherever it contains {name}. Up to 120 bytes.
  name: 'Example website',
  // An absolute HTTPS link to your privacy notice, or '' for no link.
  privacyPolicyUrl: '',
  // Your own Formspree form, 'https://formspree.io/f/ID', or '' for no request form.
  formspreeEndpoint: '',
  // 1-10 lowercase category names, including analytics. Each starts denied.
  categories: ['analytics', 'functional', 'marketing'],
  // Honor the browser's Global Privacy Control signal: marketing stays denied.
  respectGpc: true,
  // Ask again after this many days (1-365).
  consentLifetimeDays: 180,
  // Change this when your purposes or notice change, to ask everyone again.
  revision: '1',
  // Browser storage key. Use a different key for each website on one domain.
  storageKey: 'micro_consent_v2',
  // The tracker namespace used by the optional js/aggregate-consent.js bridge.
  aggregateNamespace: 'Aggregate',

  // Wording, as plain text: HTML is shown as typed. Labels allow 120 bytes and
  // paragraphs 1,000, without line breaks. Uncomment a line to change it.
  // {name} in title becomes the name above; {days} in storageNotice the lifetime.
  // details is a list of up to four paragraphs; [] shows none. categories maps
  // category names to labels; others show their name, capitalized.
  text: {
    // Banner.
    // title: '{name}: privacy choices',
    // description: 'Choose which optional categories to allow. They start denied. You can change your choices at any time.',
    // details: ['These choices apply to connected tools. If this website uses privacy-minimized analytics, coarse measurement may continue after rejection. See its privacy notice for the actual data and providers.'],
    // privacyLink: 'Read this website’s privacy notice',
    // reject: 'Reject optional categories',
    // accept: 'Accept optional categories',
    // manage: 'Manage choices',
    // reopen: 'Privacy choices',
    // Preferences dialog.
    // settingsLabel: 'Privacy settings',
    // preferencesTab: 'Preferences',
    // requestsTab: 'Privacy request',
    // preferencesIntro: 'Allow only the categories you choose. Rejection and withdrawal stop future actions in connected tools. Scripts already loaded may continue until you reload, and their cookies and stored history are not automatically erased.',
    // categoriesLegend: 'Optional categories',
    // categories: {analytics: 'Analytics (enhanced details when connected to the analytics tracker)'},
    // optOut: 'Opt out of sale, sharing, or targeted advertising in connected tools',
    // optOutHelp: 'This opt-out disables the marketing category and signals connected providers. It cannot enforce choices for tools that are not connected or process an organization-wide privacy request.',
    // gpcNotice: 'Your browser sends Global Privacy Control. We keep the advertising opt-out on and marketing denied. This signal does not grant analytics consent.',
    // storageNotice: 'Your category choices, opt-out, policy revision, and save time are stored in this browser for up to {days} days. No visitor identifier is added.',
    // save: 'Save selected choices',
    // close: 'Close privacy settings',
    // Announced to screen readers after a choice.
    // statusApplied: 'Your privacy choices have been applied.',
    // statusNotSaved: 'They could not be saved. Choose again on your next visit.',
    // statusOtherTab: 'Privacy choices were updated in another tab.',
    // Privacy request form, shown only with formspreeEndpoint.
    // requestDisclosure: 'Submitting this form sends your email address, request type, and optional message to Formspree for this website’s operator. Nothing is sent until you submit. No visitor identifier or page URL is added. Do not include sensitive information.',
    // requestEmail: 'Email address',
    // requestType: 'Request type',
    // requestAccess: 'Access my data',
    // requestDelete: 'Delete my data',
    // requestCorrect: 'Correct my data',
    // requestOptOut: 'Opt out of sale, sharing, or targeted advertising',
    // requestMessage: 'Message (optional, up to 2,000 characters)',
    // requestSubmit: 'Submit privacy request',
    // requestInvalid: 'Enter a valid email address and request type, and keep your message within 2,000 characters.',
    // requestUnavailable: 'This request could not be sent. Use the contact information in this website’s privacy notice.',
    // requestSending: 'Sending your request to Formspree…',
    // requestFailed: 'Your request could not be submitted. Your entries are still here; retry or use the contact information in this website’s privacy notice.',
    // requestSent: 'Your request was submitted to Formspree for this website’s operator. The operator must review and process it; submission does not erase stored data.',
  },

  // Colors as '#RRGGBB'. Text, links and button labels need 4.5:1 contrast
  // against their background, and buttons need a background or border with 3:1
  // against the banner. Every button shares these colors, so no choice looks preferred.
  theme: {
    // background: '#FFFFFF',  /* banner and dialog background */
    // text: '#17212D',  /* headings and paragraphs */
    // accent: '#174F85',  /* links, focus outlines and checkboxes */
    // border: '#B7C1CE',  /* banner, dialog and field borders */
    // buttonBackground: '#FFFFFF',  /* every button */
    // buttonText: '#174F85',  /* button labels */
    // buttonBorder: '#174F85',  /* button borders */
  },

  buttons: {
    // The banner's buttons, in order, from 'reject', 'accept' and 'manage'
    // (manage opens the preferences). reject is required, plus accept or manage.
    // show: ['reject', 'accept', 'manage'],
    // The button that reopens the banner later: 'bottom-right', 'bottom-left',
    // or 'hidden' if every page links to MicroConsent.open() instead.
    // reopen: 'bottom-right'
  }
};
