'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(process.env.AGGREGATE_CONSENT_SOURCE || path.join(__dirname, '../../public/consent.js'), 'utf8');
const KEY = 'analytics_consent_v1:Aggregate';

function target(object = {}) {
  const listeners = new Map();
  object.addEventListener = (name, callback) => {
    if (!listeners.has(name)) listeners.set(name, []);
    listeners.get(name).push(callback);
  };
  object.dispatchEvent = (event) => { for (const callback of listeners.get(event.type) || []) callback(event); };
  return object;
}

function run(saved, gpc, config = {}) {
  const values = new Map(saved === null ? [] : [[KEY, saved]]);
  const storage = {
    getItem: (key) => values.get(key) ?? null,
    setItem: (key, value) => { values.set(key, String(value)); },
    removeItem: (key) => { values.delete(key); }
  };
  const document = target({readyState: 'complete', querySelector: () => null});
  document.createElement = (tag) => {
    const node = target({tagName: tag.toUpperCase(), textContent: '', children: [], attributes: {}, style: {setProperty() {}}});
    node.setAttribute = (key, value) => { node.attributes[key] = String(value); };
    node.getAttribute = (key) => node.attributes[key] ?? null;
    node.appendChild = (child) => { node.children.push(child); return child; };
    node.focus = () => {};
    return node;
  };
  document.body = document.createElement('body');
  document.head = document.createElement('head');
  document.currentScript = {src: 'https://analytics.example.test/consent.js', nonce: ''};
  const window = target({localStorage: storage, sessionStorage: storage, dataLayer: [],
    navigator: {globalPrivacyControl: gpc}});
  const context = vm.createContext({window, document, console, URL,
    CustomEvent: class { constructor(type, details) { this.type = type; Object.assign(this, details); } }});
  const configuration = JSON.stringify({namespace: 'Aggregate', name: 'Shop', categories: ['marketing'], ...config});
  vm.runInContext(source.replace("var consentConfig = {namespace: 'Aggregate', name: 'Analytics'};",
    'var consentConfig = ' + configuration + ';'), context);
  return {window, values};
}

const layerEvents = (app) => app.window.dataLayer.filter((entry) => /^cmp_/.test(entry.event)).map((entry) => entry.event);

test('GPC overrides a saved grant: denied, stored, source gpc, consent then optout', () => {
  const app = run('{"analytics":true,"marketing":true}', true);
  const state = JSON.parse(JSON.stringify(app.window.AggregateCMP.state));
  assert.equal(state.source, 'gpc');
  assert.equal(state.categories.analytics, false);
  assert.equal(state.categories.marketing, false);
  assert.deepEqual(JSON.parse(app.values.get(KEY)), {analytics: false, marketing: false});
  assert.deepEqual(layerEvents(app), ['cmp_consent', 'cmp_optout']);
});

test('a saved grant stays when GPC is absent or not respected', () => {
  const plain = run('{"analytics":true,"marketing":true}', false);
  assert.equal(plain.window.AggregateCMP.state.source, 'saved');
  assert.equal(plain.window.AggregateCMP.state.categories.marketing, true);
  const ignored = run('{"analytics":true,"marketing":true}', true, {respectGpc: false});
  assert.equal(ignored.window.AggregateCMP.state.source, 'saved');
});
