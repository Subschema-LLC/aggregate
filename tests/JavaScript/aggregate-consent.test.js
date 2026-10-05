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

function createStorage(initialValues, unavailable) {
  const values = new Map(Object.entries(initialValues || {}));
  const reads = [];
  const writes = [];
  const removals = [];

  return {
    getItem: (key) => {
      reads.push(key);
      if (unavailable) throw new Error('Storage access blocked');
      return values.has(key) ? values.get(key) : null;
    },
    setItem: (key, value) => {
      if (unavailable) throw new Error('Storage access blocked');
      writes.push([key, String(value)]);
      values.set(key, String(value));
    },
    removeItem: (key) => {
      removals.push(key);
      if (unavailable) throw new Error('Storage access blocked');
      values.delete(key);
    },
    has: (key) => values.has(key),
    reads,
    writes,
    removals
  };
}

function loadSdk(options) {
  const requests = [];
  const historyCalls = [];
  const historyPushCalls = [];
  const cookieWrites = [];
  const consoleWarnings = [];
  const localStorage = createStorage(
    Object.assign({aggregate_visitor_id: 'legacy-visitor'}, options.localStorage || {}),
    options.localStorageUnavailable
  );
  const sessionStorage = options.sessionStorageInstance || createStorage(
    Object.assign({aggregate_session_id: 'legacy-session'}, options.sessionStorage || {}),
    options.sessionStorageUnavailable
  );
  const script = {
    dataset: Object.assign({websiteToken: 'site-token'}, options.dataset || {}),
    src: options.src || ''
  };
  const listeners = {};
  const captureListeners = {};
  const document = {
    currentScript: script,
    readyState: 'loading',
    referrer: options.referrer || '',
    // Capturing listeners (marked click and form tracking) are kept apart
    // from the bubbling ones these tests dispatch to.
    addEventListener: (name, listener, options) => {
      if (options === true || (options && options.capture)) captureListeners[name] = listener;
      else listeners[name] = listener;
    },
    getElementsByTagName: () => [script],
    querySelector: (selector) => selector === 'base[target]' && options.baseTarget
      ? {getAttribute: () => options.baseTarget} : null
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
    location: Object.assign({
      origin: 'https://www.example.com',
      pathname: '/pricing',
      protocol: 'https:',
      search: ''
    }, options.location || {}),
    screen: {width: 1440},
    self: options.self || {crypto: {randomUUID: () => '00000000-0000-4000-8000-000000000001'}},
    sessionStorage,
    setTimeout: () => {},
    window
  };
  window.location = context.location;
  if (typeof context.location.href === 'undefined') {
    Object.defineProperty(context.location, 'href', {get: () => context.location.origin + context.location.pathname + (context.location.search || '') + (context.location.hash || '')});
  }
  const history = {
    state: options.historyState,
    length: 7,
    replaceState: (state, title, url) => {
      historyCalls.push({state, title, url});
      if (options.historyThrows) throw new Error('History replacement blocked');
      const destination = new URL(url, context.location.href);
      assert.equal(destination.origin, context.location.origin, 'History replacement must remain same-origin');
      context.location.pathname = destination.pathname;
      context.location.search = destination.search;
      context.location.hash = destination.hash || (destination.href.endsWith('#') ? '#' : '');
      history.state = state;
      if (options.onHistoryReplace) options.onHistoryReplace(window, history);
    },
    pushState: (...args) => historyPushCalls.push(args)
  };
  if (!options.historyUnavailable) {
    Object.defineProperty(window, 'history', {get: () => {
      if (options.historyGetterThrows) throw new Error('History access blocked');
      return history;
    }});
  }
  Object.defineProperty(document, 'baseURI', {get: () => options.baseURI || context.location.origin + context.location.pathname + (context.location.search || '') + (context.location.hash || '')});

  const source = options.serverCustomData
    ? sdkSource.replace(
      /\{queryParameters:\s*\{utm_source:\s*['"]utm_source['"][^}]*\},\s*consentFreeProperties:\s*\[\]\}/,
      JSON.stringify(options.serverCustomData)
    )
    : sdkSource;
  if (options.serverCustomData) assert.notEqual(source, sdkSource, 'Runtime fixture must replace the public tracker defaults');
  vm.runInNewContext(source, context, {filename: 'aggregate.js'});

  return {
    window, requests, cookieWrites, cookies, consoleWarnings, localStorage, sessionStorage, historyCalls, historyPushCalls, history,
    triggerPageView: () => listeners.DOMContentLoaded(),
    createAnchor: (href, attributes) => {
      const values = new Map(Object.entries(Object.assign({href}, attributes || {})));
      const anchor = {
        tagName: 'A',
        getAttribute: (name) => values.has(name) ? values.get(name) : null,
        hasAttribute: (name) => values.has(name),
        setAttribute: (name, value) => values.set(name, String(value))
      };
      Object.defineProperty(anchor, 'href', {get: () => new URL(values.get('href'), document.baseURI).href});
      return anchor;
    },
    click: (target, options) => {
      const event = Object.assign({target, button: 0, defaultPrevented: false, preventDefault: () => { event.defaultPrevented = true; }}, options || {});
      if (listeners.click) listeners.click(event);
      return event;
    }
  };
}

function flushPromises() {
  return new Promise((resolve) => setImmediate(resolve));
}

function assertLastRequestIsAnonymous(runtime) {
  const payload = runtime.requests.at(-1);

  assert.equal(payload.consentState, 'denied');
  assert.equal(payload.eventName, 'button_click');
  assert.equal(payload.customData, undefined);
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
    assert.equal(payload.customData, undefined);
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
  assert.equal(payload.customData, undefined);
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
  assert.deepEqual(payload.customData, {plan: 'pro'});
  assert.equal(payload.eventData, undefined, 'custom properties are sent only as customData');
  assert.equal(payload.goalEvent, 'purchase');
  assert.equal(payload.screenWidth, 1440);
  assert.match(payload.visitorId, /^[A-Za-z0-9_-]+$/);
  assert.match(payload.sessionId, /^[A-Za-z0-9_-]+$/);
});

test('new identifiers come only from the browser cryptographic source', () => {
  const fresh = {localStorage: {aggregate_visitor_id: null}, sessionStorage: {aggregate_session_id: null}};
  // Without randomUUID (an insecure context, say), random bytes make a version 4 UUID.
  let calls = 0;
  const bytes = {crypto: {getRandomValues: (array) => { calls++; for (let i = 0; i < array.length; i++) array[i] = (i * 37 + calls) & 255; return array; }}};
  const runtime = loadSdk(Object.assign({self: bytes}, fresh));
  runtime.window.Aggregate.configure({consent: true});
  runtime.window.Aggregate.emit('button_click');
  const payload = runtime.requests.at(-1);
  const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
  assert.match(payload.visitorId, uuid);
  assert.match(payload.sessionId, uuid);
  assert.notEqual(payload.visitorId, payload.sessionId);

  // Without any cryptographic source, no identifier is created or stored.
  const none = loadSdk(Object.assign({self: {}}, fresh));
  none.window.Aggregate.configure({consent: true});
  none.window.Aggregate.emit('button_click');
  const anonymous = none.requests.at(-1);
  assert.equal(anonymous.consentState, 'granted');
  assert.equal(anonymous.visitorId, undefined);
  assert.equal(anonymous.sessionId, undefined);
  assert.equal(none.localStorage.writes.some(([key]) => key === 'aggregate_visitor_id'), false);
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

test('standard UTM parameters accompany enhanced page views and custom events', () => {
  const runtime = loadSdk({
    inline: {consent: true},
    location: {search: '?utm_source=newsletter&utm_medium=email&utm_campaign=summer+sale&utm_term=red%20shoes&utm_content=hero&utm_id=launch&email=private%40example.com'}
  });

  runtime.triggerPageView();
  runtime.window.Aggregate.emit('button_click', {plan: 'pro'});

  const utms = {
    utm_source: 'newsletter', utm_medium: 'email', utm_campaign: 'summer sale',
    utm_term: 'red shoes', utm_content: 'hero', utm_id: 'launch'
  };
  assert.deepEqual(runtime.requests[0].customData, utms);
  assert.deepEqual(runtime.requests[1].customData, Object.assign({plan: 'pro'}, utms));
  for (const payload of runtime.requests) {
    assert.equal(payload.pagePath, '/pricing');
    assert.equal(JSON.stringify(payload).includes('private@example.com'), false);
  }
});

test('UTMs require consent by default and never create anonymous identifiers', () => {
  const runtime = loadSdk({location: {search: '?utm_source=newsletter'}});

  runtime.triggerPageView();
  runtime.window.Aggregate.emit('button_click');
  runtime.window.Aggregate.setConsent(false);
  runtime.window.Aggregate.emit('button_click');

  for (const payload of runtime.requests) {
    assert.equal(payload.customData, undefined);
    assert.equal(payload.visitorId, undefined);
    assert.equal(payload.sessionId, undefined);
  }
  assert.deepEqual(runtime.localStorage.reads, []);
});

test('anonymous views and events include only operator-approved custom properties', () => {
  const runtime = loadSdk({
    inline: {customData: {consentFreeProperties: ['utm_source', 'plan', 'active', 'quantity', 'nullable']}},
    location: {search: '?utm_source=newsletter&utm_term=private-search'}
  });

  runtime.triggerPageView();
  runtime.window.Aggregate.emit('button_click', {
    plan: 'pro', active: false, quantity: 0, nullable: null, email: 'private@example.com'
  });

  assert.deepEqual(runtime.requests[0].customData, {utm_source: 'newsletter'});
  assert.deepEqual(runtime.requests[1].customData, {
    plan: 'pro', active: false, quantity: 0, nullable: null, utm_source: 'newsletter'
  });
  assert.equal(runtime.requests[1].consentState, 'unknown');
  assert.equal(runtime.requests[1].visitorId, undefined);
  assert.equal(runtime.requests[1].sessionId, undefined);
  assert.equal(runtime.requests[1].screenWidth, undefined);
});

test('many query parameters mapping to one property use configuration order and first nonblank value', () => {
  const runtime = loadSdk({
    inline: {customData: {
      queryParameters: {campaign: 'campaignName', legacy_campaign: 'campaignName'},
      consentFreeProperties: ['campaignName']
    }},
    location: {search: '?legacy_campaign=fallback&campaign=&campaign=%20%00&campaign=preferred&campaign=later'}
  });

  runtime.triggerPageView();
  assert.deepEqual(runtime.requests[0].customData, {campaignName: 'preferred'});

  runtime.window.location.search = '?legacy_campaign=fallback&campaign=%20';
  runtime.window.Aggregate.emit('button_click');
  assert.deepEqual(runtime.requests.at(-1).customData, {campaignName: 'fallback'});
});

test('explicit scalar event values override query values, including false, zero, empty string and null', () => {
  const runtime = loadSdk({
    inline: {customData: {queryParameters: {campaign: 'campaignName'}, consentFreeProperties: ['campaignName']}},
    location: {search: '?campaign=query-value'}
  });

  for (const value of ['explicit', false, 0, '', null]) {
    runtime.window.Aggregate.emit('button_click', {campaignName: value});
    assert.deepEqual(runtime.requests.at(-1).customData, {campaignName: value});
  }
});

test('query collection uses the current page only and never persists attribution', () => {
  const runtime = loadSdk({
    inline: {customData: {consentFreeProperties: ['utm_source']}},
    src: 'https://analytics.example/aggregate.js?utm_source=tracker-source',
    location: {search: '?utm_source=first-page', hash: '#utm_source=fragment'}
  });

  runtime.triggerPageView();
  runtime.window.location.search = '';
  runtime.window.Aggregate.emit('button_click');

  assert.deepEqual(runtime.requests[0].customData, {utm_source: 'first-page'});
  assert.equal(runtime.requests[1].customData, undefined);
  assert.equal(runtime.localStorage.has('utm_source'), false);
  assert.equal(runtime.sessionStorage.has('utm_source'), false);
  assert.equal(runtime.cookieWrites.every((value) => value.startsWith('aggregate_session=')), true);
});

test('query names match case exactly and malformed query encoding does not break tracking', () => {
  const runtime = loadSdk({
    inline: {customData: {consentFreeProperties: ['utm_source']}},
    location: {search: '?UTM_SOURCE=ignored&utm_source=%broken'}
  });

  assert.equal(runtime.window.Aggregate.emit('button_click'), true);
  assert.deepEqual(runtime.requests.at(-1).customData, {utm_source: '%broken'});
});

test('custom property processing rejects inherited, nested, unsafe and organization-marker values', () => {
  const runtime = loadSdk({inline: {consent: true}});
  const eventData = Object.assign(Object.create({inherited: 'ignored'}), {
    nested: {private: 'ignored'}, list: ['ignored'], constructor: 'ignored', prototype: 'ignored',
    'unsafe key': 'ignored', orgInternalTraffic: true, nan: NaN, infinity: Infinity,
    ' plan ': 'pro\u0000', safe: true
  });

  runtime.window.Aggregate.emit('button_click', eventData);

  assert.deepEqual(runtime.requests.at(-1).customData, {plan: 'pro', safe: true});
  assert.equal(runtime.requests.at(-1).internalTraffic, false);
});

test('custom properties and query values have the server scalar count and UTF-8 bounds', () => {
  const runtime = loadSdk({inline: {consent: true}});
  const eventData = {unicode: '😀'.repeat(130), malformed: 'valid\ud800text'};
  for (let i = 0; i < 60; i++) eventData['property' + i] = i;

  runtime.window.Aggregate.emit('button_click', eventData);

  const sent = runtime.requests.at(-1).customData;
  assert.equal(Object.keys(sent).length, 50);
  assert.equal(sent.unicode, '😀'.repeat(125));
  assert.equal(sent.malformed, 'validtext');
  assert.equal(sent.property48, undefined);

  runtime.window.location.search = '?utm_source=' + encodeURIComponent('é'.repeat(300));
  runtime.window.Aggregate.emit('button_click');
  assert.equal(runtime.requests.at(-1).customData.utm_source, 'é'.repeat(250));
});

test('custom-data configuration can replace mappings, disable them and revoke consent-free properties', () => {
  const runtime = loadSdk({
    dataset: {namespace: 'CompanyAnalytics'},
    inline: {customData: {consentFreeProperties: ['utm_source']}},
    location: {search: '?utm_source=newsletter&campaign=launch'}
  });
  const sdk = runtime.window.CompanyAnalytics;

  sdk.emit('button_click');
  assert.deepEqual(runtime.requests.at(-1).customData, {utm_source: 'newsletter'});

  sdk.configure({customData: {queryParameters: {campaign: 'campaignName'}, consentFreeProperties: ['campaignName']}});
  sdk.emit('button_click');
  assert.deepEqual(runtime.requests.at(-1).customData, {campaignName: 'launch'});

  sdk.configure({customData: {queryParameters: {}}});
  sdk.emit('button_click');
  assert.equal(runtime.requests.at(-1).customData, undefined);

  sdk.emit('button_click', {campaignName: 'explicit'});
  assert.deepEqual(runtime.requests.at(-1).customData, {campaignName: 'explicit'});
  sdk.configure({customData: {consentFreeProperties: []}});
  sdk.emit('button_click', {campaignName: 'explicit'});
  assert.equal(runtime.requests.at(-1).customData, undefined);
});

test('malformed browser collection settings fail closed and cannot map the organization marker', () => {
  const runtime = loadSdk({
    inline: {customData: {
      queryParameters: {campaign: 'orgInternalTraffic', constructor: 'plan', valid: 'prototype', 'invalid source': 'plan'},
      consentFreeProperties: ['orgInternalTraffic', 'plan', 'prototype']
    }},
    location: {search: '?campaign=true&constructor=private&valid=private&invalid+source=private'}
  });

  runtime.window.Aggregate.emit('button_click');
  assert.equal(runtime.requests.at(-1).customData, undefined);
  assert.equal(runtime.requests.at(-1).internalTraffic, false);

  runtime.window.Aggregate.configure({customData: {queryParameters: null, consentFreeProperties: 'plan'}});
  runtime.window.Aggregate.emit('button_click', {plan: 'private'});
  assert.equal(runtime.requests.at(-1).customData, undefined);
});

test('consent withdrawal immediately filters event properties back to the approved subset', () => {
  const runtime = loadSdk({
    inline: {customData: {consentFreeProperties: ['plan']}},
    location: {search: '?utm_source=newsletter'}
  });

  runtime.window.Aggregate.setConsent(true);
  runtime.window.Aggregate.emit('button_click', {plan: 'pro', private: 'enhanced-only'});
  assert.deepEqual(runtime.requests.at(-1).customData, {plan: 'pro', private: 'enhanced-only', utm_source: 'newsletter'});

  runtime.window.Aggregate.setConsent(false);
  runtime.window.Aggregate.emit('button_click', {plan: 'pro', private: 'enhanced-only'});
  assert.deepEqual(runtime.requests.at(-1).customData, {plan: 'pro'});
  assert.equal(runtime.requests.at(-1).visitorId, undefined);
  assert.equal(runtime.requests.at(-1).sessionId, undefined);
});

for (const enhanced of [false, true]) {
  test(`explicit JSON property types validate scalars with enhanced consent ${enhanced}`, () => {
    const runtime = loadSdk({inline: {consent: enhanced, customData: {
      queryParameters: {},
      consentFreeProperties: ['title', 'quantity', 'amount', 'revenue', 'active', 'optional', 'legacy'],
      propertyTypes: {title: 'string', quantity: 'integer', amount: 'float', revenue: 'double', active: 'boolean', optional: 'integer'}
    }}});
    const sdk = runtime.window.Aggregate;
    sdk.emit('purchase_completed', {title: 'Demo\u0000', quantity: 2, amount: 12.5, revenue: 25, active: false, optional: null, legacy: '12.5'});
    assert.deepEqual(runtime.requests.at(-1).customData, {title: 'Demo', quantity: 2, amount: 12.5, revenue: 25, active: false, optional: null, legacy: '12.5'});

    sdk.emit('purchase_completed', {title: 2, quantity: 2.5, amount: '12.5', revenue: Infinity, active: 'false', optional: true});
    assert.equal(runtime.requests.at(-1).customData, enhanced ? null : undefined);

    for (const quantity of [0, -2, 9007199254740991, -9007199254740991]) {
      sdk.emit('purchase_completed', {quantity});
      assert.deepEqual(runtime.requests.at(-1).customData, {quantity});
    }
    for (const quantity of [9007199254740992, -9007199254740992, NaN, Infinity, '2', true]) {
      sdk.emit('purchase_completed', {quantity});
      assert.equal(runtime.requests.at(-1).customData, enhanced ? null : undefined);
    }
  });
}

test('typed numeric and boolean query mappings never coerce URL strings or block later valid mappings', () => {
  const runtime = loadSdk({
    inline: {customData: {
      queryParameters: {qty: 'quantity', total: 'revenue', active: 'active', name: 'title'},
      consentFreeProperties: ['quantity', 'revenue', 'active', 'title'],
      propertyTypes: {quantity: 'integer', revenue: 'double', active: 'boolean', title: 'string'}
    }},
    location: {search: '?qty=2&total=12.5&active=true&name=Demo'}
  });
  runtime.triggerPageView();
  assert.deepEqual(runtime.requests.at(-1).customData, {title: 'Demo'});
  runtime.window.Aggregate.emit('purchase_completed', {quantity: 2, revenue: 12.5, active: true});
  assert.deepEqual(runtime.requests.at(-1).customData, {quantity: 2, revenue: 12.5, active: true, title: 'Demo'});
});

test('declaring a type cannot grant consent and withdrawal restores the permitted subset', () => {
  const runtime = loadSdk({inline: {customData: {
    queryParameters: {}, consentFreeProperties: ['quantity'],
    propertyTypes: {quantity: 'integer', revenue: 'double', orgInternalTraffic: 'boolean'}
  }}});
  const sdk = runtime.window.Aggregate;
  sdk.emit('purchase_completed', {quantity: 2, revenue: 12.5, orgInternalTraffic: true});
  assert.deepEqual(runtime.requests.at(-1).customData, {quantity: 2});
  sdk.setConsent(true);
  sdk.emit('purchase_completed', {quantity: 2, revenue: 12.5, orgInternalTraffic: true});
  assert.deepEqual(runtime.requests.at(-1).customData, {quantity: 2, revenue: 12.5});
  sdk.setConsent(false);
  sdk.emit('purchase_completed', {quantity: 2, revenue: 12.5});
  assert.deepEqual(runtime.requests.at(-1).customData, {quantity: 2});
  assert.equal(runtime.requests.at(-1).visitorId, undefined);
});

test('malformed declared types block affected properties and malformed maps block all custom properties', () => {
  const runtime = loadSdk({inline: {consent: true, customData: {
    queryParameters: {}, propertyTypes: {valid: 'integer', invalid: 'number', invalidNull: null}
  }}});
  const sdk = runtime.window.Aggregate;
  sdk.emit('purchase_completed', {valid: 2, invalid: 12.5, invalidNull: null});
  assert.deepEqual(runtime.requests.at(-1).customData, {valid: 2});
  for (const propertyTypes of [null, false, 'integer', ['integer']]) {
    sdk.configure({customData: {propertyTypes}});
    sdk.emit('purchase_completed', {valid: 2, legacy: 'text'});
    assert.equal(runtime.requests.at(-1).customData, null);
  }
  sdk.configure({customData: {propertyTypes: {valid: 'integer'}}});
  sdk.emit('purchase_completed', {valid: 2, legacy: 'text'});
  assert.deepEqual(runtime.requests.at(-1).customData, {valid: 2, legacy: 'text'});
});

const pageSequenceKey = 'aggregate_page_sequence:site-token';

test('optional page sequence survives document navigation while asynchronous events reuse the page count', async () => {
  const inline = {consent: false, customData: {pageSequenceEnabled: true}};
  const first = loadSdk({inline});
  first.window.Aggregate.emit('early_event');
  first.triggerPageView();
  await Promise.resolve().then(() => first.window.Aggregate.emit('async_complete', {}, 'purchase'));

  for (const payload of first.requests) {
    assert.equal(payload.customData.page_sequence, 1);
    assert.equal(payload.consentState, 'denied');
    assert.equal(payload.visitorId, undefined);
    assert.equal(payload.sessionId, undefined);
    assert.equal(payload.screenWidth, undefined);
  }
  assert.deepEqual(first.sessionStorage.writes, [[pageSequenceKey, '1']]);
  assert.deepEqual(first.localStorage.writes, []);
  assert.equal(first.cookieWrites.some((value) => /max-age=1800/i.test(value)), false);

  const second = loadSdk({inline, sessionStorageInstance: first.sessionStorage, location: {pathname: '/features'}});
  second.triggerPageView();
  second.window.Aggregate.emit('button_click');
  assert.deepEqual(second.requests.map((payload) => payload.customData.page_sequence), [2, 2]);
  const reload = loadSdk({inline, sessionStorageInstance: first.sessionStorage, location: {pathname: '/features'}});
  reload.triggerPageView();
  assert.equal(reload.requests.at(-1).customData.page_sequence, 3);
  const separateTab = loadSdk({inline});
  separateTab.triggerPageView();
  assert.equal(separateTab.requests.at(-1).customData.page_sequence, 1);
});

test('explicit SPA page views advance the count while consent transitions preserve coarse page depth', () => {
  const runtime = loadSdk({inline: {customData: {pageSequenceEnabled: true}}});
  const sdk = runtime.window.Aggregate;
  runtime.triggerPageView();
  runtime.window.location.pathname = '/features';
  sdk.trackView();
  sdk.emit('async_complete');
  sdk.setConsent(true);
  sdk.emit('view');
  sdk.setConsent(false);
  sdk.emit('button_click');
  assert.deepEqual(runtime.requests.map((payload) => payload.customData.page_sequence), [1, 2, 2, 3, 3]);
  assert.deepEqual(runtime.requests.map((payload) => payload.pagePath), ['/pricing', '/features', '/features', '/features', '/features']);
  const withdrawn = runtime.requests.at(-1);
  assert.equal(withdrawn.consentState, 'denied');
  assert.equal(withdrawn.visitorId, undefined);
  assert.equal(withdrawn.sessionId, undefined);
  assert.equal(runtime.localStorage.has('aggregate_visitor_id'), false);
  assert.equal(runtime.sessionStorage.has('aggregate_session_id'), false);
});

test('page sequence is opt-in, clears old state when disabled, and rejects truthy enable values', () => {
  for (const enabled of [undefined, false, 'true', 'false', 1, 0, null, [], {}]) {
    const runtime = loadSdk({
      inline: {consent: true, customData: {pageSequenceEnabled: enabled, consentFreeProperties: ['page_sequence']}},
      sessionStorage: {[pageSequenceKey]: '7'}
    });
    runtime.triggerPageView();
    runtime.window.Aggregate.emit('button_click', {page_sequence: 2});
    for (const payload of runtime.requests) assert.equal(payload.customData?.page_sequence, undefined);
    assert.equal(runtime.sessionStorage.has(pageSequenceKey), false);
    assert.equal(runtime.sessionStorage.reads.includes(pageSequenceKey), false);
    assert.equal(runtime.sessionStorage.writes.some(([key]) => key === pageSequenceKey), false);
  }
  const runtime = loadSdk({inline: {customData: {pageSequenceEnabled: true}}});
  const sdk = runtime.window.Aggregate;
  runtime.triggerPageView();
  sdk.configure({customData: {pageSequenceEnabled: false}});
  assert.equal(runtime.sessionStorage.has(pageSequenceKey), false);
  sdk.emit('button_click');
  assert.equal(runtime.requests.at(-1).customData, undefined);
  sdk.configure({customData: {pageSequenceEnabled: true}});
  sdk.emit('button_click');
  assert.deepEqual(runtime.requests.at(-1).customData, {page_sequence: 1});
});

test('reserved page sequence cannot be replaced by explicit properties, query mappings, types or a full property payload', () => {
  const runtime = loadSdk({
    inline: {consent: true, customData: {
      pageSequenceEnabled: true, queryParameters: {sequence: 'page_sequence'},
      consentFreeProperties: ['page_sequence'], propertyTypes: {page_sequence: 'string'}
    }},
    location: {search: '?sequence=999999'}
  });
  runtime.triggerPageView();
  const data = Object.fromEntries(Array.from({length: 50}, (_, index) => ['property_' + index, index]));
  data.page_sequence = 123456;
  runtime.window.Aggregate.emit('button_click', data);
  assert.equal(runtime.requests.at(-1).customData.page_sequence, 1);
  assert.equal(Object.keys(runtime.requests.at(-1).customData).length, 50);
  runtime.window.Aggregate.configure({customData: {pageSequenceEnabled: false}});
  runtime.window.Aggregate.emit('button_click', {page_sequence: 'spoofed'});
  assert.equal(runtime.requests.at(-1).customData, null);
});

test('sequence state is scoped by public website token and does not advance on unrelated reconfiguration', () => {
  const runtime = loadSdk({inline: {customData: {pageSequenceEnabled: true}}});
  const sdk = runtime.window.Aggregate;
  runtime.triggerPageView();
  sdk.configure({endpoint: '/new-receiver', customData: {pageSequenceEnabled: true}});
  sdk.emit('button_click');
  sdk.configure({websiteToken: 'second/token'});
  sdk.emit('button_click');
  sdk.trackView();
  sdk.configure({websiteToken: 'site-token'});
  sdk.emit('button_click');
  assert.deepEqual(runtime.requests.map((payload) => payload.customData.page_sequence), [1, 1, 1, 2, 1]);
  assert.deepEqual(runtime.sessionStorage.writes, [
    [pageSequenceKey, '1'], ['aggregate_page_sequence:second%2Ftoken', '1'], ['aggregate_page_sequence:second%2Ftoken', '2']
  ]);
  sdk.configure({customData: {pageSequenceEnabled: false}});
  assert.equal(runtime.sessionStorage.has(pageSequenceKey), false);
  assert.equal(runtime.sessionStorage.has('aggregate_page_sequence:second%2Ftoken'), false);
});

test('page sequence does not allocate state for missing or malformed website tokens or invalid events', () => {
  for (const websiteToken of [null, '\ud800', 12, {}]) {
    const runtime = loadSdk({
      dataset: {websiteToken: ''}, inline: {websiteToken, customData: {pageSequenceEnabled: true}}
    });
    runtime.triggerPageView();
    assert.deepEqual(runtime.sessionStorage.writes, []);
    for (const payload of runtime.requests) assert.equal(payload.customData, undefined);
  }
  const runtime = loadSdk({inline: {customData: {pageSequenceEnabled: true}}});
  assert.equal(runtime.window.Aggregate.emit('invalid event name'), false);
  assert.deepEqual(runtime.sessionStorage.writes, []);
});

test('page counts stop at twenty and malformed stored counts restart at one', () => {
  for (const stored of ['0', '-1', '2.5', '21', '99999999999999999999', '01', ' 2', '2 ', '1e1', 'NaN', 'Infinity', '{"count":2}', '2\n']) {
    const runtime = loadSdk({inline: {customData: {pageSequenceEnabled: true}}, sessionStorage: {[pageSequenceKey]: stored}});
    runtime.triggerPageView();
    assert.equal(runtime.requests.at(-1).customData.page_sequence, 1, stored);
  }
  const runtime = loadSdk({inline: {customData: {pageSequenceEnabled: true}}, sessionStorage: {[pageSequenceKey]: '19'}});
  runtime.triggerPageView();
  for (let page = 0; page < 25; page++) runtime.window.Aggregate.trackView();
  assert.equal(runtime.requests.every((payload) => payload.customData.page_sequence === 20), true);
  assert.deepEqual(runtime.sessionStorage.writes, [[pageSequenceKey, '20']]);
  const nextDocument = loadSdk({inline: {customData: {pageSequenceEnabled: true}}, sessionStorageInstance: runtime.sessionStorage});
  nextDocument.triggerPageView();
  assert.equal(nextDocument.requests.at(-1).customData.page_sequence, 20);
});

test('blocked session storage uses memory only and a new document starts over', () => {
  const options = {inline: {consent: false, customData: {pageSequenceEnabled: true}}, sessionStorageUnavailable: true};
  const runtime = loadSdk(options);
  runtime.triggerPageView();
  runtime.window.Aggregate.trackView();
  runtime.window.Aggregate.emit('async_complete');
  assert.deepEqual(runtime.requests.map((payload) => payload.customData.page_sequence), [1, 2, 2]);
  assert.deepEqual(runtime.sessionStorage.writes, []);
  const nextDocument = loadSdk(options);
  nextDocument.triggerPageView();
  assert.equal(nextDocument.requests.at(-1).customData.page_sequence, 1);
});

test('excluded document and SPA paths do not read, store, disclose or advance page sequence', () => {
  const inline = {customData: {pageSequenceEnabled: true, pageSequenceExcludedPaths: ['/private/**', '/users/_redacted']}};
  const runtime = loadSdk({inline, location: {pathname: '/private'}, sessionStorage: {[pageSequenceKey]: '4'}});
  runtime.triggerPageView();
  runtime.window.Aggregate.emit('button_click');
  assert.equal(runtime.requests.every((payload) => payload.customData === undefined), true);
  assert.equal(runtime.sessionStorage.reads.includes(pageSequenceKey), false);
  assert.deepEqual(runtime.sessionStorage.writes, []);
  runtime.window.location.pathname = '/public';
  runtime.window.Aggregate.trackView();
  assert.equal(runtime.requests.at(-1).customData.page_sequence, 5);
  runtime.window.location.pathname = '/users/1234';
  runtime.window.Aggregate.trackView();
  assert.equal(runtime.requests.at(-1).customData, undefined);
  runtime.window.location.pathname = '/private/nested/path';
  runtime.window.Aggregate.emit('view');
  assert.equal(runtime.requests.at(-1).customData, undefined);
  runtime.window.location.pathname = '/public';
  runtime.window.Aggregate.trackView();
  assert.equal(runtime.requests.at(-1).customData.page_sequence, 6);
  assert.deepEqual(runtime.sessionStorage.writes, [[pageSequenceKey, '5'], [pageSequenceKey, '6']]);
});

test('counter exclusions match single-segment and recursive server globs without regex injection', () => {
  for (const [pattern, pathname, excluded] of [
    ['/private/*', '/private/a', true], ['/private/*', '/private/a/b', false],
    ['/private/**', '/private/a/b', true], ['/private/**', '/privateer', false],
    ['/private/?', '/private/a', true], ['/private/?', '/private/ab', false],
    ['/file.json', '/fileXjson', false], ['/file.json', '/file.json', true],
    ['/a[bc]', '/ab', false], ['/a[bc]', '/a[bc]', true]
  ]) {
    const runtime = loadSdk({inline: {customData: {pageSequenceEnabled: true, pageSequenceExcludedPaths: [pattern]}}, location: {pathname}});
    runtime.triggerPageView();
    assert.equal(Boolean(runtime.requests.at(-1).customData), !excluded, pattern + ': ' + pathname);
  }
  for (const patterns of [null, true, '/private/**', {}, [null], [7], ['private'], ['/' + 'a'.repeat(512)]]) {
    const runtime = loadSdk({inline: {customData: {pageSequenceEnabled: true, pageSequenceExcludedPaths: patterns}}});
    runtime.triggerPageView();
    assert.equal(runtime.requests.at(-1).customData, undefined);
    assert.equal(runtime.sessionStorage.reads.includes(pageSequenceKey), false);
    assert.deepEqual(runtime.sessionStorage.writes, []);
  }
});

test('counter exclusions canonicalize encoded routes before touching sequence state', () => {
  for (const pathname of [
    '/private%2Frecords', '/%70rivate/x', '/%2570rivate/x', '/private%252Frecords',
    '/public/../private/records', '/public/%2e%2e/private/records',
    '/public/%252e%252e/private/records', '/private%5Crecords', '/private\\records',
    '//private//records', '/private;ignored/records', '/private%3Bignored/records',
    '/priva%00te/records', '/private/%invalid'
  ]) {
    const runtime = loadSdk({
      inline: {customData: {pageSequenceEnabled: true, pageSequenceExcludedPaths: ['/private/**']}},
      location: {pathname}, sessionStorage: {[pageSequenceKey]: '4'}
    });
    runtime.triggerPageView();
    assert.equal(runtime.requests.at(-1).customData, undefined, pathname);
    assert.equal(runtime.sessionStorage.reads.includes(pageSequenceKey), false, pathname);
    assert.deepEqual(runtime.sessionStorage.writes, [], pathname);
  }
  const runtime = loadSdk({
    inline: {customData: {pageSequenceEnabled: true, pageSequenceExcludedPaths: ['/people/_redacted']}},
    location: {pathname: '/people/%2531%2532%2533%2534'}
  });
  runtime.triggerPageView();
  assert.equal(runtime.requests.at(-1).customData, undefined);
  assert.equal(runtime.sessionStorage.reads.includes(pageSequenceKey), false);
});

test('browser overrides cannot enable a counter disabled by the served runtime policy', () => {
  for (const enabled of [false, 'true', 1, null]) {
    const runtime = loadSdk({
      serverCustomData: {queryParameters: {}, consentFreeProperties: [], pageSequenceEnabled: enabled},
      inline: {customData: {pageSequenceEnabled: true}},
      sessionStorage: {[pageSequenceKey]: '7'}
    });
    runtime.triggerPageView();
    runtime.window.Aggregate.configure({customData: {pageSequenceEnabled: true}});
    runtime.window.Aggregate.trackView();
    assert.equal(runtime.requests.every((payload) => payload.customData === undefined), true);
    assert.equal(runtime.sessionStorage.has(pageSequenceKey), false);
    assert.equal(runtime.sessionStorage.reads.includes(pageSequenceKey), false);
    assert.deepEqual(runtime.sessionStorage.writes, []);
  }
});

test('browser exclusions can add restrictions but cannot remove served runtime exclusions', () => {
  const runtime = loadSdk({
    serverCustomData: {
      queryParameters: {}, consentFreeProperties: [], pageSequenceEnabled: true,
      pageSequenceExcludedPaths: ['/private/**']
    },
    inline: {customData: {pageSequenceEnabled: true, pageSequenceExcludedPaths: []}},
    location: {pathname: '/private/records'}
  });
  const sdk = runtime.window.Aggregate;
  runtime.triggerPageView();
  sdk.configure({customData: {pageSequenceExcludedPaths: []}});
  sdk.emit('view');
  assert.equal(runtime.requests.every((payload) => payload.customData === undefined), true);
  assert.equal(runtime.sessionStorage.reads.includes(pageSequenceKey), false);
  assert.deepEqual(runtime.sessionStorage.writes, []);
  runtime.window.location.pathname = '/public';
  sdk.trackView();
  assert.equal(runtime.requests.at(-1).customData.page_sequence, 1);
  sdk.configure({customData: {pageSequenceExcludedPaths: ['/public']}});
  sdk.trackView();
  assert.equal(runtime.requests.at(-1).customData, undefined);
  assert.deepEqual(runtime.sessionStorage.writes, [[pageSequenceKey, '1']]);
});

function assertNoPageCounterStorage(runtime) {
  for (const storage of [runtime.localStorage, runtime.sessionStorage]) {
    assert.equal(storage.reads.some((key) => key.startsWith('aggregate_page_sequence:')), false);
    assert.equal(storage.writes.some(([key]) => key.startsWith('aggregate_page_sequence:')), false);
    assert.equal(storage.removals.some((key) => key.startsWith('aggregate_page_sequence:')), false);
  }
  assert.equal(runtime.cookieWrites.some((value) => value.startsWith('aggregate_page_sequence')), false);
}

function urlSequenceOptions(options) {
  return Object.assign({inline: {consent: false, customData: {pageSequenceEnabled: true, pageSequenceMethod: 'url_parameter'}}}, options || {});
}

test('URL sequence initializes the current page directly and preserves depth for asynchronous events without counter storage', async () => {
  const runtime = loadSdk(urlSequenceOptions({
    location: {search: '?aggregate_page_sequence=7'}, sessionStorage: {[pageSequenceKey]: '19'}
  }));
  runtime.window.Aggregate.emit('early_event');
  runtime.triggerPageView();
  await Promise.resolve().then(() => runtime.window.Aggregate.emit('async_complete'));
  runtime.window.location.pathname = '/features';
  runtime.window.Aggregate.trackView();
  runtime.window.Aggregate.emit('async_complete');
  runtime.window.Aggregate.emit('view');
  assert.deepEqual(runtime.requests.map((payload) => payload.customData.page_sequence), [7, 7, 7, 8, 8, 9]);
  for (const payload of runtime.requests) {
    assert.equal(payload.consentState, 'denied');
    assert.equal(payload.visitorId, undefined);
    assert.equal(payload.sessionId, undefined);
  }
  assert.equal(runtime.sessionStorage.has(pageSequenceKey), true);
  assertNoPageCounterStorage(runtime);
});

test('URL sequence requires exactly one bounded canonical integer value', () => {
  for (const [query, expected] of [
    ['', 1], ['aggregate_page_sequence=1', 1], ['aggregate_page_sequence=2', 2], ['aggregate_page_sequence=20', 20],
    ['aggregate_page_sequence=%32', 2], ['aggregate_page_sequence', 1], ['aggregate_page_sequence=', 1],
    ['aggregate_page_sequence=0', 1], ['aggregate_page_sequence=21', 1], ['aggregate_page_sequence=01', 1],
    ['aggregate_page_sequence=2.0', 1], ['aggregate_page_sequence=2e0', 1], ['aggregate_page_sequence=-2', 1],
    ['aggregate_page_sequence=+2', 1], ['aggregate_page_sequence=2%20', 1], ['aggregate_page_sequence=%0A2', 1],
    ['aggregate_page_sequence=NaN', 1], ['aggregate_page_sequence=Infinity', 1], ['aggregate_page_sequence=false', 1],
    ['aggregate_page_sequence=2&aggregate_page_sequence=2', 1], ['aggregate_page_sequence=2&aggregate_page_sequence=3', 1],
    ['aggregate_page_sequence=2&aggregate%5Fpage_sequence=3', 1], ['Aggregate_page_sequence=7', 1]
  ]) {
    const runtime = loadSdk(urlSequenceOptions({location: {search: query ? '?' + query : ''}}));
    runtime.triggerPageView();
    assert.equal(runtime.requests.at(-1).customData.page_sequence, expected, query);
    assertNoPageCounterStorage(runtime);
  }
});

test('URL sequence decorates only the activated internal link and preserves native behavior and query bytes', () => {
  const runtime = loadSdk(urlSequenceOptions({location: {search: '?aggregate_page_sequence=2'}}));
  const anchor = runtime.createAnchor('/features?signature=a%20b&raw=~&plus=a+b&empty=&aggregate_page_sequence=19#details');
  const untouched = runtime.createAnchor('/untouched');
  assert.equal(anchor.getAttribute('href'), '/features?signature=a%20b&raw=~&plus=a+b&empty=&aggregate_page_sequence=19#details');
  const event = runtime.click({tagName: 'SPAN', parentNode: {parentNode: anchor}});
  const expected = 'https://www.example.com/features?signature=a%20b&raw=~&plus=a+b&empty=&aggregate_page_sequence=3#details';
  assert.equal(anchor.getAttribute('href'), expected);
  assert.equal(untouched.getAttribute('href'), '/untouched');
  assert.equal(event.defaultPrevented, false);
  assert.equal(runtime.window.location.pathname, '/pricing');
  assert.deepEqual(runtime.requests, []);
  runtime.click(anchor, {detail: 0});
  assert.equal(anchor.getAttribute('href'), expected);
  runtime.triggerPageView();
  runtime.window.Aggregate.emit('async_complete');
  assert.deepEqual(runtime.requests.map((payload) => payload.customData.page_sequence), [2, 2]);
  assertNoPageCounterStorage(runtime);
});

test('URL sequence follows decorated navigation, resets after a cleaned-URL reload, and caps propagation at twenty', () => {
  const first = loadSdk(urlSequenceOptions());
  const anchor = first.createAnchor('/next?preserved=yes#part');
  first.triggerPageView();
  first.click(anchor);
  const destination = new URL(anchor.href);
  const next = loadSdk(urlSequenceOptions({location: {pathname: destination.pathname, search: destination.search, hash: destination.hash}}));
  next.triggerPageView();
  assert.equal(next.requests.at(-1).customData.page_sequence, 2);
  const reload = loadSdk(urlSequenceOptions({location: {pathname: next.window.location.pathname, search: next.window.location.search}}));
  reload.triggerPageView();
  assert.equal(reload.requests.at(-1).customData.page_sequence, 1);

  const capped = loadSdk(urlSequenceOptions({location: {search: '?aggregate_page_sequence=20'}}));
  const capLink = capped.createAnchor('/next?aggregate_page_sequence=1&aggregate%5Fpage_sequence=2');
  capped.triggerPageView();
  capped.window.Aggregate.trackView();
  capped.click(capLink);
  assert.equal(capLink.getAttribute('href'), 'https://www.example.com/next?aggregate_page_sequence=20');
  assert.equal(capped.requests.every((payload) => payload.customData.page_sequence === 20), true);
  for (const runtime of [first, next, reload, capped]) assertNoPageCounterStorage(runtime);
});

test('URL decoration skips external, credentialed, download, fragment and new-context links', () => {
  for (const [href, attributes] of [
    ['https://other.example/features'], ['http://www.example.com/features'], ['//other.example/features'],
    ['https://user:password@www.example.com/features'], ['mailto:person@example.com'], ['tel:+15551234567'],
    ['javascript:alert(1)'], ['data:text/plain,hello'], ['#details'], ['#'], ['', {}],
    ['/pricing#details'], ['/pricing#'], ['/pricing?aggregate_page_sequence=9#details'],
    ['/features', {download: ''}], ['/features', {target: '_blank'}], ['/features', {target: 'named-frame'}]
  ]) {
    const runtime = loadSdk(urlSequenceOptions({location: {search: '?aggregate_page_sequence=2'}}));
    const anchor = runtime.createAnchor(href, attributes);
    const event = runtime.click(anchor);
    assert.equal(anchor.getAttribute('href'), href, href);
    assert.equal(event.defaultPrevented, false, href);
    assertNoPageCounterStorage(runtime);
  }
  for (const eventOptions of [{button: 1}, {button: 2}, {ctrlKey: true}, {metaKey: true}, {shiftKey: true}, {altKey: true}, {defaultPrevented: true}]) {
    const runtime = loadSdk(urlSequenceOptions());
    const anchor = runtime.createAnchor('/features');
    runtime.click(anchor, eventOptions);
    assert.equal(anchor.getAttribute('href'), '/features');
    assertNoPageCounterStorage(runtime);
  }
});

test('URL decoration respects base URLs, inherited targets, and explicit same-tab overrides', () => {
  const externalBase = loadSdk(urlSequenceOptions({baseURI: 'https://other.example/base/'}));
  const external = externalBase.createAnchor('relative');
  externalBase.click(external);
  assert.equal(external.getAttribute('href'), 'relative');
  const localBase = loadSdk(urlSequenceOptions({baseURI: 'https://www.example.com/base/'}));
  const local = localBase.createAnchor('relative?signature=a%20b#part');
  localBase.click(local);
  assert.equal(local.getAttribute('href'), 'https://www.example.com/base/relative?signature=a%20b&aggregate_page_sequence=2#part');
  const inheritedTarget = loadSdk(urlSequenceOptions({baseTarget: '_blank'}));
  const inherited = inheritedTarget.createAnchor('/features');
  inheritedTarget.click(inherited);
  assert.equal(inherited.getAttribute('href'), '/features');
  const sameTab = inheritedTarget.createAnchor('/features', {target: '_self'});
  inheritedTarget.click(sameTab);
  assert.equal(sameTab.getAttribute('href'), 'https://www.example.com/features?aggregate_page_sequence=2');
});

test('URL decoration preserves same-document hash navigation and permits explicit reload links', () => {
  const runtime = loadSdk(urlSequenceOptions({location: {pathname: '//section/page', search: '?x=1&aggregate_page_sequence=2'}}));
  const jump = runtime.createAnchor('https://www.example.com//section/page?x=1#');
  runtime.click(jump);
  assert.equal(jump.getAttribute('href'), 'https://www.example.com//section/page?x=1#');
  const reload = runtime.createAnchor('https://www.example.com//section/page?x=1');
  runtime.click(reload);
  assert.equal(reload.getAttribute('href'), 'https://www.example.com//section/page?x=1&aggregate_page_sequence=3');
});

test('URL sequence neither collects nor propagates through excluded source and destination paths', () => {
  const serverCustomData = {
    queryParameters: {}, consentFreeProperties: [], pageSequenceEnabled: true,
    pageSequenceMethod: 'url_parameter', pageSequenceExcludedPaths: ['/private/**', '/users/_redacted']
  };
  for (const pathname of ['/private', '/private/deep/path', '/%70rivate/x', '/private%252Frecords', '/users/%2531%2532%2533%2534']) {
    const source = loadSdk({serverCustomData, location: {pathname, search: '?aggregate_page_sequence=7'}});
    const sourceLink = source.createAnchor('/public');
    source.click(sourceLink);
    source.triggerPageView();
    assert.equal(sourceLink.getAttribute('href'), '/public', pathname);
    assert.equal(source.requests.at(-1).customData, undefined, pathname);
    assert.deepEqual(source.historyCalls, [], pathname);
    assertNoPageCounterStorage(source);
    const destination = loadSdk({serverCustomData});
    const destinationLink = destination.createAnchor(pathname);
    destination.click(destinationLink);
    assert.equal(destinationLink.getAttribute('href'), pathname, pathname);
    assertNoPageCounterStorage(destination);
  }
});

test('disabled and malformed URL sequence settings fail closed without counter storage cleanup', () => {
  for (const customData of [
    {pageSequenceEnabled: false, pageSequenceMethod: 'url_parameter'},
    {pageSequenceEnabled: 'true', pageSequenceMethod: 'url_parameter'},
    {pageSequenceEnabled: true, pageSequenceMethod: null},
    {pageSequenceEnabled: true, pageSequenceMethod: 'url'},
    {pageSequenceEnabled: true, pageSequenceMethod: true},
    {pageSequenceEnabled: true, pageSequenceMethod: []},
    {pageSequenceEnabled: true, pageSequenceMethod: 'url_parameter', pageSequenceExcludedPaths: null},
    {pageSequenceEnabled: true, pageSequenceMethod: 'url_parameter', propertyTypes: null}
  ]) {
    const runtime = loadSdk({inline: {customData}, location: {search: '?aggregate_page_sequence=7'}, sessionStorage: {[pageSequenceKey]: '12'}});
    const anchor = runtime.createAnchor('/features');
    runtime.triggerPageView();
    runtime.click(anchor);
    assert.equal(runtime.requests.at(-1).customData, undefined);
    assert.equal(anchor.getAttribute('href'), '/features');
    assert.equal(runtime.sessionStorage.has(pageSequenceKey), true);
    assert.deepEqual(runtime.historyCalls, []);
    assertNoPageCounterStorage(runtime);
  }
});

test('served URL method cannot be switched into storage by inline or configure overrides', () => {
  const serverCustomData = {
    queryParameters: {}, consentFreeProperties: [], pageSequenceEnabled: true, pageSequenceMethod: 'url_parameter'
  };
  const runtime = loadSdk({
    serverCustomData, inline: {customData: {pageSequenceMethod: 'session_storage'}},
    location: {search: '?aggregate_page_sequence=7'}, sessionStorage: {[pageSequenceKey]: '12'}
  });
  runtime.triggerPageView();
  runtime.window.Aggregate.configure({customData: {pageSequenceMethod: 'session_storage'}});
  runtime.window.Aggregate.emit('async_complete');
  assert.deepEqual(runtime.requests.map((payload) => payload.customData.page_sequence), [7, 7]);
  runtime.window.Aggregate.configure({customData: {pageSequenceEnabled: false, pageSequenceMethod: 'session_storage'}});
  runtime.window.Aggregate.emit('async_complete');
  assert.equal(runtime.requests.at(-1).customData, undefined);
  assertNoPageCounterStorage(runtime);

  const storage = loadSdk({
    serverCustomData: Object.assign({}, serverCustomData, {pageSequenceMethod: 'session_storage'}),
    inline: {customData: {pageSequenceMethod: 'url_parameter'}}, location: {search: '?aggregate_page_sequence=7'}
  });
  storage.triggerPageView();
  storage.window.Aggregate.configure({customData: {pageSequenceMethod: 'url_parameter'}});
  const anchor = storage.createAnchor('/features');
  storage.click(anchor);
  assert.equal(anchor.getAttribute('href'), '/features');
  assert.equal(storage.requests.at(-1).customData.page_sequence, 1);
  assert.deepEqual(storage.sessionStorage.writes, [[pageSequenceKey, '1']]);
});

test('disabled or invalid served URL policy cannot be repaired by browser overrides', () => {
  for (const serverSettings of [
    {pageSequenceEnabled: false, pageSequenceMethod: 'url_parameter'},
    {pageSequenceEnabled: true, pageSequenceMethod: null},
    {pageSequenceEnabled: true, pageSequenceMethod: 'url'},
    {pageSequenceEnabled: true, pageSequenceMethod: false}
  ]) {
    const runtime = loadSdk({
      serverCustomData: Object.assign({queryParameters: {}, consentFreeProperties: []}, serverSettings),
      inline: {customData: {pageSequenceEnabled: true, pageSequenceMethod: 'url_parameter'}},
      location: {search: '?aggregate_page_sequence=7'}, sessionStorage: {[pageSequenceKey]: '12'}
    });
    runtime.window.Aggregate.configure({customData: {pageSequenceEnabled: true, pageSequenceMethod: 'url_parameter'}});
    runtime.triggerPageView();
    const anchor = runtime.createAnchor('/features');
    runtime.click(anchor);
    assert.equal(runtime.requests.at(-1).customData, undefined);
    assert.equal(anchor.getAttribute('href'), '/features');
    assert.equal(runtime.sessionStorage.has(pageSequenceKey), true);
    assertNoPageCounterStorage(runtime);
  }
});

test('static storage-to-URL mode transitions leave old counter state untouched and clear only memory', () => {
  const runtime = loadSdk({inline: {customData: {pageSequenceEnabled: true}}, location: {search: '?aggregate_page_sequence=7'}});
  runtime.triggerPageView();
  const before = {
    reads: runtime.sessionStorage.reads.slice(), writes: runtime.sessionStorage.writes.slice(), removals: runtime.sessionStorage.removals.slice()
  };
  runtime.window.Aggregate.configure({customData: {pageSequenceMethod: 'url_parameter'}});
  runtime.window.Aggregate.emit('async_complete');
  assert.equal(runtime.requests.at(-1).customData.page_sequence, 7);
  runtime.window.Aggregate.configure({customData: {pageSequenceEnabled: false}});
  runtime.window.Aggregate.emit('async_complete');
  assert.equal(runtime.requests.at(-1).customData, undefined);
  assert.deepEqual(runtime.sessionStorage.reads, before.reads);
  assert.deepEqual(runtime.sessionStorage.writes, before.writes);
  assert.deepEqual(runtime.sessionStorage.removals, before.removals);
  assert.equal(runtime.sessionStorage.has(pageSequenceKey), true);
});

test('the URL transport parameter cannot become an ordinary mapped custom property', () => {
  const runtime = loadSdk(urlSequenceOptions({
    inline: {consent: false, customData: {
      pageSequenceEnabled: true, pageSequenceMethod: 'url_parameter',
      queryParameters: {aggregate_page_sequence: 'aliased_count'}, consentFreeProperties: ['aliased_count', 'page_sequence']
    }}, location: {search: '?aggregate_page_sequence=7'}
  }));
  runtime.window.Aggregate.emit('async_complete', {page_sequence: 19});
  assert.deepEqual(runtime.requests.at(-1).customData, {page_sequence: 7});
  assertNoPageCounterStorage(runtime);
});

test('URL depth is captured before DOM readiness and cleanup preserves history state, query bytes, path and hash', () => {
  const state = {router: {path: '/nested//path', revision: 2}, other: ['preserved']};
  const snapshot = JSON.stringify(state);
  const runtime = loadSdk(urlSequenceOptions({
    historyState: state,
    location: {pathname: '/nested//path', search: '?signature=a%20b&x=~&&aggregate_page_sequence=7&plus=a+b', hash: '#part%20one'}
  }));
  assert.deepEqual(runtime.requests, []);
  assert.equal(runtime.historyCalls.length, 1);
  assert.equal(runtime.historyCalls[0].state, state);
  assert.equal(runtime.history.state, state);
  assert.equal(JSON.stringify(state), snapshot);
  assert.equal(runtime.historyCalls[0].url, 'https://www.example.com/nested//path?signature=a%20b&x=~&&plus=a+b#part%20one');
  assert.equal(runtime.window.location.search, '?signature=a%20b&x=~&&plus=a+b');
  assert.equal(runtime.history.length, 7);
  assert.deepEqual(runtime.historyPushCalls, []);
  runtime.window.Aggregate.emit('early_event');
  runtime.triggerPageView();
  runtime.window.Aggregate.emit('async_complete');
  assert.deepEqual(runtime.requests.map((payload) => payload.customData.page_sequence), [7, 7, 7]);
  assert.equal(runtime.historyCalls.length, 1);
  const anchor = runtime.createAnchor('/next');
  runtime.click(anchor);
  assert.equal(anchor.href, 'https://www.example.com/next?aggregate_page_sequence=8');
  assertNoPageCounterStorage(runtime);
});

test('cleanup removes invalid and duplicate transport values and preserves empty fragments and double-slash paths', () => {
  for (const search of [
    '?aggregate_page_sequence=21', '?aggregate_page_sequence=invalid', '?aggregate_page_sequence=',
    '?aggregate_page_sequence=2&aggregate_page_sequence=3', '?aggregate_page_sequence=2&aggregate%5Fpage_sequence=2'
  ]) {
    const runtime = loadSdk(urlSequenceOptions({location: {pathname: '//section/page', search: search + '&keep=a%20b', hash: '#'}}));
    assert.equal(runtime.historyCalls[0].url, 'https://www.example.com//section/page?keep=a%20b#');
    assert.equal(runtime.window.location.origin, 'https://www.example.com');
    assert.equal(runtime.window.location.pathname, '//section/page');
    runtime.triggerPageView();
    assert.equal(runtime.requests.at(-1).customData.page_sequence, 1);
    assertNoPageCounterStorage(runtime);
  }
});

test('deferred URL-mode configuration captures and trims once without resetting repeated configuration or token changes', () => {
  const runtime = loadSdk({
    inline: {customData: {pageSequenceEnabled: false, pageSequenceMethod: 'url_parameter'}},
    location: {search: '?aggregate_page_sequence=7'}
  });
  assert.equal(runtime.window.location.search, '?aggregate_page_sequence=7');
  assert.deepEqual(runtime.historyCalls, []);
  const sdk = runtime.window.Aggregate;
  sdk.configure({customData: {pageSequenceEnabled: true}});
  assert.equal(runtime.window.location.search, '');
  sdk.emit('early_event');
  sdk.configure({endpoint: '/changed', customData: {pageSequenceEnabled: true}});
  sdk.emit('async_complete');
  sdk.configure({websiteToken: 'second-site'});
  sdk.emit('async_complete');
  sdk.configure({websiteToken: 'site-token'});
  sdk.trackView();
  runtime.triggerPageView();
  assert.deepEqual(runtime.requests.map((payload) => payload.customData.page_sequence), [7, 7, 7, 8, 8]);
  assert.equal(runtime.historyCalls.length, 1);
  assertNoPageCounterStorage(runtime);
});

test('unavailable or throwing History APIs leave URL depth usable without repeated cleanup attempts', () => {
  for (const historyOptions of [{historyUnavailable: true}, {historyThrows: true}, {historyGetterThrows: true}]) {
    const runtime = loadSdk(urlSequenceOptions(Object.assign({location: {search: '?aggregate_page_sequence=6&keep=yes'}}, historyOptions)));
    runtime.triggerPageView();
    runtime.window.Aggregate.emit('async_complete');
    runtime.window.Aggregate.configure({endpoint: '/changed'});
    runtime.window.Aggregate.emit('async_complete');
    assert.equal(runtime.window.location.search, '?aggregate_page_sequence=6&keep=yes');
    assert.deepEqual(runtime.requests.map((payload) => payload.customData.page_sequence), [6, 6, 6]);
    assert.equal(runtime.historyCalls.length, historyOptions.historyThrows ? 1 : 0);
    const anchor = runtime.createAnchor('/next');
    runtime.click(anchor);
    assert.equal(anchor.href, 'https://www.example.com/next?aggregate_page_sequence=7');
    assert.deepEqual(runtime.historyPushCalls, []);
    assertNoPageCounterStorage(runtime);
  }
});

test('framework-wrapped replaceState can reenter configuration and tracking without recursion or lost depth', () => {
  const state = {framework: 'existing'};
  const runtime = loadSdk(urlSequenceOptions({
    historyState: state, location: {search: '?aggregate_page_sequence=9'},
    onHistoryReplace: (window) => {
      window.Aggregate.configure({endpoint: '/framework-receiver'});
      window.Aggregate.emit('history_callback');
    }
  }));
  runtime.triggerPageView();
  runtime.window.Aggregate.emit('async_complete');
  assert.deepEqual(runtime.requests.map((payload) => payload.customData.page_sequence), [9, 9, 9]);
  assert.equal(runtime.historyCalls.length, 1);
  assert.equal(runtime.historyCalls[0].state, state);
  assert.equal(runtime.window.location.search, '');
  assertNoPageCounterStorage(runtime);
});

test('page information and network referrers omit full queries in both modes and consent states even when cleanup fails', () => {
  for (const method of ['url_parameter', 'session_storage']) {
    for (const consent of [false, true]) {
      for (const historyThrows of [false, true]) {
        const transports = [];
        const runtime = loadSdk({
          inline: {consent, customData: {pageSequenceEnabled: true, pageSequenceMethod: method}}, historyThrows,
          location: {pathname: '/pricing', search: '?aggregate_page_sequence=7&ignored_private=page-secret', hash: '#private-fragment'},
          referrer: 'https://other.example/from?aggregate_page_sequence=6&private=referrer-secret#source-fragment',
          fetch: (_url, request) => { transports.push(request); return Promise.resolve({ok: true}); }
        });
        runtime.triggerPageView();
        runtime.window.Aggregate.emit('async_complete');
        for (const payload of runtime.requests) {
          assert.equal(payload.pagePath, '/pricing');
          assert.equal(payload.referrerChannel, 'referral');
          assert.equal(payload.referrer, undefined);
          assert.equal(payload.url, undefined);
          assert.equal(/aggregate_page_sequence|page-secret|referrer-secret|private-fragment|source-fragment/.test(JSON.stringify(payload)), false);
        }
        for (const request of transports) {
          assert.equal(request.referrerPolicy, 'no-referrer');
          assert.equal(request.credentials, 'omit');
        }
      }
    }
  }
});

const scriptUrl = (query) => 'https://analytics.example/aggregate.js?min=1&endpoint=https%3A%2F%2Fanalytics.example%2Fapi%2Freceive&token=site-token' + query;

test('cd.* script URL values accompany the automatic page view under the same rules as emit() properties', () => {
  const runtime = loadSdk({
    src: scriptUrl('&consent=0&cd.page_type=pricing&cd.plan=team&cd.section.name=sales&cd.email=private%40example.com'),
    serverCustomData: {queryParameters: {}, consentFreeProperties: ['page_type', 'section.name']}
  });

  runtime.triggerPageView();
  runtime.window.Aggregate.emit('button_click');
  runtime.window.Aggregate.trackView();

  assert.deepEqual(runtime.requests[0].customData, {page_type: 'pricing', 'section.name': 'sales'}, 'consent-required values wait for consent');
  assert.equal(runtime.requests[0].consentState, 'denied');
  assert.equal(runtime.requests[1].customData, undefined, 'named events do not inherit script URL values');
  assert.equal(runtime.requests[2].customData, undefined, 'later views do not inherit them either');
  assert.equal(JSON.stringify(runtime.requests).includes('private@example.com'), false);
});

test('cd.* values include consent-required properties after an affirmative choice and override page URL mappings', () => {
  const runtime = loadSdk({
    src: scriptUrl('&cd.plan=team&cd.utm_source=from-script'),
    inline: {consent: true},
    location: {search: '?utm_source=newsletter&utm_medium=email'}
  });

  runtime.triggerPageView();

  assert.deepEqual(runtime.requests[0].customData, {plan: 'team', utm_source: 'from-script', utm_medium: 'email'});
});

test('cd.* values are bounded, trimmed strings with safe keys, and never coerced to declared types', () => {
  const many = Array.from({length: 60}, (_, index) => '&cd.key' + index + '=v' + index).join('');
  const runtime = loadSdk({
    src: scriptUrl('&cd.blank=%20%20&cd.blank=second&cd.first=one&cd.first=two&cd.padded=%20%00value%20&cd.__proto__=x'
      + '&cd.=empty&cd.9bad=x&cd.total_minor=1299&cd.active=true&cd.long=' + 'é'.repeat(300) + '&CD.upper=x&cdplain=x&plan=x' + many),
    inline: {consent: true, customData: {queryParameters: {}, propertyTypes: {total_minor: 'integer', active: 'boolean'}}}
  });

  runtime.triggerPageView();
  const sent = runtime.requests[0].customData;

  assert.equal(sent.blank, 'second');
  assert.equal(sent.first, 'one');
  assert.equal(sent.padded, 'value');
  assert.equal(Buffer.byteLength(sent.long), 500);
  for (const key of ['__proto__', '', '9bad', 'total_minor', 'active', 'upper', 'cdplain', 'plan']) {
    assert.equal(Object.prototype.hasOwnProperty.call(sent, key), false, key);
  }
  assert.equal(Object.keys(sent).length, 50);
});

test('strict collection and a missing script URL send no cd.* values', () => {
  const strict = loadSdk({src: scriptUrl('&cd.plan=team'), inline: {consent: true, collectionProfile: 'strict'}});
  strict.triggerPageView();
  assert.deepEqual(Object.keys(strict.requests[0]).sort(), ['eventName', 'pagePath', 'websiteToken']);

  const inline = loadSdk({inline: {consent: true, customData: {queryParameters: {}}}});
  inline.triggerPageView();
  assert.equal(inline.requests[0].customData, undefined);
});
