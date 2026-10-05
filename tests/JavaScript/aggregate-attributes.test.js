'use strict';

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

// A minimal element: attributes in source order, a parent and a tag name.
class Element {
  constructor(tagName, attributes = {}, parent = null) {
    this.tagName = tagName.toUpperCase();
    this.nodeType = 1;
    this.parentNode = parent;
    this.attributes = Object.entries(attributes).map(([name, value]) => ({name, value: String(value)}));
  }
  getAttribute(name) {
    const attribute = this.attributes.find((entry) => entry.name === name);
    return attribute ? attribute.value : null;
  }
  hasAttribute(name) {
    return this.getAttribute(name) !== null;
  }
  child(tagName, attributes) {
    return new Element(tagName, attributes, this);
  }
}

function page(options = {}) {
  const requests = [];
  const warnings = [];
  const capture = {click: [], submit: []};
  const documentNode = {nodeType: 9, parentNode: null};
  const script = {dataset: {}, src: ''};
  const document = Object.assign(documentNode, {
    currentScript: script,
    readyState: 'loading',
    referrer: '',
    cookie: '',
    addEventListener: (name, listener, listenerOptions) => {
      if ((listenerOptions === true || (listenerOptions && listenerOptions.capture)) && capture[name]) capture[name].push(listener);
    },
    getElementsByTagName: () => [script],
    querySelector: () => null
  });
  const namespace = options.namespace || 'Aggregate';
  const window = {};
  window[namespace] = {endpoint: 'https://analytics.example/api/receive', websiteToken: 'site-token', consent: options.consent === true};
  const storage = () => ({getItem: () => null, setItem: () => {}, removeItem: () => {}});
  const context = {
    URL, document, window, screen: {width: 1440}, innerWidth: 1440,
    location: {origin: 'https://www.example.com', pathname: '/pricing', protocol: 'https:', search: options.search || '', href: 'https://www.example.com/pricing' + (options.search || '')},
    localStorage: storage(), sessionStorage: storage(), setTimeout: () => {},
    self: {crypto: {randomUUID: () => '00000000-0000-4000-8000-000000000001'}},
    console: {warn: (message) => warnings.push(message)},
    fetch: (_url, request) => { requests.push(JSON.parse(request.body)); return Promise.resolve({ok: true}); }
  };
  window.innerWidth = 1440;

  let source = sdkSource;
  const replace = (pattern, value) => {
    const replaced = source.replace(pattern, () => value);
    assert.notEqual(replaced, source, 'fixture must replace ' + pattern);
    source = replaced;
  };
  if (options.namespace) replace("var namespace = 'Aggregate';", 'var namespace = ' + JSON.stringify(options.namespace) + ';');
  if (options.customData) replace(/var customDataDefaults = \{[^\n]*\};/, 'var customDataDefaults = ' + JSON.stringify(options.customData) + ';');
  if (options.profile) replace("var collectionDefaults = {profile: 'standard'};", 'var collectionDefaults = ' + JSON.stringify({profile: options.profile}) + ';');
  for (let load = 0; load < (options.loads || 1); load++) vm.runInNewContext(source, context, {filename: 'aggregate.js'});

  const root = new Element('html', {});
  root.parentNode = documentNode;
  const body = root.child('body', options.bodyAttributes || {});
  const dispatch = (name, target, extra = {}) => {
    const event = Object.assign({type: name, target}, extra);
    capture[name].forEach((listener) => listener(event));
    return event;
  };
  return {
    body, requests, warnings, capture, api: window[namespace],
    click: (target, extra) => dispatch('click', target, extra),
    submit: (form) => dispatch('submit', form)
  };
}

const sent = (runtime) => runtime.requests.map((request) => [request.eventName, request.customData === undefined ? undefined : JSON.parse(JSON.stringify(request.customData)), request.goalEvent]);

test('a click on a marked element, or anything inside it, sends its event with nearby properties and goal', () => {
  const runtime = page({consent: true});
  const section = runtime.body.child('section', {'data-aggregate-prop-section': 'pricing', 'data-aggregate-prop-plan': 'outer'});
  const button = section.child('button', {
    'data-aggregate-event': 'plan_click', 'data-aggregate-goal': ' signup ', 'data-aggregate-prop-plan': 'pro'
  });
  runtime.click(button.child('span', {}));
  runtime.click(section.child('p', {}));

  assert.deepEqual(sent(runtime), [['plan_click', {plan: 'pro', section: 'pricing'}, 'signup']]);
  const [request] = runtime.requests;
  assert.equal(request.pagePath, '/pricing');
  assert.equal(request.deviceClass, 'desktop');
  assert.equal(request.consentState, 'granted');
  assert.equal(request.websiteToken, 'site-token');
});

test('the same click is sent again each time, and a nested marked element wins over one around it', () => {
  const runtime = page({consent: true});
  const card = runtime.body.child('div', {'data-aggregate-event': 'card_click', 'data-aggregate-prop-card': 'starter'});
  const link = card.child('a', {'data-aggregate-event': 'details_click'});
  runtime.click(link);
  runtime.click(card.child('img', {}));
  runtime.click(link);
  assert.deepEqual(sent(runtime).map(([name, data]) => [name, data]), [
    ['details_click', {card: 'starter'}], ['card_click', {card: 'starter'}], ['details_click', {card: 'starter'}]
  ]);
});

test('forms send on submission only; clicks inside them belong to marked buttons or marked ancestors', () => {
  const runtime = page({consent: true});
  const wrapper = runtime.body.child('div', {'data-aggregate-event': 'footer_click', 'data-aggregate-prop-area': 'footer'});
  const form = wrapper.child('form', {'data-aggregate-event': 'newsletter_submit', 'data-aggregate-prop-list': 'weekly'});
  const field = form.child('input', {name: 'email', value: 'private@example.com'});
  const button = form.child('button', {'data-aggregate-event': 'newsletter_button'});

  runtime.submit(form);
  runtime.click(button);
  runtime.click(field);
  const plainForm = wrapper.child('form', {});
  runtime.submit(plainForm);

  assert.deepEqual(sent(runtime).map(([name, data]) => [name, data]), [
    ['newsletter_submit', {list: 'weekly', area: 'footer'}],
    ['newsletter_button', {list: 'weekly', area: 'footer'}],
    ['footer_click', {area: 'footer'}]
  ]);
  assert.doesNotMatch(JSON.stringify(runtime.requests), /private@example\.com|email/, 'form fields are never read');
});

test('before consent only properties the data model allows without consent are sent', () => {
  const runtime = page({customData: {queryParameters: {}, consentFreeProperties: ['section']}});
  const button = runtime.body.child('section', {'data-aggregate-prop-section': 'pricing'})
    .child('button', {'data-aggregate-event': 'plan_click', 'data-aggregate-prop-plan': 'pro', 'data-aggregate-goal': 'signup'});
  runtime.click(button);
  runtime.api.setConsent(true);
  runtime.click(button);

  assert.deepEqual(sent(runtime), [
    ['plan_click', {section: 'pricing'}, 'signup'],
    ['plan_click', {section: 'pricing', plan: 'pro'}, 'signup']
  ]);
  assert.equal(runtime.requests[0].consentState, 'denied');
  assert.equal(runtime.requests[0].visitorId, undefined);
});

test('declared number and boolean types convert only their plain written form; other text stays text', () => {
  const runtime = page({consent: true, customData: {queryParameters: {}, consentFreeProperties: [],
    propertyTypes: {total_minor: 'integer', rate: 'double', trial: 'boolean', count: 'integer', ratio: 'float', flag: 'boolean', label: 'string'}}});
  runtime.click(runtime.body.child('button', {
    'data-aggregate-event': 'checkout_click',
    'data-aggregate-prop-total_minor': '1299', 'data-aggregate-prop-rate': '-0.25e1', 'data-aggregate-prop-trial': 'false',
    'data-aggregate-prop-count': '12.0', 'data-aggregate-prop-ratio': '1,5', 'data-aggregate-prop-flag': 'yes',
    'data-aggregate-prop-label': '42', 'data-aggregate-prop-plain': ' pro\u0001 ', 'data-aggregate-prop-blank': '  ',
    'data-aggregate-prop-': 'no key', 'data-aggregate-prop-__proto__': 'x', 'data-aggregate-prop-9lives': 'x'
  }));
  assert.deepEqual(sent(runtime)[0][1], {total_minor: 1299, rate: -2.5, trial: false, label: '42', plain: 'pro'});
  assert.strictEqual(runtime.requests[0].customData.total_minor, 1299);
});

test('invalid and reserved event names send nothing and explain themselves once in the console', () => {
  const runtime = page();
  const bad = runtime.body.child('button', {'data-aggregate-event': 'user-12345-click'});
  const view = runtime.body.child('a', {'data-aggregate-event': 'view'});
  const empty = runtime.body.child('a', {'data-aggregate-event': ''});
  runtime.click(bad);
  runtime.click(bad);
  runtime.click(view);
  runtime.click(empty);
  assert.equal(runtime.requests.length, 0);
  assert.equal(runtime.warnings.length, 3);
  assert.match(runtime.warnings[0], /^\[Aggregate\] data-aggregate-event="user-12345-click" is not a valid event name/);
  assert.match(runtime.warnings[1], /reserved for page views/);
});

test('attribute names follow the configured namespace for white-labelled installations', () => {
  const runtime = page({namespace: 'AcmeStats', consent: true});
  runtime.click(runtime.body.child('button', {'data-aggregate-event': 'ignored_click'}));
  runtime.click(runtime.body.child('button', {'data-acmestats-event': 'signup_click', 'data-acmestats-prop-plan': 'pro', 'data-acmestats-goal': 'signup'}));
  assert.deepEqual(sent(runtime), [['signup_click', {plan: 'pro'}, 'signup']]);

  // TrackingAttributesTest checks that the dashboard derives the same names.
  for (const [namespace, attribute] of [['$_Shop$', 'data-_shop-event'], ['Stat\u0130stik', 'data-statstik-event'], ['$$', 'data-aggregate-event']]) {
    const other = page({namespace, consent: true});
    other.click(other.body.child('button', {[attribute]: 'shop_click'}));
    assert.deepEqual(sent(other).map(([name]) => name), ['shop_click'], namespace);
  }
});

test('the strict profile sends only the event name, goal and page path', () => {
  const runtime = page({profile: 'strict', consent: true});
  runtime.click(runtime.body.child('button', {'data-aggregate-event': 'plan_click', 'data-aggregate-prop-plan': 'pro', 'data-aggregate-goal': 'signup'}));
  assert.deepEqual(JSON.parse(JSON.stringify(runtime.requests)), [{pagePath: '/pricing', eventName: 'plan_click', goalEvent: 'signup', websiteToken: 'site-token'}]);
});

test('a tracker loaded twice sends each marked interaction once', () => {
  const runtime = page({loads: 2, consent: true});
  assert.equal(runtime.capture.click.length, 2);
  runtime.click(runtime.body.child('button', {'data-aggregate-event': 'plan_click'}));
  runtime.submit(runtime.body.child('form', {'data-aggregate-event': 'contact_submit'}));
  assert.deepEqual(sent(runtime).map(([name]) => name), ['plan_click', 'contact_submit']);
});

test('elements inside an open shadow root are found through the event path', () => {
  const runtime = page({consent: true});
  const host = runtime.body.child('pricing-card', {'data-aggregate-prop-card': 'pro'});
  const inner = new Element('button', {'data-aggregate-event': 'card_cta'});
  const shadowRoot = {nodeType: 11};
  runtime.click(host, {composedPath: () => [inner, shadowRoot, host, runtime.body, runtime.body.parentNode]});
  assert.deepEqual(sent(runtime).map(([name, data]) => [name, data]), [['card_cta', {card: 'pro'}]]);
});

test('unrelated clicks and malformed events are ignored without errors', () => {
  const runtime = page();
  runtime.click(runtime.body.child('button', {}));
  runtime.click(null);
  runtime.click({nodeType: 3, parentNode: null});
  runtime.capture.click.forEach((listener) => listener(undefined));
  runtime.submit(runtime.body.child('div', {'data-aggregate-event': 'not_a_form'}));
  assert.equal(runtime.requests.length, 0);
});
