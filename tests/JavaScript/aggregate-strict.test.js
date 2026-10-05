'use strict';

// Strict collection must send only the page path, event name and goal, and must
// not read device details or read, write or remove cookies or Web Storage. These
// tests wrap every browser object in a recording proxy and fail on any access
// outside the small set a page-view request needs.

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const sdkSource = fs.readFileSync(
  process.env.AGGREGATE_SDK_SOURCE
    ? path.resolve(process.env.AGGREGATE_SDK_SOURCE)
    : path.join(__dirname, '..', '..', 'public', 'aggregate.js'),
  'utf8'
);
const collectionDeclaration = /\{profile:\s*['"]standard['"]\}/;

function recorder(name, target, accesses) {
  return new Proxy(target, {
    get(object, property, receiver) {
      if (typeof property === 'string') accesses.push(name + '.' + property);
      return Reflect.get(object, property, receiver);
    },
    set(object, property, value, receiver) {
      if (typeof property === 'string') accesses.push(name + '.' + property + '=');
      return Reflect.set(object, property, value, receiver);
    },
    has(object, property) {
      if (typeof property === 'string') accesses.push(name + '.' + property + '?');
      return Reflect.has(object, property);
    }
  });
}

function storage() {
  const values = new Map([['aggregate_visitor_id', 'earlier-visitor'], ['aggregate_page_sequence:site-token', '3']]);
  return {
    getItem: (key) => values.has(key) ? values.get(key) : null,
    setItem: (key, value) => values.set(key, String(value)),
    removeItem: (key) => values.delete(key),
    values
  };
}

function loadSdk(options) {
  const accesses = [];
  const requests = [];
  const listeners = {};
  const captureListeners = {};
  const script = recorder('script', {
    dataset: Object.assign({websiteToken: 'site-token'}, options.dataset || {}),
    src: options.src || ''
  }, accesses);
  const documentTarget = {
    currentScript: script,
    readyState: 'loading',
    referrer: 'https://www.search.example/?q=private+search',
    cookie: 'aggregate_session=earlier-session; orgInternalTraffic=true',
    // Capturing listeners (marked click and form tracking) are kept apart
    // from the bubbling ones these tests dispatch to.
    addEventListener: (name, listener, options) => {
      if (options === true || (options && options.capture)) captureListeners[name] = listener;
      else listeners[name] = listener;
    },
    getElementsByTagName: () => [script],
    querySelector: () => null,
    baseURI: 'https://www.example.com/pricing?utm_medium=email&aggregate_page_sequence=4'
  };
  const document = recorder('document', documentTarget, accesses);
  const location = recorder('location', {
    origin: 'https://www.example.com',
    pathname: '/pricing',
    protocol: 'https:',
    search: '?utm_medium=email&aggregate_page_sequence=4',
    hash: '',
    href: 'https://www.example.com/pricing?utm_medium=email&aggregate_page_sequence=4'
  }, accesses);
  const localStorageTarget = storage();
  const sessionStorageTarget = storage();
  const windowTarget = {
    innerWidth: 1280,
    history: recorder('history', {state: null, replaceState: () => accesses.push('history.replaceState()')}, accesses)
  };
  windowTarget.Aggregate = Object.assign({
    endpoint: 'https://analytics.example/api/receive',
    websiteToken: 'site-token'
  }, options.inline || {});
  const window = recorder('window', windowTarget, accesses);
  const context = {
    URL,
    console: {warn: () => {}},
    document,
    fetch: (_url, request) => {
      requests.push(JSON.parse(request.body));
      return Promise.resolve({ok: true});
    },
    localStorage: recorder('localStorage', localStorageTarget, accesses),
    sessionStorage: recorder('sessionStorage', sessionStorageTarget, accesses),
    location,
    screen: recorder('screen', {width: 1440}, accesses),
    navigator: recorder('navigator', {userAgent: 'private'}, accesses),
    self: recorder('self', {crypto: {randomUUID: () => '00000000-0000-4000-8000-000000000001'}}, accesses),
    setTimeout: () => {},
    window
  };

  let source = sdkSource;
  if (typeof options.servedProfile === 'string') {
    source = sdkSource.replace(collectionDeclaration, JSON.stringify({profile: options.servedProfile}));
    assert.notEqual(source, sdkSource, 'Runtime fixture must replace the public collection defaults');
  }
  vm.runInNewContext(source, context, {filename: 'aggregate.js'});

  return {
    accesses,
    requests,
    listeners,
    api: windowTarget.Aggregate,
    localStorage: localStorageTarget,
    sessionStorage: sessionStorageTarget,
    triggerPageView: () => listeners.DOMContentLoaded()
  };
}

// Anything that reveals device details, browser storage, navigation history or
// query strings. The page path, script configuration and request are allowed.
const FORBIDDEN = [
  /^document\.(referrer|cookie)/,
  /^window\.(innerWidth|outerWidth|history|screen|devicePixelRatio|navigator)/,
  /^screen\./,
  /^navigator\./,
  /^self\./,
  /^(localStorage|sessionStorage)\./,
  /^history\./,
  /^location\.(search|href|hash)/
];

function assertNoDeviceAccess(accesses, label) {
  const forbidden = accesses.filter((access) => FORBIDDEN.some((pattern) => pattern.test(access)));
  assert.deepEqual(forbidden, [], (label || 'strict collection') + ' must not read or write device state');
}

function assertStorageUntouched(runtime) {
  assert.deepEqual(Array.from(runtime.localStorage.values.keys()).sort(), ['aggregate_page_sequence:site-token', 'aggregate_visitor_id']);
  assert.deepEqual(Array.from(runtime.sessionStorage.values.keys()).sort(), ['aggregate_page_sequence:site-token', 'aggregate_visitor_id']);
}

test('the served strict profile sends only the page path, event name and goal', () => {
  const runtime = loadSdk({servedProfile: 'strict'});

  runtime.triggerPageView();
  runtime.api.emit('plan_selected', {plan: 'pro', email: 'private@example.com'}, 'purchase');
  runtime.api.emit('button_click');

  assert.deepEqual(runtime.requests, [
    {eventName: 'view', pagePath: '/pricing', websiteToken: 'site-token'},
    {pagePath: '/pricing', eventName: 'plan_selected', goalEvent: 'purchase', websiteToken: 'site-token'},
    {pagePath: '/pricing', eventName: 'button_click', goalEvent: null, websiteToken: 'site-token'}
  ]);
  assertNoDeviceAccess(runtime.accesses);
  assertStorageUntouched(runtime);
  assert.equal(runtime.listeners.click, undefined, 'strict collection registers no link decoration');
});

test('strict consent choices never enable enhanced fields or touch identifier storage', () => {
  const runtime = loadSdk({servedProfile: 'strict', inline: {consent: true}, dataset: {consent: 'true'}});

  runtime.api.setConsent(true);
  runtime.triggerPageView();
  runtime.api.setConsent(false);
  runtime.api.setConsent('1');
  runtime.api.emit('signup_completed', {plan: 'pro'});

  for (const payload of runtime.requests) {
    for (const field of ['consentState', 'visitorId', 'sessionId', 'screenWidth', 'customData', 'eventData', 'internalTraffic', 'referrerChannel', 'deviceClass', 'viewportBucket']) {
      assert.equal(Object.prototype.hasOwnProperty.call(payload, field), false, field + ' must not be sent');
    }
  }
  assertNoDeviceAccess(runtime.accesses);
  assertStorageUntouched(runtime);
});

test('browser configuration cannot loosen a served strict profile', () => {
  const runtime = loadSdk({
    servedProfile: 'strict',
    inline: {collectionProfile: 'standard', customData: {pageSequenceEnabled: true, consentFreeProperties: ['plan'], queryParameters: {utm_medium: 'plan'}}},
    dataset: {collectionProfile: 'standard'}
  });

  runtime.api.configure({collectionProfile: 'standard', consent: true, customData: {pageSequenceEnabled: true, pageSequenceMethod: 'url_parameter'}});
  runtime.triggerPageView();
  runtime.api.emit('plan_selected', {plan: 'pro'});

  assert.deepEqual(runtime.requests.map((payload) => Object.keys(payload).sort()), [
    ['eventName', 'pagePath', 'websiteToken'],
    ['eventName', 'goalEvent', 'pagePath', 'websiteToken']
  ]);
  assertNoDeviceAccess(runtime.accesses);
  assertStorageUntouched(runtime);
});

test('an unrecognized served profile fails closed to strict collection', () => {
  const runtime = loadSdk({servedProfile: 'relaxed'});

  runtime.triggerPageView();

  assert.deepEqual(runtime.requests, [{eventName: 'view', pagePath: '/pricing', websiteToken: 'site-token'}]);
  assertNoDeviceAccess(runtime.accesses);
});

for (const [label, options] of [
  ['an inline setting', {inline: {collectionProfile: 'strict', consent: false}}],
  ['a data attribute', {dataset: {collectionProfile: 'strict', consent: '0'}}]
]) {
  test('a static copy can opt into strict collection through ' + label + ' before any storage cleanup', () => {
    const runtime = loadSdk(options);

    runtime.triggerPageView();

    assert.deepEqual(runtime.requests, [{eventName: 'view', pagePath: '/pricing', websiteToken: 'site-token'}]);
    assertNoDeviceAccess(runtime.accesses, label);
    assertStorageUntouched(runtime);
  });
}

test('configure can switch a standard page to strict for all later events', () => {
  const runtime = loadSdk({});

  runtime.triggerPageView();
  assert.equal(runtime.requests[0].referrerChannel, 'referral', 'standard collection is unchanged');
  assert.equal(runtime.requests[0].viewportBucket, 'large');

  runtime.api.configure({collectionProfile: 'strict'});
  const accessesBefore = runtime.accesses.length;
  runtime.api.setConsent(true);
  runtime.api.emit('button_click', {plan: 'pro'});
  runtime.api.trackView();
  runtime.api.configure({collectionProfile: 'standard'});
  runtime.api.emit('button_click');

  assert.deepEqual(runtime.requests.slice(1), [
    {pagePath: '/pricing', eventName: 'button_click', goalEvent: null, websiteToken: 'site-token'},
    {eventName: 'view', pagePath: '/pricing', websiteToken: 'site-token'},
    {pagePath: '/pricing', eventName: 'button_click', goalEvent: null, websiteToken: 'site-token'}
  ]);
  assertNoDeviceAccess(runtime.accesses.slice(accessesBefore), 'events after switching to strict');
});

test('the served standard profile keeps its existing coarse dimensions', () => {
  const runtime = loadSdk({servedProfile: 'standard'});

  runtime.triggerPageView();

  const payload = runtime.requests[0];
  assert.equal(payload.consentState, 'unknown');
  assert.equal(payload.referrerChannel, 'referral');
  assert.equal(payload.deviceClass, 'desktop');
  assert.equal(payload.viewportBucket, 'large');
  // Guards the recorder itself: standard collection is expected to read these.
  for (const access of ['document.referrer', 'window.innerWidth', 'document.cookie', 'localStorage.removeItem']) {
    assert.ok(runtime.accesses.includes(access), access + ' should be recorded in standard mode');
  }
  assert.throws(() => assertNoDeviceAccess(runtime.accesses), assert.AssertionError);
});
