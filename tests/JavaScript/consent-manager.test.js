'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(process.env.AGGREGATE_CONSENT_SOURCE || path.join(__dirname, '../../public/consent.js'), 'utf8');
const tracker = fs.readFileSync(path.join(__dirname, '../../public/aggregate.js'), 'utf8');

function eventTarget(object = {}) {
  const listeners = new Map();
  object.addEventListener = (name, callback) => {
    if (!listeners.has(name)) listeners.set(name, []);
    listeners.get(name).push(callback);
  };
  object.dispatchEvent = (event) => {
    for (const callback of listeners.get(event.type) || []) callback(event);
  };
  return object;
}

function runtime(options = {}) {
  const namespace = options.namespace || 'Aggregate';
  const values = options.sharedValues || new Map(options.saved === undefined ? [] : [['analytics_consent_v1:' + namespace, options.saved]]);
  const sessionValues = new Map();
  const cookies = new Map();
  const allNodes = [];
  const requests = [];
  const storage = {
    getItem(key) { if (options.readBlocked) throw new Error('Blocked'); return values.get(key) ?? null; },
    setItem(key, value) { if (options.writeBlocked) throw new Error('Blocked'); values.set(key, String(value)); },
    removeItem(key) { if (options.removeBlocked) throw new Error('Blocked'); values.delete(key); }
  };
  const sessionStorage = {
    getItem: (key) => sessionValues.get(key) ?? null,
    setItem: (key, value) => sessionValues.set(key, String(value)),
    removeItem: (key) => sessionValues.delete(key)
  };
  const document = eventTarget({readyState: options.readyState || 'complete', referrer: ''});
  document.createElement = (tag) => {
    const node = eventTarget({tagName: tag.toUpperCase(), textContent: '', children: [], attributes: {}, style: {}, hidden: false});
    node.setAttribute = (key, value) => { node.attributes[key] = String(value); };
    node.appendChild = (child) => { node.children.push(child); return child; };
    node.focus = () => { document.activeElement = node; };
    allNodes.push(node);
    return node;
  };
  document.body = document.createElement('body');
  document.head = document.createElement('head');
  document.currentScript = {dataset: {namespace}, src: '', nonce: options.nonce || ''};
  document.getElementsByTagName = () => [document.currentScript];
  Object.defineProperty(document, 'cookie', {
    get: () => [...cookies].map(([key, value]) => key + '=' + value).join('; '),
    set: (value) => {
      const [key, entry] = value.split(';')[0].split('=');
      if (/max-age=0(?:;|$)/i.test(value)) cookies.delete(key);
      else cookies.set(key, entry);
    }
  });
  const window = eventTarget({localStorage: storage, innerWidth: 1280});
  window[namespace] = {endpoint: 'https://analytics.example.test/api/receive', websiteToken: 'public-site-token'};
  const tagChoices = [];
  if (options.tags !== false) window.AggregateTags = {setConsent: (value) => tagChoices.push(JSON.parse(JSON.stringify(value)))};
  const context = vm.createContext({
    window, document, localStorage: storage, sessionStorage,
    CustomEvent: class { constructor(type, details) { this.type = type; Object.assign(this, details); } },
    console, URL, screen: {width: 1440},
    self: {crypto: {randomUUID: () => '00000000-0000-4000-8000-000000000001'}},
    location: {origin: 'https://example.test', pathname: '/pricing', protocol: 'https:', search: ''},
    setTimeout: () => {},
    fetch: (url, request) => { requests.push(JSON.parse(request.body)); return Promise.resolve({ok: true}); }
  });
  window.location = context.location;
  const configuration = JSON.stringify({namespace, name: options.name || 'Example Analytics', categories: options.categories || ['analytics'], siteId: options.siteId});
  const configured = source.replace("var consentConfig = {namespace: 'Aggregate', name: 'Analytics'};", 'var consentConfig = ' + configuration + ';')
    .replaceAll('__AGGREGATE_CONSENT_CONFIG__', configuration);
  const load = () => vm.runInContext(configured, context);
  if (options.trackerFirst) vm.runInContext(tracker, context);
  let ready = 0;
  document.addEventListener('aggregate:consent-ready', () => { ready++; });
  load();
  return {
    window, document, values, sessionValues, cookies, tagChoices, requests, allNodes, options,
    load, get ready() { return ready; },
    loadTracker: () => vm.runInContext(tracker, context),
    findText: (text) => allNodes.find((node) => node.textContent === text),
    panel: () => allNodes.find((node) => node.attributes.role === 'dialog')
  };
}

test('fresh consent starts denied and presents equal accept/reject actions without remote submissions', () => {
  const app = runtime();
  assert.equal(app.window.AggregateConsent.getState().analytics, false);
  assert.equal(app.window.Aggregate.consent, false);
  assert.deepEqual(app.tagChoices, [{analytics: false}]);
  assert.equal(app.ready, 1);
  assert.equal(app.panel().hidden, false);
  assert.equal(app.panel().attributes['aria-labelledby'], 'ac-consent-title');
  assert.equal(app.findText('Accept all optional categories').tagName, 'BUTTON');
  assert.equal(app.findText('Reject all optional categories').tagName, 'BUTTON');
  assert.equal(app.findText('Example Analytics: privacy choices').tagName, 'H2');
  assert.equal(app.allNodes.some((node) => ['SCRIPT', 'FORM', 'IFRAME', 'IMG'].includes(node.tagName)), false);
  assert.deepEqual(app.requests, []);
  assert.equal(app.values.size, 0);
});

test('only an actual boolean true grants consent; unknown values deny', () => {
  const app = runtime();
  const api = app.window.AggregateConsent;
  for (const value of ['true', 'false', 'granted', 1, {}, [], null, undefined]) {
    api.setConsent(value);
    assert.equal(api.getState().analytics, false);
    assert.equal(app.tagChoices.at(-1).analytics, false);
  }
  app.findText('Accept all optional categories').dispatchEvent({type: 'click'});
  assert.equal(api.getState().analytics, true);
  assert.deepEqual(JSON.parse(app.values.get('analytics_consent_v1:Aggregate')), {analytics: true});
  assert.equal(app.panel().hidden, true);
  app.findText('Reject all optional categories').dispatchEvent({type: 'click'});
  assert.equal(api.getState().analytics, false);
  assert.deepEqual(JSON.parse(app.values.get('analytics_consent_v1:Aggregate')), {analytics: false});
});

test('remembered boolean choices are restored while malformed values and blocked storage fail closed', () => {
  for (const saved of ['"true"', '"false"', '1', '{}', '[]', '{broken', 'null']) {
    assert.equal(runtime({saved}).window.AggregateConsent.getState().analytics, false, saved);
  }
  const allowed = runtime({saved: 'true'});
  assert.equal(allowed.window.AggregateConsent.getState().analytics, true);
  assert.equal(allowed.panel().hidden, true);
  assert.equal(runtime({saved: 'false'}).window.AggregateConsent.getState().analytics, false);
  assert.equal(runtime({saved: 'true', readBlocked: true}).window.AggregateConsent.getState().analytics, false);
  assert.equal(runtime({saved: 'true', writeBlocked: true}).window.AggregateConsent.getState().analytics, false);
});

test('different website instances on the same origin and namespace never reuse affirmative consent', () => {
  const sharedValues = new Map();
  const common = {sharedValues, namespace: 'CompanyAnalytics', categories: ['analytics', 'marketing']};
  const first = runtime({...common, siteId: 'store-a'});
  first.window.AggregateConsent.setConsent({analytics: true, marketing: true});
  assert.deepEqual(JSON.parse(sharedValues.get('analytics_consent_v1:CompanyAnalytics:store-a')), {analytics: true, marketing: true});

  const second = runtime({...common, siteId: 'store-b'});
  assert.deepEqual(second.tagChoices.at(-1), {analytics: false, marketing: false});
  assert.equal(second.window.CompanyAnalytics.consent, false);
  assert.equal(second.panel().hidden, false);
  second.window.AggregateConsent.setConsent({analytics: false, marketing: true});
  assert.deepEqual(JSON.parse(sharedValues.get('analytics_consent_v1:CompanyAnalytics:store-b')), {analytics: false, marketing: true});

  const firstAgain = runtime({...common, siteId: 'store-a'});
  assert.deepEqual(firstAgain.tagChoices.at(-1), {analytics: true, marketing: true});
  assert.equal(firstAgain.panel().hidden, true);
  assert.equal(sharedValues.has('analytics_consent_v1:CompanyAnalytics'), false);
});

test('the same website restores only its currently configured categories and asks about newly added ones', () => {
  const sharedValues = new Map();
  const first = runtime({sharedValues, siteId: 'store-a', categories: ['analytics', 'marketing']});
  first.window.AggregateConsent.setConsent({analytics: true, marketing: true});

  const narrowed = runtime({sharedValues, siteId: 'store-a', categories: ['analytics']});
  assert.deepEqual(narrowed.tagChoices.at(-1), {analytics: true});
  assert.equal(Object.prototype.hasOwnProperty.call(narrowed.window.AggregateConsent.getState(), 'marketing'), false);
  assert.equal(narrowed.panel().hidden, true);

  const changed = runtime({sharedValues, siteId: 'store-a', categories: ['analytics', 'functional']});
  assert.deepEqual(changed.tagChoices.at(-1), {analytics: true, functional: false});
  assert.equal(changed.panel().hidden, false);
  assert.equal(Object.prototype.hasOwnProperty.call(changed.window.AggregateConsent.getState(), 'marketing'), false);
});

test('legacy unscoped stored choices do not grant consent to a website-scoped instance', () => {
  for (const saved of ['true', '{"analytics":true,"marketing":true}']) {
    const sharedValues = new Map([['analytics_consent_v1:Aggregate', saved]]);
    const scoped = runtime({sharedValues, siteId: 'store-a', categories: ['analytics', 'marketing']});
    assert.deepEqual(scoped.tagChoices.at(-1), {analytics: false, marketing: false});
    assert.equal(scoped.window.Aggregate.consent, false);
    assert.equal(scoped.panel().hidden, false);
    assert.equal(sharedValues.get('analytics_consent_v1:Aggregate'), saved);
    assert.equal(sharedValues.has('analytics_consent_v1:Aggregate:store-a'), false);
  }
});

test('cross-tab storage events update only the matching website instance', () => {
  const sharedValues = new Map();
  const first = runtime({sharedValues, siteId: 'store-a', categories: ['analytics', 'marketing']});
  const second = runtime({sharedValues, siteId: 'store-b', categories: ['analytics', 'marketing']});
  first.window.AggregateConsent.setConsent({analytics: true, marketing: true});
  const key = 'analytics_consent_v1:Aggregate:store-a';
  const newValue = sharedValues.get(key);
  second.window.dispatchEvent({type: 'storage', key, newValue});
  second.window.dispatchEvent({type: 'storage', key: 'analytics_consent_v1:Aggregate', newValue});
  assert.deepEqual(second.tagChoices.at(-1), {analytics: false, marketing: false});

  const firstTab = runtime({sharedValues, siteId: 'store-a', categories: ['analytics', 'marketing']});
  first.window.AggregateConsent.setConsent({analytics: false, marketing: false});
  firstTab.window.dispatchEvent({type: 'storage', key, newValue: sharedValues.get(key)});
  assert.deepEqual(firstTab.tagChoices.at(-1), {analytics: false, marketing: false});
  assert.equal(firstTab.window.Aggregate.consent, false);
});

test('withdrawal still reaches tracker/tags when persistence fails and an independent subscriber throws', () => {
  const app = runtime({saved: 'true'});
  const received = [];
  app.window.AggregateConsent.subscribe(() => { throw new Error('Unrelated broken consumer'); });
  const unsubscribe = app.window.AggregateConsent.subscribe((state) => { received.push(state.analytics); });
  app.options.writeBlocked = true;
  app.window.AggregateConsent.setConsent(false);
  assert.equal(app.window.Aggregate.consent, false);
  assert.equal(app.tagChoices.at(-1).analytics, false);
  assert.deepEqual(received, [false]);
  assert.equal(app.values.has('analytics_consent_v1:Aggregate'), false);
  assert.equal(app.allNodes.some((node) => node.textContent.includes('could not be saved')), true);
  unsubscribe();
  app.window.AggregateConsent.setConsent(false);
  assert.deepEqual(received, [false]);
});

test('withdrawal clears real SDK identifiers and leaves later events anonymous in either load order', () => {
  for (const trackerFirst of [false, true]) {
    const app = runtime({trackerFirst, namespace: 'CompanyAnalytics'});
    if (!trackerFirst) app.loadTracker();
    const sdk = app.window.CompanyAnalytics;
    sdk.emit('button_click', {plan: 'private'});
    assert.equal(app.requests.at(-1).visitorId, undefined);
    app.window.AggregateConsent.setConsent(true);
    sdk.emit('button_click', {plan: 'private'});
    assert.equal(app.requests.at(-1).consentState, 'granted');
    assert.ok(app.requests.at(-1).visitorId);
    assert.ok(app.values.has('aggregate_visitor_id'));
    assert.ok(app.sessionValues.has('aggregate_session_id'));
    app.window.AggregateConsent.setConsent(false);
    assert.equal(app.values.has('aggregate_visitor_id'), false);
    assert.equal(app.sessionValues.has('aggregate_session_id'), false);
    assert.equal(app.cookies.has('aggregate_session'), false);
    sdk.emit('button_click', {plan: 'private'});
    const anonymous = app.requests.at(-1);
    assert.equal(anonymous.consentState, 'denied');
    assert.equal(anonymous.visitorId, undefined);
    assert.equal(anonymous.sessionId, undefined);
    assert.equal(anonymous.screenWidth, undefined);
    assert.equal(anonymous.eventData, undefined);
    assert.equal(app.tagChoices.at(-1).analytics, false);
  }
});

test('choice changes and removals from another tab update consumers without re-persisting the event', () => {
  const app = runtime({saved: 'true'});
  for (const newValue of ['false', '"true"', '{broken', null]) {
    app.window.dispatchEvent({type: 'storage', key: 'analytics_consent_v1:Aggregate', newValue});
    assert.equal(app.window.AggregateConsent.getState().analytics, false);
    assert.equal(app.tagChoices.at(-1).analytics, false);
  }
  app.window.dispatchEvent({type: 'storage', key: 'analytics_consent_v1:Aggregate', newValue: 'true'});
  assert.equal(app.window.AggregateConsent.getState().analytics, true);
  app.window.dispatchEvent({type: 'storage', key: null, newValue: null});
  assert.equal(app.window.AggregateConsent.getState().analytics, false);
  assert.equal(app.panel().hidden, false);
  app.window.dispatchEvent({type: 'storage', key: 'unrelated', newValue: 'true'});
  assert.equal(app.window.AggregateConsent.getState().analytics, false);
});

test('controls can be reopened by keyboard and Escape dismisses without granting consent', () => {
  const app = runtime({readyState: 'loading'});
  assert.equal(app.panel(), undefined);
  app.document.dispatchEvent({type: 'DOMContentLoaded'});
  app.window.AggregateConsent.open();
  assert.equal(app.document.activeElement.id, 'ac-consent-title');
  app.panel().dispatchEvent({type: 'keydown', key: 'Escape'});
  assert.equal(app.panel().hidden, true);
  assert.equal(app.document.activeElement.textContent, 'Privacy choices');
  assert.equal(app.window.AggregateConsent.getState().analytics, false);
  app.document.activeElement.dispatchEvent({type: 'click'});
  assert.equal(app.panel().hidden, false);
  const count = app.allNodes.length;
  app.load();
  assert.equal(app.allNodes.length, count);
  assert.equal(app.ready, 1);
});

test('named category choices are independent, known categories only, and reach tags as a full map', () => {
  const app = runtime({categories: ['analytics', 'marketing', 'functional', 'none']});
  const api = app.window.AggregateConsent;
  assert.deepEqual(app.tagChoices.at(-1), {analytics: false, marketing: false, functional: false});
  api.setConsent(true);
  assert.deepEqual(app.tagChoices.at(-1), {analytics: true, marketing: false, functional: false});
  api.setConsent({marketing: true, functional: 'true', unknown: true});
  assert.deepEqual(app.tagChoices.at(-1), {analytics: false, marketing: true, functional: false});
  assert.equal(app.window.Aggregate.consent, false);
  api.setConsent(false);
  assert.deepEqual(app.tagChoices.at(-1), {analytics: false, marketing: true, functional: false});
  app.findText('Reject all optional categories').dispatchEvent({type: 'click'});
  assert.deepEqual(app.tagChoices.at(-1), {analytics: false, marketing: false, functional: false});
  api.open();
  app.allNodes.find((node) => node.id === 'ac-consent-functional').checked = true;
  app.findText('Save selected choices').dispatchEvent({type: 'click'});
  assert.deepEqual(app.tagChoices.at(-1), {analytics: false, marketing: false, functional: true});
  app.findText('Accept all optional categories').dispatchEvent({type: 'click'});
  assert.deepEqual(app.tagChoices.at(-1), {analytics: true, marketing: true, functional: true});
  assert.equal(app.allNodes.some((node) => node.id === 'ac-consent-none'), false);
});

test('remembered legacy analytics cannot grant new categories; new or malformed choices prompt review', () => {
  const categories = ['analytics', 'marketing'];
  const legacy = runtime({categories, saved: 'true'});
  assert.deepEqual(legacy.tagChoices.at(-1), {analytics: true, marketing: false});
  assert.equal(legacy.panel().hidden, false);
  const newer = runtime({categories, saved: '{"analytics":true}'});
  assert.deepEqual(newer.tagChoices.at(-1), {analytics: true, marketing: false});
  assert.equal(newer.panel().hidden, false);
  const valid = runtime({categories, saved: '{"analytics":false,"marketing":true}'});
  assert.deepEqual(valid.tagChoices.at(-1), {analytics: false, marketing: true});
  assert.equal(valid.panel().hidden, true);
  const invalid = runtime({categories, saved: '{"analytics":"true","marketing":"false"}'});
  assert.deepEqual(invalid.tagChoices.at(-1), {analytics: false, marketing: false});
  assert.equal(invalid.panel().hidden, false);
});

test('cross-tab category changes replace every grant and update checkbox values', () => {
  const app = runtime({categories: ['analytics', 'marketing'], saved: '{"analytics":true,"marketing":true}'});
  app.window.dispatchEvent({type: 'storage', key: 'analytics_consent_v1:Aggregate', newValue: '{"marketing":true}'});
  assert.deepEqual(app.tagChoices.at(-1), {analytics: false, marketing: true});
  assert.equal(app.allNodes.find((node) => node.id === 'ac-consent-analytics').checked, false);
  assert.equal(app.allNodes.find((node) => node.id === 'ac-consent-marketing').checked, true);
  app.window.dispatchEvent({type: 'storage', key: 'analytics_consent_v1:Aggregate', newValue: 'true'});
  assert.deepEqual(app.tagChoices.at(-1), {analytics: true, marketing: false});
});

test('inherited map grants and malformed updates cannot preserve or acquire optional consent', () => {
  const app = runtime({categories: ['analytics', 'marketing']});
  const api = app.window.AggregateConsent;
  api.setConsent(Object.create({analytics: true, marketing: true}));
  assert.deepEqual(app.tagChoices.at(-1), {analytics: false, marketing: false});
  for (const invalid of [null, undefined, 'false', 'true', [], 1]) {
    api.setConsent({analytics: true, marketing: true});
    api.setConsent(invalid);
    assert.deepEqual(app.tagChoices.at(-1), {analytics: false, marketing: false});
  }
  const getter = {analytics: true, get marketing() { throw new Error('Invalid consent accessor'); }};
  api.setConsent(getter);
  assert.deepEqual(app.tagChoices.at(-1), {analytics: true, marketing: false});
});

test('the current script nonce is preserved on the CMP stylesheet after deferred mounting', () => {
  const app = runtime({readyState: 'loading', nonce: 'nonce-example'});
  app.document.currentScript = null;
  app.document.dispatchEvent({type: 'DOMContentLoaded'});
  assert.equal(app.allNodes.find((node) => node.tagName === 'STYLE').nonce, 'nonce-example');
});
