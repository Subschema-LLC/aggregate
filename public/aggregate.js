(function(){
  // Configurable namespace - defaults to 'Aggregate' but can be overridden via data-namespace attribute
  var namespace = 'Aggregate';
  try {
    var s = document.currentScript || (function(){var ss=document.getElementsByTagName('script'); return ss[ss.length-1];})();
    if (s && s.dataset && s.dataset.namespace) {
      namespace = s.dataset.namespace;
    }
  } catch(e) {}

  var Analytics = {
    config: {
      endpoint: (window[namespace] && window[namespace].endpoint) || '/api/receive',
      websiteToken: (window[namespace] && window[namespace].websiteToken) || null
    },
    consent: false,
    consentKnown: false,

    clearIdentifiers: function(){
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
        if (!this.consent) return {visitorId: null, sessionId: null};

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

    sanitizePagePath: function(){
      var path = location.pathname || '/';

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
      var viewportBucket = this.getViewportBucket();
      if (viewportBucket === 'small') return 'mobile';
      if (viewportBucket === 'medium') return 'tablet';
      if (viewportBucket === 'large') return 'desktop';
      return 'unknown';
    },

    send: function(payload){
      if (!this.config.websiteToken) return;
      payload.consentState = this.getConsentState();
      payload.websiteToken = this.config.websiteToken;
      var ids = this.ensureIds();
      if (ids.visitorId) payload.visitorId = ids.visitorId;
      if (ids.sessionId) payload.sessionId = ids.sessionId;
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
                console.warn('[Aggregate] Goal was not recorded because it is not an approved goal type.');
              }
            } catch(e) {}
          }
        }).catch(function(){});
      } catch(e) {}
    },

    trackView: function(){
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

      this.send(payload);
    },

    emit: function(eventName, eventData, goalEvent){
      var safeEventName = this.sanitizeEventName(eventName);
      if (!safeEventName) return false;

      var payload = {
        pagePath: this.sanitizePagePath(),
        referrerChannel: this.getReferrerChannel(),
        deviceClass: this.getDeviceClass(),
        viewportBucket: this.getViewportBucket(),
        eventName: safeEventName
      };

      // Goals are server-validated against the configured allowlist in both
      // privacy modes. Properties, exact dimensions and browser identifiers
      // remain enhanced analytics and require affirmative consent.
      payload.goalEvent = goalEvent || null;

      if (this.consent) {
        payload.screenWidth = (screen && screen.width) || null;
        payload.eventData = eventData || null;
      }

      this.send(payload);

      return true;
    },

    setConsent: function(granted){
      this.consent = this.parseConsent(granted);
      this.consentKnown = true;

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
  window[namespace].setConsent = Analytics.setConsent.bind(Analytics);
  window[namespace].configure = function(opts){
    Analytics.config.endpoint = opts && opts.endpoint || Analytics.config.endpoint;
    Analytics.config.websiteToken = opts && opts.websiteToken || Analytics.config.websiteToken;
    if (opts && typeof opts.consent !== 'undefined') {
      Analytics.setConsent(opts.consent);
    }
  };

  // Try to read configuration from script tag (query params or data-attributes)
  try {
    var s = document.currentScript || (function(){var ss=document.getElementsByTagName('script'); return ss[ss.length-1];})();
    if (s) {
      if (s.dataset) {
        if (s.dataset.endpoint) Analytics.config.endpoint = s.dataset.endpoint;
        if (s.dataset.websiteToken) Analytics.config.websiteToken = s.dataset.websiteToken;
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
        } catch(e) {}
      }
    }
  } catch(e) {}

  // if configured inline, copy values
  if (window[namespace].endpoint) Analytics.config.endpoint = window[namespace].endpoint;
  if (window[namespace].websiteToken) Analytics.config.websiteToken = window[namespace].websiteToken;
  if (typeof window[namespace].consent !== 'undefined') {
    Analytics.setConsent(window[namespace].consent);
  }

  // Do not leave identifiers from an earlier consented visit behind when this
  // load starts in anonymous mode. A CMP can opt in again with setConsent(true).
  if (!Analytics.consent) Analytics.clearIdentifiers();

  // auto pageview on load
  if (document.readyState === 'complete' || document.readyState === 'interactive') {
    setTimeout(function(){ Analytics.trackView(); }, 0);
  } else {
    document.addEventListener('DOMContentLoaded', function(){ Analytics.trackView(); });
  }
})();
