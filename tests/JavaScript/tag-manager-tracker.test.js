'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

function storage() {
  const values = new Map();
  return {
    getItem: (key) => values.get(key) ?? null,
    setItem: (key, value) => values.set(key, String(value)),
    removeItem: (key) => values.delete(key),
    has: (key) => values.has(key)
  };
}

test('event method tags preserve tracker consent and anonymous payload boundaries', () => {
  const requests = [];
  const listeners = new Map();
  const listen = (name, listener) => {
    if (!listeners.has(name)) listeners.set(name, []);
    listeners.get(name).push(listener);
  };
  const localStorage = storage();
  const sessionStorage = storage();
  const script = {dataset: {}, src: 'https://analytics.example/aggregate.js'};
  const document = {
    readyState: 'complete', currentScript: script, referrer: '', cookie: '',
    addEventListener: listen,
    getElementsByTagName: () => [script],
    head: {appendChild: () => assert.fail('A method-only tag must not insert a script')}
  };
  const location = {origin: 'https://shop.example', pathname: '/checkout', protocol: 'https:', search: ''};
  const window = {
    Aggregate: {endpoint: 'https://analytics.example/api/receive', websiteToken: 'public-test-token', consent: false},
    addEventListener: listen,
    location
  };
  const context = vm.createContext({
    window, document, location, localStorage, sessionStorage, URL,
    screen: {width: 1280}, self: {crypto: {randomUUID: () => '00000000-0000-4000-8000-000000000001'}},
    setTimeout: () => {}, console: {warn: () => {}},
    fetch: (_url, request) => { requests.push(JSON.parse(request.body)); return Promise.resolve({ok: true}); }
  });
  const sdk = fs.readFileSync(process.env.AGGREGATE_SDK_SOURCE || path.join(__dirname, '../../public/aggregate.js'), 'utf8');
  vm.runInContext(sdk, context);
  const configuration = {
    enabled: true,
    variables: {amount: 'ecommerce.total_minor', product: 'ecommerce.items[0].sku'},
    tags: [{
      id: 'purchase', type: 'call', method: 'Aggregate.emit',
      args: ['purchase', {total_minor: {$var: 'amount'}, product: {$var: 'product'}}],
      consent: 'marketing', trigger: {type: 'data_layer', event: 'purchase'}
    }]
  };
  const source = fs.readFileSync(process.env.AGGREGATE_TAG_SOURCE || path.join(__dirname, '../../public/tag-manager.js'), 'utf8')
    .replace('var tagManagerConfig = {enabled: false, tags: []};', 'var tagManagerConfig = ' + JSON.stringify(configuration) + ';')
    .replaceAll('__AGGREGATE_TAG_MANAGER__', JSON.stringify(configuration));
  vm.runInContext(source, context);
  const purchase = (amount) => window.dataLayer.push({event: 'purchase', ecommerce: {total_minor: amount, items: [{sku: 'sku-example'}]}});

  purchase(1000);
  assert.equal(requests.length, 0, 'a denied event must not execute');
  window.AggregateTags.setConsent({marketing: true});
  assert.equal(requests.length, 0, 'granting a category must not replay denied events');
  purchase(2000);
  assert.equal(requests.length, 1);
  assert.equal(requests[0].eventName, 'purchase');
  assert.equal(requests[0].consentState, 'denied');
  assert.equal(requests[0].visitorId, undefined);
  assert.equal(requests[0].sessionId, undefined);
  assert.equal(requests[0].eventData, undefined, 'a tag category cannot whitelist custom analytics properties');
  assert.equal(localStorage.has('aggregate_visitor_id'), false);

  window.Aggregate.setConsent(true);
  purchase(3000);
  assert.equal(requests.length, 2, 'method event tags must handle repeated events');
  assert.equal(requests[1].consentState, 'granted');
  assert.deepEqual(requests[1].eventData, {total_minor: 3000, product: 'sku-example'});
  assert.equal(typeof requests[1].visitorId, 'string');

  window.Aggregate.setConsent(false);
  purchase(4000);
  assert.equal(requests.length, 3);
  assert.equal(requests[2].consentState, 'denied');
  assert.equal(requests[2].eventData, undefined);
  assert.equal(requests[2].visitorId, undefined);
  assert.equal(localStorage.has('aggregate_visitor_id'), false);
  assert.equal(sessionStorage.has('aggregate_session_id'), false);

  window.AggregateTags.setConsent({});
  purchase(5000);
  assert.equal(requests.length, 3);
});
