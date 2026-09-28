/*! SPDX-License-Identifier: AGPL-3.0-only; see LICENSE in the source repository. */
(function () {
  'use strict';
  if (window.MicroConsentGoogleAdapter) return;
  window.MicroConsentGoogleAdapter = true;
  function own(value, key) {
    try {
      var descriptor = value && Object.getOwnPropertyDescriptor(value, key);
      return descriptor && Object.prototype.hasOwnProperty.call(descriptor, 'value') ? descriptor.value : undefined;
    } catch (error) { return undefined; }
  }
  var gtag = own(window, 'gtag');
  if (typeof gtag !== 'function') {
    var layer = own(window, 'dataLayer');
    if (layer === undefined) { layer = []; window.dataLayer = layer; }
    if (!Array.isArray(layer)) return;
    gtag = function () { layer.push(arguments); };
    window.gtag = gtag;
  }
  function send(action, state) {
    var ads = own(state, 'marketing') === true && own(state, 'doNotSell') !== true && own(state, 'gpc') !== true;
    try {
      gtag('consent', action, {
        analytics_storage: own(state, 'analytics') === true ? 'granted' : 'denied',
        ad_storage: ads ? 'granted' : 'denied', ad_user_data: ads ? 'granted' : 'denied', ad_personalization: ads ? 'granted' : 'denied'
      });
    } catch (error) {}
  }
  // Include before GTM. This signals consent and never loads a provider.
  send('default', {});
  var engine = null, unsubscribe = null;
  function update() {
    var current = {};
    try { if (engine && typeof engine.getState === 'function') current = engine.getState(); } catch (error) {}
    send('update', current);
  }
  function connect() {
    var candidate = own(window, 'MicroConsent');
    if (candidate !== engine) {
      if (typeof unsubscribe === 'function') unsubscribe();
      engine = candidate || null;
      unsubscribe = engine && typeof engine.subscribe === 'function' ? engine.subscribe(update) : null;
    }
    if (engine) update();
  }
  document.addEventListener('micro-consent-ready', connect);
  connect();
})();
