/*!
 * Aggregate optional tag manager
 * SPDX-License-Identifier: AGPL-3.0-only
 * Copyright (c) 2026 Aggregate Analytics Contributors
 * Full license available in LICENSE in the source repository.
 */
(function () {
  'use strict';

  // The server injects the current, validated public YAML settings here.
  var tagManagerConfig = {enabled: false, tags: []};
  // Custom JavaScript tags: the server compiles each one's code here as
  // function (tag) { 'use strict'; ... }, after parsing it (TagManagerCustomCode).
  var customScripts = {};
  if (window.AggregateTags && window.AggregateTags._aggregateTagManager === true) return;

  var denied = ['__proto__', 'prototype', 'constructor'];
  var blockedMethods = denied.concat(['call', 'apply', 'bind']);
  var blockedRoots = ['window', 'globalThis', 'self', 'top', 'parent', 'frames', 'document', 'location', 'localStorage', 'sessionStorage', 'Function', 'eval', 'Object', 'Reflect', 'Proxy', 'WebAssembly', 'setTimeout', 'setInterval', 'fetch', 'opener', 'navigator', 'history', 'navigation', 'XMLHttpRequest', 'WebSocket', 'Worker', 'SharedWorker', 'importScripts', 'requestAnimationFrame', 'queueMicrotask'];
  var identifier = /^[A-Za-z_$][A-Za-z0-9_$]*$/;
  var consentState = Object.create(null);
  var completed = Object.create(null);
  var executing = Object.create(null);
  var variablePaths = Object.create(null);
  var dataState = Object.create(null);
  var subscribedCmp = null;
  var unsubscribe = null;
  var started = false;
  var domReady = document.readyState !== 'loading';
  var windowLoaded = document.readyState === 'complete';
  var nonce = document.currentScript && document.currentScript.nonce;
  var actionDepth = 0;
  var actionCount = 0;
  var draining = false;
  var pending = [];
  var MISSING = {};
  var cleanups = Object.create(null);
  var trackerNamespace = 'Aggregate';
  // Tags that finished (a script loaded, a call made, custom code done), and
  // the runs of tags set to run after one of them that are still waiting.
  var finished = Object.create(null);
  var waiting = Object.create(null);
  var reportedBlocked = Object.create(null);
  // tag.emit() calls made before the tracker loaded, sent once it has.
  var heldEvents = [];
  var trackerAnnounced = false;

  function own(object, key) {
    if (!object || (typeof object !== 'object' && typeof object !== 'function') || denied.indexOf(String(key)) !== -1) return MISSING;
    try {
      var descriptor = Object.getOwnPropertyDescriptor(object, key);
      return descriptor && Object.prototype.hasOwnProperty.call(descriptor, 'value') ? descriptor.value : MISSING;
    } catch (error) { return MISSING; }
  }

  function bytes(value) {
    try { return encodeURIComponent(value).replace(/%[0-9A-F]{2}|./g, 'x').length; }
    catch (error) { return Infinity; }
  }

  function scalar(value) {
    return value === null || typeof value === 'boolean'
      || (typeof value === 'string' && bytes(value) <= 2048)
      || (typeof value === 'number' && Number.isFinite(value) && (!Number.isInteger(value) || Number.isSafeInteger(value)));
  }

  function record(value) {
    if (!value || typeof value !== 'object' || Array.isArray(value)) return false;
    var prototype = Object.getPrototypeOf(value);
    return prototype === null || Object.getPrototypeOf(prototype) === null;
  }

  function pathParts(path) {
    if (typeof path !== 'string' || path.length > 256
        || !/^[A-Za-z_$][A-Za-z0-9_$]*(?:(?:\.[A-Za-z_$][A-Za-z0-9_$]*)|(?:\[(?:0|[1-9][0-9]*)\]))*$/.test(path)) return null;
    var parts = path.match(/[A-Za-z_$][A-Za-z0-9_$]*|[0-9]+/g);
    if (parts.length > 16 || parts.some(function (part) {
      return denied.indexOf(part) !== -1 || (/^[0-9]+$/.test(part) && Number(part) > 10000);
    })) return null;
    return parts;
  }

  function methodParts(method) {
    if (typeof method !== 'string' || method.length > 128) return null;
    var parts = method.split('.');
    if (parts.length < 2 || parts.length > 8 || blockedRoots.indexOf(parts[0]) !== -1
        || parts.some(function (part) { return !identifier.test(part) || blockedMethods.indexOf(part) !== -1; })) return null;
    return parts;
  }

  function trigger(tag) {
    return Object.prototype.hasOwnProperty.call(tag, 'trigger') ? tag.trigger : {type: 'dom_ready'};
  }

  function validTemplate(src) {
    if (typeof src !== 'string' || src.length > 2048 || /[\x00-\x20\x7f\\]/.test(src) || src.indexOf('#') !== -1) return false;
    var authority = src.match(/^https:\/\/[^/?#]+/i);
    if (!authority || /[{}]/.test(authority[0])) return false;
    var valid = true;
    var replaced = src.replace(/\{\{([A-Za-z][A-Za-z0-9_]{0,63})\}\}/g, function (match, alias) {
      if (!Object.prototype.hasOwnProperty.call(variablePaths, alias)) valid = false;
      return 'variable';
    });
    if (!valid || /[{}]/.test(replaced)) return false;
    try {
      var url = new URL(replaced);
      return url.protocol === 'https:' && !!url.hostname && !url.username && !url.password;
    } catch (error) { return false; }
  }

  function validConfiguration(config) {
    try {
      if (!config || typeof config.enabled !== 'boolean' || !Array.isArray(config.tags) || config.tags.length > 20) return false;
      if (Object.prototype.hasOwnProperty.call(config, 'namespace')) {
        if (typeof config.namespace !== 'string' || !/^[A-Za-z_$][A-Za-z0-9_$]{0,63}$/.test(config.namespace)) return false;
        trackerNamespace = config.namespace;
      }
      var variables = Object.prototype.hasOwnProperty.call(config, 'variables') ? config.variables : {};
      if (!record(variables) && !(Array.isArray(variables) && variables.length === 0)) return false;
      var aliases = Object.keys(variables);
      if (aliases.length > 32) return false;
      for (var v = 0; v < aliases.length; v++) {
        var alias = aliases[v];
        if (!/^[A-Za-z][A-Za-z0-9_]{0,63}$/.test(alias) || denied.indexOf(alias) !== -1) return false;
        var parts = pathParts(own(variables, alias));
        if (!parts) return false;
        variablePaths[alias] = parts;
      }
      var ids = Object.create(null);
      var sources = Object.create(null);
      for (var i = 0; i < config.tags.length; i++) {
        var tag = config.tags[i];
        if (!tag || typeof tag.id !== 'string' || !/^[A-Za-z][A-Za-z0-9_-]{0,63}$/.test(tag.id) || ids[tag.id]
            || typeof tag.consent !== 'string' || !/^[a-z][a-z0-9_-]{0,31}$/.test(tag.consent)) return false;
        var when = trigger(tag);
        if (!when || ['dom_ready', 'window_load', 'document_event', 'window_event', 'data_layer'].indexOf(when.type) === -1) return false;
        if (when.type === 'dom_ready' || when.type === 'window_load') {
          if (Object.prototype.hasOwnProperty.call(when, 'event')) return false;
        } else if (typeof when.event !== 'string' || !/^[A-Za-z][A-Za-z0-9_.:-]{0,99}$/.test(when.event)) return false;
        var type = Object.prototype.hasOwnProperty.call(tag, 'type') ? tag.type : 'script';
        if (type === 'script') {
          if (!validTemplate(tag.src) || sources[tag.src]) return false;
          sources[tag.src] = true;
        } else if (type === 'call') {
          if (!methodParts(tag.method) || !Array.isArray(tag.args) || tag.args.length > 10) return false;
        } else if (type === 'custom') {
          if (typeof own(customScripts, tag.id) !== 'function') return false;
        } else return false;
        ids[tag.id] = true;
      }
      // "Run after" names another tag; the server also refuses chains that loop.
      for (var j = 0; j < config.tags.length; j++) {
        if (!Object.prototype.hasOwnProperty.call(config.tags[j], 'after')) continue;
        var after = config.tags[j].after;
        if (typeof after !== 'string' || !ids[after] || after === config.tags[j].id) return false;
      }
      return true;
    } catch (error) { return false; }
  }

  var configured = validConfiguration(tagManagerConfig) && tagManagerConfig.enabled === true;

  function readVariable(alias, context) {
    var parts = variablePaths[alias];
    if (!parts) return MISSING;
    var value = context;
    for (var i = 0; i < parts.length; i++) {
      value = own(value, parts[i]);
      if (value === MISSING) return MISSING;
    }
    return scalar(value) ? value : MISSING;
  }

  function scriptUrl(template, context) {
    var valid = true;
    var result = template.replace(/\{\{([A-Za-z][A-Za-z0-9_]{0,63})\}\}/g, function (match, alias) {
      var value = readVariable(alias, context);
      if (value === MISSING || value === null) { valid = false; return ''; }
      return encodeURIComponent(String(value));
    });
    if (!valid || bytes(result) > 2048) return null;
    try {
      var expected = new URL(template.replace(/\{\{[A-Za-z][A-Za-z0-9_]{0,63}\}\}/g, 'variable'));
      var actual = new URL(result);
      return actual.origin === expected.origin && actual.protocol === 'https:' && !actual.username && !actual.password && !actual.hash ? result : null;
    } catch (error) { return null; }
  }

  function argumentsFor(args, context) {
    var nodes = 1;
    function value(input, depth) {
      if (++nodes > 128) throw new Error('Argument limit');
      if (scalar(input)) return input;
      if (!input || typeof input !== 'object' || depth >= 4) throw new Error('Invalid argument');
      var keys = Object.keys(input);
      if (keys.length > 32) throw new Error('Argument limit');
      if (!Array.isArray(input) && Object.prototype.hasOwnProperty.call(input, '$var')) {
        if (keys.length !== 1 || ++nodes > 128) throw new Error('Invalid variable reference');
        var resolved = readVariable(own(input, '$var'), context);
        if (resolved === MISSING) throw new Error('Missing variable');
        return resolved;
      }
      if (!Array.isArray(input) && !record(input)) throw new Error('Invalid argument');
      var output = Array.isArray(input) ? [] : Object.create(null);
      keys.forEach(function (key) {
        if (!key || bytes(key) > 64 || /[\x00-\x1f\x7f]/.test(key) || denied.indexOf(key) !== -1) throw new Error('Invalid key');
        var member = own(input, key);
        if (member === MISSING) throw new Error('Invalid argument');
        output[key] = value(member, depth + 1);
      });
      return output;
    }
    try { return args.map(function (arg) { return value(arg, 0); }); }
    catch (error) { return null; }
  }

  function report(tag, error) {
    try {
      var log = own(window, 'console');
      if (log !== MISSING && log && typeof log.error === 'function') log.error('[Aggregate tag manager] Custom JavaScript in tag "' + tag.id + '" failed:', error);
    } catch (ignored) {}
  }

  function allowed(tag) {
    return tag.consent === 'none' || consentState[tag.consent] === true;
  }

  function warn(message) {
    try {
      var log = own(window, 'console');
      if (log !== MISSING && log && typeof log.warn === 'function') log.warn('[Aggregate tag manager] ' + message);
    } catch (ignored) {}
  }

  // The tracker's emit(), when the tracker has loaded.
  function tracker() {
    var api = own(window, trackerNamespace);
    var emit = api === MISSING ? MISSING : own(api, 'emit');
    return typeof emit === 'function' ? {api: api, emit: emit} : null;
  }

  // Sends one event through the tracker: true when it accepted the event,
  // false when it refused it or failed, null when it has not loaded yet.
  function sendEvent(tag, args) {
    var found = tracker();
    if (!found) return null;
    try { return Reflect.apply(found.emit, found.api, args) !== false; }
    catch (error) { report(tag, error); return false; }
  }

  // Runs after the current task, so a tracker that has just loaded sends its
  // own page view first.
  function soon(callback) {
    if (typeof setTimeout === 'function') setTimeout(callback, 0);
    else callback();
  }

  function trackerReady(event) {
    var detail = event && own(event, 'detail');
    var name = detail && detail !== MISSING ? own(detail, 'namespace') : MISSING;
    if (name !== MISSING && name !== trackerNamespace) return;
    trackerAnnounced = true;
    releaseHeldEvents();
  }

  // Sends events held for the tracker, for tags that still have consent.
  function releaseHeldEvents() {
    if (!heldEvents.length || !tracker()) return;
    var held = heldEvents;
    heldEvents = [];
    held.forEach(function (entry) {
      if (allowed(entry.tag)) sendEvent(entry.tag, entry.args);
    });
  }

  // A tag finished: send held events (it may have loaded the tracker), then
  // run the tags waiting for it, each checking its consent again.
  function finish(tag) {
    soon(releaseHeldEvents);
    if (!configured || finished[tag.id]) return;
    finished[tag.id] = true;
    tagManagerConfig.tags.forEach(function (dependent) {
      if (dependent.after !== tag.id) return;
      var runs = waiting[dependent.id] || [];
      waiting[dependent.id] = [];
      runs.forEach(function (context) { attempt(dependent, context); });
    });
  }

  // Explains, once, why tags set to run after a failed tag did not run.
  function blocked(tag, reason) {
    if (!configured || finished[tag.id] || reportedBlocked[tag.id]) return;
    var dependents = tagManagerConfig.tags.filter(function (dependent) { return dependent.after === tag.id; })
      .map(function (dependent) { return '"' + dependent.id + '"'; });
    if (!dependents.length) return;
    reportedBlocked[tag.id] = true;
    warn('Tag "' + tag.id + '" ' + reason + ', so the tags set to run after it did not run: ' + dependents.join(', ') + '.');
  }

  // Keeps a run of a tag whose "Run after" tag has not finished. A page-load
  // trigger keeps one run; an event trigger keeps up to 20, in order.
  function hold(tag, context) {
    var runs = waiting[tag.id] || (waiting[tag.id] = []);
    var when = trigger(tag);
    if ((when.type === 'dom_ready' || when.type === 'window_load') ? runs.length === 0 : runs.length < 20) runs.push(context);
  }

  // JSON copies give custom code ordinary objects it can change freely.
  function plain(value) {
    try { return value === undefined ? undefined : JSON.parse(JSON.stringify(value)); } catch (error) { return undefined; }
  }

  // The one object custom code receives. Everything a script may need from
  // the tag manager goes through it, with the tag's consent still applying.
  function customApi(tag, context) {
    var when = trigger(tag);
    var event = {trigger: when.type, name: Object.prototype.hasOwnProperty.call(when, 'event') ? when.event : null};
    if ((when.type === 'document_event' || when.type === 'window_event') && context && record(own(context, 'event'))) {
      event.detail = plain(own(own(context, 'event'), 'detail'));
    }
    return Object.freeze({
      id: tag.id,
      event: Object.freeze(event),
      data: plain(when.type === 'data_layer' ? context : dataState) || {},
      get: function (alias) {
        var value = readVariable(String(alias), context);
        return value === MISSING ? undefined : value;
      },
      consent: function (category) {
        return category === 'none' || consentState[category] === true;
      },
      push: function (entry) {
        if (!record(entry)) return false;
        var layer = own(window, 'dataLayer');
        if (layer === MISSING) { layer = []; window.dataLayer = layer; }
        if (!Array.isArray(layer)) return false;
        layer.push(entry);
        return true;
      },
      // Until the tracker has loaded and sent its page view, events wait for
      // it (up to 20 a page).
      emit: function (name, properties, goal) {
        if (trackerAnnounced) {
          var sent = sendEvent(tag, [name, properties, goal]);
          if (sent !== null) return sent;
        }
        if (heldEvents.length >= 20) return false;
        // A copy, so later changes to the object do not change the event.
        heldEvents.push({tag: tag, args: [name, plain(properties), goal]});
        // A tracker already here, such as an older copy that does not announce
        // itself, receives them once its own page view is on its way.
        if (tracker()) soon(releaseHeldEvents);
        return true;
      },
      loadScript: function (url) {
        return new Promise(function (resolve, reject) {
          var address;
          try { address = new URL(String(url), document.baseURI); } catch (error) { reject(new Error('tag.loadScript needs a valid URL.')); return; }
          if (address.protocol !== 'https:' || address.username || address.password) { reject(new Error('tag.loadScript loads only HTTPS URLs without credentials.')); return; }
          if (!allowed(tag)) { reject(new Error('Consent for this tag was withdrawn.')); return; }
          var parent = document.head || document.body || document.documentElement;
          var script = document.createElement('script');
          script.async = true;
          script.src = address.href;
          script.referrerPolicy = 'no-referrer';
          script.setAttribute('data-aggregate-tag', tag.id);
          if (nonce) script.nonce = nonce;
          script.addEventListener('load', function () { resolve(); });
          script.addEventListener('error', function () { reject(new Error('Could not load ' + address.href)); });
          parent.appendChild(script);
        });
      },
      onCleanup: function (callback) {
        if (typeof callback !== 'function') return;
        var list = cleanups[tag.id] || (cleanups[tag.id] = []);
        if (list.length < 50) list.push(callback);
      }
    });
  }

  // Withdrawal runs the cleanup functions of custom tags that lost consent.
  function runCleanups() {
    if (!configured) return;
    tagManagerConfig.tags.forEach(function (tag) {
      var list = cleanups[tag.id];
      if (!list || !list.length || allowed(tag)) return;
      cleanups[tag.id] = [];
      list.forEach(function (callback) {
        try { callback(); } catch (error) { report(tag, error); }
      });
    });
  }

  function attempt(tag, context) {
    if (!configured || completed[tag.id] || executing[tag.id]
        || (tag.consent !== 'none' && consentState[tag.consent] !== true)) return;
    if (typeof tag.after === 'string' && !finished[tag.after]) {
      hold(tag, context);
      return;
    }
    if (actionDepth === 0) actionCount = 0;
    if (actionDepth >= 20 || ++actionCount > 100) return;
    actionDepth++;
    executing[tag.id] = true;
    var done = false;
    try {
      if ((tag.type || 'script') === 'script') {
        var src = scriptUrl(tag.src, context);
        var parent = document.head || document.body || document.documentElement;
        if (!src || !parent) return;
        var script = document.createElement('script');
        script.async = true;
        script.src = src;
        script.referrerPolicy = 'no-referrer';
        script.setAttribute('data-aggregate-tag', tag.id);
        if (nonce) script.nonce = nonce;
        // A script has finished when it has loaded and run, including work it
        // scheduled straight away, such as the tracker's page view.
        script.addEventListener('load', function () { soon(function () { finish(tag); }); });
        script.addEventListener('error', function () { blocked(tag, 'could not load its script'); });
        completed[tag.id] = true;
        parent.appendChild(script);
      } else if (tag.type === 'custom') {
        var lifecycle = trigger(tag);
        if (lifecycle.type === 'dom_ready' || lifecycle.type === 'window_load') completed[tag.id] = true;
        var result = Reflect.apply(own(customScripts, tag.id), undefined, [customApi(tag, context)]);
        // Code that returns a promise has finished when the promise resolves.
        if (result && typeof result.then === 'function') {
          result.then(function () { finish(tag); }, function (error) {
            report(tag, error);
            blocked(tag, 'failed');
          });
        } else done = true;
      } else {
        var parts = methodParts(tag.method);
        var receiver = window;
        for (var i = 0; i < parts.length - 1; i++) {
          receiver = own(receiver, parts[i]);
          if (receiver === MISSING) return;
        }
        var method = own(receiver, parts[parts.length - 1]);
        var args = argumentsFor(tag.args, context);
        if (typeof method !== 'function' || args === null) return;
        var when = trigger(tag);
        if (when.type === 'dom_ready' || when.type === 'window_load') completed[tag.id] = true;
        Reflect.apply(method, receiver, args);
        done = true;
      }
    } catch (error) {
      // Missing or failing provider libraries do not affect other tags or expose payloads.
      if (tag.type === 'custom') report(tag, error);
      blocked(tag, 'failed');
    } finally {
      executing[tag.id] = false;
      actionDepth--;
    }
    if (done) finish(tag);
  }

  function fire(type, event, context, grants) {
    if (!configured) return;
    grants = grants || categoryState(consentState);
    tagManagerConfig.tags.forEach(function (tag) {
      var when = trigger(tag);
      if (when.type === type && (event === null || when.event === event)
          && (tag.consent === 'none' || grants[tag.consent] === true)) attempt(tag, context);
    });
  }

  function loadLifecycle() {
    if (!started) return;
    if (domReady) fire('dom_ready', null, dataState);
    if (windowLoaded) fire('window_load', null, dataState);
  }

  function categoryState(value) {
    var next = Object.create(null);
    try {
      if (value && typeof value === 'object' && !Array.isArray(value)) {
        Object.keys(value).forEach(function (category) {
          if (/^[a-z][a-z0-9_-]{0,31}$/.test(category) && category !== 'none') next[category] = value[category] === true;
        });
      }
    } catch (error) { next = Object.create(null); }
    return next;
  }

  function setConsent(value) {
    if (typeof value === 'boolean') consentState.analytics = value;
    else consentState = categoryState(value);
    runCleanups();
    loadLifecycle();
  }

  function applyCmpState(state) {
    setConsent(typeof state === 'boolean' ? {} : state);
  }

  window.AggregateTags = {_aggregateTagManager: true, setConsent: setConsent};

  function connectConsentManager() {
    var cmp = window.AggregateConsent;
    if (cmp && cmp === subscribedCmp) return;
    setConsent({});
    if (unsubscribe) {
      try { unsubscribe(); } catch (error) {}
      unsubscribe = null;
    }
    subscribedCmp = null;
    if (!cmp || typeof cmp.getState !== 'function' || typeof cmp.subscribe !== 'function') return;
    var initializing = true;
    var initialUpdate = null;
    var receivedUpdate = false;
    try {
      unsubscribe = cmp.subscribe(function (updated) {
        if (initializing) { initialUpdate = updated; receivedUpdate = true; return; }
        if (subscribedCmp === cmp) applyCmpState(updated);
      });
      if (typeof unsubscribe !== 'function') unsubscribe = null;
      var state = cmp.getState();
      subscribedCmp = cmp;
      initializing = false;
      if (receivedUpdate) {
        var initial = categoryState(initialUpdate);
        state = categoryState(state);
        Object.keys(state).forEach(function (category) {
          state[category] = state[category] === true && initial[category] === true;
        });
      }
      applyCmpState(state);
    } catch (error) {
      initializing = false;
      setConsent({});
    }
  }

  // Only bounded JSON-like own data properties enter snapshots. Getters and
  // prototype links are never traversed; caller objects are never modified.
  function snapshot(input) {
    var nodes = 0;
    var seen = new WeakSet();
    function clone(value, depth) {
      if (++nodes > 2048 || depth > 16) throw new Error('Snapshot limit');
      if (scalar(value)) return value;
      if (!value || typeof value !== 'object') return undefined;
      if (seen.has(value)) throw new Error('Cyclic state');
      if (!Array.isArray(value) && !record(value)) return undefined;
      if (Array.isArray(value) && value.length > 10001) throw new Error('Array limit');
      var keys = Object.keys(value);
      if (keys.length > 2048) throw new Error('Snapshot limit');
      seen.add(value);
      var copy = Array.isArray(value) ? new Array(value.length) : Object.create(null);
      keys.forEach(function (key) {
        if (denied.indexOf(key) !== -1 || bytes(key) > 256 || /[\x00-\x1f\x7f]/.test(key)) return;
        var member = own(value, key);
        copy[key] = member === MISSING ? undefined : clone(member, depth + 1);
      });
      seen.delete(value);
      return copy;
    }
    try { return clone(input, 0); } catch (error) { return undefined; }
  }

  function merge(base, update) {
    var output = Object.create(null);
    Object.keys(base).forEach(function (key) { output[key] = base[key]; });
    Object.keys(update).forEach(function (key) {
      if (update[key] === undefined) delete output[key];
      else output[key] = record(update[key]) && record(base[key]) ? merge(base[key], update[key]) : update[key];
    });
    return output;
  }

  function processEntry(entry) {
    var update = entry.value;
    if (!record(update)) return;
    var combined = snapshot(merge(dataState, update));
    if (!record(combined)) return;
    dataState = combined;
    var event = own(update, 'event');
    if (typeof event === 'string') fire('data_layer', event, combined, entry.grants);
  }

  function captureEntries(entries) {
    return entries.map(function (entry) {
      return {value: snapshot(entry), grants: categoryState(consentState)};
    });
  }

  function enqueue(entries) {
    if (pending.length + entries.length > 100) return;
    Array.prototype.push.apply(pending, entries);
    if (draining) return;
    draining = true;
    var count = 0;
    try {
      while (pending.length && count++ < 100) processEntry(pending.shift());
    } finally {
      pending = [];
      draining = false;
    }
  }

  function attachDataLayer() {
    if (!configured) return;
    try {
      var layer = own(window, 'dataLayer');
      if (layer === MISSING && !('dataLayer' in window)) { layer = []; window.dataLayer = layer; }
      if (!Array.isArray(layer)) return;
      var holder = layer;
      var originalPush = MISSING;
      for (var depth = 0; holder && depth < 4; depth++) {
        if (Object.getOwnPropertyDescriptor(holder, 'push')) { originalPush = own(holder, 'push'); break; }
        holder = Object.getPrototypeOf(holder);
      }
      if (typeof originalPush !== 'function') return;
      var queued = [];
      for (var index = 0; index < Math.min(own(layer, 'length'), 100); index++) {
        var entry = own(layer, String(index));
        if (entry !== MISSING) queued.push(entry);
      }
      layer.push = function () {
        var captured = this === layer ? captureEntries(Array.prototype.slice.call(arguments, 0, 100)) : [];
        var result = Reflect.apply(originalPush, this, arguments);
        if (this === layer) enqueue(captured);
        return result;
      };
      enqueue(captureEntries(queued));
    } catch (error) {
      // A frozen or incompatible dataLayer does not break other trigger types.
    }
  }

  function domEvent(type, eventName, event) {
    var detail;
    try { detail = snapshot(event.detail); } catch (error) { detail = undefined; }
    var context = Object.assign(Object.create(null), dataState);
    context.event = {type: eventName, detail: detail};
    fire(type, eventName, context);
  }

  function attachEvents() {
    var attached = Object.create(null);
    if (!configured) return;
    tagManagerConfig.tags.forEach(function (tag) {
      var when = trigger(tag);
      if (when.type !== 'document_event' && when.type !== 'window_event') return;
      var key = when.type + ':' + when.event;
      if (attached[key]) return;
      attached[key] = true;
      var target = when.type === 'document_event' ? document : window;
      target.addEventListener(when.event, function (event) { domEvent(when.type, when.event, event); });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    domReady = true;
    if (started) fire('dom_ready', null, dataState);
  });
  if (typeof window.addEventListener === 'function') window.addEventListener('load', function () {
    windowLoaded = true;
    soon(releaseHeldEvents);
    if (started) fire('window_load', null, dataState);
  });
  document.addEventListener('aggregate:consent-ready', connectConsentManager);
  // The tracker announces itself after its page view, however it was installed.
  document.addEventListener('aggregate:tracker-ready', trackerReady);
  connectConsentManager();
  attachEvents();
  attachDataLayer();
  started = true;
  loadLifecycle();
})();
