/*! SPDX-License-Identifier: AGPL-3.0-only; see LICENSE in the source repository. */
(function () {
  'use strict';
  // Optional bridge: never replace another CMP's integration.
  if (window.AggregateConsent) return;
  function own(value, key) {
    try {
      var descriptor = value && Object.getOwnPropertyDescriptor(value, key);
      return descriptor && Object.prototype.hasOwnProperty.call(descriptor, 'value') ? descriptor.value : undefined;
    } catch (error) { return undefined; }
  }
  var namespace = own(own(window, 'MicroConsentConfig'), 'aggregateNamespace');
  if (namespace === undefined) namespace = 'Aggregate';
  if (typeof namespace !== 'string' || namespace.trim() === '' || namespace.length > 120
    || /[\u0000-\u001f\u007f-\u009f]/.test(namespace) || ['constructor', 'prototype', '__proto__'].indexOf(namespace) !== -1) return;
  var engine = null, unsubscribe = null, subscribers = [];
  function state() {
    var result = {}, current = {};
    try { if (engine && typeof engine.getState === 'function') current = engine.getState(); } catch (error) {}
    if (current && typeof current === 'object' && !Array.isArray(current)) {
      Object.keys(current).forEach(function (category) {
        if (!/^[a-z][a-z0-9_-]{0,31}$/.test(category) || ['gpc', 'none', 'constructor', 'prototype', '__proto__'].indexOf(category) !== -1) return;
        result[category] = own(current, category) === true;
      });
    }
    if (own(current, 'doNotSell') === true || own(current, 'gpc') === true) result.marketing = false;
    result.analytics = own(current, 'analytics') === true;
    return result;
  }
  function update() {
    if (window.AggregateConsent !== facade) return;
    var current = state();
    try {
      var tracker = own(window, namespace);
      if (!tracker || (typeof tracker !== 'object' && typeof tracker !== 'function')) { tracker = {}; window[namespace] = tracker; }
      tracker.consent = current.analytics;
      if (typeof tracker.setConsent === 'function') tracker.setConsent(current.analytics);
    } catch (error) {}
    try { if (window.AggregateTags && typeof window.AggregateTags.setConsent === 'function') window.AggregateTags.setConsent(current); } catch (error) {}
    subscribers.slice().forEach(function (callback) { try { callback(state()); } catch (error) {} });
  }
  var facade = {
    getState: state,
    setConsent: function (value) {
      if (!engine || typeof engine.setConsent !== 'function') return;
      if (typeof value === 'boolean') { var current = state(); current.analytics = value; engine.setConsent(current); }
      else engine.setConsent(value);
    },
    subscribe: function (callback) {
      if (typeof callback !== 'function') return function () {};
      subscribers.push(callback);
      return function () { subscribers = subscribers.filter(function (entry) { return entry !== callback; }); };
    },
    open: function () { if (engine && typeof engine.open === 'function') engine.open(); }
  };
  window.AggregateConsent = facade;
  function connect() {
    if (window.AggregateConsent !== facade) return;
    var candidate = own(window, 'MicroConsent');
    if (candidate !== engine) {
      if (typeof unsubscribe === 'function') unsubscribe();
      engine = candidate || null;
      unsubscribe = engine && typeof engine.subscribe === 'function' ? engine.subscribe(update) : null;
    }
    update();
  }
  document.addEventListener('micro-consent-ready', connect);
  connect();
  document.dispatchEvent(new CustomEvent('aggregate:consent-ready'));
})();
