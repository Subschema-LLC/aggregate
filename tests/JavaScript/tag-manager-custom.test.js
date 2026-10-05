'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const base = path.join(__dirname, '../..');
const corpus = JSON.parse(fs.readFileSync(path.join(base, 'tests/Fixtures/custom-script-corpus.json'), 'utf8'));
const templates = JSON.parse(fs.readFileSync(path.join(base, 'templates/tag_manager/custom_script_templates.json'), 'utf8'))
  .map((template) => ({...template, code: template.code.join('\n')}));

// TagManagerCustomCodeTest checks the server's validator against the same
// corpus; this checks that each entry's syntax verdict matches a real engine.
test('the custom script corpus records what a JavaScript engine accepts as a strict function body', () => {
  for (const entry of corpus) {
    let accepted = true;
    try {
      new Function('tag', "'use strict';\n" + entry.code);
    } catch (error) {
      accepted = false;
    }
    assert.equal(accepted, entry.syntax, entry.code);
    if (entry.allowed) assert.equal(entry.syntax, true, 'allowed code must be valid: ' + entry.code);
  }
});

class Element {
  constructor(attributes = {}, parent = null) { this.attributes = attributes; this.parent = parent; }
  getAttribute(name) { return Object.prototype.hasOwnProperty.call(this.attributes, name) ? this.attributes[name] : null; }
  // Enough of CSS for the templates: tag, .class and [attribute], combined.
  matches(selector) {
    const tag = /^([a-z]+)/.exec(selector);
    const className = /\.([a-z-]+)/.exec(selector);
    const attribute = /\[([a-z-]+)/.exec(selector);
    return (!tag || this.tagName === tag[1])
      && (!className || (this.getAttribute('class') || '').split(/\s+/).includes(className[1]))
      && (!attribute || this.getAttribute(attribute[1]) !== null);
  }
  closest(selector) { for (let node = this; node; node = node.parent) if (node.matches(selector)) return node; return null; }
}
class HTMLFormElement extends Element { get tagName() { return 'form'; } }
class Anchor extends Element { get tagName() { return 'a'; } get href() { return this.attributes.href; } }
class Span extends Element { get tagName() { return 'span'; } }

function page({pathname = '/', scrollHeight = 2000, element = null} = {}) {
  const listeners = {document: new Map(), window: new Map()};
  const add = (target) => (name, callback, options) => {
    const capture = options === true || !!(options && options.capture);
    listeners[target].set(name + (capture ? ':capture' : ''), callback);
  };
  const remove = (target) => (name, callback, options) => {
    const key = name + ((options === true || !!(options && options.capture)) ? ':capture' : '');
    if (listeners[target].get(key) === callback) listeners[target].delete(key);
  };
  const observers = [];
  const images = [];
  const window = {innerHeight: 1000, scrollY: 0, addEventListener: add('window'), removeEventListener: remove('window'),
    IntersectionObserver: class {
      constructor(callback, options) { this.callback = callback; this.options = options; this.observed = []; this.connected = true; observers.push(this); }
      observe(node) { this.observed.push(node); }
      disconnect() { this.connected = false; }
    }};
  const document = {baseURI: 'https://shop.example' + pathname, addEventListener: add('document'), removeEventListener: remove('document'),
    documentElement: {scrollHeight}, querySelector: () => element};
  const context = vm.createContext({window, document, location: {pathname, hostname: 'shop.example', href: 'https://shop.example' + pathname},
    Element, HTMLFormElement, URL, Set, Promise, Math,
    Image: class { constructor(width, height) { this.width = width; this.height = height; images.push(this); } },
    IntersectionObserver: window.IntersectionObserver});
  Object.defineProperty(window, 'scrollY', {get: () => context.scrollY ?? 0, configurable: true});
  const calls = {emit: [], push: [], loadScript: [], cleanup: []};
  const tag = {
    id: 'test', event: {trigger: 'dom_ready', name: null}, data: {}, get: () => undefined, consent: () => true,
    emit: (...entry) => { calls.emit.push(entry); return true; },
    push: (entry) => { calls.push.push(entry); return true; },
    loadScript: (url) => { calls.loadScript.push(url); return Promise.resolve(); },
    onCleanup: (callback) => calls.cleanup.push(callback)
  };
  return {context, window, document, listeners, observers, images, calls, tag,
    run: (code) => vm.runInContext("(function (tag) {\n'use strict';\n" + code + '\n})', context)(tag),
    cleanup: () => calls.cleanup.forEach((callback) => callback())};
}

const template = (id) => templates.find((entry) => entry.id === id).code;
const plain = (value) => JSON.parse(JSON.stringify(value));

test('every starter template compiles as a strict function body and names an existing trigger and category', () => {
  assert.deepEqual([...new Set(templates.map((entry) => entry.group))], ['Engagement tracking', 'dataLayer helpers', 'Third-party vendors']);
  for (const entry of templates) {
    assert.doesNotThrow(() => new Function('tag', "'use strict';\n" + entry.code), entry.id);
    assert.ok(['dom_ready', 'window_load'].includes(entry.trigger.type), entry.id);
    assert.ok(['analytics', 'marketing'].includes(entry.consent), entry.id);
    assert.match(entry.code, /^\/\/ /, entry.id + ' starts with an explanation');
  }
});

test('the click template sends its fixed event for elements matching its selector and removes its listener on cleanup', () => {
  const browser = page();
  browser.run(template('cta-clicks'));
  const listener = browser.listeners.document.get('click:capture');
  listener({target: new Span({}, new Anchor({class: 'button signup-button', href: '/signup'}))});
  listener({target: new Span({class: 'other-button'})});
  listener({target: {}});
  assert.deepEqual(plain(browser.calls.emit), [['signup_click', {section: 'pricing'}]]);
  browser.cleanup();
  assert.equal(browser.listeners.document.has('click:capture'), false);
});

test('the outbound template sends only the host name of links to other websites', () => {
  const browser = page();
  browser.run(template('outbound-links'));
  const listener = browser.listeners.document.get('click:capture');
  listener({target: new Span({}, new Anchor({href: 'https://partner.example/path?email=private@example.com'}))});
  listener({target: new Anchor({href: '/internal'})});
  listener({target: new Anchor({href: 'mailto:someone@example.com'})});
  listener({target: new Anchor({href: 'https://['})});
  assert.deepEqual(plain(browser.calls.emit), [['outbound_click', {link_domain: 'partner.example'}]]);
  browser.cleanup();
  assert.equal(browser.listeners.document.size, 0);
});

test('the form template names forms matching its selector without reading fields', () => {
  const browser = page();
  browser.run(template('form-submissions'));
  const listener = browser.listeners.document.get('submit:capture');
  listener({target: new HTMLFormElement({class: 'newsletter'})});
  listener({target: new HTMLFormElement({class: 'search'})});
  assert.deepEqual(plain(browser.calls.emit), [['form_submit', {form_name: 'newsletter'}]]);
  assert.doesNotMatch(template('form-submissions'), /\.value|FormData|elements/);
});

test('the scroll template sends each threshold once and stops listening at 100 percent', () => {
  const browser = page({scrollHeight: 3000});
  browser.run(template('scroll-depth'));
  const listener = browser.listeners.window.get('scroll');
  for (const position of [600, 600, 1100, 2000]) {
    browser.context.scrollY = position;
    listener();
  }
  assert.deepEqual(plain(browser.calls.emit).map((entry) => entry[1].scroll_percent), [25, 50, 75, 100]);
  assert.equal(browser.listeners.window.has('scroll'), false);
  const short = page({scrollHeight: 500});
  short.run(template('scroll-depth'));
  assert.equal(short.calls.emit.length, 4, 'a page shorter than the window counts as fully seen');
});

test('the visibility template pushes one dataLayer event and disconnects; missing elements do nothing', () => {
  const browser = page({element: new Span({id: 'pricing'})});
  browser.run(template('element-visible'));
  const [observer] = browser.observers;
  assert.equal(observer.options.threshold, 0.5);
  observer.callback([{isIntersecting: false}]);
  observer.callback([{isIntersecting: true}]);
  assert.deepEqual(plain(browser.calls.push), [{event: 'pricing_seen'}]);
  assert.equal(observer.connected, false);
  const empty = page();
  empty.run(template('element-visible'));
  assert.equal(empty.observers.length, 0);
});

test('the page condition template pushes only on the matching page', () => {
  const thanks = page({pathname: '/checkout/thank-you'});
  thanks.run(template('page-condition'));
  assert.deepEqual(plain(thanks.calls.push), [{event: 'checkout_complete'}]);
  const other = page({pathname: '/products'});
  other.run(template('page-condition'));
  assert.equal(other.calls.push.length, 0);
});

test('the vendor templates load over HTTPS, set up only an available library, and send no referrer', async () => {
  const browser = page();
  browser.run(template('vendor-library'));
  await new Promise((resolve) => setImmediate(resolve));
  assert.deepEqual(browser.calls.loadScript, ['https://cdn.vendor.example/library.js']);
  const initialized = page();
  initialized.window.VendorLibrary = {init: (options) => { initialized.window.options = options; }};
  initialized.run(template('vendor-library'));
  await new Promise((resolve) => setImmediate(resolve));
  assert.deepEqual(plain(initialized.window.options), {account: 'YOUR-ACCOUNT-ID'});

  const pixel = page();
  pixel.run(template('image-pixel'));
  assert.equal(pixel.images.length, 1);
  assert.equal(pixel.images[0].referrerPolicy, 'no-referrer');
  assert.match(pixel.images[0].src, /^https:\/\//);
});
