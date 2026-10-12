/*! SPDX-License-Identifier: AGPL-3.0-only; see LICENSE in the source repository. */
(function () {
  'use strict';
  var consentConfig = {namespace: 'Aggregate', name: 'Analytics'};
  var consentStyles = null;
  if (window.AggregateConsent) return;
  var currentScript = document.currentScript;
  var scriptNonce = currentScript && currentScript.nonce;
  var scriptSource = currentScript && currentScript.src;

  // Wording, colors and buttons come from the website's consent_manager YAML,
  // validated by the server. Anything missing or malformed uses these defaults.
  // Keep in step with ConsentAppearance::DEFAULT_TEXT (a test compares them).
  var defaultText = {
    "title": "{name}: privacy choices",
    "description": "Choose which optional categories to allow. Enhanced analytics uses browser identifiers and additional event details. Tags in each selected category may load third-party scripts.",
    "details": [
      "Coarse anonymous measurement may continue after rejection. You can change your choice here at any time. Withdrawal removes analytics identifiers and stops future enhanced detail; it does not erase stored history.",
      "After withdrawal, reload this page to stop optional scripts already loaded. Tags configured to require no consent can run regardless of these choices. See this website’s privacy notice for its data, purposes, and providers."
    ],
    "categoriesLegend": "Optional categories",
    "categories": {"analytics": "Enhanced analytics"},
    "reject": "Reject all optional categories",
    "accept": "Accept all optional categories",
    "save": "Save selected choices",
    "reopen": "Privacy choices",
    "privacyLink": "Read this website’s privacy notice",
    "statusApplied": "Your privacy choices have been applied.",
    "statusNotSaved": "This choice could not be saved; choose again on your next visit.",
    "statusOtherTab": "Your privacy choices were updated in another tab."
  };
  function own(value, key) {
    try {
      return value && typeof value === 'object' && !Array.isArray(value) && Object.prototype.hasOwnProperty.call(value, key) ? value[key] : undefined;
    } catch (error) { return undefined; }
  }
  var configuredText = own(consentConfig, 'text');
  var rawRegions = Array.isArray(consentConfig.regions) ? consentConfig.regions : [];
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

  function text(key) {
    var regText = currentRegion && own(currentRegion, 'text');
    var value = regText ? own(regText, key) : undefined;
    if (typeof value !== 'string' || value.trim() === '') {
      value = own(configuredText, key);
    }
    var result = typeof value === 'string' && value.trim() !== '' ? value : defaultText[key];
    return key === 'title' ? result.split('{name}').join(consentConfig.name) : result;
  }
  function paragraphs() {
    var regText = currentRegion && own(currentRegion, 'text');
    var value = regText ? own(regText, 'details') : undefined;
    if (!Array.isArray(value)) {
      value = own(configuredText, 'details');
    }
    return Array.isArray(value) && value.length <= 4 && value.every(function (entry) { return typeof entry === 'string' && entry.trim() !== ''; })
      ? value : defaultText.details;
  }
  function categoryLabel(category) {
    var value = own(own(configuredText, 'categories'), category);
    if (typeof value === 'string' && value.trim() !== '') return value;
    return category === 'analytics' ? defaultText.categories.analytics
      : category.replace(/[-_]/g, ' ').replace(/^./, function (letter) { return letter.toUpperCase(); });
  }
  var buttonOptions = own(consentConfig, 'buttons');
  function getShownButtons() {
    var regButtons = currentRegion && own(currentRegion, 'buttons');
    var value = regButtons ? own(regButtons, 'show') : own(buttonOptions, 'show');
    var known = ['reject', 'accept', 'save'];
    if (!Array.isArray(value) || value.indexOf('reject') === -1
      || (value.indexOf('accept') === -1 && value.indexOf('save') === -1)
      || !value.every(function (entry, index) { return known.indexOf(entry) !== -1 && value.indexOf(entry) === index; })) return known;
    return value;
  }
  var shownButtons = getShownButtons();
  var reopenPosition = ['bottom-left', 'bottom-right', 'hidden'].indexOf(own(buttonOptions, 'reopen')) !== -1
    ? own(buttonOptions, 'reopen') : 'bottom-left';
  var privacyPolicyUrl = (function () {
    var value = own(consentConfig, 'privacyPolicyUrl');
    try { return typeof value === 'string' && new URL(value).protocol === 'https:' ? value : ''; }
    catch (error) { return ''; }
  })();
  function applyTheme(node) {
    var theme = own(consentConfig, 'theme');
    ['background', 'text', 'accent', 'border', 'buttonBackground', 'buttonText', 'buttonBorder'].forEach(function (key) {
      var color = own(theme, key);
      // Only #RRGGBB values reach the stylesheet, through CSSOM (allowed by CSP).
      if (typeof color === 'string' && /^#[0-9A-Fa-f]{6}$/.test(color) && node.style && typeof node.style.setProperty === 'function') {
        node.style.setProperty('--ac-' + key.replace(/[A-Z]/g, function (letter) { return '-' + letter.toLowerCase(); }), color);
      }
    });
  }

  var storageKey = 'analytics_consent_v1:' + consentConfig.namespace + (consentConfig.siteId ? ':' + consentConfig.siteId : '');
  var regionStorageKey = storageKey + ':region';
  var savedRegionId = (function () {
    try { return window.localStorage.getItem(regionStorageKey); } catch (e) { return null; }
  })();
  var currentRegion = (function () {
    if (regions.length === 0) return null;
    if (savedRegionId && getRegion(savedRegionId)) return getRegion(savedRegionId);
    var defId = typeof consentConfig.defaultRegion === 'string' ? consentConfig.defaultRegion : consentConfig.default_region;
    if (defId && getRegion(defId)) return getRegion(defId);
    for (var j = 0; j < regions.length; j++) {
      if (regions[j].default === true) return regions[j];
    }
    return regions[0];
  })();

  function isImplicit() {
    return !!(currentRegion && (currentRegion.consentType === 'implicit' || currentRegion.consent_type === 'implicit'));
  }
  var categories = ['analytics'];
  (Array.isArray(consentConfig.categories) ? consentConfig.categories : []).forEach(function (category) {
    if (typeof category === 'string' && /^[a-z][a-z0-9_-]{0,31}$/.test(category)
      && category !== 'none' && categories.indexOf(category) === -1) categories.push(category);
  });
  var precheck = Array.isArray(consentConfig.precheckCategories) ? consentConfig.precheckCategories : [];
  var choices = Object.create(null);
  categories.forEach(function (category) { choices[category] = false; });
  var chosen = false;
  var subscribers = [];
  var panel;
  var preferenceButton;
  var heading;
  var status;
  var description;
  var checkboxes = Object.create(null);
  var storageAvailable = true;
  var savedMap = null;

  function readGrant(value, category) {
    try {
      return !!(value && typeof value === 'object' && !Array.isArray(value)
        && Object.prototype.hasOwnProperty.call(value, category) && value[category] === true);
    } catch (error) { return false; }
  }

  function hasChoice(value, category) {
    return !!(value && typeof value === 'object' && !Array.isArray(value)
      && Object.prototype.hasOwnProperty.call(value, category) && typeof value[category] === 'boolean');
  }

  function hasSavedChoice(category) {
    return savedMap !== null && hasChoice(savedMap, category);
  }

  try {
    var saved = JSON.parse(window.localStorage.getItem(storageKey));
    if (typeof saved === 'boolean' || (saved && typeof saved === 'object' && !Array.isArray(saved))) {
      // If storage cannot be updated, do not restore an old affirmative choice.
      window.localStorage.setItem(storageKey, JSON.stringify(saved));
      if (typeof saved === 'boolean') {
        savedMap = {analytics: saved};
        choices.analytics = saved === true;
        chosen = categories.length === 1;
      } else {
        savedMap = saved;
        categories.forEach(function (category) { choices[category] = readGrant(saved, category); });
        chosen = categories.every(function (category) { return hasChoice(saved, category); });
      }
    } else if (isImplicit()) {
      choices.analytics = true;
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
    savedMap = state();
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
      showPanel(false);
      preferenceButton.focus();
      status.textContent = text('statusApplied') + (storageAvailable ? '' : ' ' + text('statusNotSaved'));
    }
  }

  // The reopen button shows only while the banner is closed, so they never overlap.
  function showPanel(visible) {
    panel.hidden = !visible;
    preferenceButton.hidden = visible || reopenPosition === 'hidden';
    preferenceButton.setAttribute('aria-expanded', visible ? 'true' : 'false');
  }

  function syncCheckboxes() {
    categories.forEach(function (category) { if (checkboxes[category]) checkboxes[category].checked = hasSavedChoice(category) ? choices[category] : (precheck.indexOf(category) !== -1); });
  }

  function setRegion(id) {
    var reg = getRegion(id);
    if (!reg) return;
    currentRegion = reg;
    try { window.localStorage.setItem(regionStorageKey, id); } catch (e) {}
    if (savedMap === null) {
      choices.analytics = isImplicit();
      notify();
    }
    updateBannerContent();
  }

  function open() {
    if (!panel) return;
    showPanel(true);
    syncCheckboxes();
    heading.focus();
  }

  window.AggregateConsent = {
    getState: state,
    setConsent: setConsent,
    open: open,
    getRegion: function () { return currentRegion ? currentRegion.id : null; },
    setRegion: setRegion,
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
    if (event.key === regionStorageKey && event.newValue) {
      setRegion(event.newValue);
      return;
    }
    if (event.key !== storageKey && event.key !== null) return;
    var value = null;
    try { value = JSON.parse(event.newValue); } catch (error) {}
    savedMap = typeof value === 'boolean' ? {analytics: value} : (value && typeof value === 'object' && !Array.isArray(value) ? value : null);
    categories.forEach(function (category) {
      choices[category] = typeof value === 'boolean' ? category === 'analytics' && value
        : readGrant(value, category);
    });
    chosen = event.key !== null && (typeof value === 'boolean' ? categories.length === 1
      : !!(value && typeof value === 'object' && !Array.isArray(value)
        && categories.every(function (category) { return hasChoice(value, category); })));
    notify();
    if (panel) {
      showPanel(!chosen);
      syncCheckboxes();
      status.textContent = text('statusOtherTab');
    }
  });

  function element(tag, text) {
    var node = document.createElement(tag);
    if (text) node.textContent = text;
    return node;
  }

  var detailParagraphsContainer;
  var actionsRow;
  var regionSelect;
  var actions = {
    reject: function () { setConsent({}); },
    accept: function () {
      var values = {};
      categories.forEach(function (category) { values[category] = true; });
      setConsent(values);
    },
    save: function () {
      var values = {};
      categories.forEach(function (category) { values[category] = checkboxes[category].checked === true; });
      setConsent(values);
    }
  };

  function updateBannerContent() {
    if (!panel) return;
    heading.textContent = text('title');
    description.textContent = text('description');
    if (detailParagraphsContainer) {
      while (detailParagraphsContainer.firstChild) {
        detailParagraphsContainer.removeChild(detailParagraphsContainer.firstChild);
      }
      paragraphs().forEach(function (p) {
        detailParagraphsContainer.appendChild(element('p', p));
      });
    }
    if (actionsRow) {
      while (actionsRow.firstChild) {
        actionsRow.removeChild(actionsRow.firstChild);
      }
      getShownButtons().forEach(function (name) {
        var btn = element('button', text(name));
        btn.type = 'button';
        btn.addEventListener('click', actions[name]);
        actionsRow.appendChild(btn);
      });
    }
    if (preferenceButton) {
      preferenceButton.textContent = text('reopen');
    }
    if (regionSelect && currentRegion) {
      regionSelect.value = currentRegion.id;
    }
  }

  function mount() {
    if (!document.body || panel) return;
    // Configured scripts embed the shared CSS source. Static source files load
    // their adjacent stylesheet, without needing PHP or a JavaScript build.
    var style = element(typeof consentStyles === 'string' ? 'style' : 'link');
    if (scriptNonce) style.nonce = scriptNonce;
    if (typeof consentStyles === 'string') {
      style.textContent = consentStyles;
      document.head.appendChild(style);
    } else if (scriptSource) {
      style.rel = 'stylesheet';
      style.href = new URL('consent.css', scriptSource).href;
      style.referrerPolicy = 'no-referrer';
      document.head.appendChild(style);
    }
    panel = element('section');
    panel.className = 'ac-consent';
    panel.id = 'ac-consent-panel';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-labelledby', 'ac-consent-title');
    panel.setAttribute('aria-describedby', 'ac-consent-description');
    applyTheme(panel);

    if (regions.length >= 2) {
      var regionContainer = element('div');
      regionContainer.className = 'ac-consent-region';
      var regionLabel = element('label', 'Region');
      regionLabel.setAttribute('for', 'ac-consent-region-select');
      regionSelect = element('select');
      regionSelect.id = 'ac-consent-region-select';
      regionSelect.className = 'ac-consent-region-select';
      regions.forEach(function (r) {
        var opt = element('option', r.name);
        opt.value = r.id;
        if (currentRegion && currentRegion.id === r.id) opt.selected = true;
        regionSelect.appendChild(opt);
      });
      regionSelect.addEventListener('change', function (event) {
        setRegion((event && event.target && event.target.value) || (this && this.value) || regionSelect.value);
      });
      regionContainer.appendChild(regionLabel);
      regionContainer.appendChild(regionSelect);
      panel.appendChild(regionContainer);
    }

    heading = element('h2', text('title'));
    heading.id = 'ac-consent-title';
    heading.tabIndex = -1;
    panel.appendChild(heading);
    description = element('p', text('description'));
    description.id = 'ac-consent-description';
    panel.appendChild(description);
    detailParagraphsContainer = element('div');
    detailParagraphsContainer.className = 'ac-consent-details';
    paragraphs().forEach(function (paragraph) { detailParagraphsContainer.appendChild(element('p', paragraph)); });
    panel.appendChild(detailParagraphsContainer);

    if (privacyPolicyUrl) {
      var notice = element('p');
      var link = element('a', text('privacyLink'));
      link.href = privacyPolicyUrl;
      link.referrerPolicy = 'no-referrer';
      notice.appendChild(link);
      panel.appendChild(notice);
    }
    // Without a save button there is nothing to select, so the choice is all or nothing.
    if (getShownButtons().indexOf('save') !== -1) {
      var fieldset = element('fieldset');
      fieldset.appendChild(element('legend', text('categoriesLegend')));
      categories.forEach(function (category) {
        var label = element('label');
        label.className = 'ac-consent-category';
        var checkbox = element('input');
        checkbox.type = 'checkbox';
        checkbox.checked = hasSavedChoice(category) ? choices[category] : (precheck.indexOf(category) !== -1);
        checkbox.id = 'ac-consent-' + category;
        checkboxes[category] = checkbox;
        label.appendChild(checkbox);
        label.appendChild(element('span', ' ' + categoryLabel(category)));
        fieldset.appendChild(label);
      });
      panel.appendChild(fieldset);
    }
    // Every action button shares one style, so no choice is visually favored.
    actionsRow = element('div');
    actionsRow.className = 'ac-consent-actions';
    getShownButtons().forEach(function (name) {
      var button = element('button', text(name));
      button.type = 'button';
      button.addEventListener('click', actions[name]);
      actionsRow.appendChild(button);
    });
    panel.appendChild(actionsRow);
    panel.addEventListener('keydown', function (event) {
      if (event.key !== 'Escape') return;
      showPanel(false);
      preferenceButton.focus();
    });
    preferenceButton = element('button', text('reopen'));
    preferenceButton.type = 'button';
    preferenceButton.className = 'ac-consent-open' + (reopenPosition === 'bottom-right' ? ' ac-consent-open--right' : '');
    // A hidden button needs the website's own link to AggregateConsent.open().
    applyTheme(preferenceButton);
    preferenceButton.setAttribute('aria-controls', panel.id);
    preferenceButton.addEventListener('click', open);
    status = element('span');
    status.className = 'ac-consent-status';
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    showPanel(!chosen);
    document.body.appendChild(preferenceButton);
    document.body.appendChild(panel);
    document.body.appendChild(status);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount, {once: true});
  else mount();
})();
