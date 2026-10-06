'use strict';

// Every tracker build must behave exactly like the readable source for the
// settings it is served with. The server sends a smaller build only when page
// depth is turned off or the strict profile is on (TrackerBuilds), and the
// minified builds also shorten the tracker's internal names. These tests run
// one scripted visit through each build and compare everything a page or
// visitor could observe: requests, storage and cookie use, address changes,
// links, warnings, events and which browser properties are read.
//
// AGGREGATE_COMPACT_BUILDS_DIR adds the builds BrowserScriptCompactor makes on
// a server without Node (full.js, without-page-depth.js, strict.js), as written
// by tests/Service/TrackerBuildsCompactTest.php.

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');
const {build, PLACEHOLDERS} = require('../../scripts/build-js.cjs');

const projectDir = path.resolve(__dirname, '../..');
const source = fs.readFileSync(path.join(projectDir, 'public', 'aggregate.js'), 'utf8');

let builds;
async function trackerBuilds() {
  if (builds) return builds;
  const compactDirectory = process.env.AGGREGATE_COMPACT_BUILDS_DIR;
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'aggregate-tracker-builds-'));
  builds = {};
  try {
    const outputs = await build({outputDir: directory, report: () => {}});
    builds.full = outputs.get('var/browser/aggregate.template.min.js');
    builds['without-page-depth'] = outputs.get('var/browser/aggregate-without-page-depth.template.min.js');
    builds.strict = outputs.get('var/browser/aggregate-strict.template.min.js');
  } catch (error) {
    // The PHP test suite checks the compacted builds where Terser is not installed.
    if (!compactDirectory || !/Terser .* is required/.test(error.message)) throw error;
  } finally {
    fs.rmSync(directory, {recursive: true, force: true});
  }
  if (compactDirectory) {
    for (const name of ['full', 'without-page-depth', 'strict']) {
      builds['compact ' + name] = fs.readFileSync(path.join(compactDirectory, name + '.js'), 'utf8');
    }
  }
  return builds;
}

/** The served script: a template's placeholders, or the source's declarations, filled with settings. */
function configured(script, served) {
  const values = {
    namespace: JSON.stringify('Aggregate'),
    internalTrafficDefaults: JSON.stringify({storage: 'cookie', name: 'orgInternalTraffic', value: 'true', cookieDomain: ''}),
    customDataDefaults: JSON.stringify(served.customData),
    collectionDefaults: JSON.stringify(served.collection)
  };
  for (const [variable, placeholder] of Object.entries(PLACEHOLDERS)) {
    if (script.includes(placeholder)) {
      assert.equal(script.split(placeholder).length, 2);
      script = script.split(placeholder).join(values[variable]);
    } else {
      const declaration = new RegExp('^  var ' + variable + ' = .+;$', 'm');
      assert.ok(declaration.test(script), 'declaration of ' + variable);
      script = script.replace(declaration, () => '  var ' + variable + ' = ' + values[variable] + ';');
    }
  }
  return script;
}

class Element {
  constructor(tagName, attributes, parent) {
    this.tagName = tagName.toUpperCase();
    this.nodeType = 1;
    this.parentNode = parent || null;
    this.parentElement = parent || null;
    this.values = new Map(Object.entries(attributes || {}));
  }
  get attributes() {
    return [...this.values].map(([name, value]) => ({name, value}));
  }
  getAttribute(name) { return this.values.has(name) ? this.values.get(name) : null; }
  hasAttribute(name) { return this.values.has(name); }
  setAttribute(name, value) { this.values.set(name, String(value)); }
  get href() { return this.values.has('href') ? new URL(this.values.get('href'), 'https://www.example.com/pricing/12345').href : ''; }
}

/** One scripted visit; returns everything observable, in order. */
async function visit(script) {
  const log = [];
  const record = (entry) => log.push(entry);
  const store = (name, values) => ({
    getItem: (key) => { record([name + '.getItem', key]); return values.has(key) ? values.get(key) : null; },
    setItem: (key, value) => { record([name + '.setItem', key, String(value)]); values.set(key, String(value)); },
    removeItem: (key) => { record([name + '.removeItem', key]); values.delete(key); },
    values
  });
  const localStorage = store('localStorage', new Map([['aggregate_visitor_id', 'earlier-visitor']]));
  const sessionStorage = store('sessionStorage', new Map([['aggregate_page_sequence:site-token', '3'], ['aggregate_page_sequence:other-token', '5'], ['aggregate_session_id', 'earlier-session']]));
  let cookie = 'aggregate_session=earlier-session; orgInternalTraffic=true';
  const listeners = {capture: {}, bubble: {}};
  const scriptElement = {dataset: {}, src: 'https://analytics.example/aggregate.js?min=1&token=site-token&cd.page_type=pricing&cd.qty=3&cd.vip=yes'};
  const location = {
    origin: 'https://www.example.com', protocol: 'https:', pathname: '/pricing/12345',
    search: '?utm_medium=email&utm_source=news&aggregate_page_sequence=4', hash: ''
  };
  Object.defineProperty(location, 'href', {enumerable: true, get: () => location.origin + location.pathname + location.search + location.hash});
  const history = {
    state: {page: 1},
    replaceState(state, title, url) {
      record(['history.replaceState', url]);
      const destination = new URL(url);
      location.pathname = destination.pathname;
      location.search = destination.search;
      location.hash = destination.hash;
    }
  };
  // A build without page depth registers no link listener, which nothing can
  // observe; registering listeners is therefore not compared.
  const recorded = (name, target) => new Proxy(target, {
    get(object, property) {
      if (typeof property === 'string' && property !== 'addEventListener') record([name + '.' + property]);
      return Reflect.get(object, property);
    },
    set(object, property, value) {
      if (typeof property === 'string') record([name + '.' + property + '=', String(value)]);
      return Reflect.set(object, property, value);
    }
  });
  const documentTarget = {
    currentScript: scriptElement,
    readyState: 'loading',
    referrer: 'https://www.google.com/search?q=private',
    baseURI: 'https://www.example.com/pricing/12345',
    getElementsByTagName: () => [scriptElement],
    querySelector: () => null,
    addEventListener(type, listener, options) {
      const phase = options === true || (options && options.capture) ? 'capture' : 'bubble';
      (listeners[phase][type] = listeners[phase][type] || []).push(listener);
    },
    dispatchEvent(event) { record(['dispatchEvent', event.type, JSON.stringify(event.detail)]); return true; }
  };
  Object.defineProperty(documentTarget, 'cookie', {
    get: () => cookie,
    set: (value) => {
      record(['cookie=', value]);
      const [pair] = value.split(';');
      const name = pair.split('=')[0];
      const kept = cookie.split('; ').filter((entry) => entry && entry.split('=')[0] !== name);
      if (!/max-age=0/.test(value)) kept.push(pair);
      cookie = kept.join('; ');
    }
  });
  const window = {Aggregate: {}, innerWidth: 900};
  Object.defineProperty(window, 'history', {get: () => history});
  const timers = [];
  const context = {
    URL,
    console: {warn: (message) => record(['console.warn', message])},
    CustomEvent: class { constructor(type, init) { this.type = type; this.detail = init && init.detail; } },
    document: recorded('document', documentTarget),
    location: recorded('location', location),
    screen: recorded('screen', {width: 1440}),
    window: recorded('window', window),
    self: {crypto: {randomUUID: () => '00000000-0000-4000-8000-000000000001'}},
    localStorage,
    sessionStorage,
    setTimeout: (callback) => timers.push(callback),
    fetch: (url, request) => {
      record(['fetch', url, request.method, request.keepalive, request.credentials, request.referrerPolicy, JSON.parse(request.body)]);
      return Promise.resolve({ok: true, json: () => Promise.resolve({warnings: ['goal_not_allowed']})});
    }
  };
  vm.runInNewContext(script, context);
  const settle = () => new Promise((resolve) => setImmediate(resolve));
  const step = async (name, action) => {
    record(['step', name]);
    action();
    await settle();
  };
  const dispatch = (type, target) => {
    const pathToRoot = [];
    for (let node = target; node; node = node.parentNode) pathToRoot.push(node);
    const event = {type, target, button: 0, defaultPrevented: false, composedPath: () => pathToRoot};
    for (const listener of listeners.capture[type] || []) listener(event);
    for (const listener of listeners.bubble[type] || []) listener(event);
  };
  const api = window.Aggregate;

  await step('ready', () => listeners.bubble.DOMContentLoaded.forEach((listener) => listener()));
  await step('emit', () => record(['returned', api.emit('signup', {plan: 'pro', qty: 2, medium: 'x', vip: 'yes', nested: {a: 1}}, 'newsletter')]));
  await step('invalid emit', () => record(['returned', api.emit('order-1234567', {plan: 'pro'})]));
  const page = new Element('main', {});
  const link = new Element('a', {href: '/next?ref=1#top'}, page);
  await step('link click', () => dispatch('click', link));
  record(['link href', link.getAttribute('href')]);
  const section = new Element('section', {'data-aggregate-prop-qty': '7', 'data-aggregate-prop-plan': 'outer'}, page);
  const button = new Element('button', {'data-aggregate-event': 'cta_click', 'data-aggregate-goal': 'newsletter', 'data-aggregate-prop-plan': 'pro', 'data-aggregate-prop-vip': 'true'}, section);
  await step('marked click', () => dispatch('click', button));
  await step('reserved marked click', () => dispatch('click', new Element('button', {'data-aggregate-event': 'view'}, section)));
  await step('consent', () => api.setConsent(true));
  await step('consented emit', () => api.emit('purchase', {plan: 'pro', qty: 1.5}));
  await step('page view', () => api.trackView());
  await step('configure', () => api.configure({
    websiteToken: 'other-token',
    customData: {pageSequenceEnabled: true, pageSequenceMethod: 'url_parameter', queryParameters: {utm_source: 'source'}, consentFreeProperties: ['source']},
    internalTraffic: {storage: 'local_storage', name: 'staff'}
  }));
  await step('emit after configure', () => api.emit('after_configure', {plan: 'basic'}));
  await step('second link click', () => dispatch('click', link));
  record(['link href', link.getAttribute('href')]);
  await step('withdraw', () => api.setConsent('false'));
  await step('emit after withdrawal', () => api.emit('withdrawn'));
  const form = new Element('form', {'data-aggregate-event': 'form_submit', 'data-aggregate-prop-plan': 'form'}, page);
  await step('form submit', () => dispatch('submit', form));
  await step('strict request', () => api.configure({collectionProfile: 'strict'}));
  await step('strict emit', () => api.emit('after_strict', {plan: 'pro'}));
  await step('timers', () => timers.splice(0).forEach((callback) => callback()));

  record(['localStorage', Object.fromEntries(localStorage.values)]);
  record(['sessionStorage', Object.fromEntries(sessionStorage.values)]);
  record(['cookie', cookie]);
  record(['location', location.href]);
  return log;
}

const serving = {
  'page depth off, tab storage': {
    builds: ['full', 'without-page-depth'],
    customData: {queryParameters: {utm_medium: 'medium', utm_source: 'source'}, consentFreeProperties: ['medium', 'plan', 'qty'], pageSequenceEnabled: false, pageSequenceMethod: 'session_storage', propertyTypes: {qty: 'integer', vip: 'boolean'}},
    collection: {profile: 'standard'}
  },
  'page depth off, URL parameter': {
    builds: ['full', 'without-page-depth'],
    customData: {queryParameters: {utm_medium: 'medium'}, consentFreeProperties: ['medium'], pageSequenceEnabled: false, pageSequenceMethod: 'url_parameter'},
    collection: {profile: 'standard'}
  },
  'page depth on, tab storage': {
    builds: ['full'],
    customData: {queryParameters: {utm_medium: 'medium'}, consentFreeProperties: ['medium', 'plan'], pageSequenceEnabled: true, pageSequenceMethod: 'session_storage', pageSequenceExcludedPaths: ['/account/**'], propertyTypes: {qty: 'integer'}},
    collection: {profile: 'standard'}
  },
  'page depth on, URL parameter': {
    builds: ['full'],
    customData: {queryParameters: {utm_medium: 'medium'}, consentFreeProperties: ['medium'], pageSequenceEnabled: true, pageSequenceMethod: 'url_parameter', pageSequenceExcludedPaths: []},
    collection: {profile: 'standard'}
  },
  'strict profile': {
    builds: ['full', 'strict'],
    customData: {queryParameters: {}, consentFreeProperties: [], pageSequenceEnabled: false, pageSequenceMethod: 'url_parameter', propertyTypes: {qty: 'integer'}},
    collection: {profile: 'strict'}
  }
};

for (const [name, served] of Object.entries(serving)) {
  test('every build sent for ' + name + ' behaves like the readable source', async () => {
    const available = await trackerBuilds();
    const expected = await visit(configured(source, served));
    assert.ok(expected.some((entry) => entry[0] === 'fetch'), 'the visit sends events');
    const candidates = served.builds.flatMap((buildName) => [buildName, 'compact ' + buildName]).filter((buildName) => available[buildName]);
    for (const buildName of candidates) {
      assert.deepEqual(await visit(configured(available[buildName], served)), expected, buildName);
    }
  });
}

test('page depth still works in the builds that keep it', async () => {
  const sequences = (log) => log.filter((entry) => entry[0] === 'fetch').map((entry) => entry[6].customData && entry[6].customData.page_sequence);
  const stored = await visit(configured(source, serving['page depth on, tab storage']));
  assert.equal(sequences(stored)[0], 4, 'the stored count of 3 advances to 4 on this page');
  const carried = await visit(configured(source, serving['page depth on, URL parameter']));
  assert.equal(sequences(carried)[0], 4, 'the count of 4 arrives in the address');
  assert.ok(carried.some((entry) => entry[0] === 'link href' && /aggregate_page_sequence=5/.test(entry[1])), 'links carry the next count');
  assert.ok(carried.some((entry) => entry[0] === 'history.replaceState'), 'the count is removed from the address');
});

test('the smaller builds are smaller', async (t) => {
  const available = await trackerBuilds();
  if (!available.full) return t.skip('Terser is not installed');
  const zlib = require('node:zlib');
  const size = (content) => zlib.gzipSync(content).length;
  assert.ok(size(available['without-page-depth']) < 0.9 * size(available.full));
  assert.ok(size(available.strict) < 0.6 * size(available.full));
});
