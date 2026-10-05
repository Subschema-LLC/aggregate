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

// Records page-view requests and announced events in the order they happen.
function load(readyState, namespace) {
  const timeline = [];
  const listeners = {};
  const timers = [];
  const script = {dataset: namespace ? {namespace} : {}, src: ''};
  const document = {
    currentScript: script, readyState, referrer: '', cookie: '',
    addEventListener: (name, listener) => { listeners[name] = listener; },
    dispatchEvent: (event) => timeline.push(['event', event.type, event.detail]),
    getElementsByTagName: () => [script], querySelector: () => null
  };
  const window = {};
  window[namespace || 'Aggregate'] = {endpoint: 'https://analytics.example/api/receive', websiteToken: 'site-token'};
  const storage = {getItem: () => null, setItem: () => {}, removeItem: () => {}};
  vm.runInNewContext(sdkSource, {
    URL, document, window, screen: {width: 1440}, localStorage: storage, sessionStorage: storage,
    location: {origin: 'https://www.example.com', pathname: '/weather', protocol: 'https:', search: '', href: 'https://www.example.com/weather'},
    setTimeout: (callback) => timers.push(callback),
    CustomEvent: class { constructor(type, init) { this.type = type; this.detail = init && init.detail; } },
    self: {crypto: {randomUUID: () => '00000000-0000-4000-8000-000000000001'}},
    fetch: (_url, request) => { timeline.push(['request', JSON.parse(request.body).eventName]); return Promise.resolve({ok: true}); }
  });
  return {timeline, listeners, timers};
}

test('the tracker announces itself to tag managers right after its automatic page view', () => {
  const loading = load('loading');
  assert.deepEqual(loading.timeline, [], 'nothing before the document is ready');
  loading.listeners.DOMContentLoaded();
  assert.deepEqual(JSON.parse(JSON.stringify(loading.timeline)), [['request', 'view'], ['event', 'aggregate:tracker-ready', {namespace: 'Aggregate'}]]);

  // Loaded later, for example by a tag manager after the page was ready.
  const late = load('complete', 'Shop');
  assert.equal(late.timers.length, 1);
  late.timers[0]();
  assert.deepEqual(JSON.parse(JSON.stringify(late.timeline)), [['request', 'view'], ['event', 'aggregate:tracker-ready', {namespace: 'Shop'}]]);
});
