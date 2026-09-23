/*! SPDX-License-Identifier: AGPL-3.0-only; see LICENSE in the source repository. */
(function () {
  'use strict';
  var consentConfig = {namespace: 'Aggregate', name: 'Analytics'};
  if (window.AggregateConsent) return;
  var scriptNonce = document.currentScript && document.currentScript.nonce;

  var storageKey = 'analytics_consent_v1:' + consentConfig.namespace + (consentConfig.siteId ? ':' + consentConfig.siteId : '');
  var categories = ['analytics'];
  (Array.isArray(consentConfig.categories) ? consentConfig.categories : []).forEach(function (category) {
    if (typeof category === 'string' && /^[a-z][a-z0-9_-]{0,31}$/.test(category)
      && category !== 'none' && categories.indexOf(category) === -1) categories.push(category);
  });
  var choices = Object.create(null);
  categories.forEach(function (category) { choices[category] = false; });
  var chosen = false;
  var subscribers = [];
  var panel;
  var preferenceButton;
  var heading;
  var status;
  var checkboxes = Object.create(null);
  var storageAvailable = true;

  function readGrant(value, category) {
    try {
      return !!(value && typeof value === 'object' && !Array.isArray(value)
        && Object.prototype.hasOwnProperty.call(value, category) && value[category] === true);
    } catch (error) { return false; }
  }

  function hasChoice(value, category) {
    return Object.prototype.hasOwnProperty.call(value, category) && typeof value[category] === 'boolean';
  }

  try {
    var saved = JSON.parse(window.localStorage.getItem(storageKey));
    if (typeof saved === 'boolean' || (saved && typeof saved === 'object' && !Array.isArray(saved))) {
      // If storage cannot be updated, do not restore an old affirmative choice.
      window.localStorage.setItem(storageKey, JSON.stringify(saved));
      if (typeof saved === 'boolean') {
        choices.analytics = saved === true;
        chosen = categories.length === 1;
      } else {
        categories.forEach(function (category) { choices[category] = readGrant(saved, category); });
        chosen = categories.every(function (category) { return hasChoice(saved, category); });
      }
    }
  } catch (error) { storageAvailable = false; }

  function state() {
    var result = {};
    categories.forEach(function (category) { result[category] = choices[category] === true; });
    return result;
  }

  function notify() {
    var tracker = window[consentConfig.namespace];
    if (!tracker || (typeof tracker !== 'object' && typeof tracker !== 'function')) {
      tracker = {};
      window[consentConfig.namespace] = tracker;
    }
    // Preload configuration also handles a tracker that loads after this script.
    tracker.consent = choices.analytics;
    try { if (typeof tracker.setConsent === 'function') tracker.setConsent(choices.analytics); } catch (error) {}
    try { if (window.AggregateTags && typeof window.AggregateTags.setConsent === 'function') window.AggregateTags.setConsent(state()); } catch (error) {}
    subscribers.slice().forEach(function (callback) {
      try { callback(state()); } catch (error) {}
    });
  }

  function setConsent(value) {
    if (typeof value === 'boolean') {
      // The legacy boolean API changes analytics only, never other categories.
      choices.analytics = value === true;
    } else {
      categories.forEach(function (category) { choices[category] = readGrant(value, category); });
    }
    chosen = true;
    // Apply withdrawal before touching storage or rendering controls.
    notify();
    try {
      window.localStorage.setItem(storageKey, JSON.stringify(state()));
      storageAvailable = true;
    } catch (error) {
      storageAvailable = false;
      if (!choices.analytics || categories.some(function (category) { return !choices[category]; })) {
        try { window.localStorage.removeItem(storageKey); } catch (ignored) {}
      }
    }
    if (panel) {
      panel.hidden = true;
      preferenceButton.setAttribute('aria-expanded', 'false');
      preferenceButton.focus();
      status.textContent = 'Your privacy choices have been applied.'
        + (storageAvailable ? '' : ' This choice could not be saved; choose again on your next visit.');
    }
  }

  function open() {
    if (!panel) return;
    panel.hidden = false;
    categories.forEach(function (category) { checkboxes[category].checked = choices[category]; });
    preferenceButton.setAttribute('aria-expanded', 'true');
    heading.focus();
  }

  window.AggregateConsent = {
    getState: state,
    setConsent: setConsent,
    open: open,
    subscribe: function (callback) {
      if (typeof callback !== 'function') return function () {};
      subscribers.push(callback);
      return function () { subscribers = subscribers.filter(function (entry) { return entry !== callback; }); };
    }
  };
  notify();
  document.dispatchEvent(new CustomEvent('aggregate:consent-ready'));

  // Choices in another tab apply immediately; malformed or removed choices deny.
  window.addEventListener('storage', function (event) {
    if (event.key !== storageKey && event.key !== null) return;
    var value = null;
    try { value = JSON.parse(event.newValue); } catch (error) {}
    categories.forEach(function (category) {
      choices[category] = typeof value === 'boolean' ? category === 'analytics' && value
        : readGrant(value, category);
    });
    chosen = event.key !== null && (typeof value === 'boolean' ? categories.length === 1
      : !!(value && typeof value === 'object' && !Array.isArray(value)
        && categories.every(function (category) { return hasChoice(value, category); })));
    notify();
    if (panel) {
      panel.hidden = chosen;
      preferenceButton.setAttribute('aria-expanded', chosen ? 'false' : 'true');
      categories.forEach(function (category) { checkboxes[category].checked = choices[category]; });
      status.textContent = 'Your privacy choices were updated in another tab.';
    }
  });

  function element(tag, text) {
    var node = document.createElement(tag);
    if (text) node.textContent = text;
    return node;
  }

  function mount() {
    if (!document.body || panel) return;
    var style = element('style');
    if (scriptNonce) style.nonce = scriptNonce;
    style.textContent = '.ac-consent{box-sizing:border-box;width:min(34rem,calc(100vw - 2rem));font:16px/1.5 system-ui,sans-serif;color:#202124;background:#fff;position:fixed;bottom:1rem;right:1rem;z-index:2147483000;max-width:min(34rem,calc(100vw - 2rem));padding:1rem;border:2px solid #202124;border-radius:.5rem;box-shadow:0 2px 12px #0003;max-height:calc(100vh - 2rem);overflow:auto}.ac-consent[hidden]{display:none}.ac-consent h2{font:700 1.15rem/1.4 system-ui,sans-serif;color:inherit;margin:0 0 .5rem}.ac-consent p{margin:.5rem 0}.ac-consent button,.ac-consent-open{font:inherit;color:#202124;background:#fff;border:2px solid #202124;border-radius:.25rem;padding:.5rem .75rem;cursor:pointer}.ac-consent button{margin:.25rem .5rem .25rem 0}.ac-consent button:focus-visible,.ac-consent-open:focus-visible,.ac-consent h2:focus-visible{outline:3px solid #2459b8;outline-offset:3px}.ac-consent-open{font:14px/1.5 system-ui,sans-serif;position:fixed;bottom:.5rem;left:.5rem;z-index:2147482999}.ac-consent-status{position:absolute;width:1px;height:1px;overflow:hidden;clip-path:inset(50%)}';
    document.head.appendChild(style);
    panel = element('section');
    panel.className = 'ac-consent';
    panel.id = 'ac-consent-panel';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-labelledby', 'ac-consent-title');
    panel.setAttribute('aria-describedby', 'ac-consent-description');
    heading = element('h2', consentConfig.name + ': privacy choices');
    heading.id = 'ac-consent-title';
    heading.tabIndex = -1;
    panel.appendChild(heading);
    var description = element('p', 'Choose which optional categories to allow. Enhanced analytics uses browser identifiers and additional event details. Tags in each selected category may load third-party scripts.');
    description.id = 'ac-consent-description';
    panel.appendChild(description);
    panel.appendChild(element('p', 'Coarse anonymous measurement may continue after rejection. You can change your choice here at any time. Withdrawal removes analytics identifiers and stops future enhanced detail; it does not erase stored history.'));
    panel.appendChild(element('p', 'After withdrawal, reload this page to stop optional scripts already loaded. Tags configured to require no consent can run regardless of these choices. See this website’s privacy notice for its data, purposes, and providers.'));
    var fieldset = element('fieldset');
    fieldset.appendChild(element('legend', 'Optional categories'));
    categories.forEach(function (category) {
      var label = element('label');
      label.style.display = 'block';
      label.style.margin = '.5rem 0';
      var checkbox = element('input');
      checkbox.type = 'checkbox';
      checkbox.checked = choices[category];
      checkbox.id = 'ac-consent-' + category;
      checkboxes[category] = checkbox;
      label.appendChild(checkbox);
      label.appendChild(element('span', ' ' + (category === 'analytics' ? 'Enhanced analytics' : category.replace(/[-_]/g, ' ').replace(/^./, function (letter) { return letter.toUpperCase(); }))));
      fieldset.appendChild(label);
    });
    panel.appendChild(fieldset);
    var reject = element('button', 'Reject all optional categories');
    reject.type = 'button';
    reject.addEventListener('click', function () { setConsent({}); });
    panel.appendChild(reject);
    var accept = element('button', 'Accept all optional categories');
    accept.type = 'button';
    accept.addEventListener('click', function () {
      var values = {};
      categories.forEach(function (category) { values[category] = true; });
      setConsent(values);
    });
    panel.appendChild(accept);
    var save = element('button', 'Save selected choices');
    save.type = 'button';
    save.addEventListener('click', function () {
      var values = {};
      categories.forEach(function (category) { values[category] = checkboxes[category].checked === true; });
      setConsent(values);
    });
    panel.appendChild(save);
    panel.addEventListener('keydown', function (event) {
      if (event.key !== 'Escape') return;
      panel.hidden = true;
      preferenceButton.setAttribute('aria-expanded', 'false');
      preferenceButton.focus();
    });
    preferenceButton = element('button', 'Privacy choices');
    preferenceButton.type = 'button';
    preferenceButton.className = 'ac-consent-open';
    preferenceButton.setAttribute('aria-controls', panel.id);
    preferenceButton.setAttribute('aria-expanded', chosen ? 'false' : 'true');
    preferenceButton.addEventListener('click', open);
    status = element('span');
    status.className = 'ac-consent-status';
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    panel.hidden = chosen;
    document.body.appendChild(preferenceButton);
    document.body.appendChild(panel);
    document.body.appendChild(status);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount, {once: true});
  else mount();
})();
