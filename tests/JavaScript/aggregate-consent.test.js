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

function createStorage(initialValues, unavailable) {
  const values = new Map(Object.entries(initialValues || {}));
  const reads = [];

  return {
    getItem: (key) => {
      reads.push(key);
      if (unavailable) throw new Error('Storage access blocked');
      return values.has(key) ? values.get(key) : null;
    },
    setItem: (key, value) => values.set(key, String(value)),
    removeItem: (key) => values.delete(key),
    has: (key) => values.has(key),
    reads
  };
}

function loadSdk(options) {
  const requests = [];
  const cookieWrites = [];
  const consoleWarnings = [];
  const localStorage = createStorage(
    Object.assign({aggregate_visitor_id: 'legacy-visitor'}, options.localStorage || {}),
    options.localStorageUnavailable
  );
  const sessionStorage = createStorage({aggregate_session_id: 'legacy-session'});
  const script = {
    dataset: Object.assign({websiteToken: 'site-token'}, options.dataset || {}),
    src: options.src || ''
  };
  const listeners = {};
  const document = {
    currentScript: script,
    readyState: 'loading',
    referrer: '',
    addEventListener: (name, listener) => { listeners[name] = listener; },
    getElementsByTagName: () => [script]
  };
  const cookies = new Map(Object.entries(Object.assign(
    {aggregate_session: 'legacy-cookie'}, options.cookies || {}
  )));
  Object.defineProperty(document, 'cookie', {
    get: () => {
      if (options.cookieUnavailable) throw new Error('Cookie access blocked');
      return Array.from(cookies, ([name, value]) => name + '=' + value).join('; ')
        + (options.cookieSuffix ? '; ' + options.cookieSuffix : '');
    },
    set: (value) => {
      if (options.cookieUnavailable) throw new Error('Cookie access blocked');
      cookieWrites.push(value);
      const pair = value.split(';')[0];
      const separator = pair.indexOf('=');
      const name = pair.slice(0, separator);
      if (/max-age=0(?:;|$)/i.test(value)) {
        cookies.delete(name);
      } else {
        cookies.set(name, pair.slice(separator + 1));
      }
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

  return {
    window, requests, cookieWrites, cookies, consoleWarnings, localStorage, sessionStorage,
    triggerPageView: () => listeners.DOMContentLoaded()
  };
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

test('unmarked anonymous traffic emits false and never creates the marker', () => {
  const runtime = loadSdk({});

  runtime.triggerPageView();

  assert.equal(runtime.requests.at(-1).internalTraffic, false);
  assert.equal(runtime.requests.at(-1).visitorId, undefined);
  assert.equal(runtime.cookies.has('orgInternalTraffic'), false);
  assert.equal(runtime.localStorage.has('orgInternalTraffic'), false);
  assert.equal(runtime.cookieWrites.every((value) => value.startsWith('aggregate_session=')), true);
});

test('the default cookie marks page views and events without exposing its raw name or value', () => {
  const runtime = loadSdk({cookies: {orgInternalTraffic: 'true'}});

  runtime.triggerPageView();
  runtime.window.Aggregate.emit('button_click', {private: 'ignored'});

  for (const payload of runtime.requests) {
    assert.equal(payload.internalTraffic, true);
    assert.equal(payload.consentState, 'unknown');
    assert.equal(payload.eventData, undefined);
    assert.equal(payload.visitorId, undefined);
    assert.equal(payload.sessionId, undefined);
    assert.equal(JSON.stringify(payload).includes('orgInternalTraffic'), false);
  }
  assert.equal(runtime.cookies.get('orgInternalTraffic'), 'true');
  assert.deepEqual(runtime.localStorage.reads, []);
});

for (const value of ['false', '1', 'TRUE', ' true', 'true ', 'true-other', '']) {
  test(`cookie value ${JSON.stringify(value)} does not match the configured marker`, () => {
    const runtime = loadSdk({cookies: {orgInternalTraffic: value}});

    runtime.window.Aggregate.emit('button_click');

    assert.equal(runtime.requests.at(-1).internalTraffic, false);
  });
}

test('cookie names match exactly and configured values are decoded', () => {
  const value = 'staff=yes & region=North';
  const runtime = loadSdk({
    inline: {internalTraffic: {name: 'companyStaff', value}},
    cookies: {notcompanyStaff: encodeURIComponent(value), companyStaff: encodeURIComponent(value)}
  });

  runtime.window.Aggregate.emit('button_click');
  assert.equal(runtime.requests.at(-1).internalTraffic, true);
  runtime.cookies.delete('companyStaff');
  runtime.window.Aggregate.emit('button_click');
  assert.equal(runtime.requests.at(-1).internalTraffic, false);
});

test('a matching parent-domain cookie works alongside an older or malformed host-only cookie', () => {
  for (const oldValue of ['old-staff-value', '%invalid']) {
    const runtime = loadSdk({
      cookies: {orgInternalTraffic: oldValue},
      cookieSuffix: 'orgInternalTraffic=true'
    });

    runtime.window.Aggregate.emit('button_click');

    assert.equal(runtime.requests.at(-1).internalTraffic, true);
  }
});

test('the local storage marker survives consent changes and ignores a cookie with the same name', () => {
  const runtime = loadSdk({
    inline: {internalTraffic: {storage: 'local_storage', name: 'teamFlag', value: 'staff'}},
    localStorage: {teamFlag: 'staff'},
    cookies: {teamFlag: 'different'}
  });

  runtime.window.Aggregate.emit('button_click');
  assert.equal(runtime.requests.at(-1).internalTraffic, true);
  assert.equal(runtime.requests.at(-1).visitorId, undefined);
  assert.deepEqual(runtime.localStorage.reads, ['teamFlag']);

  runtime.window.Aggregate.setConsent(true);
  runtime.window.Aggregate.emit('button_click', {plan: 'pro'});
  assert.equal(runtime.requests.at(-1).internalTraffic, true);
  assert.ok(runtime.requests.at(-1).visitorId);

  runtime.window.Aggregate.setConsent(false);
  runtime.window.Aggregate.emit('button_click');
  assert.equal(runtime.requests.at(-1).internalTraffic, true);
  assert.equal(runtime.requests.at(-1).visitorId, undefined);
  assert.equal(runtime.localStorage.getItem('teamFlag'), 'staff');
  assert.equal(runtime.localStorage.has('aggregate_visitor_id'), false);
});

test('a cookie marker survives enhanced identifier creation and withdrawal', () => {
  const runtime = loadSdk({cookies: {orgInternalTraffic: 'true'}});

  runtime.window.Aggregate.setConsent(true);
  runtime.window.Aggregate.emit('button_click');
  runtime.window.Aggregate.setConsent(false);
  runtime.window.Aggregate.emit('button_click');

  assert.equal(runtime.requests.every((payload) => payload.internalTraffic === true), true);
  assert.equal(runtime.cookies.get('orgInternalTraffic'), 'true');
  assert.equal(runtime.cookies.has('aggregate_session'), false);
});

test('only the selected storage is checked, including after configure changes', () => {
  const runtime = loadSdk({
    cookies: {orgInternalTraffic: 'true'},
    localStorage: {orgInternalTraffic: 'false'}
  });

  runtime.window.Aggregate.emit('button_click');
  assert.equal(runtime.requests.at(-1).internalTraffic, true);
  assert.deepEqual(runtime.localStorage.reads, []);

  runtime.window.Aggregate.configure({internalTraffic: {storage: 'local_storage'}});
  runtime.window.Aggregate.emit('button_click');
  assert.equal(runtime.requests.at(-1).internalTraffic, false);
  runtime.localStorage.setItem('orgInternalTraffic', 'true');
  runtime.window.Aggregate.emit('button_click');
  assert.equal(runtime.requests.at(-1).internalTraffic, true);
  runtime.localStorage.removeItem('orgInternalTraffic');
  runtime.window.Aggregate.emit('button_click');
  assert.equal(runtime.requests.at(-1).internalTraffic, false);
});

test('data attributes configure local storage markers under a custom namespace', () => {
  const runtime = loadSdk({
    dataset: {
      namespace: 'CompanyAnalytics',
      internalTrafficStorage: 'local_storage',
      internalTrafficName: 'staffFlag',
      internalTrafficValue: 'enabled'
    },
    localStorage: {staffFlag: 'enabled'}
  });

  runtime.window.CompanyAnalytics.emit('button_click');

  assert.equal(runtime.requests.at(-1).internalTraffic, true);
});

test('inline marker settings take precedence over script data attributes', () => {
  const runtime = loadSdk({
    dataset: {internalTrafficValue: 'other'},
    inline: {internalTraffic: {value: 'staff'}},
    cookies: {orgInternalTraffic: 'staff'}
  });

  runtime.window.Aggregate.emit('button_click');

  assert.equal(runtime.requests.at(-1).internalTraffic, true);
});

test('unavailable storage and malformed cookie encodings leave tracking unmarked', () => {
  const runtimes = [
    loadSdk({cookieUnavailable: true}),
    loadSdk({cookies: {orgInternalTraffic: '%invalid'}}),
    loadSdk({
      localStorageUnavailable: true,
      inline: {internalTraffic: {storage: 'local_storage'}},
      cookies: {orgInternalTraffic: 'true'}
    })
  ];

  for (const runtime of runtimes) {
    assert.equal(runtime.window.Aggregate.emit('button_click'), true);
    assert.equal(runtime.requests.at(-1).internalTraffic, false);
    assert.equal(runtime.requests.at(-1).visitorId, undefined);
  }
});

for (const config of [
  {storage: 'unsupported'},
  {value: true},
  {name: ''},
  {name: 'aggregate_session', value: 'legacy-cookie'},
  {storage: 'local_storage', name: 'aggregate_visitor_id', value: 'legacy-visitor'},
  {storage: 'local_storage', name: 'aggregate_session_id', value: 'legacy-session'}
]) {
  test(`invalid or reserved marker settings fail closed: ${JSON.stringify(config)}`, () => {
    const runtime = loadSdk({
      inline: {internalTraffic: config},
      cookies: {orgInternalTraffic: 'true'}
    });

    runtime.window.Aggregate.emit('button_click');

    assert.equal(runtime.requests.at(-1).internalTraffic, false);
  });
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
