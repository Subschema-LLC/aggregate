(function(){
  var Analytics = {
    config: {
      endpoint: (window.MyAnalytics && window.MyAnalytics.endpoint) || '/api/receive',
      websiteToken: (window.MyAnalytics && window.MyAnalytics.websiteToken) || null
    },
    consent: false,
    ensureIds: function(){
      try {
        if (!this.consent) return {visitorId: null, sessionId: null};
        var vKey = 'my_analytics_visitor_id';
        var sKey = 'my_analytics_session_id';
        var visitorId = localStorage.getItem(vKey);
        if (!visitorId) {
          visitorId = self.crypto && self.crypto.randomUUID ? self.crypto.randomUUID() : (Math.random().toString(36).slice(2) + Date.now());
          localStorage.setItem(vKey, visitorId);
        }
        var sessionId = sessionStorage.getItem(sKey);
        if (!sessionId) {
          sessionId = self.crypto && self.crypto.randomUUID ? self.crypto.randomUUID() : (Math.random().toString(36).slice(2) + Date.now());
          sessionStorage.setItem(sKey, sessionId);
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
    trackPageview: function(){
      this.send({
        url: location.href,
        referrer: document.referrer || null,
        screenWidth: (screen && screen.width) || null
      });
    },
    track: function(eventName, eventData){
      this.send({
        url: location.href,
        referrer: document.referrer || null,
        screenWidth: (screen && screen.width) || null,
        eventName: eventName || null,
        eventData: eventData || null
      });
    },
    setConsent: function(granted){
      this.consent = !!granted; // switches to Tier 2 when true
    }
  };

  // expose
  window.MyAnalytics = window.MyAnalytics || {};
  window.MyAnalytics.track = Analytics.track.bind(Analytics);
  window.MyAnalytics.setConsent = Analytics.setConsent.bind(Analytics);
  window.MyAnalytics.configure = function(opts){
    Analytics.config.endpoint = opts && opts.endpoint || Analytics.config.endpoint;
    Analytics.config.websiteToken = opts && opts.websiteToken || Analytics.config.websiteToken;
  };

  // if configured inline, copy values
  if (window.MyAnalytics.endpoint) Analytics.config.endpoint = window.MyAnalytics.endpoint;
  if (window.MyAnalytics.websiteToken) Analytics.config.websiteToken = window.MyAnalytics.websiteToken;

  // auto pageview on load
  if (document.readyState === 'complete' || document.readyState === 'interactive') {
    setTimeout(function(){ Analytics.trackPageview(); }, 0);
  } else {
    document.addEventListener('DOMContentLoaded', function(){ Analytics.trackPageview(); });
  }
})();
