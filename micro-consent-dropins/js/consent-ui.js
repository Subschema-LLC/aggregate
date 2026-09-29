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
  var keys = ['name', 'privacyPolicyUrl', 'formspreeEndpoint', 'categories', 'respectGpc', 'consentLifetimeDays', 'revision', 'storageKey', 'aggregateNamespace'];
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
    aggregateNamespace: option('aggregateNamespace', 'Aggregate', function (value) { return boundedText(value, 120, false) && reserved.indexOf(value) === -1; })
  };
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
  var root, banner, dialog, opener, heading, status, preferences, requests, requestTab, preferenceTab, optOutControl, gpcNotice;
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
    if (!saved) return;
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
    banner.hidden = chosen;
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
      ? 'Your privacy choices have been applied.' + (storageAvailable ? '' : ' They could not be saved. Choose again on your next visit.')
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
  window.MicroConsent = {
    getState: getState,
    setConsent: setConsent,
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
    if (event.key !== config.storageKey && event.key !== null) return;
    // Storage events may be delivered after a newer choice. Read the latest
    // shared value, never rewrite an old event payload or resurrect its grant.
    try { restore(window.localStorage.getItem(config.storageKey)); }
    catch (error) { storageAvailable = false; deny(); }
    notify(); renderState();
    if (status) status.textContent = 'Privacy choices were updated in another tab.';
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
    var link = element('a', 'Read this website’s privacy notice');
    link.href = config.privacyPolicyUrl; link.referrerPolicy = 'no-referrer'; parent.appendChild(link);
  }
  function accept() {
    var selected = {};
    config.categories.forEach(function (category) { selected[category] = true; });
    setConsent(selected);
  }
  function actions(parent, withSave) {
    var row = element('div', null, 'mc-actions');
    row.appendChild(button('Reject optional categories', function () { setConsent({}); }));
    var allow = button('Accept optional categories', accept);
    allow.disabled = !validConfig; row.appendChild(allow);
    if (withSave) {
      var save = button('Save selected choices', function () {
        var selected = {};
        config.categories.forEach(function (category) { selected[category] = checkboxes[category].checked === true; });
        selected.doNotSell = optOutControl.checked === true;
        setConsent(selected);
      });
      save.disabled = !validConfig; row.appendChild(save);
    } else row.appendChild(button('Manage choices', function () { open(false); }));
    parent.appendChild(row);
  }
  function requestForm(parent) {
    var disclosure = element('p', 'Submitting this form sends your email address, request type, and optional message to Formspree for this website’s operator. Nothing is sent until you submit. No visitor identifier or page URL is added. Do not include sensitive information.');
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
    var email = field('input', 'email', 'Email address');
    email.type = 'email'; email.required = true; email.maxLength = 254; email.autocomplete = 'email';
    var type = field('select', 'request_type', 'Request type');
    [['access', 'Access my data'], ['delete', 'Delete my data'], ['correct', 'Correct my data'], ['opt-out', 'Opt out of sale, sharing, or targeted advertising']].forEach(function (entry) {
      var option = element('option', entry[1]); option.value = entry[0]; type.appendChild(option);
    });
    type.value = 'access';
    var message = field('textarea', 'message', 'Message (optional, up to 2,000 characters)');
    message.maxLength = 2000; message.rows = 3;
    var submit = element('button', 'Submit privacy request', 'mc-button'); submit.type = 'submit'; form.appendChild(submit);
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
        feedback.textContent = 'Enter a valid email address and request type, and keep your message within 2,000 characters.'; return;
      }
      if (!validConfig || !config.formspreeEndpoint || typeof window.fetch !== 'function') {
        feedback.textContent = 'This request could not be sent. Use the contact information in this website’s privacy notice.'; return;
      }
      var data = new FormData();
      data.append('email', address); data.append('request_type', kind); data.append('message', text);
      pending = true; submit.disabled = true; feedback.textContent = 'Sending your request to Formspree…';
      function failed() { feedback.textContent = 'Your request could not be submitted. Your entries are still here; retry or use the contact information in this website’s privacy notice.'; }
      var submission;
      try {
        submission = window.fetch(config.formspreeEndpoint, {
          method: 'POST', body: data, headers: {'Accept': 'application/json'}, credentials: 'omit', referrerPolicy: 'no-referrer'
        });
      } catch (error) { submission = Promise.reject(error); }
      Promise.resolve(submission).then(function (response) {
        if (!response || response.ok !== true) { failed(); return; }
        email.value = ''; message.value = '';
        feedback.textContent = 'Your request was submitted to Formspree for this website’s operator. The operator must review and process it; submission does not erase stored data.';
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
    banner = element('section', null, 'mc-banner'); banner.setAttribute('aria-labelledby', 'mc-banner-title');
    var title = element('h2', config.name + ': privacy choices'); title.id = 'mc-banner-title'; banner.appendChild(title);
    banner.appendChild(element('p', 'Choose which optional categories to allow. They start denied. You can change your choices at any time.'));
    banner.appendChild(element('p', 'These choices apply to connected tools. If this website uses privacy-minimized analytics, coarse measurement may continue after rejection. See its privacy notice for the actual data and providers.'));
    if (!validConfig) banner.appendChild(element('p', 'Privacy controls are misconfigured. Optional categories remain denied. Contact this website’s operator.', 'mc-error'));
    policyLink(banner); actions(banner, false);
    opener = button('Privacy choices', function () { open(false); }); opener.className += ' mc-open';
    opener.setAttribute('aria-controls', 'mc-dialog'); opener.setAttribute('aria-expanded', 'false');
    dialog = element('dialog', null, 'mc-dialog'); dialog.id = 'mc-dialog'; dialog.hidden = true;
    dialog.setAttribute('role', 'dialog'); dialog.setAttribute('aria-modal', 'true'); dialog.setAttribute('aria-labelledby', 'mc-dialog-title');
    heading = element('h2', config.name + ': privacy choices'); heading.id = 'mc-dialog-title'; heading.tabIndex = -1; dialog.appendChild(heading);
    var tabs = element('div', null, 'mc-tabs'); tabs.setAttribute('role', 'tablist'); tabs.setAttribute('aria-label', 'Privacy settings');
    preferenceTab = button('Preferences', function () { selectPanel(false); }); preferenceTab.id = 'mc-preferences-tab';
    preferenceTab.setAttribute('role', 'tab'); preferenceTab.setAttribute('aria-controls', 'mc-preferences'); tabs.appendChild(preferenceTab);
    if (validConfig && config.formspreeEndpoint) {
      requestTab = button('Privacy request', function () { selectPanel(true); }); requestTab.id = 'mc-requests-tab';
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
    preferences.appendChild(element('p', 'Allow only the categories you choose. Rejection and withdrawal stop future actions in connected tools. Scripts already loaded may continue until you reload, and their cookies and stored history are not automatically erased.'));
    var fields = element('fieldset'); fields.appendChild(element('legend', 'Optional categories'));
    config.categories.forEach(function (category) {
      var row = element('label', null, 'mc-category'); var checkbox = element('input');
      checkbox.type = 'checkbox'; checkbox.id = 'mc-category-' + category; row.htmlFor = checkbox.id; checkboxes[category] = checkbox; row.appendChild(checkbox);
      row.appendChild(element('span', category === 'analytics' ? 'Analytics (enhanced details when connected to the analytics tracker)'
        : category.replace(/[-_]/g, ' ').replace(/^./, function (letter) { return letter.toUpperCase(); })));
      fields.appendChild(row);
    });
    preferences.appendChild(fields);
    var optOutLabel = element('label', null, 'mc-category'); optOutControl = element('input'); optOutControl.type = 'checkbox';
    optOutControl.id = 'mc-do-not-sell'; optOutLabel.htmlFor = optOutControl.id; optOutLabel.appendChild(optOutControl);
    optOutLabel.appendChild(element('span', 'Opt out of sale, sharing, or targeted advertising in connected tools'));
    optOutControl.addEventListener('change', function () {
      if (checkboxes.marketing) {
        checkboxes.marketing.disabled = !validConfig || optOutControl.checked || gpc();
        if (optOutControl.checked || gpc()) checkboxes.marketing.checked = false;
      }
    });
    preferences.appendChild(optOutLabel);
    preferences.appendChild(element('p', 'This opt-out disables the marketing category and signals connected providers. It cannot enforce choices for tools that are not connected or process an organization-wide privacy request.'));
    gpcNotice = element('p', 'Your browser sends Global Privacy Control. We keep the advertising opt-out on and marketing denied. This signal does not grant analytics consent.', 'mc-notice'); preferences.appendChild(gpcNotice);
    preferences.appendChild(element('p', 'Your category choices, opt-out, policy revision, and save time are stored in this browser for up to ' + config.consentLifetimeDays + ' days. No visitor identifier is added.'));
    policyLink(preferences); actions(preferences, true); dialog.appendChild(preferences);
    if (requestTab) {
      requests = element('section'); requests.id = 'mc-requests'; requests.setAttribute('role', 'tabpanel'); requests.setAttribute('aria-labelledby', requestTab.id);
      requestForm(requests); dialog.appendChild(requests);
    }
    dialog.appendChild(button('Close privacy settings', closePanel));
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
