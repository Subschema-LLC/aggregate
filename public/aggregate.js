/*!
 * Aggregate Analytics browser tracker
 * SPDX-License-Identifier: BSD-3-Clause
 * Full license also available in js/LICENSE.txt in the source repository.
 *
 * BSD 3-Clause License
 *
 * Copyright (c) 2025 Aggregate Analytics Contributors
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice, this
 *    list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright notice,
 *    this list of conditions and the following disclaimer in the documentation
 *    and/or other materials provided with the distribution.
 *
 * 3. Neither the name of the copyright holder nor the names of its contributors
 *    may be used to endorse or promote products derived from this software
 *    without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
 * FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
 * DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
 * SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
 * OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */
(function(){
  // Configurable namespace - defaults to 'Aggregate' but can be overridden via data-namespace attribute
  var namespace = 'Aggregate';
  // ScriptController replaces these defaults with browser-safe YAML settings.
  var internalTrafficDefaults = {storage: 'cookie', name: 'orgInternalTraffic', value: 'true', cookieDomain: ''};
  var customDataDefaults = {queryParameters: {utm_source: 'utm_source', utm_medium: 'utm_medium', utm_campaign: 'utm_campaign', utm_term: 'utm_term', utm_content: 'utm_content', utm_id: 'utm_id'}, consentFreeProperties: []};
  var collectionDefaults = {profile: 'standard'};
  // The strict profile sends only the page path, event name and goal. It reads
  // nothing else from the device and never reads, writes or removes cookies or
  // Web Storage. Anything other than the literal standard profile is strict.
  // Page configuration can opt into strict collection but never out of it.
  var strictCollection = !collectionDefaults || collectionDefaults.profile !== 'standard';
  // Served scripts retain server collection permissions when callers configure
  // additional options. Standalone/static copies have no injected policy.
  var pageSequenceAuthorized = !Object.prototype.hasOwnProperty.call(customDataDefaults, 'pageSequenceEnabled')
    || customDataDefaults.pageSequenceEnabled === true;
  var pageSequenceExcludedPaths = Object.prototype.hasOwnProperty.call(customDataDefaults, 'pageSequenceExcludedPaths')
    ? customDataDefaults.pageSequenceExcludedPaths : [];
  var pageSequenceMethodLocked = Object.prototype.hasOwnProperty.call(customDataDefaults, 'pageSequenceEnabled')
    || Object.prototype.hasOwnProperty.call(customDataDefaults, 'pageSequenceMethod');
  var pageSequenceMethodDefault = Object.prototype.hasOwnProperty.call(customDataDefaults, 'pageSequenceMethod')
    ? customDataDefaults.pageSequenceMethod : 'session_storage';
  try {
    var s = document.currentScript || (function(){var ss=document.getElementsByTagName('script'); return ss[ss.length-1];})();
    if (s && s.dataset && s.dataset.namespace) {
      namespace = s.dataset.namespace;
    }
  } catch(e) {}

  var Analytics = {
    config: {
      endpoint: (window[namespace] && window[namespace].endpoint) || '/api/receive',
      websiteToken: (window[namespace] && window[namespace].websiteToken) || null,
      internalTraffic: internalTrafficDefaults,
      customData: customDataDefaults
    },
    consent: false,
    consentKnown: false,
    pageSequences: Object.create(null),
    pageSequenceUrlInitial: null,
    pageSequenceUrlTrimAttempted: false,

    requireStrictCollection: function(profile){
      if (profile !== 'strict') return;
      strictCollection = true;
      // Forget in-memory state only; strict collection never touches storage.
      this.consent = false;
      this.pageSequences = Object.create(null);
      this.pageSequenceUrlInitial = null;
      this.pageSequenceUrlTrimAttempted = false;
    },

    configureInternalTraffic: function(options){
      if (!options || typeof options !== 'object') return;

      var fields = ['storage', 'name', 'value', 'cookieDomain'];
      for (var i = 0; i < fields.length; i++) {
        var field = fields[i];
        if (typeof options[field] !== 'undefined') {
          this.config.internalTraffic[field] = options[field];
        }
      }
    },

    isCustomDataKey: function(key){
      return typeof key === 'string' && /^[A-Za-z][A-Za-z0-9_.-]{0,63}$/.test(key)
        && ['__proto__', 'constructor', 'prototype'].indexOf(key) === -1;
    },

    configureCustomData: function(options){
      if (!options || typeof options !== 'object' || Array.isArray(options)) return;

      if (Object.prototype.hasOwnProperty.call(options, 'pageSequenceMethod') && !pageSequenceMethodLocked) {
        var previousMethod = this.pageSequenceMethod();
        this.config.customData.pageSequenceMethod = options.pageSequenceMethod;
        if (previousMethod !== this.pageSequenceMethod()) {
          this.pageSequences = Object.create(null);
          this.pageSequenceUrlInitial = null;
          this.pageSequenceUrlTrimAttempted = false;
        }
      }
      if (Object.prototype.hasOwnProperty.call(options, 'pageSequenceEnabled')) {
        // Only a literal boolean enables this optional anonymous dimension.
        this.config.customData.pageSequenceEnabled = options.pageSequenceEnabled === true && pageSequenceAuthorized;
        if (!this.config.customData.pageSequenceEnabled) this.clearPageSequence();
      }
      if (Object.prototype.hasOwnProperty.call(options, 'pageSequenceExcludedPaths')) {
        this.config.customData.pageSequenceExcludedPaths = options.pageSequenceExcludedPaths;
      }

      if (Object.prototype.hasOwnProperty.call(options, 'queryParameters')) {
        var mappings = Object.create(null);
        if (options.queryParameters && typeof options.queryParameters === 'object' && !Array.isArray(options.queryParameters)) {
          var sources = Object.keys(options.queryParameters);
          for (var i = 0; i < sources.length; i++) {
            var source = sources[i];
            var destination = options.queryParameters[source];
            if (this.isCustomDataKey(source) && source !== 'aggregate_page_sequence' && this.isCustomDataKey(destination)) {
              mappings[source] = destination;
            }
          }
        }
        this.config.customData.queryParameters = mappings;
      }

      if (Object.prototype.hasOwnProperty.call(options, 'consentFreeProperties')) {
        this.config.customData.consentFreeProperties = Array.isArray(options.consentFreeProperties)
          ? options.consentFreeProperties.filter(this.isCustomDataKey.bind(this))
          : [];
      }

      if (Object.prototype.hasOwnProperty.call(options, 'propertyTypes')) {
        var types = null;
        if (options.propertyTypes && typeof options.propertyTypes === 'object' && !Array.isArray(options.propertyTypes)) {
          types = Object.create(null);
          var properties = Object.keys(options.propertyTypes);
          for (var j = 0; j < properties.length; j++) {
            var property = properties[j];
            if (this.isCustomDataKey(property)) {
              var type = options.propertyTypes[property];
              // Keep an invalid declared type blocked rather than silently
              // reverting that property's collection to unrestricted scalars.
              types[property] = ['scalar', 'string', 'integer', 'float', 'double', 'boolean'].indexOf(type) !== -1 ? type : 'invalid';
            }
          }
        }
        this.config.customData.propertyTypes = types;
      }
    },

    pageSequenceStorageKey: function(){
      var token = this.config.websiteToken;
      try {
        return typeof token === 'string' && token
          ? 'aggregate_page_sequence:' + encodeURIComponent(token) : null;
      } catch(e) {
        return null;
      }
    },

    pageSequenceMethod: function(){
      var method = pageSequenceMethodLocked ? pageSequenceMethodDefault
        : (Object.prototype.hasOwnProperty.call(this.config.customData, 'pageSequenceMethod')
          ? this.config.customData.pageSequenceMethod : 'session_storage');
      return method === 'session_storage' || method === 'url_parameter' ? method : null;
    },

    clearPageSequence: function(){
      // URL mode never touches counter storage, even for cleanup. A counter
      // from a previous storage-mode installation may remain until tab closure.
      // Strict collection likewise leaves any earlier counter untouched.
      if (!strictCollection && this.pageSequenceMethod() === 'session_storage') {
        var keys = Object.keys(this.pageSequences);
        var currentKey = this.pageSequenceStorageKey();
        if (currentKey && keys.indexOf(currentKey) === -1) keys.push(currentKey);
        for (var i = 0; i < keys.length; i++) {
          try { sessionStorage.removeItem(keys[i]); } catch(e) {}
        }
      }
      this.pageSequences = Object.create(null);
      this.pageSequenceUrlInitial = null;
      this.pageSequenceUrlTrimAttempted = false;
    },

    pageSequenceCanonicalPath: function(path){
      try {
        // The server canonicalizes the submitted pagePath before exclusions.
        // Keep this check separate from the existing SDK payload formatting.
        if (path.slice(0, 2) === '//') return null;
        path = path.split(/[?#]/)[0];
        for (var pass = 0; pass < 2; pass++) {
          var decoded = decodeURIComponent(path);
          if (decoded === path) break;
          path = decoded;
        }
        path = path.replace(/[\u0000-\u001f\u007f]/g, '').replace(/\\/g, '/');
        var segments = path.split('/');
        var canonical = [];
        for (var i = 0; i < segments.length; i++) {
          var segment = segments[i].split(';')[0];
          if (!segment || segment === '.') continue;
          if (segment === '..') {
            canonical.pop();
            continue;
          }
          var encoded = encodeURIComponent(segment).replace(/[!'()*]/g, function(character){
            return '%' + character.charCodeAt(0).toString(16).toUpperCase();
          });
          var identifierCandidate = segment.replace(/^ +| +$/g, '');
          var byteLength = encodeURIComponent(identifierCandidate).replace(/%[0-9A-F]{2}/g, 'x').length;
          var identifier = this.sanitizePagePath('/' + identifierCandidate) === '/_redacted'
            || (byteLength >= 24 && /[a-z]/i.test(identifierCandidate) && /[0-9]/.test(identifierCandidate));
          canonical.push(/%[0-9a-f]{2}/i.test(segment) || identifier ? '_redacted' : encoded);
        }
        return ('/' + canonical.join('/')).slice(0, 512).replace(/%(?:[0-9A-F])?$/, '');
      } catch(e) {
        // Invalid encoding cannot safely establish that an exclusion is absent.
        return null;
      }
    },

    pageSequencePathAllowed: function(path){
      var configured = this.config.customData;
      var browserPatterns = Object.prototype.hasOwnProperty.call(configured, 'pageSequenceExcludedPaths')
        ? configured.pageSequenceExcludedPaths : [];
      if (!Array.isArray(pageSequenceExcludedPaths) || !Array.isArray(browserPatterns)) return false;
      var patterns = pageSequenceExcludedPaths.concat(browserPatterns);
      if (!patterns.length) return true;
      var rawPath = typeof path === 'string' ? path : (location.pathname || '/');
      var safePath = this.sanitizePagePath(rawPath);
      var canonicalPath = this.pageSequenceCanonicalPath(safePath);
      if (canonicalPath === null) return false;
      for (var i = 0; i < patterns.length; i++) {
        var pattern = patterns[i];
        if (typeof pattern !== 'string' || pattern.charAt(0) !== '/' || pattern.length > 512) return false;
        var directory = pattern.slice(-3) === '/**' ? pattern.slice(0, -3) : null;
        if (directory !== null && (rawPath === directory || safePath === directory || canonicalPath === directory)) return false;
        // Match the server: ** crosses slashes; * and ? stay within a segment.
        var expression = pattern.replace(/[.+^${}()|[\]\\]/g, '\\$&').replace(/\*\*|\*|\?/g, function(wildcard){
          return wildcard === '**' ? '.*' : (wildcard === '*' ? '[^/]*' : '[^/]');
        });
        var matcher = new RegExp('^' + expression + '(?![\\s\\S])');
        if (matcher.test(rawPath) || matcher.test(safePath) || matcher.test(canonicalPath)) return false;
      }
      return true;
    },

    pageSequenceForEvent: function(advance){
      if (strictCollection) return null;
      if (!pageSequenceAuthorized || this.config.customData.pageSequenceEnabled !== true) {
        this.clearPageSequence();
        return null;
      }
      var method = this.pageSequenceMethod();
      if (method === null) return null;
      if (Object.prototype.hasOwnProperty.call(this.config.customData, 'propertyTypes')
        && (!this.config.customData.propertyTypes || typeof this.config.customData.propertyTypes !== 'object'
          || Array.isArray(this.config.customData.propertyTypes))) return null;
      // Excluded pages must not affect the count later sent by an allowed page.
      // Evaluate before reading or writing the optional browser state.
      if (!this.pageSequencePathAllowed()) return null;

      var key = this.pageSequenceStorageKey();
      if (!key) return null;
      var sequence = this.pageSequences[key];
      var changed = false;
      if (typeof sequence === 'undefined') {
        if (method === 'url_parameter') {
          if (this.pageSequenceUrlInitial === null) {
            this.pageSequenceUrlInitial = 1;
            try {
              var pageUrl = new URL(location.origin);
              pageUrl.search = location.search || '';
              var values = pageUrl.searchParams.getAll('aggregate_page_sequence');
              if (values.length === 1 && /^(?:[1-9]|1[0-9]|20)$/.test(values[0])) this.pageSequenceUrlInitial = Number(values[0]);
            } catch(e) {}
          }
          sequence = this.pageSequenceUrlInitial;
        } else {
          var previous = 0;
          try {
            var stored = sessionStorage.getItem(key);
            // Store only the bounded count: no IDs, paths, history or timestamps.
            // Malformed or out-of-range browser state starts again at page one.
            if (typeof stored === 'string' && /^(?:[1-9]|1[0-9]|20)$/.test(stored)) previous = Number(stored);
          } catch(e) {}
          sequence = Math.min(previous + 1, 20);
        }
        changed = true;
      } else if (advance && sequence < 20) {
        sequence++;
        changed = true;
      }
      this.pageSequences[key] = sequence;
      if (method === 'url_parameter') this.trimPageSequenceUrl();
      if (changed && method === 'session_storage') {
        // When storage is blocked, the document's in-memory count still works.
        try { sessionStorage.setItem(key, String(sequence)); } catch(e) {}
      }
      return sequence;
    },

    pageSequenceQueryWithoutCounter: function(search){
      return (search || '').replace(/^\?/, '').split('&').filter(function(part){
        try {
          return decodeURIComponent(part.split('=')[0].replace(/\+/g, ' ')) !== 'aggregate_page_sequence';
        } catch(e) {
          return true;
        }
      }).join('&');
    },

    trimPageSequenceUrl: function(){
      if (this.pageSequenceUrlTrimAttempted) return;
      var search = location.search || '';
      var query = this.pageSequenceQueryWithoutCounter(search);
      if (query === search.replace(/^\?/, '')) return;
      // Capture the count and set this guard before invoking a potentially
      // framework-wrapped History API. Reentrant events reuse memory, and a
      // failed cleanup is not retried for every asynchronous event.
      this.pageSequenceUrlTrimAttempted = true;
      try {
        var history = window.history;
        if (!history || typeof history.replaceState !== 'function') return;
        var cleanUrl = typeof location.href === 'string' ? new URL(location.href) : new URL(location.origin);
        if (cleanUrl.origin !== location.origin) return;
        if (typeof location.href !== 'string') {
          cleanUrl.pathname = location.pathname || '/';
          cleanUrl.hash = location.hash || '';
        }
        cleanUrl.search = query ? '?' + query : '';
        history.replaceState(history.state, '', cleanUrl.href);
      } catch(e) {}
    },

    decoratePageSequenceLink: function(event){
      if (strictCollection) return;
      if (!pageSequenceAuthorized || this.config.customData.pageSequenceEnabled !== true
        || this.pageSequenceMethod() !== 'url_parameter' || !event || event.defaultPrevented
        || event.button !== 0 || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey
        || !this.pageSequencePathAllowed()) return;
      try {
        var anchor = event.target;
        while (anchor && (typeof anchor.tagName !== 'string' || anchor.tagName.toLowerCase() !== 'a')) {
          anchor = anchor.parentElement || anchor.parentNode;
        }
        if (!anchor || typeof anchor.getAttribute !== 'function' || anchor.hasAttribute('download')) return;
        var href = anchor.getAttribute('href');
        if (typeof href !== 'string' || !href.trim() || href.trim().charAt(0) === '#') return;
        var target = anchor.getAttribute('target');
        if (!target && typeof document.querySelector === 'function') {
          var base = document.querySelector('base[target]');
          if (base) target = base.getAttribute('target');
        }
        if (target && target.toLowerCase() !== '_self') return;

        var currentUrl = new URL(location.origin);
        currentUrl.pathname = location.pathname || '/';
        currentUrl.search = location.search || '';
        currentUrl.hash = location.hash || '';
        var destination = new URL(typeof anchor.href === 'string' ? anchor.href : href, document.baseURI || currentUrl.href);
        if (['http:', 'https:'].indexOf(destination.protocol) === -1 || destination.origin !== location.origin
          || destination.username || destination.password || !this.pageSequencePathAllowed(destination.pathname)) return;
        var query = this.pageSequenceQueryWithoutCounter(destination.search);
        if (destination.href.indexOf('#') !== -1 && destination.pathname === currentUrl.pathname
          && query === this.pageSequenceQueryWithoutCounter(currentUrl.search)) return;

        var sequence = this.pageSequenceForEvent(false);
        if (sequence === null) return;
        // Preserve unrelated query bytes (including signatures) and the hash.
        // Replacing only our parameter also makes repeated clicks idempotent.
        destination.search = '?' + query + (query ? '&' : '') + 'aggregate_page_sequence=' + Math.min(sequence + 1, 20);
        anchor.setAttribute('href', destination.href);
      } catch(e) {}
    },

    customDataForEvent: function(eventData, advancePage){
      if (strictCollection) return null;
      var clean = Object.create(null);
      var count = 0;
      var settings = this.config.customData;
      if (Object.prototype.hasOwnProperty.call(settings, 'propertyTypes')
        && (!settings.propertyTypes || typeof settings.propertyTypes !== 'object' || Array.isArray(settings.propertyTypes))) return null;
      var self = this;
      var canInclude = function(key){
        return self.isCustomDataKey(key) && key !== self.config.internalTraffic.name && key !== 'page_sequence'
          && (self.consent || settings.consentFreeProperties.indexOf(key) !== -1);
      };
      var cleanValue = function(key, value){
        var type = settings.propertyTypes && Object.prototype.hasOwnProperty.call(settings.propertyTypes, key)
          ? settings.propertyTypes[key] : 'scalar';
        if (['scalar', 'string', 'integer', 'float', 'double', 'boolean'].indexOf(type) === -1) return undefined;
        if (value !== null) {
          if (type === 'string' && typeof value !== 'string') return undefined;
          if (type === 'boolean' && typeof value !== 'boolean') return undefined;
          if (['integer', 'float', 'double'].indexOf(type) !== -1 && (typeof value !== 'number' || !isFinite(value))) return undefined;
          if (type === 'integer' && (Math.floor(value) !== value || Math.abs(value) > 9007199254740991)) return undefined;
        }
        // Match the server's flat, bounded scalar property model. Keep at
        // most 500 UTF-8 bytes without splitting a character.
        if (typeof value === 'string') {
          var characters = Array.from(value.replace(/[\u0000-\u001f\u007f]/g, ''));
          var result = '';
          var bytes = 0;
          for (var i = 0; i < characters.length; i++) {
            var point = characters[i].codePointAt(0);
            if (point >= 0xd800 && point <= 0xdfff) continue;
            var size = point < 0x80 ? 1 : (point < 0x800 ? 2 : (point < 0x10000 ? 3 : 4));
            if (bytes + size > 500) break;
            result += characters[i];
            bytes += size;
          }
          return result;
        }
        if (value === null || typeof value === 'boolean' || (typeof value === 'number' && isFinite(value))) return value;
        return undefined;
      };

      // The sequence is generated independently of event properties and URL
      // mappings, and uses one of the existing bounded custom-data slots.
      var pageSequence = this.pageSequenceForEvent(advancePage);
      if (pageSequence !== null) {
        clean.page_sequence = pageSequence;
        count++;
      }

      // Explicit event properties take precedence over URL mappings, including
      // false, zero and null. Never copy inherited or nested properties.
      if (eventData && typeof eventData === 'object' && !Array.isArray(eventData)) {
        var keys = Object.keys(eventData);
        for (var i = 0; i < keys.length && count < 50; i++) {
          var key = keys[i].trim();
          if (!canInclude(key)) continue;
          var value = cleanValue(key, eventData[keys[i]]);
          if (typeof value === 'undefined') continue;
          if (!Object.prototype.hasOwnProperty.call(clean, key)) count++;
          clean[key] = value;
        }
      }

      // Read only explicitly mapped parameters from this page's current URL.
      // Attribution is never persisted in cookies or browser storage.
      try {
        var pageUrl = new URL(location.origin);
        pageUrl.search = location.search || '';
        var sources = Object.keys(settings.queryParameters);
        for (var j = 0; j < sources.length && count < 50; j++) {
          if (sources[j] === 'aggregate_page_sequence') continue;
          var destination = settings.queryParameters[sources[j]];
          if (!canInclude(destination) || Object.prototype.hasOwnProperty.call(clean, destination)) continue;
          var values = pageUrl.searchParams.getAll(sources[j]);
          for (var k = 0; k < values.length; k++) {
            var queryValue = cleanValue(destination, values[k]);
            // Query values are strings. Numeric/boolean types must be sent as
            // JSON values through emit(); never guess or coerce URL values.
            if (typeof queryValue !== 'string') continue;
            queryValue = queryValue.trim();
            if (!queryValue) continue;
            clean[destination] = queryValue;
            count++;
            break;
          }
        }
      } catch(e) {}

      return count ? clean : null;
    },

    isInternalTraffic: function(){
      // This shared marker classifies traffic without identifying a visitor.
      // Read it independently of analytics consent, but never create it here.
      // Strict collection does not read the marker's cookie or storage.
      if (strictCollection) return false;
      try {
        var marker = this.config.internalTraffic;
        if (!marker || typeof marker.name !== 'string' || !marker.name || typeof marker.value !== 'string') return false;
        if (['aggregate_session', 'aggregate_visitor_id', 'aggregate_session_id'].indexOf(marker.name) !== -1) return false;

        if (marker.storage === 'local_storage') {
          return localStorage.getItem(marker.name) === marker.value;
        }
        if (marker.storage !== 'cookie') return false;

        var cookies = document.cookie.split(';');
        var prefix = marker.name + '=';
        for (var i = 0; i < cookies.length; i++) {
          // Strip separator whitespace without normalizing the marker value.
          var cookie = cookies[i].replace(/^[\t ]+/, '');
          if (cookie.indexOf(prefix) === 0) {
            // Host-only and parent-domain cookies can coexist after changing
            // scope. Any exact marker match opts this browser into the flag.
            try {
              if (decodeURIComponent(cookie.substring(prefix.length)) === marker.value) return true;
            } catch(e) {}
          }
        }
      } catch(e) {}

      return false;
    },

    clearIdentifiers: function(){
      // Strict collection never creates identifiers and does not touch storage,
      // even to remove identifiers left by an earlier standard-profile visit.
      if (strictCollection) return;
      try {
        localStorage.removeItem('aggregate_visitor_id');
      } catch(e) {}

      try {
        sessionStorage.removeItem('aggregate_session_id');
      } catch(e) {}

      this.deleteSessionCookie();
    },

    // Enhanced-consent session cookie helpers
    getSessionCookie: function(){
      try {
        var name = 'aggregate_session=';
        var cookies = document.cookie.split(';');
        for (var i = 0; i < cookies.length; i++) {
          var cookie = cookies[i].trim();
          if (cookie.indexOf(name) === 0) {
            return decodeURIComponent(cookie.substring(name.length));
          }
        }
        return null;
      } catch(e) {
        return null;
      }
    },

    setSessionCookie: function(sessionId){
      try {
        var cookieValue = 'aggregate_session=' + encodeURIComponent(sessionId) + ';' +
          'path=/;' +
          'max-age=1800;' +  // 30-minute sliding session window
          'SameSite=Lax';

        // Prevent clear-text transmission when the tracked page uses HTTPS.
        if (location.protocol === 'https:') {
          cookieValue += ';Secure';
        }

        document.cookie = cookieValue;
      } catch(e) {}
    },

    deleteSessionCookie: function(){
      try {
        document.cookie = 'aggregate_session=; path=/; max-age=0; SameSite=Lax';
      } catch(e) {}
    },

    ensureIds: function(){
      try {
        // Anonymous measurement never uses browser identifiers.
        if (strictCollection || !this.consent) return {visitorId: null, sessionId: null};

        // Enhanced consent permits visitor and session identifiers.
        var vKey = 'aggregate_visitor_id';
        var sKey = 'aggregate_session_id';

        // Get or create visitor ID (persistent across sessions via localStorage)
        var visitorId = localStorage.getItem(vKey);
        if (!visitorId) {
          visitorId = self.crypto && self.crypto.randomUUID ? self.crypto.randomUUID() : (Math.random().toString(36).slice(2) + Date.now());
          localStorage.setItem(vKey, visitorId);
        }

        // Get or create session ID with fallback priority:
        // 1. Session cookie (preferred - works across page loads)
        // 2. sessionStorage (fallback if cookies disabled)
        // 3. Generate new UUID
        var sessionId = this.getSessionCookie();
        if (!sessionId) {
          sessionId = sessionStorage.getItem(sKey);
        }
        if (!sessionId) {
          sessionId = self.crypto && self.crypto.randomUUID ? self.crypto.randomUUID() : (Math.random().toString(36).slice(2) + Date.now());
          sessionStorage.setItem(sKey, sessionId);
          this.setSessionCookie(sessionId);
        } else {
          // Refresh cookie expiry on each request (sliding window)
          this.setSessionCookie(sessionId);
        }

        return {visitorId: visitorId, sessionId: sessionId};
      } catch(e) {
        return {visitorId: null, sessionId: null};
      }
    },

    getConsentState: function(){
      if (!this.consentKnown) return 'unknown';
      return this.consent ? 'granted' : 'denied';
    },

    parseConsent: function(value){
      if (value === true || value === 1) return true;
      if (typeof value === 'string') {
        var normalized = value.trim().toLowerCase();
        return normalized === 'true' || normalized === '1';
      }
      return false;
    },

    sanitizeEventName: function(eventName){
      if (typeof eventName !== 'string') return null;

      var normalized = eventName.trim();
      var hasSafeGrammar = /^[A-Za-z][A-Za-z0-9_.:-]{0,99}$/.test(normalized);
      var looksLikeUuid = /(?:^|[^a-f0-9])[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[89ab][a-f0-9]{3}-[a-f0-9]{12}(?:$|[^a-f0-9])/i.test(normalized);
      var looksNumeric = /(?:^|[-_])\d{4,}(?:$|[-_])/.test(normalized);
      var looksLikeHexToken = /^[a-f0-9]{16,}$/i.test(normalized);
      var looksLikeOpaqueToken = normalized.length >= 24 && /\d/.test(normalized) && /[a-z]/i.test(normalized);

      return hasSafeGrammar && !looksLikeUuid && !looksNumeric && !looksLikeHexToken && !looksLikeOpaqueToken
        ? normalized
        : null;
    },

    sanitizePagePath: function(path){
      path = typeof path === 'string' ? path : (location.pathname || '/');

      try {
        var segments = path.split('/');
        var sanitized = [];

        for (var i = 0; i < segments.length; i++) {
          var rawSegment = segments[i].split(';')[0];
          var decodedSegment = rawSegment;

          try {
            decodedSegment = decodeURIComponent(rawSegment);
          } catch(e) {}

          // Route parameters are unsafe analytics dimensions. Redact common
          // identifiers before the path ever leaves the browser.
          var looksLikeEmail = /@/.test(decodedSegment);
          var looksLikeUuid = /(?:^|[^a-f0-9])[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[89ab][a-f0-9]{3}-[a-f0-9]{12}(?:$|[^a-f0-9])/i.test(decodedSegment);
          var looksNumeric = /^\d+$/.test(decodedSegment) || /(?:^|[-_])\d{4,}(?:$|[-_])/.test(decodedSegment);
          var looksLikeHexToken = /^[a-f0-9]{16,}$/i.test(decodedSegment);
          var looksLikeOpaqueToken = decodedSegment.length >= 24 && /\d/.test(decodedSegment) && /[a-z]/i.test(decodedSegment);

          if (looksLikeEmail || looksLikeUuid || looksNumeric || looksLikeHexToken || looksLikeOpaqueToken) {
            sanitized.push('_redacted');
          } else {
            sanitized.push(rawSegment.replace(/[\u0000-\u001f\u007f]/g, ''));
          }
        }

        path = sanitized.join('/');
      } catch(e) {
        path = '/';
      }

      if (!path || path.charAt(0) !== '/') path = '/' + path;
      return path.slice(0, 512);
    },

    getReferrerChannel: function(){
      if (strictCollection) return 'unknown';
      if (!document.referrer) return 'direct';

      try {
        var referrer = new URL(document.referrer, location.origin);
        if (referrer.origin === location.origin) return 'internal';

        var host = referrer.hostname.toLowerCase().replace(/^www\./, '');
        var searchHosts = [
          'google.', 'bing.com', 'duckduckgo.com', 'search.yahoo.',
          'baidu.com', 'yandex.', 'ecosia.org', 'brave.com'
        ];
        var socialHosts = [
          'facebook.com', 'instagram.com', 'linkedin.com', 'x.com',
          'twitter.com', 't.co', 'reddit.com', 'pinterest.com',
          'youtube.com', 'youtu.be', 'tiktok.com', 'mastodon.social'
        ];
        var emailHosts = [
          'mail.google.com', 'outlook.live.com', 'outlook.office.com',
          'mail.yahoo.com', 'proton.me', 'protonmail.com'
        ];

        for (var i = 0; i < searchHosts.length; i++) {
          if (host === searchHosts[i] || host.indexOf(searchHosts[i]) !== -1) return 'search';
        }
        for (var j = 0; j < socialHosts.length; j++) {
          if (host === socialHosts[j] || host.slice(-(socialHosts[j].length + 1)) === '.' + socialHosts[j]) return 'social';
        }
        for (var k = 0; k < emailHosts.length; k++) {
          if (host === emailHosts[k] || host.slice(-(emailHosts[k].length + 1)) === '.' + emailHosts[k]) return 'email';
        }

        return 'referral';
      } catch(e) {
        return 'unknown';
      }
    },

    getViewportBucket: function(){
      if (strictCollection) return 'unknown';
      try {
        var width = window.innerWidth || (screen && screen.width) || 0;
        if (!width) return 'unknown';
        if (width < 640) return 'small';
        if (width < 1024) return 'medium';
        return 'large';
      } catch(e) {
        return 'unknown';
      }
    },

    getDeviceClass: function(){
      if (strictCollection) return 'unknown';
      var viewportBucket = this.getViewportBucket();
      if (viewportBucket === 'small') return 'mobile';
      if (viewportBucket === 'medium') return 'tablet';
      if (viewportBucket === 'large') return 'desktop';
      return 'unknown';
    },

    send: function(payload){
      if (!this.config.websiteToken) return;
      payload.websiteToken = this.config.websiteToken;
      // Strict payloads carry no consent state, organization marker or IDs.
      if (!strictCollection) {
        payload.consentState = this.getConsentState();
        payload.internalTraffic = this.isInternalTraffic();
        var ids = this.ensureIds();
        if (ids.visitorId) payload.visitorId = ids.visitorId;
        if (ids.sessionId) payload.sessionId = ids.sessionId;
      }
      try {
        var headers = {'Content-Type':'application/json'};
        // Include Origin automatically by browser
        fetch(this.config.endpoint, {
          method: 'POST',
          headers: headers,
          body: JSON.stringify(payload),
          keepalive: true,
          referrerPolicy: 'no-referrer',
          credentials: 'omit'
        }).then(function(response){
          // Tracking remains fire-and-forget, but successful ingestion
          // responses may contain safe, machine-readable advisory codes.
          if (!response || !response.ok || typeof response.json !== 'function') return null;
          return response.json();
        }).then(function(responseBody){
          if (!responseBody || !Array.isArray(responseBody.warnings)) return;

          if (responseBody.warnings.indexOf('goal_not_allowed') !== -1) {
            try {
              if (typeof console !== 'undefined' && console && typeof console.warn === 'function') {
                console.warn('[' + namespace + '] Goal was not recorded because it is not an approved goal type.');
              }
            } catch(e) {}
          }
        }).catch(function(){});
      } catch(e) {}
    },

    trackView: function(advancePage, eventData){
      if (strictCollection) {
        this.send({eventName: 'view', pagePath: this.sanitizePagePath()});
        return;
      }

      var payload = {
        eventName: 'view',
        pagePath: this.sanitizePagePath(),
        referrerChannel: this.getReferrerChannel(),
        deviceClass: this.getDeviceClass(),
        viewportBucket: this.getViewportBucket()
      };

      // Exact screen size is an enhanced dimension. Anonymous page views use
      // only the coarse viewport bucket above.
      if (this.consent) {
        payload.screenWidth = (screen && screen.width) || null;
      }
      // The payload's custom properties travel as customData; the server still
      // accepts the earlier eventData name from older copies of this script.
      // eventData holds the script URL's cd.* values for the automatic view.
      var customData = this.customDataForEvent(eventData || null, advancePage);
      if (customData) payload.customData = customData;

      this.send(payload);
    },

    emit: function(eventName, eventData, goalEvent){
      var safeEventName = this.sanitizeEventName(eventName);
      if (!safeEventName) return false;

      if (strictCollection) {
        // Event properties are not sent. Goals remain server-validated.
        this.send({pagePath: this.sanitizePagePath(), eventName: safeEventName, goalEvent: goalEvent || null});
        return true;
      }

      var payload = {
        pagePath: this.sanitizePagePath(),
        referrerChannel: this.getReferrerChannel(),
        deviceClass: this.getDeviceClass(),
        viewportBucket: this.getViewportBucket(),
        eventName: safeEventName
      };

      // Goals are server-validated against the configured allowlist in both
      // privacy modes. Only configured consent-free properties can accompany
      // anonymous events; exact dimensions and IDs require enhanced consent.
      payload.goalEvent = goalEvent || null;

      if (this.consent) {
        payload.screenWidth = (screen && screen.width) || null;
      }
      var customData = this.customDataForEvent(eventData, safeEventName === 'view');
      if (customData || this.consent) payload.customData = customData;

      this.send(payload);

      return true;
    },

    setConsent: function(granted){
      this.consentKnown = true;
      // Strict collection never enables enhanced analytics, so a consent
      // choice changes nothing and no identifier storage is created or cleared.
      if (strictCollection) {
        this.consent = false;
        return;
      }
      this.consent = this.parseConsent(granted);

      // Withdrawing enhanced consent removes identifiers immediately. Coarse,
      // hour-bucketed anonymous-mode rows continue without those identifiers.
      if (!this.consent) {
        this.clearIdentifiers();
      }
    }
  };

  // expose on configurable namespace
  window[namespace] = window[namespace] || {};
  window[namespace].emit = Analytics.emit.bind(Analytics);
  window[namespace].trackView = function(){ Analytics.trackView(true); };
  window[namespace].setConsent = Analytics.setConsent.bind(Analytics);
  window[namespace].configure = function(opts){
    Analytics.requireStrictCollection(opts && opts.collectionProfile);
    Analytics.config.endpoint = opts && opts.endpoint || Analytics.config.endpoint;
    Analytics.config.websiteToken = opts && opts.websiteToken || Analytics.config.websiteToken;
    Analytics.configureInternalTraffic(opts && opts.internalTraffic);
    Analytics.configureCustomData(opts && opts.customData);
    if (opts && typeof opts.consent !== 'undefined') {
      Analytics.setConsent(opts.consent);
    }
    if (Analytics.pageSequenceMethod() === 'url_parameter') Analytics.pageSequenceForEvent(false);
  };

  // Apply an inline strict opt-in before any consent value is read, so a
  // static copy never clears or creates storage on its way to strict mode.
  Analytics.requireStrictCollection(window[namespace].collectionProfile);

  // Custom properties for the automatic page view, read from the script URL as
  // cd.<property>=<value> so a tag manager can fill them from page values.
  // They pass the same consent, allowlist and type checks as emit() data.
  var initialViewData = Object.create(null);

  // Try to read configuration from script tag (query params or data-attributes)
  try {
    var s = document.currentScript || (function(){var ss=document.getElementsByTagName('script'); return ss[ss.length-1];})();
    if (s) {
      if (s.dataset) {
        if (s.dataset.endpoint) Analytics.config.endpoint = s.dataset.endpoint;
        if (s.dataset.websiteToken) Analytics.config.websiteToken = s.dataset.websiteToken;
        Analytics.requireStrictCollection(s.dataset.collectionProfile);
        Analytics.configureInternalTraffic({
          storage: s.dataset.internalTrafficStorage,
          name: s.dataset.internalTrafficName,
          value: s.dataset.internalTrafficValue
        });
        if (typeof s.dataset.consent !== 'undefined') {
          Analytics.setConsent(s.dataset.consent);
        }
      }
      if (s.src) {
        try {
          var u = new URL(s.src, location.origin);
          var ep = u.searchParams.get('endpoint');
          var wt = u.searchParams.get('token') || u.searchParams.get('websiteToken');
          var cs = u.searchParams.get('consent');
          if (ep) Analytics.config.endpoint = ep;
          if (wt) Analytics.config.websiteToken = wt;
          if (cs !== null) {
            Analytics.setConsent(cs);
          }
          // Collect a bounded surplus; customDataForEvent keeps the first 50
          // values that pass consent and type checks.
          var viewDataCount = 0;
          u.searchParams.forEach(function(value, name){
            if (name.indexOf('cd.') !== 0 || viewDataCount >= 100) return;
            var key = name.slice(3);
            // URL values are strings; repeated names keep the first nonblank one.
            var text = value.replace(/[\u0000-\u001f\u007f]/g, '').trim();
            if (!text || !Analytics.isCustomDataKey(key) || Object.prototype.hasOwnProperty.call(initialViewData, key)) return;
            initialViewData[key] = text;
            viewDataCount++;
          });
        } catch(e) {}
      }
    }
  } catch(e) {}

  // if configured inline, copy values
  if (window[namespace].endpoint) Analytics.config.endpoint = window[namespace].endpoint;
  if (window[namespace].websiteToken) Analytics.config.websiteToken = window[namespace].websiteToken;
  Analytics.configureInternalTraffic(window[namespace].internalTraffic);
  Analytics.configureCustomData(window[namespace].customData);
  if (typeof window[namespace].consent !== 'undefined') {
    Analytics.setConsent(window[namespace].consent);
  }

  // Do not leave identifiers from an earlier consented visit behind when this
  // load starts in anonymous mode. A CMP can opt in again with setConsent(true).
  if (!Analytics.consent) Analytics.clearIdentifiers();

  // A server-side disable also removes the counter left by an earlier page.
  if (Analytics.config.customData.pageSequenceEnabled !== true) Analytics.clearPageSequence();

  // Capture the incoming count and shorten its URL exposure as soon as all
  // initial settings are known, without waiting for DOM readiness or a request.
  if (Analytics.pageSequenceMethod() === 'url_parameter') Analytics.pageSequenceForEvent(false);

  // Decorate only an activated eligible link; native navigation stays in charge.
  if (!strictCollection) {
    document.addEventListener('click', function(event){ Analytics.decoratePageSequenceLink(event); });
  }

  // auto pageview on load
  if (document.readyState === 'complete' || document.readyState === 'interactive') {
    setTimeout(function(){ Analytics.trackView(undefined, initialViewData); }, 0);
  } else {
    document.addEventListener('DOMContentLoaded', function(){ Analytics.trackView(undefined, initialViewData); });
  }
})();
