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
      if (unavailable) throw new Error('Storage access blocked');
      values.delete(key);
    },
    has: (key) => values.has(key),
    reads,
    writes
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
  const sessionStorage = options.sessionStorageInstance || createStorage(
    Object.assign({aggregate_session_id: 'legacy-session'}, options.sessionStorage || {}),
    options.sessionStorageUnavailable
  );
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
    location: Object.assign({
      origin: 'https://www.example.com',
      pathname: '/pricing',
      protocol: 'https:',
      search: ''
    }, options.location || {}),
    screen: {width: 1440},
    self: {crypto: {randomUUID: () => '00000000-0000-4000-8000-000000000001'}},
    sessionStorage,
    setTimeout: () => {},
    window
  };
  window.location = context.location;

  const source = options.serverCustomData
    ? sdkSource.replace(
      /\{queryParameters:\s*\{utm_source:\s*['"]utm_source['"][^}]*\},\s*consentFreeProperties:\s*\[\]\}/,
      JSON.stringify(options.serverCustomData)
    )
    : sdkSource;
  if (options.serverCustomData) assert.notEqual(source, sdkSource, 'Runtime fixture must replace the public tracker defaults');
  vm.runInNewContext(source, context, {filename: 'aggregate.js'});

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
  assert.deepEqual(runtime.requests[0].eventData, utms);
  assert.deepEqual(runtime.requests[1].eventData, Object.assign({plan: 'pro'}, utms));
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
    assert.equal(payload.eventData, undefined);
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

  assert.deepEqual(runtime.requests[0].eventData, {utm_source: 'newsletter'});
  assert.deepEqual(runtime.requests[1].eventData, {
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
  assert.deepEqual(runtime.requests[0].eventData, {campaignName: 'preferred'});

  runtime.window.location.search = '?legacy_campaign=fallback&campaign=%20';
  runtime.window.Aggregate.emit('button_click');
  assert.deepEqual(runtime.requests.at(-1).eventData, {campaignName: 'fallback'});
});

test('explicit scalar event values override query values, including false, zero, empty string and null', () => {
  const runtime = loadSdk({
    inline: {customData: {queryParameters: {campaign: 'campaignName'}, consentFreeProperties: ['campaignName']}},
    location: {search: '?campaign=query-value'}
  });

  for (const value of ['explicit', false, 0, '', null]) {
    runtime.window.Aggregate.emit('button_click', {campaignName: value});
    assert.deepEqual(runtime.requests.at(-1).eventData, {campaignName: value});
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

  assert.deepEqual(runtime.requests[0].eventData, {utm_source: 'first-page'});
  assert.equal(runtime.requests[1].eventData, undefined);
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
  assert.deepEqual(runtime.requests.at(-1).eventData, {utm_source: '%broken'});
});

test('custom property processing rejects inherited, nested, unsafe and organization-marker values', () => {
  const runtime = loadSdk({inline: {consent: true}});
  const eventData = Object.assign(Object.create({inherited: 'ignored'}), {
    nested: {private: 'ignored'}, list: ['ignored'], constructor: 'ignored', prototype: 'ignored',
    'unsafe key': 'ignored', orgInternalTraffic: true, nan: NaN, infinity: Infinity,
    ' plan ': 'pro\u0000', safe: true
  });

  runtime.window.Aggregate.emit('button_click', eventData);

  assert.deepEqual(runtime.requests.at(-1).eventData, {plan: 'pro', safe: true});
  assert.equal(runtime.requests.at(-1).internalTraffic, false);
});

test('custom properties and query values have the server scalar count and UTF-8 bounds', () => {
  const runtime = loadSdk({inline: {consent: true}});
  const eventData = {unicode: '😀'.repeat(130), malformed: 'valid\ud800text'};
  for (let i = 0; i < 60; i++) eventData['property' + i] = i;

  runtime.window.Aggregate.emit('button_click', eventData);

  const sent = runtime.requests.at(-1).eventData;
  assert.equal(Object.keys(sent).length, 50);
  assert.equal(sent.unicode, '😀'.repeat(125));
  assert.equal(sent.malformed, 'validtext');
  assert.equal(sent.property48, undefined);

  runtime.window.location.search = '?utm_source=' + encodeURIComponent('é'.repeat(300));
  runtime.window.Aggregate.emit('button_click');
  assert.equal(runtime.requests.at(-1).eventData.utm_source, 'é'.repeat(250));
});

test('custom-data configuration can replace mappings, disable them and revoke consent-free properties', () => {
  const runtime = loadSdk({
    dataset: {namespace: 'CompanyAnalytics'},
    inline: {customData: {consentFreeProperties: ['utm_source']}},
    location: {search: '?utm_source=newsletter&campaign=launch'}
  });
  const sdk = runtime.window.CompanyAnalytics;

  sdk.emit('button_click');
  assert.deepEqual(runtime.requests.at(-1).eventData, {utm_source: 'newsletter'});

  sdk.configure({customData: {queryParameters: {campaign: 'campaignName'}, consentFreeProperties: ['campaignName']}});
  sdk.emit('button_click');
  assert.deepEqual(runtime.requests.at(-1).eventData, {campaignName: 'launch'});

  sdk.configure({customData: {queryParameters: {}}});
  sdk.emit('button_click');
  assert.equal(runtime.requests.at(-1).eventData, undefined);

  sdk.emit('button_click', {campaignName: 'explicit'});
  assert.deepEqual(runtime.requests.at(-1).eventData, {campaignName: 'explicit'});
  sdk.configure({customData: {consentFreeProperties: []}});
  sdk.emit('button_click', {campaignName: 'explicit'});
  assert.equal(runtime.requests.at(-1).eventData, undefined);
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
  assert.equal(runtime.requests.at(-1).eventData, undefined);
  assert.equal(runtime.requests.at(-1).internalTraffic, false);

  runtime.window.Aggregate.configure({customData: {queryParameters: null, consentFreeProperties: 'plan'}});
  runtime.window.Aggregate.emit('button_click', {plan: 'private'});
  assert.equal(runtime.requests.at(-1).eventData, undefined);
});

test('consent withdrawal immediately filters event properties back to the approved subset', () => {
  const runtime = loadSdk({
    inline: {customData: {consentFreeProperties: ['plan']}},
    location: {search: '?utm_source=newsletter'}
  });

  runtime.window.Aggregate.setConsent(true);
  runtime.window.Aggregate.emit('button_click', {plan: 'pro', private: 'enhanced-only'});
  assert.deepEqual(runtime.requests.at(-1).eventData, {plan: 'pro', private: 'enhanced-only', utm_source: 'newsletter'});

  runtime.window.Aggregate.setConsent(false);
  runtime.window.Aggregate.emit('button_click', {plan: 'pro', private: 'enhanced-only'});
  assert.deepEqual(runtime.requests.at(-1).eventData, {plan: 'pro'});
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
    assert.deepEqual(runtime.requests.at(-1).eventData, {title: 'Demo', quantity: 2, amount: 12.5, revenue: 25, active: false, optional: null, legacy: '12.5'});

    sdk.emit('purchase_completed', {title: 2, quantity: 2.5, amount: '12.5', revenue: Infinity, active: 'false', optional: true});
    assert.equal(runtime.requests.at(-1).eventData, enhanced ? null : undefined);

    for (const quantity of [0, -2, 9007199254740991, -9007199254740991]) {
      sdk.emit('purchase_completed', {quantity});
      assert.deepEqual(runtime.requests.at(-1).eventData, {quantity});
    }
    for (const quantity of [9007199254740992, -9007199254740992, NaN, Infinity, '2', true]) {
      sdk.emit('purchase_completed', {quantity});
      assert.equal(runtime.requests.at(-1).eventData, enhanced ? null : undefined);
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
  assert.deepEqual(runtime.requests.at(-1).eventData, {title: 'Demo'});
  runtime.window.Aggregate.emit('purchase_completed', {quantity: 2, revenue: 12.5, active: true});
  assert.deepEqual(runtime.requests.at(-1).eventData, {quantity: 2, revenue: 12.5, active: true, title: 'Demo'});
});

test('declaring a type cannot grant consent and withdrawal restores the permitted subset', () => {
  const runtime = loadSdk({inline: {customData: {
    queryParameters: {}, consentFreeProperties: ['quantity'],
    propertyTypes: {quantity: 'integer', revenue: 'double', orgInternalTraffic: 'boolean'}
  }}});
  const sdk = runtime.window.Aggregate;
  sdk.emit('purchase_completed', {quantity: 2, revenue: 12.5, orgInternalTraffic: true});
  assert.deepEqual(runtime.requests.at(-1).eventData, {quantity: 2});
  sdk.setConsent(true);
  sdk.emit('purchase_completed', {quantity: 2, revenue: 12.5, orgInternalTraffic: true});
  assert.deepEqual(runtime.requests.at(-1).eventData, {quantity: 2, revenue: 12.5});
  sdk.setConsent(false);
  sdk.emit('purchase_completed', {quantity: 2, revenue: 12.5});
  assert.deepEqual(runtime.requests.at(-1).eventData, {quantity: 2});
  assert.equal(runtime.requests.at(-1).visitorId, undefined);
});

test('malformed declared types block affected properties and malformed maps block all custom properties', () => {
  const runtime = loadSdk({inline: {consent: true, customData: {
    queryParameters: {}, propertyTypes: {valid: 'integer', invalid: 'number', invalidNull: null}
  }}});
  const sdk = runtime.window.Aggregate;
  sdk.emit('purchase_completed', {valid: 2, invalid: 12.5, invalidNull: null});
  assert.deepEqual(runtime.requests.at(-1).eventData, {valid: 2});
  for (const propertyTypes of [null, false, 'integer', ['integer']]) {
    sdk.configure({customData: {propertyTypes}});
    sdk.emit('purchase_completed', {valid: 2, legacy: 'text'});
    assert.equal(runtime.requests.at(-1).eventData, null);
  }
  sdk.configure({customData: {propertyTypes: {valid: 'integer'}}});
  sdk.emit('purchase_completed', {valid: 2, legacy: 'text'});
  assert.deepEqual(runtime.requests.at(-1).eventData, {valid: 2, legacy: 'text'});
});

const pageSequenceKey = 'aggregate_page_sequence:site-token';

test('optional page sequence survives document navigation while asynchronous events reuse the page count', async () => {
  const inline = {consent: false, customData: {pageSequenceEnabled: true}};
  const first = loadSdk({inline});
  first.window.Aggregate.emit('early_event');
  first.triggerPageView();
  await Promise.resolve().then(() => first.window.Aggregate.emit('async_complete', {}, 'purchase'));

  for (const payload of first.requests) {
    assert.equal(payload.eventData.page_sequence, 1);
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
  assert.deepEqual(second.requests.map((payload) => payload.eventData.page_sequence), [2, 2]);
  const reload = loadSdk({inline, sessionStorageInstance: first.sessionStorage, location: {pathname: '/features'}});
  reload.triggerPageView();
  assert.equal(reload.requests.at(-1).eventData.page_sequence, 3);
  const separateTab = loadSdk({inline});
  separateTab.triggerPageView();
  assert.equal(separateTab.requests.at(-1).eventData.page_sequence, 1);
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
  assert.deepEqual(runtime.requests.map((payload) => payload.eventData.page_sequence), [1, 2, 2, 3, 3]);
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
    for (const payload of runtime.requests) assert.equal(payload.eventData?.page_sequence, undefined);
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
  assert.equal(runtime.requests.at(-1).eventData, undefined);
  sdk.configure({customData: {pageSequenceEnabled: true}});
  sdk.emit('button_click');
  assert.deepEqual(runtime.requests.at(-1).eventData, {page_sequence: 1});
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
  assert.equal(runtime.requests.at(-1).eventData.page_sequence, 1);
  assert.equal(Object.keys(runtime.requests.at(-1).eventData).length, 50);
  runtime.window.Aggregate.configure({customData: {pageSequenceEnabled: false}});
  runtime.window.Aggregate.emit('button_click', {page_sequence: 'spoofed'});
  assert.equal(runtime.requests.at(-1).eventData, null);
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
  assert.deepEqual(runtime.requests.map((payload) => payload.eventData.page_sequence), [1, 1, 1, 2, 1]);
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
    for (const payload of runtime.requests) assert.equal(payload.eventData, undefined);
  }
  const runtime = loadSdk({inline: {customData: {pageSequenceEnabled: true}}});
  assert.equal(runtime.window.Aggregate.emit('invalid event name'), false);
  assert.deepEqual(runtime.sessionStorage.writes, []);
});

test('page counts stop at twenty and malformed stored counts restart at one', () => {
  for (const stored of ['0', '-1', '2.5', '21', '99999999999999999999', '01', ' 2', '2 ', '1e1', 'NaN', 'Infinity', '{"count":2}', '2\n']) {
    const runtime = loadSdk({inline: {customData: {pageSequenceEnabled: true}}, sessionStorage: {[pageSequenceKey]: stored}});
    runtime.triggerPageView();
    assert.equal(runtime.requests.at(-1).eventData.page_sequence, 1, stored);
  }
  const runtime = loadSdk({inline: {customData: {pageSequenceEnabled: true}}, sessionStorage: {[pageSequenceKey]: '19'}});
  runtime.triggerPageView();
  for (let page = 0; page < 25; page++) runtime.window.Aggregate.trackView();
  assert.equal(runtime.requests.every((payload) => payload.eventData.page_sequence === 20), true);
  assert.deepEqual(runtime.sessionStorage.writes, [[pageSequenceKey, '20']]);
  const nextDocument = loadSdk({inline: {customData: {pageSequenceEnabled: true}}, sessionStorageInstance: runtime.sessionStorage});
  nextDocument.triggerPageView();
  assert.equal(nextDocument.requests.at(-1).eventData.page_sequence, 20);
});

test('blocked session storage uses memory only and a new document starts over', () => {
  const options = {inline: {consent: false, customData: {pageSequenceEnabled: true}}, sessionStorageUnavailable: true};
  const runtime = loadSdk(options);
  runtime.triggerPageView();
  runtime.window.Aggregate.trackView();
  runtime.window.Aggregate.emit('async_complete');
  assert.deepEqual(runtime.requests.map((payload) => payload.eventData.page_sequence), [1, 2, 2]);
  assert.deepEqual(runtime.sessionStorage.writes, []);
  const nextDocument = loadSdk(options);
  nextDocument.triggerPageView();
  assert.equal(nextDocument.requests.at(-1).eventData.page_sequence, 1);
});

test('excluded document and SPA paths do not read, store, disclose or advance page sequence', () => {
  const inline = {customData: {pageSequenceEnabled: true, pageSequenceExcludedPaths: ['/private/**', '/users/_redacted']}};
  const runtime = loadSdk({inline, location: {pathname: '/private'}, sessionStorage: {[pageSequenceKey]: '4'}});
  runtime.triggerPageView();
  runtime.window.Aggregate.emit('button_click');
  assert.equal(runtime.requests.every((payload) => payload.eventData === undefined), true);
  assert.equal(runtime.sessionStorage.reads.includes(pageSequenceKey), false);
  assert.deepEqual(runtime.sessionStorage.writes, []);
  runtime.window.location.pathname = '/public';
  runtime.window.Aggregate.trackView();
  assert.equal(runtime.requests.at(-1).eventData.page_sequence, 5);
  runtime.window.location.pathname = '/users/1234';
  runtime.window.Aggregate.trackView();
  assert.equal(runtime.requests.at(-1).eventData, undefined);
  runtime.window.location.pathname = '/private/nested/path';
  runtime.window.Aggregate.emit('view');
  assert.equal(runtime.requests.at(-1).eventData, undefined);
  runtime.window.location.pathname = '/public';
  runtime.window.Aggregate.trackView();
  assert.equal(runtime.requests.at(-1).eventData.page_sequence, 6);
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
    assert.equal(Boolean(runtime.requests.at(-1).eventData), !excluded, pattern + ': ' + pathname);
  }
  for (const patterns of [null, true, '/private/**', {}, [null], [7], ['private'], ['/' + 'a'.repeat(512)]]) {
    const runtime = loadSdk({inline: {customData: {pageSequenceEnabled: true, pageSequenceExcludedPaths: patterns}}});
    runtime.triggerPageView();
    assert.equal(runtime.requests.at(-1).eventData, undefined);
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
    assert.equal(runtime.requests.at(-1).eventData, undefined, pathname);
    assert.equal(runtime.sessionStorage.reads.includes(pageSequenceKey), false, pathname);
    assert.deepEqual(runtime.sessionStorage.writes, [], pathname);
  }
  const runtime = loadSdk({
    inline: {customData: {pageSequenceEnabled: true, pageSequenceExcludedPaths: ['/people/_redacted']}},
    location: {pathname: '/people/%2531%2532%2533%2534'}
  });
  runtime.triggerPageView();
  assert.equal(runtime.requests.at(-1).eventData, undefined);
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
    assert.equal(runtime.requests.every((payload) => payload.eventData === undefined), true);
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
  assert.equal(runtime.requests.every((payload) => payload.eventData === undefined), true);
  assert.equal(runtime.sessionStorage.reads.includes(pageSequenceKey), false);
  assert.deepEqual(runtime.sessionStorage.writes, []);
  runtime.window.location.pathname = '/public';
  sdk.trackView();
  assert.equal(runtime.requests.at(-1).eventData.page_sequence, 1);
  sdk.configure({customData: {pageSequenceExcludedPaths: ['/public']}});
  sdk.trackView();
  assert.equal(runtime.requests.at(-1).eventData, undefined);
  assert.deepEqual(runtime.sessionStorage.writes, [[pageSequenceKey, '1']]);
});
