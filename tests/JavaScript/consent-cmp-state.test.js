'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(process.env.AGGREGATE_CONSENT_SOURCE || path.join(__dirname, '../../public/consent.js'), 'utf8');
const NAMES = ['cmp_ready', 'cmp_consent', 'cmp_change', 'cmp_optout'];
const REGIONS = [
  {id: 'US-TX', name: 'Texas', consentType: 'implicit_us'},
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

// A page with the consent script loaded, in region options.region (US-TX or DE).
function run(options = {}) {
  const values = options.values || new Map();
  const nodes = [];
  const events = [];
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
    nodes.push(node);
    return node;
  };
  document.body = document.createElement('body');
  document.head = document.createElement('head');
  document.currentScript = {src: 'https://analytics.example.test/consent.js', nonce: ''};
  const window = target({localStorage: storage, sessionStorage: storage, dataLayer: [],
    navigator: {globalPrivacyControl: options.gpc === true}});
  const context = vm.createContext({
    window, document, console, URL,
    CustomEvent: class { constructor(type, details) { this.type = type; Object.assign(this, details); } }
  });
  const configuration = JSON.stringify({namespace: 'Aggregate', name: 'Shop', categories: ['analytics', 'marketing'],
    regions: REGIONS, defaultRegion: options.region || 'US-TX', ...(options.config || {})});
  const configured = source.replace("var consentConfig = {namespace: 'Aggregate', name: 'Analytics'};", 'var consentConfig = ' + configuration + ';');
  const gateLog = [];
  for (const name of NAMES) {
    document.addEventListener(name, () => {
      events.push(name);
      if (name === 'cmp_ready' && options.gate) window.AggregateCMP.gate('marketing', () => gateLog.push('gate'));
      if (name === 'cmp_consent') gateLog.push('cmp_consent');
    });
  }
  const load = () => vm.runInContext(configured, context);
  load();
  return {
    window, values, events, gateLog, load,
    click: (label) => nodes.find((node) => node.textContent === label).dispatchEvent({type: 'click'}),
    layerEvents: () => window.dataLayer.filter((entry) => NAMES.includes(entry.event)).map((entry) => entry.event)
  };
}

const json = (value) => JSON.parse(JSON.stringify(value));

test('T1 implied first visit: ready then consent, source implied, gated script runs after consent', () => {
  const app = run({region: 'US-TX', gate: true});
  assert.deepEqual(app.events, ['cmp_ready', 'cmp_consent']);
  assert.deepEqual(app.layerEvents(), ['cmp_ready', 'cmp_consent']);
  const state = json(app.window.AggregateCMP.state);
  assert.equal(state.source, 'implied');
  assert.equal(state.mode, 'implied');
  assert.equal(state.region, 'US-TX');
  assert.equal(state.categories.analytics, true);
  assert.equal(state.categories.marketing, false);
  assert.equal(state.categories.necessary, true);
  assert.deepEqual(app.gateLog, ['cmp_consent']);
});

test('gate runs once when granted, at once if already granted, and never when denied', () => {
  const app = run({region: 'US-TX'});
  const log = [];
  app.window.AggregateCMP.gate('analytics', () => log.push('now'));
  app.window.AggregateCMP.gate('marketing', () => log.push('marketing'));
  assert.deepEqual(log, ['now']);
  app.click('Accept all optional categories');
  assert.deepEqual(log, ['now', 'marketing']);
  app.click('Accept all optional categories');
  assert.deepEqual(log, ['now', 'marketing']);
});

test('T2 explicit first visit: only cmp_ready, source default, gated script waits', () => {
  const app = run({region: 'DE', gate: true});
  assert.deepEqual(app.events, ['cmp_ready']);
  const state = json(app.window.AggregateCMP.state);
  assert.equal(state.source, 'default');
  assert.equal(state.mode, 'explicit');
  assert.equal(state.categories.analytics, false);
  assert.deepEqual(app.gateLog, []);
});

test('T3 allow all grants the gated script after the click', () => {
  const app = run({region: 'DE', gate: true});
  app.click('Accept all optional categories');
  assert.deepEqual(app.events, ['cmp_ready', 'cmp_consent']);
  assert.equal(app.window.AggregateCMP.state.source, 'explicit');
  assert.deepEqual(app.gateLog, ['cmp_consent', 'gate']);
});

test('T4 deny all: ready, consent and optout; the gated script never runs', () => {
  const app = run({region: 'DE', gate: true});
  app.click('Reject all optional categories');
  assert.deepEqual(app.events, ['cmp_ready', 'cmp_consent', 'cmp_optout']);
  assert.equal(app.window.AggregateCMP.state.categories.marketing, false);
  assert.deepEqual(app.gateLog, ['cmp_consent']);
});

test('T5 and T6 GPC denies in implied and explicit regions: consent then optout, source gpc', () => {
  for (const region of ['US-TX', 'DE']) {
    const app = run({region, gpc: true, gate: true});
    assert.deepEqual(app.events, ['cmp_consent', 'cmp_optout'], region);
    const state = json(app.window.AggregateCMP.state);
    assert.equal(state.source, 'gpc');
    assert.equal(state.signals.gpc, true);
    assert.equal(state.categories.analytics, false);
    assert.deepEqual(app.gateLog, ['cmp_consent']);
  }
});

test('T7 and T8 saved choices return as source saved with consent only', () => {
  const first = run({region: 'DE'});
  first.click('Accept all optional categories');
  const granted = run({region: 'DE', values: first.values, gate: true});
  assert.deepEqual(granted.events, ['cmp_consent']);
  assert.equal(granted.window.AggregateCMP.state.source, 'saved');
  assert.equal(granted.window.AggregateCMP.state.saved.exists, true);

  const denied = run({region: 'DE'});
  denied.click('Reject all optional categories');
  const returning = run({region: 'US-TX', values: denied.values});
  assert.deepEqual(returning.events, ['cmp_consent'], 'a saved denial beats the implied grant');
  assert.equal(returning.window.AggregateCMP.state.source, 'saved');
  assert.equal(returning.window.AggregateCMP.state.categories.analytics, false);
});

test('T10 opt-out after an implied grant fires change and optout and runs cleanup hooks', () => {
  const app = run({region: 'US-TX'});
  const cleaned = [];
  app.window.AggregateCMP.onOptOut((state) => cleaned.push(state.categories.analytics));
  app.window.AggregateCMP.openOptOut();
  app.click('Reject all optional categories');
  assert.deepEqual(app.events, ['cmp_ready', 'cmp_consent', 'cmp_change', 'cmp_optout']);
  assert.deepEqual(cleaned, [false]);
  const change = app.window.dataLayer.find((entry) => entry.event === 'cmp_change');
  assert.equal(change.previous.categories.analytics, true);
  assert.equal(change.state.source, 'explicit');
});

test('T17 a second copy of the script emits nothing again', () => {
  const app = run({region: 'US-TX'});
  app.load();
  assert.deepEqual(app.events, ['cmp_ready', 'cmp_consent']);
  assert.deepEqual(app.layerEvents(), ['cmp_ready', 'cmp_consent']);
});

test('the existing AggregateConsent API and aggregate_consent_update event still work', () => {
  const app = run({region: 'DE'});
  assert.equal(typeof app.window.AggregateConsent.setConsent, 'function');
  assert.ok(app.window.dataLayer.some((entry) => entry.event === 'aggregate_consent_update'));
});
