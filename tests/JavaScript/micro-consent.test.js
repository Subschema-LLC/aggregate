'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const base = path.join(__dirname, '../..');
const core = fs.readFileSync(process.env.MICRO_CONSENT_SOURCE || path.join(base, 'micro-consent-dropins/js/consent-ui.js'), 'utf8');
const aggregate = fs.readFileSync(path.join(base, 'micro-consent-dropins/js/aggregate-consent.js'), 'utf8');
const google = fs.readFileSync(path.join(base, 'micro-consent-dropins/js/gtm-consent-mode.js'), 'utf8');
const tracker = fs.readFileSync(path.join(base, 'public/aggregate.js'), 'utf8');
const tagManager = fs.readFileSync(path.join(base, 'public/tag-manager.js'), 'utf8');
const clean = value => JSON.parse(JSON.stringify(value));

function target(object = {}) {
  const listeners = new Map();
  object.addEventListener = (name, fn) => { if (!listeners.has(name)) listeners.set(name, []); listeners.get(name).push(fn); };
  object.dispatchEvent = event => {
    event.preventDefault ||= function () { this.defaultPrevented = true; };
    for (const listener of [...(listeners.get(event.type) || [])]) listener(event);
    return !event.defaultPrevented;
  };
  return object;
}

function runtime(options = {}) {
  const all = [], requests = [], operations = [], timers = new Map(), session = new Map(), cookies = new Map();
  const clock = {now: 1800000000000};
  let timerId = 0;
  const shared = options.shared || {values: new Map(), tabs: [], queue: []};
  const window = target({navigator: {globalPrivacyControl: options.gpc === true, userAgent: 'Test browser'}});
  const document = target({readyState: options.readyState || 'complete', referrer: ''});
  function node(tag) {
    const properties = {};
    const value = target({tagName: tag.toUpperCase(), children: [], attributes: {}, style: {properties, setProperty: (name, entry) => { properties[name] = entry; }}, hidden: false, disabled: false, checked: false, value: '', tabIndex: 0, textContent: ''});
    value.appendChild = child => { value.children.push(child); child.parentElement = value; return child; };
    value.setAttribute = (name, entry) => { value.attributes[name] = String(entry); if (name === 'open') value.open = true; };
    value.removeAttribute = name => { delete value.attributes[name]; if (name === 'open') value.open = false; };
    value.focus = () => { document.activeElement = value; };
    value.closest = selector => { assert.equal(selector, '[hidden]'); for (let current = value; current; current = current.parentElement) if (current.hidden) return current; return null; };
    value.querySelectorAll = () => { const found = []; function visit(current) { for (const child of current.children) { if (['BUTTON', 'INPUT', 'SELECT', 'TEXTAREA', 'A'].includes(child.tagName)) found.push(child); visit(child); } } visit(value); return found; };
    if (tag === 'dialog') { value.showModal = () => { value.open = true; }; value.close = () => { value.open = false; }; }
    Object.defineProperty(value, 'innerHTML', {set() { assert.fail('Standalone UI must render operator text through textContent'); }});
    all.push(value);
    return value;
  }
  document.createElement = node;
  document.head = node('head'); document.body = node('body');
  document.currentScript = {src: 'https://site.example/assets/js/consent-ui.js', nonce: 'test-nonce', dataset: {namespace: options.namespace || 'Aggregate'}};
  document.getElementsByTagName = tag => all.filter(entry => entry.tagName === tag.toUpperCase());
  Object.defineProperty(document, 'cookie', {
    get: () => [...cookies].map(([key, value]) => key + '=' + value).join('; '),
    set(value) { const [key, entry] = value.split(';')[0].split('='); if (/max-age=0(?:;|$)/i.test(value)) cookies.delete(key); else cookies.set(key, entry); }
  });
  window.setTimeout = (fn, delay) => { const id = ++timerId; timers.set(id, {fn, delay}); return id; };
  window.clearTimeout = id => timers.delete(id);
  const tab = {window}; shared.tabs.push(tab);
  const storage = {
    getItem(key) { if (options.readBlocked) throw Error('Blocked'); return shared.values.get(key) ?? null; },
    setItem(key, value) {
      operations.push(['set', key, String(value)]);
      if (options.writeBlocked) throw Error('Blocked');
      const old = shared.values.get(key) ?? null; shared.values.set(key, String(value));
      if (old !== String(value)) for (const peer of shared.tabs) if (peer !== tab) shared.queue.push({peer, event: {type: 'storage', key, newValue: String(value)}});
    },
    removeItem(key) {
      operations.push(['remove', key]);
      if (options.removeBlocked) throw Error('Blocked');
      if (shared.values.delete(key)) for (const peer of shared.tabs) if (peer !== tab) shared.queue.push({peer, event: {type: 'storage', key, newValue: null}});
    }
  };
  window.localStorage = storage;
  const sessionStorage = {getItem: key => session.get(key) ?? null, setItem: (key, value) => session.set(key, String(value)), removeItem: key => session.delete(key)};
  window.location = {origin: 'https://site.example', pathname: '/private-context', search: '?email=not-to-be-sent@example.test', protocol: 'https:'};
  window.fetch = (url, request) => {
    requests.push({url, ...request, payload: typeof request.body === 'string' ? JSON.parse(request.body) : Object.fromEntries(request.body.entries())});
    return options.fetch ? options.fetch(url, request) : Promise.resolve({ok: true});
  };
  if (Object.prototype.hasOwnProperty.call(options, 'config')) window.MicroConsentConfig = options.config;
  if (options.existingCmp) window.AggregateConsent = options.existingCmp;
  if (options.trackerConfig) window[options.namespace || 'Aggregate'] = {endpoint: 'https://analytics.example/api/receive', websiteToken: 'public-token'};
  const context = vm.createContext({
    window, document, URL, navigator: window.navigator, location: window.location,
    localStorage: storage, sessionStorage, screen: {width: 1280},
    self: {crypto: {randomUUID: () => '00000000-0000-4000-8000-000000000001'}},
    setTimeout: window.setTimeout, clearTimeout: window.clearTimeout, fetch: window.fetch,
    Date: class extends Date { static now() { return clock.now; } },
    CustomEvent: class { constructor(type, options) { this.type = type; Object.assign(this, options); } },
    FormData: class { constructor() { this.data = new Map(); } append(key, value) { this.data.set(key, value); } entries() { return this.data.entries(); } },
    console
  });
  const run = source => vm.runInContext(source, context);
  const app = {
    window, document, all, requests, operations, timers, clock, shared, session, cookies, run,
    byId: id => all.find(entry => entry.id === id), text: text => all.find(entry => entry.textContent === text),
    load: () => run(core), bridge: () => run(aggregate), google: () => run(google), loadTracker: () => run(tracker),
    loadTags: tags => run(tagManager.replace('var tagManagerConfig = {enabled: false, tags: []};', 'var tagManagerConfig = ' + JSON.stringify({enabled: true, tags}) + ';')),
    state: () => clean(window.MicroConsent.getState()),
    drain() { let count = 0; while (shared.queue.length && count++ < 200) { const next = shared.queue.shift(); next.peer.window.dispatchEvent(next.event); } assert.equal(shared.queue.length, 0, 'Storage events must drain without rewriting old preferences'); return count; }
  };
  if (options.load !== false) app.load();
  return app;
}

function saved(changes = {}) {
  return JSON.stringify({revision: '1', savedAt: 1800000000000, choices: {analytics: true, functional: false, marketing: true}, doNotSell: false, ...changes});
}

test('standalone defaults deny, render equal buttons and make no submissions or Aggregate globals', () => {
  const app = runtime();
  assert.deepEqual(app.state(), {analytics: false, functional: false, marketing: false, doNotSell: false, gpc: false});
  assert.equal(app.requests.length, 0);
  for (const key of ['Aggregate', 'AggregateConsent', 'AggregateTags', 'gtag', 'dataLayer']) assert.equal(app.window[key], undefined);
  assert.equal(app.byId('mc-request-form'), undefined);
  assert.equal(app.text('Reject optional categories').className, app.text('Accept optional categories').className);
  const style = app.all.find(node => node.tagName === 'LINK');
  assert.equal(style.href, 'https://site.example/assets/css/consent-ui.css');
  assert.equal(style.nonce, 'test-nonce'); assert.equal(style.referrerPolicy, 'no-referrer');
  assert.equal(app.operations.length, 0);
  const count = app.all.length; app.load(); assert.equal(app.all.length, count, 'Repeated loads do not duplicate the UI');
});

test('strict own booleans, storage metadata, withdrawal ordering, and subscriber isolation', () => {
  const app = runtime(); const changes = [];
  app.window.MicroConsent.subscribe(() => { throw Error('Subscriber failure'); });
  const unsubscribe = app.window.MicroConsent.subscribe(state => { changes.push({state: clean(state), writesBeforeNotification: app.operations.length}); });
  app.window.MicroConsent.setConsent({analytics: true, marketing: 'true', functional: 1});
  assert.deepEqual(app.state(), {analytics: true, functional: false, marketing: false, doNotSell: false, gpc: false});
  app.window.MicroConsent.setConsent(Object.create({analytics: true}));
  assert.equal(app.state().analytics, false);
  unsubscribe(); app.window.MicroConsent.setConsent({get analytics() { throw Error('Do not invoke getter'); }});
  assert.equal(changes.length, 2);
  assert.deepEqual(changes.map(change => change.writesBeforeNotification), [0, 1], 'Each notification precedes that choice’s storage write');
  const record = JSON.parse(app.shared.values.get('micro_consent_v2'));
  assert.deepEqual(Object.keys(record).sort(), ['choices', 'doNotSell', 'revision', 'savedAt']);
  assert.equal(record.savedAt, app.clock.now);
  assert.equal(app.requests.length, 0);
});

test('legacy, malformed, expired, future, revised and incomplete choices cannot grant consent', () => {
  for (const value of ['true', '{broken', saved({revision: 'old'}), saved({savedAt: 1}), saved({savedAt: 1800000000001}), saved({choices: {analytics: true}}), saved({choices: {analytics: 'true', functional: false, marketing: false}})]) {
    const shared = {values: new Map([['micro_consent_v2', value], ['privacy_prefs', '{"analytics":true}']]), tabs: [], queue: []};
    const app = runtime({shared}); assert.equal(app.state().analytics, false, value); assert.equal(app.requests.length, 0);
    assert.equal(shared.values.get('privacy_prefs'), '{"analytics":true}');
  }
  const shared = {values: new Map([['micro_consent_v2', saved()]]), tabs: [], queue: []};
  assert.equal(runtime({shared}).state().analytics, true);
  assert.equal(runtime({shared, writeBlocked: true}).state().analytics, false);
});

test('invalid static configuration fails closed including trailing-newline endpoint and category', () => {
  for (const config of [null, [], {respectGpc: 'false'}, {consentLifetimeDays: 0}, {categories: ['analytics', 'constructor']}, {categories: ['analytics', 'marketing\n']}, {formspreeEndpoint: 'https://formspree.io/f/abc\n'}, {formspreeEndpoint: 'https://formspree.io.evil.test/f/abc'}, {formspreeEndpoint: 'https://formspree.io/f/abc?secret=value'}, {privacyPolicyUrl: 'javascript:alert(1)'}, {get categories() { throw Error('Do not read getter'); }}]) {
    const app = runtime({config}); app.window.MicroConsent.setConsent({analytics: true, marketing: true});
    assert.equal(app.state().analytics, false); assert.equal(app.byId('mc-request-form'), undefined); assert.equal(app.requests.length, 0);
  }
});

test('GPC and separate visitor opt-out suppress marketing without granting analytics', () => {
  const app = runtime({gpc: true});
  assert.equal(app.state().doNotSell, true); assert.equal(app.state().analytics, false);
  app.window.MicroConsent.setConsent({analytics: true, marketing: true, doNotSell: false});
  assert.equal(app.state().analytics, true); assert.equal(app.state().marketing, false); assert.equal(app.state().gpc, true);
  assert.equal(app.byId('mc-category-marketing').disabled, true); assert.equal(app.byId('mc-do-not-sell').disabled, true);
  const manual = runtime(); manual.window.MicroConsent.setConsent({analytics: true, marketing: true, doNotSell: true});
  manual.window.MicroConsent.setConsent({analytics: true, marketing: true});
  assert.equal(manual.state().doNotSell, true); assert.equal(manual.state().marketing, false);
  manual.window.MicroConsent.setConsent({marketing: true, doNotSell: false}); assert.equal(manual.state().marketing, true);
});

test('queued cross-tab grants never resurrect a newer withdrawal or loop on preference writes', () => {
  const shared = {values: new Map(), tabs: [], queue: []};
  const first = runtime({shared}), second = runtime({shared}); const received = [];
  second.window.MicroConsent.subscribe(state => received.push(state.analytics));
  first.window.MicroConsent.setConsent({analytics: true});
  first.window.MicroConsent.setConsent({analytics: false});
  first.drain();
  assert.ok(received.length >= 2); assert.ok(received.every(value => value === false));
  assert.equal(second.state().analytics, false);
  assert.equal([...first.operations, ...second.operations].filter(entry => entry[0] === 'set' && entry[1] === 'micro_consent_v2').length, 2);
});

test('expiry autonomously withdraws real tracker and tag grants across capped timer intervals', () => {
  const app = runtime({trackerConfig: true}); app.bridge(); app.loadTracker();
  const fired = []; app.window.Library = {record: () => fired.push('event')};
  app.loadTags([{id: 'event', type: 'call', method: 'Library.record', args: [], consent: 'analytics', trigger: {type: 'document_event', event: 'action'}}]);
  app.window.MicroConsent.setConsent({analytics: true});
  app.document.dispatchEvent({type: 'action'}); assert.equal(fired.length, 1);
  assert.equal(app.window.Aggregate.consent, true);
  let segments = 0;
  while (app.timers.size) {
    const [id, timer] = app.timers.entries().next().value;
    // The actual tracker also schedules its initial page view at zero delay.
    if (timer.delay === 0) { app.timers.delete(id); timer.fn(); continue; }
    assert.ok(timer.delay > 0 && timer.delay <= 2147483647); app.timers.delete(id); app.clock.now += timer.delay; timer.fn();
    assert.ok(++segments < 12, 'Long-lived consent must eventually expire');
  }
  assert.ok(segments > 1); assert.equal(app.window.Aggregate.consent, false);
  app.document.dispatchEvent({type: 'action'}); assert.equal(fired.length, 1);
  app.window.Aggregate.emit('after_expiry');
  assert.equal(app.requests.at(-1).payload.consentState, 'denied');
  assert.equal([...app.shared.values.keys()].some(key => /visitor/i.test(key)), false);
  assert.equal(app.session.size, 0); assert.equal(app.cookies.size, 0);
});

test('Aggregate bridge supports either CMP order and actual tag manager before/after bridge', () => {
  for (const order of ['bridge-first', 'cmp-first', 'tags-first']) {
    const app = runtime({load: false, trackerConfig: true}); const fired = []; app.window.Library = {record: () => fired.push('event')};
    const tags = [{id: 'event', type: 'call', method: 'Library.record', args: [], consent: 'analytics', trigger: {type: 'document_event', event: 'action'}}];
    if (order === 'tags-first') app.loadTags(tags);
    if (order === 'bridge-first') { app.bridge(); app.load(); } else { app.load(); app.bridge(); }
    if (order !== 'tags-first') app.loadTags(tags);
    app.loadTracker(); app.document.dispatchEvent({type: 'action'}); assert.equal(fired.length, 0);
    app.window.MicroConsent.setConsent({analytics: true}); app.document.dispatchEvent({type: 'action'}); assert.equal(fired.length, 1);
    app.window.Aggregate.emit('accepted'); assert.equal(app.requests.at(-1).payload.consentState, 'granted');
    app.window.MicroConsent.setConsent({}); app.document.dispatchEvent({type: 'action'}); assert.equal(fired.length, 1);
    app.window.Aggregate.emit('withdrawn'); assert.equal(app.requests.at(-1).payload.consentState, 'denied');
  }
  const existing = {getState: () => ({analytics: false})}; const app = runtime({existingCmp: existing}); app.bridge();
  assert.equal(app.window.AggregateConsent, existing); assert.equal(app.window.Aggregate, undefined);
});

test('Google adapter synchronously denies before initialization, updates only strict grants, and loads no script', () => {
  for (const before of [true, false]) {
    const app = runtime({load: false, gpc: true});
    if (before) app.google(); else app.load();
    if (before) app.load(); else app.google();
    const commands = () => Array.from(app.window.dataLayer, entry => Array.from(entry));
    assert.equal(commands()[0][1], 'default'); assert.ok(Object.values(commands()[0][2]).every(value => value === 'denied'));
    app.window.MicroConsent.setConsent({analytics: true, marketing: true});
    assert.deepEqual(clean(commands().at(-1)[2]), {analytics_storage: 'granted', ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied'});
    app.window.MicroConsent.setConsent({analytics: 'true', marketing: 'true'});
    assert.ok(Object.values(commands().at(-1)[2]).every(value => value === 'denied'));
    assert.equal(app.all.some(node => node.tagName === 'SCRIPT'), false); assert.equal(app.requests.length, 0);
  }
});

test('keyboard controls preserve focus, expose labelled inputs, and close without granting', () => {
  const app = runtime({config: {name: '<img src=x>', formspreeEndpoint: 'https://formspree.io/f/abc'}});
  app.text('Privacy choices').focus(); app.window.MicroConsent.open();
  assert.equal(app.document.activeElement, app.byId('mc-dialog-title'));
  app.byId('mc-preferences-tab').focus();
  app.byId('mc-preferences-tab').parentElement.dispatchEvent({type: 'keydown', key: 'ArrowRight'});
  assert.equal(app.document.activeElement, app.byId('mc-requests-tab')); assert.equal(app.byId('mc-preferences').hidden, true);
  app.byId('mc-dialog').dispatchEvent({type: 'keydown', key: 'Escape'});
  assert.equal(app.document.activeElement, app.text('Privacy choices')); assert.equal(app.state().analytics, false);
  for (const input of app.all.filter(node => ['INPUT', 'SELECT', 'TEXTAREA'].includes(node.tagName))) assert.ok(app.all.some(label => label.tagName === 'LABEL' && label.htmlFor === input.id));
  assert.equal(app.all.some(node => node.tagName === 'IMG'), false);
});

test('Formspree receives only explicitly submitted fields, with omitted credentials and referrer', async () => {
  let resolve; const pending = new Promise(done => { resolve = done; });
  const app = runtime({config: {formspreeEndpoint: 'https://formspree.io/f/abc', privacyPolicyUrl: 'https://site.example/privacy'}, fetch: () => pending});
  app.window.MicroConsent.openRequests(); assert.equal(app.requests.length, 0);
  app.byId('mc-request-email').value = 'person@example.test'; app.byId('mc-request-request_type').value = 'delete'; app.byId('mc-request-message').value = 'Please review this request.';
  app.byId('mc-request-form').dispatchEvent({type: 'submit'}); app.byId('mc-request-form').dispatchEvent({type: 'submit'});
  assert.equal(app.requests.length, 1);
  assert.deepEqual(app.requests[0].payload, {email: 'person@example.test', request_type: 'delete', message: 'Please review this request.'});
  assert.equal(app.requests[0].credentials, 'omit'); assert.equal(app.requests[0].referrerPolicy, 'no-referrer');
  assert.equal(app.shared.values.size, 0); assert.equal(app.text('Submit privacy request').disabled, true);
  resolve({ok: true}); await new Promise(setImmediate);
  assert.match(app.byId('mc-request-status').textContent, /submission does not erase stored data/);
  assert.equal(app.byId('mc-request-email').value, ''); assert.equal(app.text('Submit privacy request').disabled, false);
});

test('invalid and failed Formspree submissions retain user input and never alter consent', async () => {
  const app = runtime({config: {formspreeEndpoint: 'https://formspree.io/f/abc'}, fetch: () => Promise.reject(Error('private server response'))});
  app.byId('mc-request-form').dispatchEvent({type: 'submit'}); assert.equal(app.requests.length, 0);
  app.byId('mc-request-email').value = 'person@example.test'; app.byId('mc-request-message').value = 'x'.repeat(2001);
  app.byId('mc-request-form').dispatchEvent({type: 'submit'}); assert.equal(app.requests.length, 0);
  app.byId('mc-request-message').value = 'Request text'; app.byId('mc-request-form').dispatchEvent({type: 'submit'});
  await new Promise(setImmediate);
  assert.equal(app.byId('mc-request-email').value, 'person@example.test');
  assert.match(app.byId('mc-request-status').textContent, /could not be submitted/);
  assert.doesNotMatch(app.byId('mc-request-status').textContent, /private server response/);
  assert.equal(app.state().analytics, false); assert.equal(app.text('Submit privacy request').disabled, false);
});

const configFile = fs.readFileSync(path.join(base, 'micro-consent-dropins/consent-config.js'), 'utf8');
function configFrom(source) {
  const sandbox = {window: {}};
  vm.runInNewContext(source, sandbox);
  return clean(sandbox.window.MicroConsentConfig);
}
const shown = app => app.all.filter(node => node.textContent && node.tagName !== 'STYLE').map(node => node.tagName + ':' + node.textContent);
const buttonsIn = (app, parent) => parent.children.filter(node => node.tagName === 'DIV' && node.className === 'mc-actions')[0].children.map(node => node.textContent);

test('the shipped config file is valid, and uncommenting every option reproduces the defaults exactly', () => {
  const shipped = configFrom(configFile);
  assert.deepEqual(Object.keys(shipped), ['name', 'privacyPolicyUrl', 'formspreeEndpoint', 'categories', 'respectGpc', 'consentLifetimeDays', 'revision', 'storageKey', 'aggregateNamespace', 'text', 'theme', 'buttons']);
  const plain = runtime({config: shipped});
  assert.equal(plain.all.some(node => node.className === 'mc-error'), false);
  assert.equal(plain.text('Example website: privacy choices').tagName, 'H2');

  const everything = configFrom(configFile.replace(/^(\s*)\/\/ (\w+: .*)$/gm, '$1$2'));
  assert.ok(Object.keys(everything.text).length >= 37, 'every wording key is listed in the file');
  assert.deepEqual(Object.keys(everything.theme).length, 7);
  assert.deepEqual(everything.buttons, {show: ['reject', 'accept', 'manage'], reopen: 'bottom-right'});
  const explicit = runtime({config: everything});
  assert.equal(explicit.all.some(node => node.className === 'mc-error'), false);
  assert.deepEqual(shown(explicit), shown(plain), 'listed values equal the built-in defaults');
  const root = explicit.all.find(node => node.className === 'mc-root');
  assert.equal(root.style.properties['--mc-button-text'], '#174F85');
  assert.equal(root.style.properties['--mc-background'], '#FFFFFF');
});

test('configured wording, colors, button order and reopen position are applied as plain text and CSS variables', () => {
  const app = runtime({config: {
    name: 'Shop', consentLifetimeDays: 30, categories: ['analytics', 'ad-tools'], formspreeEndpoint: 'https://formspree.io/f/abc123',
    text: {title: 'Cookies at {name}', description: '<b>Not markup</b>', details: [], accept: 'Yes', reject: 'No', manage: 'Choose', save: 'Keep',
      reopen: 'Cookies', storageNotice: 'Kept {days} days.', categories: {'ad-tools': 'Advertising'}, requestAccess: 'See my data'},
    theme: {background: '#102030', text: '#fff', accent: '#9CC8FF', buttonBackground: '#FFFFFF', buttonText: '#102030', buttonBorder: '#FFFFFF'},
    buttons: {show: ['manage', 'accept', 'reject'], reopen: 'bottom-left'}
  }});
  assert.equal(app.all.some(node => node.className === 'mc-error'), false);
  const banner = app.all.find(node => node.className === 'mc-banner');
  assert.deepEqual(banner.children.filter(node => node.tagName === 'P').map(node => node.textContent), ['<b>Not markup</b>']);
  assert.equal(banner.children[0].textContent, 'Cookies at Shop');
  assert.deepEqual(buttonsIn(app, banner), ['Choose', 'Yes', 'No']);
  assert.deepEqual(buttonsIn(app, app.byId('mc-preferences')), ['Keep', 'Yes', 'No'], 'manage becomes save in the dialog');
  assert.ok(app.text('Kept 30 days.'));
  assert.ok(app.text('Advertising'));
  assert.ok(app.text('Analytics (enhanced details when connected to the analytics tracker)'));
  assert.ok(app.text('See my data'));
  const opener = app.all.find(node => String(node.className).includes('mc-open'));
  assert.equal(opener.className, 'mc-button mc-open mc-open--left');
  assert.equal(opener.textContent, 'Cookies');
  assert.deepEqual(app.all.find(node => node.className === 'mc-root').style.properties, {
    '--mc-background': '#102030', '--mc-text': '#FFFFFF', '--mc-accent': '#9CC8FF', '--mc-button-background': '#FFFFFF', '--mc-button-text': '#102030', '--mc-button-border': '#FFFFFF'
  });

  const minimal = runtime({config: {buttons: {show: ['reject', 'accept'], reopen: 'hidden'}}});
  assert.deepEqual(buttonsIn(minimal, minimal.all.find(node => node.className === 'mc-banner')), ['Reject optional categories', 'Accept optional categories']);
  assert.deepEqual(buttonsIn(minimal, minimal.byId('mc-preferences')), ['Reject optional categories', 'Accept optional categories', 'Save selected choices']);
  assert.equal(minimal.all.find(node => String(node.className).includes('mc-open')).hidden, true);
  minimal.window.MicroConsent.open();
  assert.equal(minimal.byId('mc-dialog').open, true, 'the API still opens preferences');
});

test('invalid wording, colors or buttons fail closed like any other invalid setting', () => {
  for (const config of [
    {text: {footer: 'x'}}, {text: {accept: ''}}, {text: {accept: 'Line\nbreak'}}, {text: {accept: 'é'.repeat(61)}}, {text: {description: 'x'.repeat(1001)}},
    {text: {details: ['a', 'b', 'c', 'd', 'e']}}, {text: {details: 'one'}}, {text: {categories: {Analytics: 'x'}}}, {text: {categories: {none: 'x'}}}, {text: ['x']},
    {theme: {accent: 'red'}}, {theme: {shadow: '#000000'}}, {theme: {text: '#999999'}}, {theme: {buttonBorder: '#FFFFFF'}}, {theme: {background: '#000; display:none'}},
    {buttons: {show: ['accept', 'manage']}}, {buttons: {show: ['reject']}}, {buttons: {show: ['reject', 'reject', 'accept']}}, {buttons: {show: ['reject', 'save']}},
    {buttons: {reopen: 'top'}}, {buttons: {other: true}}, {buttons: ['reject']}
  ]) {
    const app = runtime({config});
    assert.ok(app.all.some(node => node.className === 'mc-error'), JSON.stringify(config));
    app.text('Accept optional categories') && assert.equal(app.text('Accept optional categories').disabled, true);
    assert.equal(app.all.find(node => node.className === 'mc-root').style.properties['--mc-background'], undefined, 'no partial theme');
    app.window.MicroConsent.setConsent({analytics: true});
    assert.equal(app.state().analytics, false, JSON.stringify(config));
  }
});
