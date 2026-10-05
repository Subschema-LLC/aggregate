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

function configuredSdk(namespace = 'Aggregate') {
  return fs.readFileSync(process.env.AGGREGATE_SDK_SOURCE || path.join(__dirname, '../../public/aggregate.js'), 'utf8')
    .replace("var namespace = 'Aggregate';", 'var namespace = ' + JSON.stringify(namespace) + ';')
    .replaceAll('__AGGREGATE_NAMESPACE__', JSON.stringify(namespace))
    .replaceAll('__AGGREGATE_INTERNAL_TRAFFIC__', JSON.stringify({storage: 'cookie', name: 'orgInternalTraffic', value: 'true', cookieDomain: ''}))
    .replaceAll('__AGGREGATE_CUSTOM_DATA__', JSON.stringify({queryParameters: {}, consentFreeProperties: []}));
}

function configuredManager(configuration) {
  return fs.readFileSync(process.env.AGGREGATE_TAG_SOURCE || path.join(__dirname, '../../public/tag-manager.js'), 'utf8')
    .replace('var tagManagerConfig = {enabled: false, tags: []};', 'var tagManagerConfig = ' + JSON.stringify(configuration) + ';')
    .replaceAll('__AGGREGATE_TAG_MANAGER__', JSON.stringify(configuration));
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
  vm.runInContext(configuredSdk(), context);
  const configuration = {
    enabled: true,
    variables: {amount: 'ecommerce.total_minor', product: 'ecommerce.items[0].sku'},
    tags: [{
      id: 'purchase', type: 'call', method: 'Aggregate.emit',
      args: ['purchase', {total_minor: {$var: 'amount'}, product: {$var: 'product'}}],
      consent: 'marketing', trigger: {type: 'data_layer', event: 'purchase'}
    }]
  };
  vm.runInContext(configuredManager(configuration), context);
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
  assert.equal(requests[0].customData, undefined, 'a tag category cannot whitelist custom analytics properties');
  assert.equal(localStorage.has('aggregate_visitor_id'), false);

  window.Aggregate.setConsent(true);
  purchase(3000);
  assert.equal(requests.length, 2, 'method event tags must handle repeated events');
  assert.equal(requests[1].consentState, 'granted');
  assert.deepEqual(requests[1].customData, {total_minor: 3000, product: 'sku-example'});
  assert.equal(typeof requests[1].visitorId, 'string');

  window.Aggregate.setConsent(false);
  purchase(4000);
  assert.equal(requests.length, 3);
  assert.equal(requests[2].consentState, 'denied');
  assert.equal(requests[2].customData, undefined);
  assert.equal(requests[2].visitorId, undefined);
  assert.equal(localStorage.has('aggregate_visitor_id'), false);
  assert.equal(sessionStorage.has('aggregate_session_id'), false);

  window.AggregateTags.setConsent({});
  purchase(5000);
  assert.equal(requests.length, 3);
});

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

// Model only the two installed drop-ins. A configured script tag is fetched
// asynchronously and evaluated later, with its own currentScript URL, exactly
// where the SDK reads query parameters. There is no inline tracker config.
function dropInPage({namespace = 'Aggregate', tokenParameter = 'token', saved, trackerTag = true, tagConsent = 'none', withCmp = true, trackerQuery = '', variables = {}, dataLayer} = {}) {
  const requests = [];
  const timers = [];
  const injectedScripts = [];
  const localStorage = storage();
  const sessionStorage = storage();
  const cookies = new Map();
  const siteId = '0123456789abcdef01234567';
  if (saved !== undefined) localStorage.setItem('analytics_consent_v1:' + namespace + ':' + siteId, saved);
  const document = eventTarget({readyState: 'complete', currentScript: null, referrer: ''});
  document.createElement = (tag) => {
    const node = eventTarget({tagName: tag.toUpperCase(), children: [], attributes: {}, dataset: {}, style: {}});
    node.setAttribute = (key, value) => { node.attributes[key] = String(value); };
    node.appendChild = (child) => { node.children.push(child); return child; };
    node.focus = () => { document.activeElement = node; };
    return node;
  };
  document.head = document.createElement('head');
  document.head.appendChild = (node) => {
    document.head.children.push(node);
    if (node.tagName === 'SCRIPT') injectedScripts.push(node);
    return node;
  };
  document.body = document.createElement('body');
  document.getElementsByTagName = () => injectedScripts;
  Object.defineProperty(document, 'cookie', {
    get: () => [...cookies].map(([key, value]) => key + '=' + value).join('; '),
    set: (value) => {
      const [key, entry] = value.split(';')[0].split('=');
      if (/max-age=0(?:;|$)/i.test(value)) cookies.delete(key);
      else cookies.set(key, entry);
    }
  });
  const location = {origin: 'https://shop.example', pathname: '/checkout', protocol: 'https:', search: ''};
  const window = eventTarget({localStorage, location, innerWidth: 1280});
  const context = vm.createContext({
    window, document, location, localStorage, sessionStorage, URL,
    screen: {width: 1280}, self: {crypto: {randomUUID: () => '00000000-0000-4000-8000-000000000001'}},
    CustomEvent: class { constructor(type, details) { this.type = type; Object.assign(this, details); } },
    setTimeout: (callback) => { timers.push(callback); }, console: {warn: () => {}},
    fetch: (url, request) => { requests.push({url, body: JSON.parse(request.body)}); return Promise.resolve({ok: true}); }
  });
  const execute = (source, script) => {
    document.currentScript = script;
    try { vm.runInContext(source, context); } finally { document.currentScript = null; }
  };
  assert.equal(window[namespace], undefined, 'the page starts without inline tracker configuration');
  if (withCmp) {
    const configuration = JSON.stringify({namespace, name: 'Example store', categories: ['analytics'], siteId});
    const styles = JSON.stringify(fs.readFileSync(path.join(__dirname, '../../public/consent.css'), 'utf8'));
    const cmp = fs.readFileSync(process.env.AGGREGATE_CONSENT_SOURCE || path.join(__dirname, '../../public/consent.js'), 'utf8')
      .replace("var consentConfig = {namespace: 'Aggregate', name: 'Analytics'};", 'var consentConfig = ' + configuration + ';')
      .replaceAll('__AGGREGATE_CONSENT_CONFIG__', configuration)
      .replace('var consentStyles = null;', 'var consentStyles = ' + styles + ';')
      .replaceAll('__AGGREGATE_CONSENT_STYLES__', styles);
    execute(cmp, {src: 'https://analytics.example/cmp-lite/sites/' + siteId + '/consent.js', dataset: {}});
    assert.deepEqual(Object.keys(window[namespace]), ['consent'], 'the CMP only supplies its current choice before the SDK exists');
  }
  const endpoint = 'https://analytics.example/api/receive';
  const token = 'public+token&=example';
  const trackerUrl = new URL('https://analytics.example/aggregate.js');
  trackerUrl.searchParams.set('endpoint', endpoint);
  trackerUrl.searchParams.set(tokenParameter, token);
  trackerUrl.searchParams.set('consent', '0');
  const tags = trackerTag ? [{
    id: 'aggregate-sdk', type: 'script', src: trackerUrl.href + trackerQuery, consent: tagConsent, trigger: {type: 'dom_ready'}
  }] : [];
  if (dataLayer) window.dataLayer = dataLayer;
  execute(configuredManager({enabled: true, variables, tags}), {src: 'https://analytics.example/tms-lite/sites/' + siteId + '/lib.js', dataset: {}});

  return {
    window, document, requests, injectedScripts, localStorage, sessionStorage, cookies, endpoint, token, trackerUrl,
    loadInjectedTracker() {
      const script = injectedScripts.find((node) => node.src === trackerUrl.href || node.src.startsWith(trackerUrl.href + '&'));
      assert.ok(script, 'the configured TMS tag must request the SDK');
      assert.equal(script.async, true);
      execute(configuredSdk(namespace), script);
    },
    flushTimers() { while (timers.length) timers.shift()(); }
  };
}

for (const namespace of ['Aggregate', 'CompanyAnalytics']) {
  for (const tokenParameter of ['token', 'websiteToken']) {
    test('CMP and managed query bootstrap collect anonymous events without inline config: ' + namespace + '/' + tokenParameter, () => {
      const page = dropInPage({namespace, tokenParameter});
      assert.equal(page.requests.length, 0);
      assert.equal(page.injectedScripts.length, 1, 'only TMS inserts the tracker script');
      page.loadInjectedTracker();
      assert.equal(page.requests.length, 0, 'the first page view waits for its scheduled task');
      page.flushTimers();
      assert.equal(page.requests.length, 1);
      const first = page.requests[0];
      assert.equal(first.url, page.endpoint);
      assert.equal(first.body.websiteToken, page.token);
      assert.equal(first.body.eventName, 'view');
      assert.equal(first.body.consentState, 'denied');
      assert.equal(first.body.visitorId, undefined);
      assert.equal(first.body.sessionId, undefined);
      assert.equal(page.localStorage.has('aggregate_visitor_id'), false);
      assert.equal(page.cookies.has('aggregate_session'), false);
      assert.equal(page.window[namespace].endpoint, undefined, 'endpoint comes from the script URL, not a window object');
      assert.equal(page.window[namespace].websiteToken, undefined);
      page.window[namespace].emit('button_click', {private: 'omitted'});
      assert.equal(page.requests.at(-1).url, page.endpoint);
      assert.equal(page.requests.at(-1).body.websiteToken, page.token);
      assert.equal(page.requests.at(-1).body.customData, undefined);
      if (namespace !== 'Aggregate') assert.equal(page.window.Aggregate, undefined);
    });
  }

  test('remembered analytics consent survives query consent=0 and withdrawal clears the managed SDK: ' + namespace, () => {
    const page = dropInPage({namespace, saved: '{"analytics":true}'});
    page.loadInjectedTracker();
    page.flushTimers();
    assert.equal(page.requests[0].body.consentState, 'granted');
    assert.equal(typeof page.requests[0].body.visitorId, 'string');
    assert.equal(page.localStorage.has('aggregate_visitor_id'), true);
    assert.equal(page.cookies.has('aggregate_session'), true);
    page.window[namespace].emit('purchase', {total_minor: 1234});
    assert.deepEqual(page.requests.at(-1).body.customData, {total_minor: 1234});
    page.window.AggregateConsent.setConsent(false);
    assert.equal(page.localStorage.has('aggregate_visitor_id'), false);
    assert.equal(page.sessionStorage.has('aggregate_session_id'), false);
    assert.equal(page.cookies.has('aggregate_session'), false);
    page.window[namespace].emit('purchase', {total_minor: 2345});
    assert.equal(page.requests.at(-1).body.consentState, 'denied');
    assert.equal(page.requests.at(-1).body.customData, undefined);
    assert.equal(page.requests.at(-1).body.visitorId, undefined);
    assert.equal(page.requests.at(-1).body.websiteToken, page.token);
    assert.equal(page.injectedScripts.length, 1, 'consent changes do not insert the SDK twice');
  });

  test('grant and withdrawal while the managed SDK downloads apply before its first page view: ' + namespace, () => {
    const page = dropInPage({namespace, tagConsent: 'analytics'});
    assert.equal(page.injectedScripts.length, 0);
    page.window.AggregateConsent.setConsent(true);
    assert.equal(page.injectedScripts.length, 1);
    page.window.AggregateConsent.setConsent(false);
    page.loadInjectedTracker();
    page.flushTimers();
    assert.equal(page.requests[0].body.consentState, 'denied');
    assert.equal(page.requests[0].body.visitorId, undefined);
    page.window.AggregateConsent.setConsent(true);
    page.window[namespace].emit('purchase', {total_minor: 3456});
    assert.equal(page.requests.at(-1).body.consentState, 'granted');
    assert.deepEqual(page.requests.at(-1).body.customData, {total_minor: 3456});
    assert.equal(page.injectedScripts.length, 1);
  });
}

test('CMP and TMS alone do not load or initialize analytics until an SDK tag is configured', () => {
  const page = dropInPage({trackerTag: false});
  assert.equal(page.window.Aggregate.emit, undefined);
  assert.equal(page.injectedScripts.length, 0);
  page.window.AggregateConsent.setConsent(true);
  page.flushTimers();
  assert.equal(page.injectedScripts.length, 0);
  assert.deepEqual(page.requests, []);
  assert.equal(page.localStorage.has('aggregate_visitor_id'), false);
  assert.equal(page.cookies.has('aggregate_session'), false);
});

test('query endpoint and token also initialize the SDK without a CMP or any inline configuration', () => {
  const page = dropInPage({withCmp: false});
  assert.equal(page.window.Aggregate, undefined);
  page.loadInjectedTracker();
  page.flushTimers();
  assert.equal(page.requests[0].url, page.endpoint);
  assert.equal(page.requests[0].body.websiteToken, page.token);
  assert.equal(page.requests[0].body.consentState, 'denied');
  assert.equal(page.requests[0].body.visitorId, undefined);
});

test('a managed tracker URL carries dataLayer values as custom data on its page view', () => {
  const page = dropInPage({
    saved: '{"analytics":true}',
    trackerQuery: '&cd.page_type={{page_type}}&cd.plan={{plan}}',
    variables: {page_type: 'page.type', plan: 'account.plan'},
    dataLayer: [{page: {type: 'checkout'}, account: {plan: 'team & co'}}]
  });
  assert.equal(page.injectedScripts.length, 1);
  assert.equal(new URL(page.injectedScripts[0].src).searchParams.get('cd.plan'), 'team & co', 'the manager URL-encodes each value');
  page.loadInjectedTracker();
  page.flushTimers();
  assert.equal(page.requests[0].body.eventName, 'view');
  assert.deepEqual(page.requests[0].body.customData, {page_type: 'checkout', plan: 'team & co'});
  page.window.Aggregate.emit('button_click');
  assert.equal(page.requests.at(-1).body.customData, null, 'named events do not inherit the page view values');

  const missing = dropInPage({saved: '{"analytics":true}', trackerQuery: '&cd.plan={{plan}}', variables: {plan: 'account.plan'}, dataLayer: []});
  assert.equal(missing.injectedScripts.length, 0, 'a missing variable skips the whole tracker tag');
});
