'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(process.env.AGGREGATE_CONSENT_SOURCE || path.join(__dirname, '../../public/consent.js'), 'utf8');
const REGIONS = [
  {id: 'US-TX', name: 'Texas', consentType: 'implicit_us', defaultGranted: ['analytics', 'marketing']},
  {id: 'DE', name: 'Germany', consentType: 'explicit_eu'}
];

function target(object = {}) {
  const listeners = new Map();
  object.addEventListener = (name, callback) => {
    if (!listeners.has(name)) listeners.set(name, []);
    listeners.get(name).push(callback);
  };
  object.dispatchEvent = (event) => { for (const callback of listeners.get(event.type) || []) callback(event); };
  return object;
}

function run(options = {}) {
  const values = new Map();
  const storage = {
    getItem: (key) => values.get(key) ?? null,
    setItem: (key, value) => { values.set(key, String(value)); },
    removeItem: (key) => { values.delete(key); }
  };
  const document = target({readyState: 'complete'});
  document.createElement = (tag) => {
    const node = target({tagName: tag.toUpperCase(), textContent: '', children: [], attributes: {}, hidden: false,
      style: {setProperty() {}}});
    node.setAttribute = (key, value) => { node.attributes[key] = String(value); };
    node.getAttribute = (key) => node.attributes[key] ?? null;
    node.appendChild = (child) => { node.children.push(child); return child; };
    node.focus = () => {};
    return node;
  };
  document.body = document.createElement('body');
  document.head = document.createElement('head');
  document.currentScript = {src: 'https://analytics.example.test/consent.js', nonce: ''};
  const window = target({localStorage: storage, sessionStorage: storage, dataLayer: options.noLayer ? undefined : [],
    navigator: {globalPrivacyControl: options.gpc === true}});
  const warnings = [];
  if (options.optout !== undefined) document.querySelector = () => (options.optout ? {} : null);
  const context = vm.createContext({window, document, URL,
    console: {warn: (message) => warnings.push(message)},
    CustomEvent: class { constructor(type, details) { this.type = type; Object.assign(this, details); } }});
  const configuration = JSON.stringify({namespace: 'Aggregate', name: 'Shop', categories: ['analytics', 'marketing'],
    regions: REGIONS, defaultRegion: options.region || 'US-TX', googleConsentMode: options.gcm !== false,
    ...(options.config || {})});
  vm.runInContext(source.replace("var consentConfig = {namespace: 'Aggregate', name: 'Analytics'};",
    'var consentConfig = ' + configuration + ';'), context);
  return {window, document, warnings, layer: () => JSON.parse(JSON.stringify(window.dataLayer || []))};
}

const consentCalls = (app) => app.layer().filter((entry) => Array.isArray(entry) && entry[0] === 'consent');

test('T1: implied first visit pushes a denied default, then a granted update', () => {
  const calls = consentCalls(run());
  assert.equal(calls.length, 2);
  assert.deepEqual(calls[0].slice(0, 2), ['consent', 'default']);
  assert.equal(calls[0][2].analytics_storage, 'denied');
  assert.equal(calls[0][2].ad_storage, 'denied');
  assert.equal(calls[0][2].wait_for_update, 2000);
  assert.deepEqual(calls[1].slice(0, 2), ['consent', 'update']);
  assert.equal(calls[1][2].analytics_storage, 'granted');
  assert.equal(calls[1][2].ad_user_data, 'granted');
  assert.equal(calls[1][2].functionality_storage, 'denied');
  const order = run().layer().map((entry) => (Array.isArray(entry) ? entry[1] : entry.event));
  assert.ok(order.indexOf('update') < order.indexOf('cmp_consent'), 'the update comes before cmp_consent');
});

test('T2: explicit first visit has a denied default and no update', () => {
  const calls = consentCalls(run({region: 'DE'}));
  assert.deepEqual(calls.map((call) => call[1]), ['default']);
});

test('T3, T4: a choice pushes one update; Deny All pushes denied', () => {
  const allow = run({region: 'DE'});
  allow.window.AggregateConsent.setConsent({analytics: true, marketing: true});
  assert.deepEqual(consentCalls(allow).map((call) => call[1]), ['default', 'update']);
  assert.equal(consentCalls(allow)[1][2].ad_storage, 'granted');
  const deny = run({region: 'DE'});
  deny.window.AggregateConsent.setConsent({});
  assert.equal(consentCalls(deny)[1][2].analytics_storage, 'denied');
});

test('T5: GPC in an implied region pushes a denied update', () => {
  const calls = consentCalls(run({gpc: true}));
  assert.deepEqual(calls.map((call) => call[1]), ['default', 'update']);
  assert.equal(calls[1][2].analytics_storage, 'denied');
});

test('wait_for_update is configurable; the adapter is off by default and creates a layer when on', () => {
  assert.equal(consentCalls(run({config: {waitForUpdate: 500}}))[0][2].wait_for_update, 500);
  assert.equal(consentCalls(run({config: {waitForUpdate: 'x'}}))[0][2].wait_for_update, 2000);
  assert.equal(consentCalls(run({gcm: false})).length, 0);
  assert.equal(run({noLayer: true, gcm: false}).window.dataLayer, undefined);
  assert.equal(consentCalls(run({noLayer: true})).length, 2);
});

test('an unchanged state is not pushed twice', () => {
  const app = run({region: 'DE'});
  app.window.AggregateConsent.setConsent({analytics: true});
  app.window.AggregateConsent.setConsent({analytics: true});
  assert.equal(consentCalls(app).filter((call) => call[1] === 'update').length, 1);
});

test('an implied page with no data-cmp-optout element logs a warning; one with it does not', () => {
  assert.equal(run({optout: false}).warnings.length, 1);
  assert.equal(run({optout: true}).warnings.length, 0);
  assert.equal(run({optout: false, region: 'DE'}).warnings.length, 0);
});

test('a click on a data-cmp-optout element opens the opt-out through delegation', () => {
  const app = run({optout: true});
  let prevented = false;
  const link = {};
  const event = {type: 'click', target: {closest: (selector) => (selector === '[data-cmp-optout]' ? link : null)},
    preventDefault: () => { prevented = true; }};
  app.document.dispatchEvent(event);
  assert.equal(prevented, true);
});

test('a withdrawal in another tab pushes a denied update', () => {
  const app = run({region: 'DE'});
  app.window.AggregateConsent.setConsent({analytics: true, marketing: true});
  app.window.dispatchEvent({type: 'storage', key: null, newValue: null});
  const updates = consentCalls(app).filter((call) => call[1] === 'update');
  assert.equal(updates.length, 2);
  assert.equal(updates[1][2].analytics_storage, 'denied');
  assert.equal(updates[1][2].ad_storage, 'denied');
});
