'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const sdkSource = fs.readFileSync(
  path.join(__dirname, '..', '..', 'public', 'aggregate.js'),
  'utf8'
);

function createStorage(initialValues) {
  const values = new Map(Object.entries(initialValues || {}));

  return {
    getItem: (key) => values.has(key) ? values.get(key) : null,
    setItem: (key, value) => values.set(key, String(value)),
    removeItem: (key) => values.delete(key),
    has: (key) => values.has(key)
  };
}

function loadSdk(options) {
  const requests = [];
  const cookieWrites = [];
  const consoleWarnings = [];
  const localStorage = createStorage({aggregate_visitor_id: 'legacy-visitor'});
  const sessionStorage = createStorage({aggregate_session_id: 'legacy-session'});
  const script = {
    dataset: Object.assign({websiteToken: 'site-token'}, options.dataset || {}),
    src: options.src || ''
  };
  const document = {
    currentScript: script,
    readyState: 'loading',
    referrer: '',
    addEventListener: () => {},
    getElementsByTagName: () => [script]
  };
  let cookieValue = 'aggregate_session=legacy-cookie';
  Object.defineProperty(document, 'cookie', {
    get: () => cookieValue,
    set: (value) => {
      cookieWrites.push(value);
      cookieValue = value;
    }
  });

  const configuredNamespace = (options.dataset && options.dataset.namespace) || 'Aggregate';
  const window = {};
  window[configuredNamespace] = Object.assign({
    endpoint: 'https://analytics.example/api/receive',
    websiteToken: 'site-token'
  }, options.inline || {});
  const context = {
    URL,
    console: {
      warn: (message) => consoleWarnings.push(message)
    },
    document,
    fetch: (_url, request) => {
      requests.push(JSON.parse(request.body));
      return options.fetch
        ? options.fetch(_url, request)
        : Promise.resolve(options.fetchResponse || {ok: true});
    },
    localStorage,
    location: {
      origin: 'https://www.example.com',
      pathname: '/pricing',
      protocol: 'https:'
    },
    screen: {width: 1440},
    self: {crypto: {randomUUID: () => '00000000-0000-4000-8000-000000000001'}},
    sessionStorage,
    setTimeout: () => {},
    window
  };
  window.location = context.location;

  vm.runInNewContext(sdkSource, context, {filename: 'aggregate.js'});

  return {window, requests, cookieWrites, consoleWarnings, localStorage, sessionStorage};
}

function flushPromises() {
  return new Promise((resolve) => setImmediate(resolve));
}

function assertLastRequestIsAnonymous(runtime) {
  const payload = runtime.requests.at(-1);

  assert.equal(payload.consentState, 'denied');
  assert.equal(payload.eventName, 'button_click');
  assert.equal(payload.eventData, undefined);
  assert.equal(payload.goalEvent, 'purchase');
  assert.equal(payload.screenWidth, undefined);
  assert.equal(payload.visitorId, undefined);
  assert.equal(payload.sessionId, undefined);
  assert.equal(runtime.localStorage.has('aggregate_visitor_id'), false);
  assert.equal(runtime.sessionStorage.has('aggregate_session_id'), false);
  assert.equal(runtime.cookieWrites.some((value) => /max-age=1800/i.test(value)), false);
}

test('anonymous events transmit goal candidates but omit enhanced fields', () => {
  const runtime = loadSdk({});

  runtime.window.Aggregate.emit('button_click', {email: 'private@example.com'}, 'purchase');
  const payload = runtime.requests.at(-1);

  assert.equal(payload.consentState, 'unknown');
  assert.equal(payload.goalEvent, 'purchase');
  assert.equal(payload.eventData, undefined);
  assert.equal(payload.screenWidth, undefined);
  assert.equal(payload.visitorId, undefined);
  assert.equal(payload.sessionId, undefined);
});

for (const value of [false, 0, '0', 'false', 'denied', 'unknown']) {
  test(`configure consent ${JSON.stringify(value)} never enables enhanced analytics`, () => {
    const runtime = loadSdk({});

    runtime.window.Aggregate.configure({consent: value});
    runtime.window.Aggregate.emit('button_click', {email: 'private@example.com'}, 'purchase');

    assertLastRequestIsAnonymous(runtime);
  });
}

test('the inline string "false" never enables enhanced analytics', () => {
  const runtime = loadSdk({inline: {consent: 'false'}});

  runtime.window.Aggregate.emit('button_click', {email: 'private@example.com'}, 'purchase');

  assertLastRequestIsAnonymous(runtime);
});

test('the data-consent string "false" never enables enhanced analytics', () => {
  const runtime = loadSdk({dataset: {consent: 'false'}});

  runtime.window.Aggregate.emit('button_click', {email: 'private@example.com'}, 'purchase');

  assertLastRequestIsAnonymous(runtime);
});

test('the consent=false query parameter never enables enhanced analytics', () => {
  const runtime = loadSdk({
    src: 'https://analytics.example/aggregate.js?consent=false'
  });

  runtime.window.Aggregate.emit('button_click', {email: 'private@example.com'}, 'purchase');

  assertLastRequestIsAnonymous(runtime);
});

test('an explicit true value enables enhanced fields', () => {
  const runtime = loadSdk({});

  runtime.window.Aggregate.configure({consent: true});
  runtime.window.Aggregate.emit('button_click', {plan: 'pro'}, 'purchase');
  const payload = runtime.requests.at(-1);

  assert.equal(payload.consentState, 'granted');
  assert.deepEqual(payload.eventData, {plan: 'pro'});
  assert.equal(payload.goalEvent, 'purchase');
  assert.equal(payload.screenWidth, 1440);
  assert.match(payload.visitorId, /^[A-Za-z0-9_-]+$/);
  assert.match(payload.sessionId, /^[A-Za-z0-9_-]+$/);
});

test('an anonymously submitted rejected goal produces only the generic SDK warning', async () => {
  const submittedGoal = 'private@example.com';
  const expectedWarning = '[Aggregate] Goal was not recorded because it is not an approved goal type.';
  const runtime = loadSdk({
    fetchResponse: {
      ok: true,
      json: () => Promise.resolve({
        status: 'recorded',
        warnings: ['goal_not_allowed'],
        rejectedGoal: submittedGoal
      })
    }
  });

  const result = runtime.window.Aggregate.emit('button_click', null, submittedGoal);

  assert.equal(result, true);
  assert.equal(runtime.requests.at(-1).goalEvent, submittedGoal);
  await flushPromises();
  assert.deepEqual(runtime.consoleWarnings, [expectedWarning]);
  assert.equal(runtime.consoleWarnings.join(' ').includes(submittedGoal), false);
});

test('the generic SDK warning uses the configured namespace', async () => {
  const runtime = loadSdk({
    dataset: {namespace: 'CompanyAnalytics'},
    fetchResponse: {
      ok: true,
      json: () => Promise.resolve({warnings: ['goal_not_allowed']})
    }
  });

  runtime.window.CompanyAnalytics.emit('button_click', null, 'purchase');
  await flushPromises();
  await flushPromises();

  assert.deepEqual(runtime.consoleWarnings, [
    '[CompanyAnalytics] Goal was not recorded because it is not an approved goal type.'
  ]);
});

test('unknown server warning codes are not written to the console', async () => {
  const runtime = loadSdk({
    fetchResponse: {
      ok: true,
      json: () => Promise.resolve({warnings: ['arbitrary_server_text']})
    }
  });

  assert.equal(runtime.window.Aggregate.emit('button_click'), true);
  await flushPromises();
  assert.deepEqual(runtime.consoleWarnings, []);
});

test('network and non-JSON response failures remain fire-and-forget', async () => {
  const runtimes = [
    loadSdk({fetch: () => Promise.reject(new Error('offline'))}),
    loadSdk({
      fetchResponse: {
        ok: true,
        json: () => Promise.reject(new SyntaxError('not JSON'))
      }
    }),
    loadSdk({fetch: () => { throw new Error('fetch failed synchronously'); }})
  ];

  for (const runtime of runtimes) {
    assert.equal(runtime.window.Aggregate.emit('button_click'), true);
  }

  await flushPromises();
  for (const runtime of runtimes) {
    assert.deepEqual(runtime.consoleWarnings, []);
  }
});
