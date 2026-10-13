/*! SPDX-License-Identifier: AGPL-3.0-only; see LICENSE in the source repository. */
(function () {
  'use strict';
  if (window.MicroConsent) return;
  var microConsentStyles = null;
  var script = document.currentScript;
  var scriptSource = script && script.src;
  var scriptNonce = script && script.nonce;
  var absent = {};
  var validConfig = true;
  var reserved = ['none', 'gpc', 'doNotSell', 'constructor', 'prototype', '__proto__'];

  function record(value) { return value !== null && typeof value === 'object' && !Array.isArray(value); }
  function own(value, key) {
    if (!record(value) && typeof value !== 'function') return absent;
    try {
      var descriptor = Object.getOwnPropertyDescriptor(value, key);
      return descriptor && Object.prototype.hasOwnProperty.call(descriptor, 'value') ? descriptor.value : absent;
    } catch (error) { return absent; }
  }
  function boundedText(value, maximum, empty) {
    if (typeof value !== 'string' || /[\u0000-\u001f\u007f-\u009f]/.test(value)) return false;
    try { return (empty || value.trim() !== '') && encodeURIComponent(value).replace(/%[A-F0-9]{2}/gi, 'x').length <= maximum; }
    catch (error) { return false; }
  }
  function policyUrl(value) {
    if (value === '') return true;
    if (!boundedText(value, 2048, false) || /\s|\\/.test(value)) return false;
    try {
      var parsed = new URL(value);
      return parsed.protocol === 'https:' && !parsed.username && !parsed.password && !!parsed.hostname;
    } catch (error) { return false; }
  }
  var input = own(window, 'MicroConsentConfig');
  if (input === absent) input = {};
  if (!record(input)) { validConfig = false; input = {}; }
  var keys = ['name', 'privacyPolicyUrl', 'formspreeEndpoint', 'categories', 'respectGpc', 'consentLifetimeDays', 'revision', 'storageKey', 'aggregateNamespace', 'text', 'theme', 'buttons', 'layout', 'externalTags', 'regions', 'defaultRegion', 'consentType', 'closeButton', 'defaultGranted'];
  try {
    Object.getOwnPropertyNames(input).forEach(function (key) {
      if (keys.indexOf(key) === -1 || own(input, key) === absent) validConfig = false;
    });
  } catch (error) { validConfig = false; }
  function option(key, fallback, check) {
    var value = own(input, key);
    if (value === absent) return fallback;
    if (!check(value)) { validConfig = false; return fallback; }
    return value;
  }
  // Default wording. Keep in step with ConsentAppearance::DEFAULT_TEXT (a test compares them).
  var defaultText = {
    "title": "{name}: privacy choices",
    "description": "Choose which optional categories to allow. They start denied. You can change your choices at any time.",
    "details": [
      "These choices apply to connected tools. If this website uses privacy-minimized analytics, coarse measurement may continue after rejection. See its privacy notice for the actual data and providers."
    ],
    "privacyLink": "Read this website’s privacy notice",
    "reject": "Reject optional categories",
    "accept": "Accept optional categories",
    "manage": "Manage choices",
    "save": "Save selected choices",
    "reopen": "Privacy choices",
    "close": "Close privacy settings",
    "dismiss": "Close this notice",
    "dismissReject": "Reject optional categories and close",
    "settingsLabel": "Privacy settings",
    "preferencesTab": "Preferences",
    "requestsTab": "Privacy request",
    "preferencesIntro": "Allow only the categories you choose. Rejection and withdrawal stop future actions in connected tools. Scripts already loaded may continue until you reload, and their cookies and stored history are not automatically erased.",
    "categoriesLegend": "Optional categories",
    "categories": {
      "analytics": "Analytics (enhanced details when connected to the analytics tracker)"
    },
    "categoryTags": "Includes: {tags}",
    "optOut": "Opt out of sale, sharing, or targeted advertising in connected tools",
    "optOutHelp": "This opt-out disables the marketing category and signals connected providers. It cannot enforce choices for tools that are not connected or process an organization-wide privacy request.",
    "gpcNotice": "Your browser sends Global Privacy Control. We keep the advertising opt-out on and marketing denied. This signal does not grant analytics consent.",
    "storageNotice": "Your category choices, opt-out, policy revision, and save time are stored in this browser for up to {days} days. No visitor identifier is added.",
    "statusApplied": "Your privacy choices have been applied.",
    "statusNotSaved": "They could not be saved. Choose again on your next visit.",
    "statusOtherTab": "Privacy choices were updated in another tab.",
    "requestDisclosure": "Submitting this form sends your email address, request type, and optional message to Formspree for this website’s operator. Nothing is sent until you submit. No visitor identifier or page URL is added. Do not include sensitive information.",
    "requestEmail": "Email address",
    "requestType": "Request type",
    "requestAccess": "Access my data",
    "requestDelete": "Delete my data",
    "requestCorrect": "Correct my data",
    "requestOptOut": "Opt out of sale, sharing, or targeted advertising",
    "requestMessage": "Message (optional, up to 2,000 characters)",
    "requestSubmit": "Submit privacy request",
    "requestInvalid": "Enter a valid email address and request type, and keep your message within 2,000 characters.",
    "requestUnavailable": "This request could not be sent. Use the contact information in this website’s privacy notice.",
    "requestSending": "Sending your request to Formspree…",
    "requestFailed": "Your request could not be submitted. Your entries are still here; retry or use the contact information in this website’s privacy notice.",
    "requestSent": "Your request was submitted to Formspree for this website’s operator. The operator must review and process it; submission does not erase stored data."
  };
  // Labels allow 120 UTF-8 bytes; paragraphs and messages allow 1,000.
  var labelKeys = ["title", "privacyLink", "reject", "accept", "manage", "save", "reopen", "close", "dismiss", "dismissReject", "settingsLabel", "preferencesTab", "requestsTab", "categoriesLegend", "categories", "categoryTags", "requestEmail", "requestType", "requestAccess", "requestDelete", "requestCorrect", "requestOptOut", "requestMessage", "requestSubmit"];
  var themeDefaults = {background: '#FFFFFF', text: '#17212D', accent: '#174F85', border: '#B7C1CE', buttonBackground: '#FFFFFF', buttonText: '#174F85', buttonBorder: '#174F85'};
  var buttonNames = ['reject', 'accept', 'manage'];
  var reopenPositions = ['bottom-left', 'bottom-right', 'hidden'];
  function names(value) {
    try { return Object.keys(value); } catch (error) { return null; }
  }
  function hexColor(value) {
    if (typeof value !== 'string' || !/^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/.test(value)) return null;
    var hex = value.slice(1).toUpperCase();
    return '#' + (hex.length === 3 ? hex.replace(/./g, '$&$&') : hex);
  }
  function contrast(first, second) {
    function luminance(hex) {
      return [1, 3, 5].reduce(function (sum, offset, index) {
        var channel = parseInt(hex.slice(offset, offset + 2), 16) / 255;
        channel = channel <= 0.04045 ? channel / 12.92 : Math.pow((channel + 0.055) / 1.055, 2.4);
        return sum + [0.2126, 0.7152, 0.0722][index] * channel;
      }, 0);
    }
    var a = luminance(first), b = luminance(second);
    return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
  }
  // The same rules as the server's ConsentAppearance; anything else fails closed.
  function validText(value) {
    var entries = record(value) ? names(value) : null;
    return !!entries && entries.every(function (key) {
      var entry = own(value, key);
      if (!Object.prototype.hasOwnProperty.call(defaultText, key) || entry === absent) return false;
      if (key === 'details') {
        return Array.isArray(entry) && entry.length <= 4 && entry.every(function (paragraph) { return boundedText(paragraph, 1000, false); });
      }
      if (key === 'categories') {
        var categories = record(entry) ? names(entry) : null;
        return !!categories && categories.every(function (category) {
          return /^[a-z][a-z0-9_-]{0,31}$/.test(category) && reserved.indexOf(category) === -1 && boundedText(own(entry, category), 120, false);
        });
      }
      if (key === 'categoryTags' && (typeof entry !== 'string' || entry.indexOf('{tags}') === -1)) return false;
      return boundedText(entry, labelKeys.indexOf(key) === -1 ? 1000 : 120, false);
    });
  }
  function validTheme(value) {
    var entries = record(value) ? names(value) : null;
    if (!entries || !entries.every(function (key) { return Object.prototype.hasOwnProperty.call(themeDefaults, key) && hexColor(own(value, key)) !== null; })) return false;
    var colors = {};
    names(themeDefaults).forEach(function (key) { colors[key] = entries.indexOf(key) === -1 ? themeDefaults[key] : hexColor(own(value, key)); });
    return contrast(colors.text, colors.background) >= 4.5 && contrast(colors.accent, colors.background) >= 4.5
      && contrast(colors.buttonText, colors.buttonBackground) >= 4.5
      && Math.max(contrast(colors.buttonBackground, colors.background), contrast(colors.buttonBorder, colors.background)) >= 3;
  }
  function validButtons(value) {
    var entries = record(value) ? names(value) : null;
    if (!entries || !entries.every(function (key) { return key === 'show' || key === 'reopen'; })) return false;
    var show = own(value, 'show'), reopen = own(value, 'reopen');
    return (show === absent || (Array.isArray(show) && show.indexOf('reject') !== -1
        && (show.indexOf('accept') !== -1 || show.indexOf('manage') !== -1)
        && show.every(function (button, index) { return buttonNames.indexOf(button) !== -1 && show.indexOf(button) === index; })))
      && (reopen === absent || reopenPositions.indexOf(reopen) !== -1);
  }
  // Tags the website loads itself: a unique id the markup refers to, a name and
  // a category, as the server's ConsentAppearance::externalTags() requires.
  function validExternalTags(value) {
    if (!Array.isArray(value) || value.length > 30) return false;
    var seen = [];
    return value.every(function (tag) {
      var entries = record(tag) ? names(tag) : null;
      if (!entries || !entries.every(function (key) { return key === 'id' || key === 'name' || key === 'category'; })) return false;
      var id = own(tag, 'id'), category = own(tag, 'category');
      if (typeof id !== 'string' || !/^[A-Za-z][A-Za-z0-9_-]{0,63}$/.test(id) || seen.indexOf(id) !== -1) return false;
      seen.push(id);
      return boundedText(own(tag, 'name'), 120, false) && typeof category === 'string'
        && /^[a-z][a-z0-9_-]{0,31}$/.test(category) && reserved.indexOf(category) === -1;
    });
  }
  // The first three are current; explicit and implicit are older names.
  var consentTypes = ['implicit_us', 'explicit_us', 'explicit_eu', 'explicit', 'implicit'];
  var closeButtons = ['reject', 'dismiss', 'hidden'];
  function validConsentType(value) { return consentTypes.indexOf(value) !== -1; }
  function validCloseButton(value) { return closeButtons.indexOf(value) !== -1; }
  function validCategoryList(value) {
    return Array.isArray(value) && value.length <= 10 && value.every(function (category) {
      return typeof category === 'string' && /^[a-z][a-z0-9_-]{0,31}$/.test(category) && reserved.indexOf(category) === -1;
    });
  }
  function validRegions(value) {
    if (!Array.isArray(value) || value.length > 10) return false;
    var seen = [];
    var hasDefault = false;
    return value.every(function (reg) {
      if (!record(reg)) return false;
      var id = own(reg, 'id');
      var name = own(reg, 'name');
      var consentType = own(reg, 'consentType') === absent ? own(reg, 'consent_type') : own(reg, 'consentType');
      var isDefault = own(reg, 'default');
      if (typeof id !== 'string' || !/^[a-z0-9_-]{1,32}$/.test(id) || seen.indexOf(id) !== -1) return false;
      seen.push(id);
      if (!boundedText(name, 120, false)) return false;
      if (consentType !== absent && consentTypes.indexOf(consentType) === -1) return false;
      if (isDefault !== absent && typeof isDefault !== 'boolean') return false;
      if (isDefault === true) {
        if (hasDefault) return false;
        hasDefault = true;
      }
      var regText = own(reg, 'text');
      if (regText !== absent && !validText(regText)) return false;
      var regButtons = own(reg, 'buttons');
      if (regButtons !== absent && !validButtons(regButtons)) return false;
      var regClose = own(reg, 'closeButton');
      if (regClose !== absent && !validCloseButton(regClose)) return false;
      var regGranted = own(reg, 'defaultGranted');
      // Default grants belong only to implicit regions, as the server requires.
      var regType = consentType === absent ? own(input, 'consentType') : consentType;
      if (regGranted !== absent && (!validCategoryList(regGranted) || (regType !== 'implicit_us' && regType !== 'implicit'))) return false;
      return true;
    });
  }
  var config = {
    name: option('name', 'Privacy choices', function (value) { return boundedText(value, 120, false); }),
    privacyPolicyUrl: option('privacyPolicyUrl', '', policyUrl),
    formspreeEndpoint: option('formspreeEndpoint', '', function (value) {
      return boundedText(value, 2048, true) && (value === '' || /^https:\/\/formspree\.io\/f\/[A-Za-z0-9]+$/.test(value));
    }),
    categories: option('categories', ['analytics', 'functional', 'marketing'], function (value) {
      return Array.isArray(value) && value.length > 0 && value.length <= 10 && value.indexOf('analytics') !== -1
        && value.every(function (category, index) {
          return boundedText(category, 32, false) && /^[a-z][a-z0-9_-]{0,31}$/.test(category)
            && reserved.indexOf(category) === -1 && value.indexOf(category) === index;
        });
    }),
    respectGpc: option('respectGpc', true, function (value) { return typeof value === 'boolean'; }),
    consentLifetimeDays: option('consentLifetimeDays', 180, function (value) { return Number.isInteger(value) && value >= 1 && value <= 365; }),
    revision: option('revision', '1', function (value) { return boundedText(value, 64, false); }),
    storageKey: option('storageKey', 'micro_consent_v2', function (value) { return boundedText(value, 191, false); }),
    aggregateNamespace: option('aggregateNamespace', 'Aggregate', function (value) { return boundedText(value, 120, false) && reserved.indexOf(value) === -1; }),
    text: option('text', {}, validText),
    theme: option('theme', {}, validTheme),
    buttons: option('buttons', {}, validButtons),
    layout: option('layout', 'card', function (value) { return value === 'card' || value === 'bar'; }),
    externalTags: option('externalTags', [], validExternalTags),
    regions: option('regions', [], validRegions),
    defaultRegion: option('defaultRegion', '', function (value) { return typeof value === 'string' && /^[a-z0-9_-]{0,32}$/.test(value); }),
    consentType: option('consentType', 'explicit_eu', validConsentType),
    closeButton: option('closeButton', '', validCloseButton),
    defaultGranted: option('defaultGranted', null, validCategoryList)
  };
  // A tag waiting for a category the banner does not ask about could never run.
  if (!config.externalTags.every(function (tag) { return config.categories.indexOf(tag.category) !== -1; })) validConfig = false;
  // Invalid settings deny everything, so no tag is listed or started.
  if (!validConfig) config.externalTags = [];
  var rawRegions = Array.isArray(config.regions) ? config.regions : [];
  var regions = [];
  rawRegions.forEach(function (r) {
    if (r && typeof r === 'object' && typeof r.id === 'string' && typeof r.name === 'string') {
      regions.push(r);
    }
  });

  function getRegion(id) {
    for (var i = 0; i < regions.length; i++) {
      if (regions[i].id === id) return regions[i];
    }
    return null;
  }

  var regionStorageKey = config.storageKey + ':region';
  var savedRegionId = (function () {
    try { return window.localStorage.getItem(regionStorageKey); } catch (e) { return null; }
  })();

  var currentRegion = (function () {
    if (regions.length === 0) return null;
    if (savedRegionId && getRegion(savedRegionId)) return getRegion(savedRegionId);
    var defId = typeof config.defaultRegion === 'string' ? config.defaultRegion : config.default_region;
    if (defId && getRegion(defId)) return getRegion(defId);
    for (var j = 0; j < regions.length; j++) {
      if (regions[j].default === true) return regions[j];
    }
    return regions[0];
  })();

  // implicit_us: a notice; default categories (analytics unless configured)
  // start granted. explicit_us and explicit_eu (the default): every optional
  // category starts denied. Older configurations used explicit and implicit.
  function consentType() {
    var value = currentRegion ? own(currentRegion, 'consentType') : absent;
    if (value === absent && currentRegion) value = own(currentRegion, 'consent_type');
    if (value === absent) value = config.consentType;
    if (value === 'implicit' || value === 'implicit_us') return 'implicit_us';
    return value === 'explicit_us' ? 'explicit_us' : 'explicit_eu';
  }
  // What the close button does: configured, or reject for explicit_eu and dismiss otherwise.
  function closeAction() {
    var value = currentRegion ? own(currentRegion, 'closeButton') : absent;
    if (value === absent) value = config.closeButton;
    if (closeButtons.indexOf(value) !== -1) return value;
    return consentType() === 'explicit_eu' ? 'reject' : 'dismiss';
  }
  // Categories granted before a choice: only in implicit_us, and none when a
  // respected Global Privacy Control signal is present.
  function defaultGrants() {
    if (!validConfig || consentType() !== 'implicit_us' || gpc()) return [];
    var value = currentRegion ? own(currentRegion, 'defaultGranted') : absent;
    if (value === absent) value = config.defaultGranted;
    if (value === null) value = ['analytics'];
    return value.filter(function (category) { return Object.prototype.hasOwnProperty.call(choices, category); });
  }
  function applyDefaultGrants() {
    config.categories.forEach(function (category) { choices[category] = false; });
    defaultGrants().forEach(function (category) { choices[category] = true; });
    enforceSignals();
  }
  // Closing without a choice hides the banner for this tab's session without
  // saving anything, so the default consent stays in effect and nothing identifies the visitor.
  var dismissKey = config.storageKey + ':dismissed';
  var dismissed = (function () {
    try { return window.sessionStorage.getItem(dismissKey) === '1'; } catch (error) { return false; }
  })();
  function dismissedForSession() {
    return dismissed && closeAction() === 'dismiss';
  }

  function t(key) {
    var regText = currentRegion && own(currentRegion, 'text');
    var value = regText !== absent && regText ? own(regText, key) : absent;
    if (value === absent) {
      value = own(config.text, key);
    }
    var result = typeof value === 'string' ? value.trim() : defaultText[key];
    if (key === 'title') result = result.split('{name}').join(config.name.trim());
    if (key === 'storageNotice') result = result.split('{days}').join(String(config.consentLifetimeDays));
    return result;
  }
  function details() {
    var regText = currentRegion && own(currentRegion, 'text');
    var value = regText !== absent && regText ? own(regText, 'details') : absent;
    if (value === absent || !Array.isArray(value)) {
      value = own(config.text, 'details');
    }
    return (value === absent ? defaultText.details : value).map(function (paragraph) { return paragraph.trim(); });
  }
  function tagNames(category) {
    return config.externalTags.filter(function (tag) { return tag.category === category; }).map(function (tag) { return tag.name.trim(); });
  }
  function tagCategory(id) {
    for (var i = 0; i < config.externalTags.length; i++) if (config.externalTags[i].id === id) return config.externalTags[i].category;
    return null;
  }
  function categoryLabel(category) {
    var value = own(own(config.text, 'categories'), category);
    if (typeof value === 'string') return value.trim();
    return own(defaultText.categories, category) !== absent ? defaultText.categories[category]
      : category.replace(/[-_]/g, ' ').replace(/^./, function (letter) { return letter.toUpperCase(); });
  }
  function currentShownButtons() {
    var regButtons = currentRegion && own(currentRegion, 'buttons');
    var show = regButtons !== absent && regButtons ? own(regButtons, 'show') : absent;
    if (show !== absent && Array.isArray(show)) return show.slice();
    return own(config.buttons, 'show') === absent ? buttonNames.slice() : own(config.buttons, 'show').slice();
  }
  var shownButtons = currentShownButtons();
  var reopenPosition = own(config.buttons, 'reopen') === absent ? 'bottom-right' : own(config.buttons, 'reopen');
  config.name = config.name.trim();
  config.revision = config.revision.trim();
  config.categories = config.categories.slice();
  var choices = Object.create(null);
  var visitorOptOut = false;
  var chosen = false;
  var storageAvailable = true;
  var savedAt = 0;
  var expiryTimer = null;
  var subscribers = [];
  var root, banner, closeButton, dialog, opener, heading, status, preferences, requests, requestTab, preferenceTab, optOutControl, gpcNotice;
  var checkboxes = Object.create(null);
  var pendingOpen = null;
  var priorFocus;
  var openPanel = false;
  config.categories.forEach(function (category) { choices[category] = false; });

  function gpc() {
    try { return config.respectGpc && window.navigator.globalPrivacyControl === true; }
    catch (error) { return false; }
  }
  function enforceSignals() {
    if ((visitorOptOut || gpc()) && Object.prototype.hasOwnProperty.call(choices, 'marketing')) choices.marketing = false;
  }
  function snapshot() {
    var result = {};
    var signal = gpc();
    config.categories.forEach(function (category) {
      result[category] = validConfig && choices[category] === true && !(category === 'marketing' && (signal || visitorOptOut));
    });
    result.doNotSell = visitorOptOut || signal;
    result.gpc = signal;
    return result;
  }
  function deny() {
    if (expiryTimer !== null) window.clearTimeout(expiryTimer);
    expiryTimer = null;
    config.categories.forEach(function (category) { choices[category] = false; });
    visitorOptOut = false;
    chosen = false;
    savedAt = 0;
  }
  function scheduleExpiry() {
    if (expiryTimer !== null) window.clearTimeout(expiryTimer);
    expiryTimer = null;
    if (savedAt <= 0) return;
    var remaining = Math.max(1, savedAt + config.consentLifetimeDays * 86400000 - Date.now());
    // Browser timers are signed 32-bit milliseconds; long lifetimes rearm.
    expiryTimer = window.setTimeout(function () {
      expiryTimer = null;
      getState();
      if (savedAt > 0) scheduleExpiry();
    }, Math.min(remaining, 2147483647));
  }
  function getState() {
    if (savedAt > 0 && Date.now() - savedAt >= config.consentLifetimeDays * 86400000) {
      deny(); notify(); renderState();
    }
    return snapshot();
  }
  function notify() {
    enforceSignals();
    subscribers.slice().forEach(function (callback) { try { callback(snapshot()); } catch (error) {} });
    window.dispatchEvent(new CustomEvent('privacy_update', {detail: snapshot()}));
    pushDataLayer();
    activateTags();
  }
  // A tag manager that already keeps a dataLayer (Google Tag Manager, for one)
  // hears each change of choices; none is created when the page has none.
  var lastPushed = null;
  function pushDataLayer() {
    var layer = own(window, 'dataLayer');
    if (!Array.isArray(layer)) return;
    var current = snapshot();
    var serialized = JSON.stringify(current);
    if (serialized === lastPushed) return;
    lastPushed = serialized;
    try { layer.push({event: 'micro_consent_update', micro_consent: current}); } catch (error) {}
  }
  // Start the website's waiting tags whose category is allowed, one at a time in
  // page order: a script with src and no async attribute loads before the next
  // starts. Only listed tag IDs run, and only after an explicit grant (or an
  // implicit_us default grant). Withdrawal cannot stop a tag that already ran.
  var pendingTags = [];
  var tagRunning = false;
  function activateTags() {
    var found;
    try { found = document.querySelectorAll('script[type="text/plain"][data-consent-tag]'); } catch (error) { return; }
    var granted = snapshot();
    Array.prototype.forEach.call(found || [], function (node) {
      var category = tagCategory(node.getAttribute('data-consent-tag'));
      if (category && granted[category] === true && !pendingTags.some(function (entry) { return entry.node === node; })) {
        pendingTags.push({node: node, category: category});
      }
    });
    runNextTag();
  }
  function runNextTag() {
    if (tagRunning || !pendingTags.length) return;
    var entry = pendingTags.shift();
    var placeholder = entry.node;
    var parent = placeholder.parentNode;
    // A grant withdrawn while the tag waited leaves it inert.
    if (!parent || snapshot()[entry.category] !== true) { runNextTag(); return; }
    var script = document.createElement('script');
    Array.prototype.forEach.call(placeholder.attributes, function (attribute) {
      if (attribute.name !== 'type' && attribute.name !== 'data-consent-tag') script.setAttribute(attribute.name, attribute.value);
    });
    if (placeholder.nonce) script.nonce = placeholder.nonce;
    var external = !!script.getAttribute('src');
    if (!external) script.text = placeholder.text;
    var wait = external && !placeholder.hasAttribute('async');
    if (wait) {
      tagRunning = true;
      var next = function () { if (!tagRunning) return; tagRunning = false; runNextTag(); };
      script.addEventListener('load', next);
      script.addEventListener('error', next);
    }
    try { parent.replaceChild(script, placeholder); } catch (error) { tagRunning = false; }
    if (!wait || !tagRunning) runNextTag();
  }
  function readSaved(raw) {
    try {
      var saved = JSON.parse(raw);
      var timestamp = own(saved, 'savedAt');
      var selected = own(saved, 'choices');
      var optOut = own(saved, 'doNotSell');
      if (!record(saved) || own(saved, 'revision') !== config.revision
        || !Number.isSafeInteger(timestamp) || timestamp <= 0 || timestamp > Date.now()
        || Date.now() - timestamp >= config.consentLifetimeDays * 86400000
        || !record(selected) || typeof optOut !== 'boolean'
        || !config.categories.every(function (category) { return typeof own(selected, category) === 'boolean'; })) return null;
      return {choices: selected, doNotSell: optOut, savedAt: timestamp};
    } catch (error) { return null; }
  }
  function restore(raw) {
    deny();
    var saved = validConfig ? readSaved(raw) : null;
    if (!saved) {
      applyDefaultGrants();
      return;
    }
    try {
      // Probe writability without rewriting a possibly stale preference record.
      window.localStorage.setItem(config.storageKey + ':probe', '1');
      window.localStorage.removeItem(config.storageKey + ':probe');
    } catch (error) { storageAvailable = false; return; }
    config.categories.forEach(function (category) { choices[category] = own(saved.choices, category) === true; });
    visitorOptOut = saved.doNotSell;
    savedAt = saved.savedAt;
    chosen = true;
    enforceSignals();
    scheduleExpiry();
  }
  if (validConfig) {
    try { restore(window.localStorage.getItem(config.storageKey)); }
    catch (error) { storageAvailable = false; deny(); }
  }
  function closePanel() {
    if (!dialog || !openPanel) return;
    openPanel = false;
    if (typeof dialog.close === 'function' && dialog.open) dialog.close();
    dialog.hidden = true;
    dialog.removeAttribute('open');
    opener.setAttribute('aria-expanded', 'false');
    if (priorFocus && typeof priorFocus.focus === 'function') priorFocus.focus();
    else opener.focus();
  }
  function renderState() {
    if (!root) return;
    var state = snapshot();
    banner.hidden = chosen || dismissedForSession();
    // The bar covers the reopen button's corner, so it shows only once the bar is closed.
    opener.hidden = reopenPosition === 'hidden' || (config.layout === 'bar' && !banner.hidden);
    config.categories.forEach(function (category) {
      checkboxes[category].checked = state[category];
      checkboxes[category].disabled = !validConfig || (category === 'marketing' && state.doNotSell);
    });
    optOutControl.checked = state.doNotSell;
    optOutControl.disabled = !validConfig || state.gpc;
    gpcNotice.hidden = !state.gpc;
  }
  function setConsent(value) {
    config.categories.forEach(function (category) { choices[category] = validConfig && own(value, category) === true; });
    var optOut = own(value, 'doNotSell');
    if (typeof optOut === 'boolean') visitorOptOut = optOut;
    enforceSignals();
    chosen = validConfig;
    savedAt = validConfig ? Date.now() : 0;
    scheduleExpiry();
    // Withdrawal reaches connected tools before storage or rendering can fail.
    notify();
    if (validConfig) {
      try {
        window.localStorage.setItem(config.storageKey, JSON.stringify({revision: config.revision, savedAt: savedAt, choices: choices, doNotSell: visitorOptOut}));
        storageAvailable = true;
      } catch (error) {
        storageAvailable = false;
        try { window.localStorage.removeItem(config.storageKey); } catch (ignored) {}
      }
    }
    renderState();
    closePanel();
    if (status) status.textContent = validConfig
      ? t('statusApplied') + (storageAvailable ? '' : ' ' + t('statusNotSaved'))
      : 'Privacy controls are misconfigured. Optional categories remain denied.';
  }
  function selectPanel(showRequests) {
    var showForm = !!(showRequests && requests);
    preferences.hidden = showForm;
    if (requests) requests.hidden = !showForm;
    preferenceTab.setAttribute('aria-selected', showForm ? 'false' : 'true');
    preferenceTab.tabIndex = showForm ? -1 : 0;
    if (requestTab) { requestTab.setAttribute('aria-selected', showForm ? 'true' : 'false'); requestTab.tabIndex = showForm ? 0 : -1; }
  }
  function open(showRequests) {
    if (!dialog) { pendingOpen = showRequests ? 'requests' : 'preferences'; return; }
    getState(); renderState(); selectPanel(showRequests);
    if (!openPanel) priorFocus = document.activeElement;
    openPanel = true;
    dialog.hidden = false;
    if (typeof dialog.showModal === 'function') { if (!dialog.open) dialog.showModal(); }
    else dialog.setAttribute('open', '');
    opener.setAttribute('aria-expanded', 'true');
    heading.focus();
  }
  var bannerTitle, bannerDescription, regionSelect;
  var bannerButtons = {};

  function dismiss() {
    var action = closeAction();
    if (action === 'reject') { setConsent({}); return; }
    if (action !== 'dismiss') return;
    dismissed = true;
    try { window.sessionStorage.setItem(dismissKey, '1'); } catch (error) {}
    renderState();
    if (!opener.hidden) opener.focus();
  }
  function labelCloseButton() {
    if (!closeButton) return;
    var action = closeAction();
    closeButton.hidden = action === 'hidden';
    var label = t(action === 'reject' ? 'dismissReject' : 'dismiss');
    closeButton.setAttribute('aria-label', label);
    closeButton.title = label;
  }

  function updateUiText() {
    labelCloseButton();
    if (bannerTitle) bannerTitle.textContent = t('title');
    if (bannerDescription) bannerDescription.textContent = t('description');
    if (bannerButtons.reject) bannerButtons.reject.textContent = t('reject');
    if (bannerButtons.accept) bannerButtons.accept.textContent = t('accept');
    if (bannerButtons.manage) bannerButtons.manage.textContent = t('manage');
    if (opener) opener.textContent = t('reopen');
    if (heading) heading.textContent = t('title');
    if (regionSelect && currentRegion) regionSelect.value = currentRegion.id;
  }

  function setRegion(id) {
    var reg = getRegion(id);
    if (!reg) return;
    currentRegion = reg;
    try { window.localStorage.setItem(regionStorageKey, id); } catch (e) {}
    if (!chosen && savedAt === 0) {
      applyDefaultGrants();
      notify();
      renderState();
    }
    updateUiText();
  }

  window.MicroConsent = {
    getState: getState,
    setConsent: setConsent,
    getRegion: function () { return currentRegion ? currentRegion.id : null; },
    setRegion: setRegion,
    subscribe: function (callback) {
      if (typeof callback !== 'function') return function () {};
      subscribers.push(callback);
      return function () { subscribers = subscribers.filter(function (entry) { return entry !== callback; }); };
    },
    open: function () { open(false); },
    openRequests: function () { open(true); }
  };
  notify();
  document.dispatchEvent(new CustomEvent('micro-consent-ready'));
  window.addEventListener('storage', function (event) {
    if (event.key === regionStorageKey && event.newValue) {
      setRegion(event.newValue);
      return;
    }
    if (event.key !== config.storageKey && event.key !== null) return;
    // Storage events may be delivered after a newer choice. Read the latest
    // shared value, never rewrite an old event payload or resurrect its grant.
    try { restore(window.localStorage.getItem(config.storageKey)); }
    catch (error) { storageAvailable = false; deny(); }
    notify(); renderState();
    if (status) status.textContent = t('statusOtherTab');
  });
  window.addEventListener('focus', function () { getState(); enforceSignals(); notify(); renderState(); });

  function element(tag, text, className) {
    var node = document.createElement(tag);
    if (text) node.textContent = text;
    if (className) node.className = className;
    return node;
  }
  function button(label, action) {
    var node = element('button', label, 'mc-button');
    node.type = 'button'; node.addEventListener('click', action);
    return node;
  }
  function policyLink(parent) {
    if (!config.privacyPolicyUrl) return;
    var link = element('a', t('privacyLink'));
    link.href = config.privacyPolicyUrl; link.referrerPolicy = 'no-referrer'; parent.appendChild(link);
  }
  function accept() {
    var selected = {};
    config.categories.forEach(function (category) { selected[category] = true; });
    setConsent(selected);
  }
  // The banner shows the configured buttons; the preferences dialog replaces
  // manage with save. Every action button shares one style.
  function actions(parent, withSave) {
    var row = element('div', null, 'mc-actions');
    var list = currentShownButtons().map(function (name) { return withSave && name === 'manage' ? 'save' : name; });
    if (withSave && list.indexOf('save') === -1) list.push('save');
    list.forEach(function (name) {
      var node;
      if (name === 'reject') {
        node = button(t('reject'), function () { setConsent({}); });
        if (!withSave) bannerButtons.reject = node;
      } else if (name === 'manage') {
        node = button(t('manage'), function () { open(false); });
        if (!withSave) bannerButtons.manage = node;
      } else if (name === 'accept') {
        node = button(t('accept'), accept); node.disabled = !validConfig;
        if (!withSave) bannerButtons.accept = node;
      }
      else {
        node = button(t('save'), function () {
          var selected = {};
          config.categories.forEach(function (category) { selected[category] = checkboxes[category].checked === true; });
          selected.doNotSell = optOutControl.checked === true;
          setConsent(selected);
        });
        node.disabled = !validConfig;
      }
      row.appendChild(node);
    });
    parent.appendChild(row);
  }
  function requestForm(parent) {
    var disclosure = element('p', t('requestDisclosure'));
    disclosure.id = 'mc-request-disclosure'; parent.appendChild(disclosure);
    var form = element('form');
    form.id = 'mc-request-form'; form.method = 'post'; form.action = config.formspreeEndpoint;
    form.setAttribute('aria-describedby', disclosure.id);
    function field(tag, name, text) {
      var row = element('div', null, 'mc-field');
      var label = element('label', text); label.htmlFor = 'mc-request-' + name;
      var control = element(tag); control.id = label.htmlFor; control.name = name;
      row.appendChild(label); row.appendChild(control); form.appendChild(row);
      return control;
    }
    var email = field('input', 'email', t('requestEmail'));
    email.type = 'email'; email.required = true; email.maxLength = 254; email.autocomplete = 'email';
    var type = field('select', 'request_type', t('requestType'));
    [['access', t('requestAccess')], ['delete', t('requestDelete')], ['correct', t('requestCorrect')], ['opt-out', t('requestOptOut')]].forEach(function (entry) {
      var option = element('option', entry[1]); option.value = entry[0]; type.appendChild(option);
    });
    type.value = 'access';
    var message = field('textarea', 'message', t('requestMessage'));
    message.maxLength = 2000; message.rows = 3;
    var submit = element('button', t('requestSubmit'), 'mc-button'); submit.type = 'submit'; form.appendChild(submit);
    var feedback = element('p', '', 'mc-request-status'); feedback.id = 'mc-request-status';
    feedback.setAttribute('role', 'status'); feedback.setAttribute('aria-live', 'polite'); form.appendChild(feedback);
    var pending = false;
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      if (pending) return;
      var address = typeof email.value === 'string' ? email.value.trim() : '';
      var kind = type.value;
      var text = typeof message.value === 'string' ? message.value.trim() : '';
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(address) || address.length > 254
        || ['access', 'delete', 'correct', 'opt-out'].indexOf(kind) === -1 || text.length > 2000
        || /[\u0000-\u0008\u000b\u000c\u000e-\u001f\u007f-\u009f]/.test(text)) {
        feedback.textContent = t('requestInvalid'); return;
      }
      if (!validConfig || !config.formspreeEndpoint || typeof window.fetch !== 'function') {
        feedback.textContent = t('requestUnavailable'); return;
      }
      var data = new FormData();
      data.append('email', address); data.append('request_type', kind); data.append('message', text);
      pending = true; submit.disabled = true; feedback.textContent = t('requestSending');
      function failed() { feedback.textContent = t('requestFailed'); }
      var submission;
      try {
        submission = window.fetch(config.formspreeEndpoint, {
          method: 'POST', body: data, headers: {'Accept': 'application/json'}, credentials: 'omit', referrerPolicy: 'no-referrer'
        });
      } catch (error) { submission = Promise.reject(error); }
      Promise.resolve(submission).then(function (response) {
        if (!response || response.ok !== true) { failed(); return; }
        email.value = ''; message.value = '';
        feedback.textContent = t('requestSent');
      }, failed).then(function () { pending = false; submit.disabled = false; });
    });
    parent.appendChild(form);
  }
  function mount() {
    if (root || !document.body) return;
    var style = element(typeof microConsentStyles === 'string' ? 'style' : 'link');
    if (scriptNonce) style.nonce = scriptNonce;
    if (typeof microConsentStyles === 'string') { style.textContent = microConsentStyles; document.head.appendChild(style); }
    else if (scriptSource) {
      style.rel = 'stylesheet'; style.href = new URL('../css/consent-ui.css', scriptSource).href;
      style.referrerPolicy = 'no-referrer'; document.head.appendChild(style);
    }
    root = element('div', null, 'mc-root');
    // Validated #RRGGBB colors reach the stylesheet through CSSOM, which CSP allows.
    names(config.theme).forEach(function (key) {
      if (root.style && typeof root.style.setProperty === 'function') {
        root.style.setProperty('--mc-' + key.replace(/[A-Z]/g, function (letter) { return '-' + letter.toLowerCase(); }), hexColor(config.theme[key]));
      }
    });
    banner = element('section', null, 'mc-banner' + (config.layout === 'bar' ? ' mc-banner--bar' : '')); banner.setAttribute('aria-labelledby', 'mc-banner-title');
    // The visible × is not announced; the label says what closing does.
    closeButton = element('button', '\u00D7', 'mc-close'); closeButton.type = 'button';
    closeButton.addEventListener('click', dismiss); labelCloseButton(); banner.appendChild(closeButton);

    if (regions.length >= 2) {
      var regionRow = element('div', null, 'mc-region');
      var regLabel = element('label', 'Region', 'mc-region-label');
      regLabel.setAttribute('for', 'mc-region-select');
      regionSelect = element('select', null, 'mc-region-select');
      regionSelect.id = 'mc-region-select';
      regions.forEach(function (r) {
        var opt = element('option', r.name);
        opt.value = r.id;
        if (currentRegion && currentRegion.id === r.id) opt.selected = true;
        regionSelect.appendChild(opt);
      });
      regionSelect.addEventListener('change', function (event) {
        setRegion((event && event.target && event.target.value) || (this && this.value) || (regionSelect && regionSelect.value));
      });
      regionRow.appendChild(regLabel);
      regionRow.appendChild(regionSelect);
      banner.appendChild(regionRow);
    }

    bannerTitle = element('h2', t('title')); bannerTitle.id = 'mc-banner-title'; banner.appendChild(bannerTitle);
    bannerDescription = element('p', t('description')); banner.appendChild(bannerDescription);
    details().forEach(function (paragraph) { banner.appendChild(element('p', paragraph)); });
    if (!validConfig) banner.appendChild(element('p', 'Privacy controls are misconfigured. Optional categories remain denied. Contact this website’s operator.', 'mc-error'));
    policyLink(banner); actions(banner, false);
    opener = button(t('reopen'), function () { open(false); }); opener.className += ' mc-open' + (reopenPosition === 'bottom-left' ? ' mc-open--left' : '');
    // A hidden button needs the website's own link to MicroConsent.open().
    opener.hidden = reopenPosition === 'hidden';
    opener.setAttribute('aria-controls', 'mc-dialog'); opener.setAttribute('aria-expanded', 'false');
    dialog = element('dialog', null, 'mc-dialog'); dialog.id = 'mc-dialog'; dialog.hidden = true;
    dialog.setAttribute('role', 'dialog'); dialog.setAttribute('aria-modal', 'true'); dialog.setAttribute('aria-labelledby', 'mc-dialog-title');
    heading = element('h2', t('title')); heading.id = 'mc-dialog-title'; heading.tabIndex = -1; dialog.appendChild(heading);
    var tabs = element('div', null, 'mc-tabs'); tabs.setAttribute('role', 'tablist'); tabs.setAttribute('aria-label', t('settingsLabel'));
    preferenceTab = button(t('preferencesTab'), function () { selectPanel(false); }); preferenceTab.id = 'mc-preferences-tab';
    preferenceTab.setAttribute('role', 'tab'); preferenceTab.setAttribute('aria-controls', 'mc-preferences'); tabs.appendChild(preferenceTab);
    if (validConfig && config.formspreeEndpoint) {
      requestTab = button(t('requestsTab'), function () { selectPanel(true); }); requestTab.id = 'mc-requests-tab';
      requestTab.setAttribute('role', 'tab'); requestTab.setAttribute('aria-controls', 'mc-requests'); tabs.appendChild(requestTab);
    }
    tabs.addEventListener('keydown', function (event) {
      if (!requestTab || ['ArrowLeft', 'ArrowRight', 'Home', 'End'].indexOf(event.key) === -1) return;
      event.preventDefault();
      var next = event.key === 'Home' ? preferenceTab : event.key === 'End' ? requestTab : document.activeElement === preferenceTab ? requestTab : preferenceTab;
      selectPanel(next === requestTab); next.focus();
    });
    dialog.appendChild(tabs);
    preferences = element('section'); preferences.id = 'mc-preferences'; preferences.setAttribute('role', 'tabpanel'); preferences.setAttribute('aria-labelledby', preferenceTab.id);
    preferences.appendChild(element('p', t('preferencesIntro')));
    var fields = element('fieldset'); fields.appendChild(element('legend', t('categoriesLegend')));
    config.categories.forEach(function (category) {
      var row = element('label', null, 'mc-category'); var checkbox = element('input');
      checkbox.type = 'checkbox'; checkbox.id = 'mc-category-' + category; row.htmlFor = checkbox.id; checkboxes[category] = checkbox; row.appendChild(checkbox);
      row.appendChild(element('span', categoryLabel(category)));
      var tagList = tagNames(category);
      if (tagList.length) row.appendChild(element('span', t('categoryTags').split('{tags}').join(tagList.join(', ')), 'mc-category-tags'));
      fields.appendChild(row);
    });
    preferences.appendChild(fields);
    var optOutLabel = element('label', null, 'mc-category'); optOutControl = element('input'); optOutControl.type = 'checkbox';
    optOutControl.id = 'mc-do-not-sell'; optOutLabel.htmlFor = optOutControl.id; optOutLabel.appendChild(optOutControl);
    optOutLabel.appendChild(element('span', t('optOut')));
    optOutControl.addEventListener('change', function () {
      if (checkboxes.marketing) {
        checkboxes.marketing.disabled = !validConfig || optOutControl.checked || gpc();
        if (optOutControl.checked || gpc()) checkboxes.marketing.checked = false;
      }
    });
    preferences.appendChild(optOutLabel);
    preferences.appendChild(element('p', t('optOutHelp')));
    gpcNotice = element('p', t('gpcNotice'), 'mc-notice'); preferences.appendChild(gpcNotice);
    preferences.appendChild(element('p', t('storageNotice')));
    policyLink(preferences); actions(preferences, true); dialog.appendChild(preferences);
    if (requestTab) {
      requests = element('section'); requests.id = 'mc-requests'; requests.setAttribute('role', 'tabpanel'); requests.setAttribute('aria-labelledby', requestTab.id);
      requestForm(requests); dialog.appendChild(requests);
    }
    dialog.appendChild(button(t('close'), closePanel));
    dialog.addEventListener('cancel', function (event) { event.preventDefault(); closePanel(); });
    dialog.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') { event.preventDefault(); closePanel(); return; }
      if (event.key !== 'Tab') return;
      var focusable = Array.prototype.filter.call(dialog.querySelectorAll('button, input, select, textarea, a[href]'), function (node) {
        return !node.disabled && node.tabIndex !== -1 && !(node.closest && node.closest('[hidden]'));
      });
      if (!focusable.length) return;
      var first = focusable[0], last = focusable[focusable.length - 1];
      if (event.shiftKey && (document.activeElement === first || document.activeElement === heading)) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    });
    status = element('p', '', 'mc-status'); status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
    root.appendChild(banner); root.appendChild(opener); root.appendChild(dialog); root.appendChild(status); document.body.appendChild(root);
    selectPanel(false); renderState();
    if (pendingOpen) { var requested = pendingOpen; pendingOpen = null; open(requested === 'requests'); }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount, {once: true});
  else mount();
})();
