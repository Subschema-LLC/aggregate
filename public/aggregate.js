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

    // Privacy-compliant session cookie helpers
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
          'max-age=1800;' +  // 30 minutes - compliant with privacy laws
          'SameSite=Lax';

        // Add Secure flag for HTTPS (required for strict compliance)
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
        // Tier 1: No consent - no IDs
        if (!this.consent) return {visitorId: null, sessionId: null};

        // Tier 2: Consent granted - use privacy-compliant identifiers
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
          this.setSessionCookie(sessionId);  // Set privacy-compliant 30-minute cookie
        } else {
          // Refresh cookie expiry on each request (sliding window)
          this.setSessionCookie(sessionId);
        }

        return {visitorId: visitorId, sessionId: sessionId};
      } catch(e) {
        return {visitorId: null, sessionId: null};
      }
    },

    send: function(payload){
      if (!this.config.websiteToken) return;
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
          credentials: 'omit'
        }).catch(function(){});
      } catch(e) {}
    },

    trackView: function(){
      this.send({
        url: location.href,
        referrer: document.referrer || null,
        screenWidth: (screen && screen.width) || null
      });
    },

    emit: function(eventName, eventData, goalEvent){
      this.send({
        url: location.href,
        referrer: document.referrer || null,
        screenWidth: (screen && screen.width) || null,
        eventName: eventName || null,
        eventData: eventData || null,
        goalEvent: goalEvent || null
      });
    },

    setConsent: function(granted){
      var wasConsented = this.consent;
      this.consent = !!granted;

      // Privacy compliance: Clean up all tracking data when consent is withdrawn
      if (wasConsented && !granted) {
        try {
          localStorage.removeItem('aggregate_visitor_id');
          sessionStorage.removeItem('aggregate_session_id');
          this.deleteSessionCookie();
        } catch(e) {}
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
    if (opts && typeof opts.consent !== 'undefined') Analytics.consent = !!opts.consent;
  };

  // Try to read configuration from script tag (query params or data-attributes)
  try {
    var s = document.currentScript || (function(){var ss=document.getElementsByTagName('script'); return ss[ss.length-1];})();
    if (s) {
      if (s.dataset) {
        if (s.dataset.endpoint) Analytics.config.endpoint = s.dataset.endpoint;
        if (s.dataset.websiteToken) Analytics.config.websiteToken = s.dataset.websiteToken;
        if (typeof s.dataset.consent !== 'undefined') Analytics.consent = (s.dataset.consent === 'true' || s.dataset.consent === '1');
      }
      if (s.src) {
        try {
          var u = new URL(s.src, location.origin);
          var ep = u.searchParams.get('endpoint');
          var wt = u.searchParams.get('token') || u.searchParams.get('websiteToken');
          var cs = u.searchParams.get('consent');
          if (ep) Analytics.config.endpoint = ep;
          if (wt) Analytics.config.websiteToken = wt;
          if (cs !== null) Analytics.consent = (cs === 'true' || cs === '1');
        } catch(e) {}
      }
    }
  } catch(e) {}

  // if configured inline, copy values
  if (window[namespace].endpoint) Analytics.config.endpoint = window[namespace].endpoint;
  if (window[namespace].websiteToken) Analytics.config.websiteToken = window[namespace].websiteToken;
  if (typeof window[namespace].consent !== 'undefined') Analytics.consent = !!window[namespace].consent;

  // auto pageview on load
  if (document.readyState === 'complete' || document.readyState === 'interactive') {
    setTimeout(function(){ Analytics.trackView(); }, 0);
  } else {
    document.addEventListener('DOMContentLoaded', function(){ Analytics.trackView(); });
  }
})();
