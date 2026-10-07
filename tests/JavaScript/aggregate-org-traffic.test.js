'use strict';

// Organization traffic: every standard-profile event reports
// org_internal_traffic as true or false, and a marking link
// (#aggregate-org-traffic=<code>) makes the tracker on that website write or
// remove the marker in the website's own cookie or local storage once the
// analytics server confirms the code. These tests use only public behaviour,
// so they also run against the minified build (AGGREGATE_SDK_SOURCE).

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

const CODE = 'v1.1800001800.mark.' + 'A'.repeat(43);
const CONTINUE = 'https://analytics.example/internal-traffic/continue';

// A small cookie jar: Max-Age=0 deletes, and every stored cookie is visible
// to the page (the jar holds only cookies whose domain covers it).
function cookieJar({refuseDomain = false, refuseAll = false, initial = []} = {}) {
  const cookies = new Map(initial.map(([name, value, domain = '']) => [name + '|' + domain, {name, value, domain}]));
  const writes = [];
  return {
    writes,
    cookies,
    get: () => Array.from(cookies.values()).map((cookie) => cookie.name + '=' + cookie.value).join('; '),
    set(text) {
      // Ignore the tracker's own identifier clean-up.
      if (!text.startsWith('aggregate_')) writes.push(text);
      const [pair, ...attributes] = text.split(';').map((part) => part.trim());
      const name = pair.slice(0, pair.indexOf('='));
      const value = pair.slice(pair.indexOf('=') + 1);
      const attribute = (key) => {
        const found = attributes.find((part) => part.toLowerCase().startsWith(key.toLowerCase() + '='));
        return found ? found.slice(key.length + 1) : null;
      };
      const domain = (attribute('Domain') || '').toLowerCase();
      if (refuseAll || (domain && refuseDomain)) return;
      if (attribute('Max-Age') === '0') cookies.delete(name + '|' + domain);
      else cookies.set(name + '|' + domain, {name, value, domain});
    }
  };
}

function storage(initial = {}) {
  const values = new Map(Object.entries(initial));
  return {
    getItem: (key) => values.has(key) ? values.get(key) : null,
    setItem: (key, value) => values.set(key, String(value)),
    removeItem: (key) => values.delete(key),
    values
  };
}

function loadSdk({
  hash = '',
  hostname = 'www.shop.example.com',
  protocol = 'https:',
  inline = {},
  dataset = {},
  verify = {valid: true, action: 'mark', cookieDomain: 'shop.example.com', continueUrl: CONTINUE},
  jar = cookieJar(),
  local = {}
} = {}) {
  const listeners = {};
  const verifications = [];
  const events = [];
  const replaced = [];
  const navigations = [];
  const origin = protocol + '//' + hostname;
  const location = {
    origin,
    protocol,
    host: hostname,
    hostname,
    pathname: '/',
    search: '',
    hash,
    href: origin + '/' + hash,
    replace: (url) => navigations.push(url)
  };
  const document = {
    currentScript: {dataset: Object.assign({websiteToken: 'site-token'}, dataset), src: 'https://analytics.example/aggregate.js'},
    readyState: 'loading',
    referrer: '',
    get cookie() { return jar.get(); },
    set cookie(text) { jar.set(text); },
    addEventListener: (name, listener, options) => {
      if (!(options === true || (options && options.capture))) listeners[name] = listener;
    },
    getElementsByTagName: () => [],
    querySelector: () => null,
    baseURI: location.href
  };
  const localStorage = storage(local);
  const windowTarget = {
    innerWidth: 1280,
    history: {
      state: null,
      replaceState: (_state, _title, url) => {
        replaced.push(url);
        location.href = url;
        location.hash = url.includes('#') ? url.slice(url.indexOf('#')) : '';
      }
    },
    Aggregate: Object.assign({endpoint: 'https://analytics.example/api/receive', websiteToken: 'site-token'}, inline)
  };
  const context = {
    URL,
    URLSearchParams,
    console: {warn: () => {}},
    document,
    fetch: (url, request) => {
      if (String(url).includes('/internal-traffic/verify')) {
        verifications.push({url: String(url), request});
        if (verify instanceof Error) return Promise.reject(verify);
        return Promise.resolve({json: () => Promise.resolve(verify)});
      }
      events.push(JSON.parse(request.body));
      return Promise.resolve({ok: true});
    },
    localStorage,
    sessionStorage: storage(),
    location,
    screen: {width: 1440},
    navigator: {userAgent: 'Mozilla/5.0 Chrome/126.0'},
    self: {crypto: {randomUUID: () => '00000000-0000-4000-8000-000000000001'}},
    setTimeout: (callback) => callback(),
    window: windowTarget
  };
  vm.runInNewContext(sdkSource, context, {filename: 'aggregate.js'});

  return {
    api: windowTarget.Aggregate,
    events,
    verifications,
    replaced,
    navigations,
    jar,
    localStorage,
    listeners,
    pageView: () => listeners.DOMContentLoaded && listeners.DOMContentLoaded()
  };
}

const settle = async () => {
  for (let i = 0; i < 5; i++) await new Promise((resolve) => setImmediate(resolve));
};

test('every event reports org_internal_traffic as true or false, and properties cannot change it', async () => {
  const unmarked = loadSdk();
  unmarked.pageView();
  unmarked.api.emit('signup', {org_internal_traffic: true, orgInternalTraffic: 'true'});
  await settle();
  assert.equal(unmarked.events.length, 2);
  for (const event of unmarked.events) {
    assert.equal(event.org_internal_traffic, false);
    assert.equal(Object.prototype.hasOwnProperty.call(event, 'internalTraffic'), false);
    assert.equal(Object.prototype.hasOwnProperty.call(event.customData || {}, 'org_internal_traffic'), false);
  }

  const marked = loadSdk({jar: cookieJar({initial: [['staff', 'team%20member']]}), inline: {internalTraffic: {name: 'staff', value: 'team member'}}});
  marked.pageView();
  marked.api.emit('signup', {org_internal_traffic: false});
  await settle();
  assert.deepEqual(marked.events.map((event) => event.org_internal_traffic), [true, true]);

  const stored = loadSdk({local: {staff: 'yes'}, inline: {internalTraffic: {storage: 'local_storage', name: 'staff', value: 'yes'}}});
  stored.pageView();
  await settle();
  assert.equal(stored.events[0].org_internal_traffic, true);
});

test('a marking link writes the cookie on this website and returns to the analytics server without a page view', async () => {
  const sdk = loadSdk({hash: '#aggregate-org-traffic=' + CODE, inline: {internalTraffic: {name: 'staff', value: 'team member'}}});
  await settle();

  assert.equal(sdk.verifications.length, 1);
  assert.equal(sdk.verifications[0].url, 'https://analytics.example/internal-traffic/verify?code=' + CODE + '&token=site-token');
  assert.equal(sdk.verifications[0].request.credentials, 'omit');
  assert.equal(sdk.verifications[0].request.referrerPolicy, 'no-referrer');
  // The code leaves the address before the request is made.
  assert.deepEqual(sdk.replaced, ['https://www.shop.example.com/']);
  assert.deepEqual(sdk.jar.writes, ['staff=team%20member; Path=/; Max-Age=31536000; SameSite=Lax; Secure; Domain=shop.example.com']);
  assert.deepEqual(sdk.navigations, [CONTINUE + '#marked=www.shop.example.com']);
  assert.equal(sdk.listeners.DOMContentLoaded, undefined, 'a page that only passes the browser on sends no page view');
  assert.deepEqual(sdk.events, []);
});

test('local storage markers are written and removed for this origin only', async () => {
  const marked = loadSdk({hash: '#aggregate-org-traffic=' + CODE, inline: {internalTraffic: {storage: 'local_storage', name: 'staff', value: 'yes'}}});
  await settle();
  assert.equal(marked.localStorage.values.get('staff'), 'yes');
  assert.deepEqual(marked.jar.writes, []);
  assert.deepEqual(marked.navigations, [CONTINUE + '#marked=www.shop.example.com']);

  const removed = loadSdk({
    hash: '#aggregate-org-traffic=' + CODE,
    local: {staff: 'yes', other: 'kept'},
    inline: {internalTraffic: {storage: 'local_storage', name: 'staff', value: 'yes'}},
    verify: {valid: true, action: 'remove', cookieDomain: '', continueUrl: CONTINUE}
  });
  await settle();
  assert.equal(removed.localStorage.values.has('staff'), false);
  assert.equal(removed.localStorage.values.get('other'), 'kept');
  assert.deepEqual(removed.navigations, [CONTINUE + '#removed=www.shop.example.com']);
});

test('removing a cookie clears the host-only copy and every parent-domain copy', async () => {
  const jar = cookieJar({initial: [['orgInternalTraffic', 'true', 'example.com'], ['orgInternalTraffic', 'true'], ['other', 'kept']]});
  const sdk = loadSdk({hash: '#aggregate-org-traffic=' + CODE, jar, verify: {valid: true, action: 'remove', cookieDomain: 'shop.example.com', continueUrl: CONTINUE}});
  await settle();

  assert.deepEqual(Array.from(jar.cookies.values()).map((cookie) => cookie.name), ['other']);
  assert.ok(jar.writes.every((text) => text.startsWith('orgInternalTraffic=; Path=/; Max-Age=0;')));
  assert.deepEqual(sdk.navigations, [CONTINUE + '#removed=www.shop.example.com']);
});

test('a cookie domain that does not contain this page is ignored, and a refused domain falls back to this hostname', async () => {
  const foreign = loadSdk({hash: '#aggregate-org-traffic=' + CODE, verify: {valid: true, action: 'mark', cookieDomain: 'evil.example.net', continueUrl: CONTINUE}});
  await settle();
  assert.deepEqual(foreign.jar.writes, ['orgInternalTraffic=true; Path=/; Max-Age=31536000; SameSite=Lax; Secure']);

  const refused = loadSdk({hash: '#aggregate-org-traffic=' + CODE, jar: cookieJar({refuseDomain: true})});
  await settle();
  assert.equal(refused.jar.writes.length, 2);
  assert.equal(refused.jar.writes[1], 'orgInternalTraffic=true; Path=/; Max-Age=31536000; SameSite=Lax; Secure');
  assert.deepEqual(refused.navigations, [CONTINUE + '#marked=www.shop.example.com']);

  const plain = loadSdk({hash: '#aggregate-org-traffic=' + CODE, protocol: 'http:', hostname: 'localhost', verify: {valid: true, action: 'mark', cookieDomain: '', continueUrl: CONTINUE}});
  await settle();
  assert.deepEqual(plain.jar.writes, ['orgInternalTraffic=true; Path=/; Max-Age=31536000; SameSite=Lax']);
});

test('a browser that refuses the cookie is reported as not changed', async () => {
  const sdk = loadSdk({hash: '#aggregate-org-traffic=' + CODE, jar: cookieJar({refuseAll: true})});
  await settle();
  assert.deepEqual(sdk.navigations, [CONTINUE + '#blocked=www.shop.example.com']);
});

test('an invalid, failed or redirected verification leaves storage alone and records the page view', async () => {
  for (const verify of [
    {valid: false, continueUrl: CONTINUE},
    {valid: 'true', action: 'mark', continueUrl: CONTINUE},
    {valid: true, action: 'mark', continueUrl: 'javascript:alert(1)'},
    {valid: true, action: 'mark', continueUrl: CONTINUE + '#elsewhere'},
    {valid: true, action: 'grant', continueUrl: 'https://analytics.example/internal-traffic/continue'},
    new TypeError('offline')
  ]) {
    const sdk = loadSdk({hash: '#aggregate-org-traffic=' + CODE, verify});
    await settle();
    const granted = verify && verify.action === 'grant';
    // An unknown action is not applied; the browser is still sent back.
    assert.deepEqual(sdk.jar.writes, [], JSON.stringify(verify));
    if (granted) {
      assert.deepEqual(sdk.navigations, [CONTINUE + '#blocked=www.shop.example.com']);
      continue;
    }
    assert.deepEqual(sdk.navigations, [], JSON.stringify(verify));
    sdk.pageView();
    await settle();
    assert.equal(sdk.events.length, 1);
    assert.equal(sdk.events[0].org_internal_traffic, false);
    assert.equal(sdk.events[0].pagePath, '/');
  }
});

test('other fragment parts are kept and malformed codes are not sent', async () => {
  const kept = loadSdk({hash: '#pricing&aggregate-org-traffic=' + CODE + '&tab=2'});
  await settle();
  assert.equal(kept.verifications.length, 1);
  assert.deepEqual(kept.replaced, ['https://www.shop.example.com/#pricing&tab=2']);

  for (const hash of ['#aggregate-org-traffic=', '#aggregate-org-traffic=<script>', '#xaggregate-org-traffic=' + CODE, '#aggregate-org-traffic=' + 'a'.repeat(201)]) {
    const sdk = loadSdk({hash});
    await settle();
    assert.deepEqual(sdk.verifications, [], hash);
    assert.deepEqual(sdk.replaced, [], hash);
    sdk.pageView();
    await settle();
    assert.equal(sdk.events.length, 1, hash);
  }
});

test('the verification address follows the configured endpoint path', async () => {
  const sdk = loadSdk({hash: '#aggregate-org-traffic=' + CODE, inline: {endpoint: 'https://analytics.example/stats/api/receive'}});
  await settle();
  assert.equal(sdk.verifications[0].url.split('?')[0], 'https://analytics.example/stats/internal-traffic/verify');
});

test('the strict profile neither reads a marking link nor reports the flag', async () => {
  const sdk = loadSdk({hash: '#aggregate-org-traffic=' + CODE, inline: {collectionProfile: 'strict'}});
  await settle();
  assert.deepEqual(sdk.verifications, []);
  assert.deepEqual(sdk.jar.writes, []);
  sdk.pageView();
  await settle();
  assert.equal(sdk.events.length, 1);
  assert.equal(Object.prototype.hasOwnProperty.call(sdk.events[0], 'org_internal_traffic'), false);
});
