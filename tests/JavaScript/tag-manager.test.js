const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(process.env.AGGREGATE_TAG_SOURCE || path.join(__dirname, '../../public/tag-manager.js'), 'utf8');
const defaults = 'var tagManagerConfig = {enabled: false, tags: []};';
const configuration = {enabled: true, tags: [{id: 'analytics', src: 'https://scripts.example/analytics.js', consent: 'analytics'}]};

function consentManager(initial = false) {
  let state = {analytics: initial};
  const callbacks = new Set();
  return {
    getState: () => state,
    subscribe: (callback) => { callbacks.add(callback); return () => callbacks.delete(callback); },
    update: (analytics) => { state = {analytics}; for (const callback of callbacks) callback(state); },
    subscriberCount: () => callbacks.size
  };
}

function runtime({config = configuration, cmp, ready = true, nonce, dataLayer, globals = {}} = {}) {
  const appended = [];
  const listeners = new Map();
  const windowListeners = new Map();
  const window = {...globals};
  window.addEventListener = (event, callback) => {
    if (!windowListeners.has(event)) windowListeners.set(event, []);
    windowListeners.get(event).push(callback);
  };
  if (dataLayer !== undefined) window.dataLayer = dataLayer;
  if (cmp) window.AggregateConsent = cmp;
  const document = {
    readyState: ready ? 'complete' : 'loading',
    currentScript: {nonce},
    head: {appendChild: (script) => appended.push(script)},
    createElement: (tag) => ({tag, attributes: {}, setAttribute(name, value) { this.attributes[name] = value; }}),
    addEventListener: (event, callback) => {
      if (!listeners.has(event)) listeners.set(event, []);
      listeners.get(event).push(callback);
    }
  };
  const context = vm.createContext({window, document, URL});
  const configuredSource = source.replace(defaults, 'var tagManagerConfig = ' + JSON.stringify(config) + ';')
    .replaceAll('__AGGREGATE_TAG_MANAGER__', JSON.stringify(config));
  const run = () => vm.runInContext(configuredSource, context);
  const dispatch = (event, detail) => { for (const callback of listeners.get(event) || []) callback({type: event, detail}); };
  const dispatchWindow = (event, detail) => { for (const callback of windowListeners.get(event) || []) callback({type: event, detail}); };
  run();
  return {window, document, appended, run, dispatch, dispatchWindow};
}

function scriptTag(id, trigger, consent = 'none', src = 'https://scripts.example/' + id + '.js') {
  return {id, type: 'script', src, consent, trigger};
}

function callTag(id, trigger, args = [], consent = 'none', method = 'Library.track') {
  return {id, type: 'call', method, args, consent, trigger};
}

test('document-ready and window-load triggers follow their distinct lifecycle', () => {
  const page = runtime({ready: false, config: {enabled: true, tags: [
    scriptTag('dom', {type: 'dom_ready'}), scriptTag('load', {type: 'window_load'})
  ]}});
  assert.equal(page.appended.length, 0);
  page.dispatch('DOMContentLoaded');
  assert.deepEqual(page.appended.map((tag) => tag.attributes['data-aggregate-tag']), ['dom']);
  page.dispatchWindow('load');
  assert.deepEqual(page.appended.map((tag) => tag.attributes['data-aggregate-tag']), ['dom', 'load']);
  page.dispatchWindow('load');
  page.dispatch('DOMContentLoaded');
  assert.equal(page.appended.length, 2);
});

test('document and window event triggers match their configured target and exact name', () => {
  const page = runtime({config: {enabled: true, tags: [
    scriptTag('document', {type: 'document_event', event: 'cart:updated'}),
    scriptTag('window', {type: 'window_event', event: 'cart:updated'})
  ]}});
  assert.equal(page.appended.length, 0);
  page.dispatch('Cart:updated');
  assert.equal(page.appended.length, 0);
  page.dispatch('cart:updated');
  assert.deepEqual(page.appended.map((tag) => tag.attributes['data-aggregate-tag']), ['document']);
  page.dispatchWindow('cart:updated');
  assert.deepEqual(page.appended.map((tag) => tag.attributes['data-aggregate-tag']), ['document', 'window']);
});

test('a denied event is discarded and not replayed after its category is granted', () => {
  const page = runtime({config: {enabled: true, tags: [scriptTag('event', {type: 'document_event', event: 'purchase'}, 'marketing')]}});
  page.dispatch('purchase');
  page.window.AggregateTags.setConsent({marketing: true});
  assert.equal(page.appended.length, 0);
  page.dispatch('purchase');
  assert.equal(page.appended.length, 1);
});

test('queued dataLayer events use merged state, encode variables, and preserve existing push behavior', () => {
  const queued = [{ecommerce: {items: [{id: 'A & B/?#'}]}}, {event: 'purchase'}];
  const original = Array.prototype.push;
  const pushed = [];
  queued.push = function (...entries) { pushed.push(...entries); original.apply(this, entries); return 'existing-wrapper-result'; };
  const before = JSON.stringify(queued);
  const page = runtime({dataLayer: queued, cmp: consentManager(true), config: {enabled: true, variables: {productId: 'ecommerce.items[0].id'}, tags: [
    scriptTag('purchase', {type: 'data_layer', event: 'purchase'}, 'analytics', 'https://scripts.example/purchase.js?product={{productId}}')
  ]}});
  assert.equal(page.appended.length, 1);
  assert.equal(page.appended[0].src, 'https://scripts.example/purchase.js?product=A%20%26%20B%2F%3F%23');
  assert.equal(JSON.stringify(queued), before);
  assert.equal(page.window.dataLayer.push({event: 'purchase'}), 'existing-wrapper-result');
  assert.equal(pushed.length, 1);
  assert.equal(page.appended.length, 1);
});

test('dataLayer merges objects, replaces arrays, clears null and triggers only an own newly pushed event', () => {
  const calls = [];
  const page = runtime({globals: {Library: {track: (...args) => calls.push(args)}}, config: {enabled: true, variables: {id: 'ecommerce.items[0].id'}, tags: [
    callTag('purchase', {type: 'data_layer', event: 'purchase'}, [{$var: 'id'}])
  ]}});
  const layer = page.window.dataLayer;
  layer.push({ecommerce: {items: [{id: 'first'}, {id: 'second'}]}});
  layer.push({event: 'purchase'});
  layer.push({ecommerce: {currency: 'USD'}});
  assert.deepEqual(calls, [['first']]);
  layer.push({ecommerce: {items: [{id: 'replacement'}]}, event: 'purchase'});
  layer.push({ecommerce: null, event: 'purchase'});
  layer.push(Object.create({event: 'purchase'}));
  assert.deepEqual(calls, [['first'], ['replacement']]);
});

test('calls preserve the library receiver, repeat event triggers, and clone structured literal arguments', () => {
  const calls = [];
  const library = {prefix: 'receiver', track(...args) { calls.push({receiver: this.prefix, args}); args[1].quantity = 999; }};
  const page = runtime({globals: {Library: library}, config: {enabled: true, variables: {product: 'event.detail.product.id'}, tags: [
    callTag('purchase', {type: 'window_event', event: 'purchase'}, ['purchase_completed', {product: {$var: 'product'}, quantity: 2, active: false, optional: null}])
  ]}});
  page.dispatchWindow('purchase', {product: {id: 'one'}});
  page.dispatchWindow('purchase', {product: {id: 'two'}});
  assert.equal(calls.length, 2);
  assert.equal(calls[0].receiver, 'receiver');
  assert.equal(calls[0].args[1].product, 'one');
  assert.equal(calls[1].args[1].product, 'two');
  assert.equal(calls[1].args[1].active, false);
});

test('missing methods and variables skip a call until a later matching event without partial args', () => {
  const calls = [];
  const page = runtime({config: {enabled: true, variables: {id: 'event.detail.id'}, tags: [
    callTag('event', {type: 'document_event', event: 'submit'}, ['submit', {$var: 'id'}])
  ]}});
  page.dispatch('submit', {id: 'before-library'});
  page.window.Library = {track: (...args) => calls.push(args)};
  page.dispatch('submit', {});
  page.dispatch('submit', {id: {nested: 'invalid scalar'}});
  page.dispatch('submit', {id: null});
  page.dispatch('submit', {id: 'after-library'});
  assert.deepEqual(calls, [['submit', null], ['submit', 'after-library']]);
});

test('lifecycle call tags run once and respect consent granted after document readiness', () => {
  const calls = [];
  const page = runtime({globals: {Library: {track: () => calls.push('called')}}, config: {enabled: true, tags: [
    callTag('ready', {type: 'dom_ready'}, [], 'analytics')
  ]}});
  assert.deepEqual(calls, []);
  page.window.AggregateTags.setConsent(true);
  page.window.AggregateTags.setConsent(false);
  page.window.AggregateTags.setConsent(true);
  page.dispatch('DOMContentLoaded');
  assert.deepEqual(calls, ['called']);
});

test('event-time consent cannot be granted later while a dataLayer queue drains', () => {
  let page;
  const calls = [];
  const library = {
    start() {
      page.window.AggregateTags.setConsent(false);
      page.window.dataLayer.push({event: 'purchase'});
      page.window.AggregateTags.setConsent(true);
    },
    track() { calls.push('purchase'); }
  };
  page = runtime({globals: {Library: library}, config: {enabled: true, tags: [
    callTag('start', {type: 'data_layer', event: 'start'}, [], 'none', 'Library.start'),
    callTag('purchase', {type: 'data_layer', event: 'purchase'}, [], 'analytics')
  ]}});
  page.window.dataLayer.push({event: 'start'});
  assert.deepEqual(calls, []);
  page.window.dataLayer.push({event: 'purchase'});
  assert.deepEqual(calls, ['purchase']);
});

test('event-time values are immutable even when the caller mutates a queued record', () => {
  let page;
  const calls = [];
  page = runtime({globals: {Library: {
    start() { const event = {event: 'purchase', product: {id: 'original'}}; page.window.dataLayer.push(event); event.product.id = 'mutated'; },
    track: (value) => calls.push(value)
  }}, config: {enabled: true, variables: {id: 'product.id'}, tags: [
    callTag('start', {type: 'data_layer', event: 'start'}, [], 'none', 'Library.start'),
    callTag('purchase', {type: 'data_layer', event: 'purchase'}, [{$var: 'id'}])
  ]}});
  page.window.dataLayer.push({event: 'start'});
  assert.deepEqual(calls, ['original']);
});

test('recursive provider calls have a bounded dataLayer drain and retain a responsive bridge', () => {
  let page;
  let calls = 0;
  page = runtime({globals: {Library: {track() { calls++; page.window.dataLayer.push({event: 'loop'}); }}}, config: {enabled: true, tags: [
    callTag('loop', {type: 'data_layer', event: 'loop'})
  ]}});
  page.window.dataLayer.push({event: 'loop'});
  assert.equal(calls, 100);
  assert.equal(page.window.dataLayer.push({event: 'unrelated'}), 102);
  assert.equal(calls, 100);
});

test('getters, prototypes and cyclic records never supply variable values or invoke methods', () => {
  let reads = 0;
  const calls = [];
  const page = runtime({globals: {Library: {track: (...args) => calls.push(args)}}, config: {enabled: true, variables: {id: 'product.id'}, tags: [
    callTag('safe', {type: 'data_layer', event: 'purchase'}, [{$var: 'id'}])
  ]}});
  const getter = {event: 'purchase', product: {get id() { reads++; return 'private'; }}};
  const inherited = {event: 'purchase', product: Object.create({id: 'private'})};
  const cycle = {event: 'purchase'};
  cycle.product = cycle;
  page.window.dataLayer.push(getter, inherited, cycle);
  assert.equal(reads, 0);
  assert.deepEqual(calls, []);
  page.window.Library = Object.create({track: () => calls.push('inherited method')});
  page.window.dataLayer.push({event: 'purchase', product: {id: 'safe'}});
  assert.deepEqual(calls, []);
});

test('queue accessors are not executed and incompatible dataLayer cannot break ordinary tags', () => {
  let reads = 0;
  const layer = [];
  Object.defineProperty(layer, '0', {get() { reads++; return {event: 'purchase'}; }, configurable: true});
  const page = runtime({dataLayer: layer, config: {enabled: true, tags: [scriptTag('ready', {type: 'dom_ready'})]}});
  assert.equal(reads, 0);
  assert.equal(page.appended.length, 1);
  const frozen = runtime({dataLayer: Object.freeze([]), config: {enabled: true, tags: [scriptTag('ready', {type: 'dom_ready'})]}});
  assert.equal(frozen.appended.length, 1);
});

test('URL interpolation skips missing, non-scalar and oversized values without requests', () => {
  const page = runtime({config: {enabled: true, variables: {id: 'event.detail.id'}, tags: [
    scriptTag('event', {type: 'document_event', event: 'purchase'}, 'none', 'https://scripts.example/a.js?id={{id}}')
  ]}});
  for (const id of [undefined, null, {}, [], NaN, Infinity, 9007199254740992, '\ud800', 'é'.repeat(1024)]) page.dispatch('purchase', {id});
  assert.equal(page.appended.length, 0);
  page.dispatch('purchase', {id: false});
  assert.equal(page.appended[0].src, 'https://scripts.example/a.js?id=false');
});

test('no tag or browser identifier before explicit boolean consent', () => {
  const page = runtime();
  assert.deepEqual(page.appended, []);
  for (const value of [false, 'false', 'true', 0, 1, null, undefined, {}, []]) {
    page.window.AggregateTags.setConsent(value);
    assert.deepEqual(page.appended, []);
  }
  page.window.AggregateTags.setConsent(true);
  assert.equal(page.appended.length, 1);
  assert.equal(page.appended[0].src, configuration.tags[0].src);
  assert.equal(page.appended[0].referrerPolicy, 'no-referrer');
  assert.equal(page.appended[0].attributes['data-aggregate-tag'], 'analytics');
  assert.equal(page.appended[0].async, true);
});

test('disabled default and disabled configured manager ignore consent', () => {
  for (const config of [{enabled: false, tags: []}, {...configuration, enabled: false}]) {
    const page = runtime({config, cmp: consentManager(true)});
    page.window.AggregateTags.setConsent(true);
    assert.deepEqual(page.appended, []);
  }
});

function categorizedConfiguration() {
  return {enabled: true, tags: ['none', 'analytics', 'functional', 'marketing'].map((category) => ({
    id: category, src: 'https://scripts.example/' + category + '.js', consent: category
  }))};
}

test('the loader and an explicit no-consent tag work before any visitor choice', () => {
  const page = runtime({config: categorizedConfiguration()});
  assert.deepEqual(page.appended.map((tag) => tag.attributes['data-aggregate-tag']), ['none']);
  page.window.AggregateTags.setConsent(false);
  page.window.AggregateTags.setConsent({analytics: false, functional: false, marketing: false, none: false});
  assert.equal(page.appended.length, 1);
});

test('each category loads only after its own strict boolean grant', () => {
  const page = runtime({config: categorizedConfiguration()});
  page.window.AggregateTags.setConsent({analytics: true, functional: 'true', marketing: 'false'});
  assert.deepEqual(page.appended.map((tag) => tag.attributes['data-aggregate-tag']), ['none', 'analytics']);
  page.window.AggregateTags.setConsent({functional: true});
  assert.deepEqual(page.appended.map((tag) => tag.attributes['data-aggregate-tag']), ['none', 'analytics', 'functional']);
  page.window.AggregateTags.setConsent({marketing: 1});
  assert.equal(page.appended.length, 3);
  page.window.AggregateTags.setConsent({marketing: true});
  assert.equal(page.appended.length, 4);
});

test('replacing category grants revokes missing categories before DOM readiness', () => {
  const page = runtime({config: categorizedConfiguration(), ready: false});
  page.window.AggregateTags.setConsent({analytics: true, functional: true, marketing: true});
  page.window.AggregateTags.setConsent({functional: true});
  page.dispatch('DOMContentLoaded');
  assert.deepEqual(page.appended.map((tag) => tag.attributes['data-aggregate-tag']), ['none', 'functional']);
});

test('boolean shorthand changes analytics independently from other category choices', () => {
  const page = runtime({config: categorizedConfiguration(), ready: false});
  page.window.AggregateTags.setConsent({functional: true});
  page.window.AggregateTags.setConsent(true);
  page.window.AggregateTags.setConsent(false);
  page.dispatch('DOMContentLoaded');
  assert.deepEqual(page.appended.map((tag) => tag.attributes['data-aggregate-tag']), ['none', 'functional']);
});

test('inherited grants, malformed maps and truthy strings cannot authorize optional tags', () => {
  const page = runtime({config: categorizedConfiguration()});
  for (const state of [Object.create({analytics: true, marketing: true}), ['marketing'], {analytics: 'true'}, 'true', 1]) {
    page.window.AggregateTags.setConsent(state);
    assert.deepEqual(page.appended.map((tag) => tag.attributes['data-aggregate-tag']), ['none']);
  }
});

test('throwing category getters and proxies revoke the entire state without partial grants', () => {
  const broken = [
    {analytics: true, get marketing() { throw new Error('broken integration'); }},
    new Proxy({analytics: true}, {ownKeys() { throw new Error('broken integration'); }})
  ];
  const revoked = Proxy.revocable({analytics: true}, {});
  revoked.revoke();
  broken.push(revoked.proxy);
  for (const state of broken) {
    const page = runtime({config: categorizedConfiguration(), ready: false});
    page.window.AggregateTags.setConsent({analytics: true, marketing: true});
    assert.doesNotThrow(() => page.window.AggregateTags.setConsent(state));
    page.dispatch('DOMContentLoaded');
    assert.deepEqual(page.appended.map((tag) => tag.attributes['data-aggregate-tag']), ['none']);
  }
});

test('CMP state forwards all categories and rejects a malformed boolean state', () => {
  const cmp = {getState: () => ({analytics: false, marketing: true}), subscribe: () => () => {}};
  const page = runtime({config: categorizedConfiguration(), cmp});
  assert.deepEqual(page.appended.map((tag) => tag.attributes['data-aggregate-tag']), ['none', 'marketing']);
  const invalid = runtime({config: categorizedConfiguration(), cmp: {getState: () => true, subscribe: () => () => {}}});
  assert.deepEqual(invalid.appended.map((tag) => tag.attributes['data-aggregate-tag']), ['none']);
});

test('withdrawal cancels tags waiting for document readiness', () => {
  const page = runtime({ready: false});
  page.window.AggregateTags.setConsent(true);
  assert.equal(page.appended.length, 0);
  page.window.AggregateTags.setConsent(false);
  page.dispatch('DOMContentLoaded');
  assert.equal(page.appended.length, 0);
  page.window.AggregateTags.setConsent(true);
  assert.equal(page.appended.length, 1);
});

test('repeated inclusion, readiness and re-granted consent never insert a tag twice', () => {
  const page = runtime({cmp: consentManager(true)});
  assert.equal(page.appended.length, 1);
  page.run();
  page.dispatch('DOMContentLoaded');
  page.window.AggregateTags.setConsent(true);
  page.window.AggregateTags.setConsent(false);
  page.window.AggregateTags.setConsent(true);
  assert.equal(page.appended.length, 1);
});

test('an existing CMP grants only its strict analytics boolean and revokes pending loads', () => {
  for (const consent of [false, 'false', 'true', 1, null, undefined]) {
    const page = runtime({cmp: consentManager(consent)});
    assert.deepEqual(page.appended, []);
  }
  const cmp = consentManager(true);
  const page = runtime({cmp, ready: false});
  cmp.update(false);
  page.dispatch('DOMContentLoaded');
  assert.deepEqual(page.appended, []);
  cmp.update(true);
  assert.equal(page.appended.length, 1);
});

test('a CMP arriving after the manager connects on its ready event', () => {
  const page = runtime();
  const cmp = consentManager(false);
  page.window.AggregateConsent = cmp;
  page.dispatch('aggregate:consent-ready');
  page.dispatch('aggregate:consent-ready');
  assert.equal(cmp.subscriberCount(), 1);
  assert.equal(page.appended.length, 0);
  cmp.update(true);
  assert.equal(page.appended.length, 1);
});

test('replacing a CMP unsubscribes the previous manager and rejects stale callbacks', () => {
  const oldCmp = consentManager(true);
  const page = runtime({cmp: oldCmp, ready: false});
  const replacement = consentManager(false);
  page.window.AggregateConsent = replacement;
  page.dispatch('aggregate:consent-ready');
  assert.equal(oldCmp.subscriberCount(), 0);
  oldCmp.update(true);
  page.dispatch('DOMContentLoaded');
  assert.equal(page.appended.length, 0);
  replacement.update(true);
  assert.equal(page.appended.length, 1);
});

test('malformed CMP and exceptions cannot load tags during initialization', () => {
  for (const cmp of [
    {},
    {getState: () => ({analytics: true})},
    {getState() { throw new Error('broken'); }, subscribe: () => () => {}},
    {getState: () => ({analytics: true}), subscribe(callback) { callback({analytics: true}); throw new Error('broken'); }},
    {getState() { throw new Error('broken'); }, subscribe(callback) { callback({analytics: true}); return () => {}; }}
  ]) {
    const page = runtime({cmp});
    assert.equal(page.appended.length, 0);
  }
});

test('a synchronous rejection from subscribe takes precedence over an old snapshot', () => {
  const page = runtime({cmp: {
    getState: () => ({analytics: true}),
    subscribe(callback) { callback({analytics: false}); return () => {}; }
  }});
  assert.equal(page.appended.length, 0);
});

test('a current rejection in getState takes precedence over an old synchronous grant', () => {
  const page = runtime({cmp: {
    getState: () => ({analytics: false}),
    subscribe(callback) { callback({analytics: true}); return () => {}; }
  }});
  assert.equal(page.appended.length, 0);
});

test('conflicting initial category states grant only their common affirmative choices', () => {
  const page = runtime({config: categorizedConfiguration(), cmp: {
    getState: () => ({analytics: true, functional: true, marketing: false}),
    subscribe(callback) { callback({analytics: false, functional: true, marketing: true}); return () => {}; }
  }});
  assert.deepEqual(page.appended.map((tag) => tag.attributes['data-aggregate-tag']), ['none', 'functional']);
});

test('provider scripts receive the installation script CSP nonce', () => {
  const page = runtime({nonce: 'page-generated-nonce', cmp: consentManager(true)});
  assert.equal(page.appended[0].nonce, 'page-generated-nonce');
});

test('invalid runtime configuration fails closed before any provider request', () => {
  const invalid = [
    null, {}, {enabled: 'true', tags: []}, {enabled: true, tags: 'script'},
    {enabled: true, tags: [{id: 'analytics', src: 'http://scripts.example/a.js'}]},
    {enabled: true, tags: [{id: 'analytics', src: 'javascript:alert(1)'}]},
    {enabled: true, tags: [{id: 'analytics', src: 'https://user:pass@scripts.example/a.js'}]},
    {enabled: true, tags: [{id: 'analytics', src: 'https://scripts.example/a.js#'}]},
    {enabled: true, tags: [{id: 'unsafe id', src: 'https://scripts.example/a.js'}]},
    {enabled: true, tags: [{id: 'analytics', src: 'https://scripts.example/a\\b.js'}]},
    {enabled: true, tags: [configuration.tags[0], configuration.tags[0]]},
    {enabled: true, tags: [configuration.tags[0], {...configuration.tags[0], id: 'another'}]},
    {enabled: true, tags: Array.from({length: 21}, (_, i) => ({id: 'tag' + i, src: 'https://scripts.example/' + i + '.js'}))}
  ];
  for (const config of invalid) {
    if (config && Array.isArray(config.tags)) {
      for (const tag of config.tags) tag.consent ??= 'analytics';
    }
    const page = runtime({config, cmp: consentManager(true)});
    page.window.AggregateTags.setConsent(true);
    assert.equal(page.appended.length, 0);
  }
});
