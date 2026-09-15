'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const markerSource = fs.readFileSync(
  process.env.AGGREGATE_MARKER_SOURCE
    ? path.resolve(process.env.AGGREGATE_MARKER_SOURCE)
    : path.join(__dirname, '..', '..', 'templates', 'internal_traffic', 'marker.js.twig'),
  'utf8'
);

function loadMarker(options = {}) {
  const config = Object.assign({
    storage: 'cookie', name: 'orgInternalTraffic', value: 'true', cookieDomain: ''
  }, options.config || {});
  const cookies = new Map(Object.entries(options.cookies || {}));
  const localValues = new Map(Object.entries(options.localValues || {}));
  const writes = {cookies: [], local: [], localRemovals: [], cookieReads: 0};
  const handlers = {};
  const status = {textContent: ''};
  const root = {
    querySelector(selector) {
      if (selector === '[data-marker-status]') return status;
      return {addEventListener: (event, callback) => { handlers[selector + ':' + event] = callback; }};
    }
  };
  const document = {
    querySelector: () => root,
    getElementById: () => ({textContent: JSON.stringify(config)})
  };
  Object.defineProperty(document, 'cookie', {
    get() {
      writes.cookieReads++;
      if (options.blockCookieRead) throw new Error('Cookie access is blocked');
      return Array.from(cookies, ([name, value]) => name + '=' + value).join('; ')
        + (options.cookieSuffix ? '; ' + options.cookieSuffix : '');
    },
    set(value) {
      writes.cookies.push(value);
      if (options.blockCookieWrite) throw new Error('Cookie writes are blocked');
      if (options.ignoreCookieWrite) return;
      const pair = value.split(';')[0];
      const separator = pair.indexOf('=');
      const name = pair.slice(0, separator);
      if (/Max-Age=-1(?:;|$)/.test(value)) cookies.delete(name);
      else cookies.set(name, pair.slice(separator + 1));
    }
  });
  const localStorage = {
    getItem(name) {
      if (options.blockLocalRead) throw new Error('Local storage access is blocked');
      return localValues.has(name) ? localValues.get(name) : null;
    },
    setItem(name, value) {
      writes.local.push([name, value]);
      if (options.blockLocalWrite) throw new Error('Local storage writes are blocked');
      if (!options.ignoreLocalWrite) localValues.set(name, value);
    },
    removeItem(name) {
      writes.localRemovals.push(name);
      if (options.blockLocalRemove) throw new Error('Local storage removal is blocked');
      if (!options.ignoreLocalRemove) localValues.delete(name);
    }
  };

  vm.runInNewContext(markerSource, {
    document,
    localStorage,
    location: Object.assign({protocol: 'https:', hostname: 'www.example.com'}, options.location || {})
  }, {filename: 'marker.js.twig'});

  return {
    status, writes, cookies, localValues,
    clickSet: () => handlers['[data-marker-set]:click'](),
    clickRemove: () => handlers['[data-marker-remove]:click']()
  };
}

test('the enrollment page only reads the marker before an explicit click', () => {
  const runtime = loadMarker();

  assert.match(runtime.status.textContent, /not marked/);
  assert.deepEqual(runtime.writes.cookies, []);
  assert.deepEqual(runtime.writes.local, []);
  assert.deepEqual(runtime.writes.localRemovals, []);
  assert.equal(runtime.cookies.size, 0);
  assert.equal(runtime.localValues.size, 0);
});

test('the cookie button writes the default shared marker with its persistence and security attributes', () => {
  const runtime = loadMarker();

  runtime.clickSet();

  assert.equal(runtime.cookies.get('orgInternalTraffic'), 'true');
  assert.match(runtime.writes.cookies[0], /^orgInternalTraffic=true; Path=\/; Max-Age=31536000;/);
  assert.match(runtime.writes.cookies[0], /; Expires=.+ GMT; SameSite=Lax; Secure$/);
  assert.doesNotMatch(runtime.writes.cookies[0], /Domain=/);
  assert.match(runtime.status.textContent, /^Marker saved\./);
  assert.match(runtime.status.textContent, /browser is marked/);
  assert.deepEqual(runtime.writes.local, []);
});

test('cookie removal expires only the configured marker and keeps unrelated browser storage', () => {
  const runtime = loadMarker({
    cookies: {orgInternalTraffic: 'true', aggregate_session: 'session'},
    localValues: {aggregate_visitor_id: 'visitor'}
  });

  runtime.clickRemove();

  assert.equal(runtime.cookies.has('orgInternalTraffic'), false);
  assert.equal(runtime.cookies.get('aggregate_session'), 'session');
  assert.equal(runtime.localValues.get('aggregate_visitor_id'), 'visitor');
  assert.match(runtime.writes.cookies[0], /^orgInternalTraffic=; Path=\/; Max-Age=-1;/);
  assert.match(runtime.status.textContent, /^Marker removed\./);
  assert.match(runtime.status.textContent, /not marked/);
});

test('a parent-domain marker is encoded and can be applied from its subdomain', () => {
  const value = 'staff=yes & region=Montréal';
  const runtime = loadMarker({
    config: {name: 'teamMarker', value, cookieDomain: '.example.com'}
  });

  runtime.clickSet();

  assert.equal(runtime.cookies.get('teamMarker'), encodeURIComponent(value));
  assert.match(runtime.writes.cookies[0], /; Domain=\.example\.com; Secure$/);
  assert.match(runtime.status.textContent, /^Marker saved\./);

  runtime.clickRemove();
  assert.match(runtime.writes.cookies[1], /; Domain=\.example\.com; Secure$/);
  assert.equal(runtime.cookies.has('teamMarker'), false);
});

test('an ordinary cookie on HTTP omits Secure', () => {
  const runtime = loadMarker({location: {protocol: 'http:'}});

  runtime.clickSet();

  assert.match(runtime.status.textContent, /^Marker saved\./);
  assert.doesNotMatch(runtime.writes.cookies[0], /; Secure/);
});

for (const name of ['__Host-teamMarker', '__Secure-teamMarker']) {
  test(`the prefixed cookie ${name} requires HTTPS before writes`, () => {
    const runtime = loadMarker({config: {name}, location: {protocol: 'http:'}});

    runtime.clickSet();

    assert.match(runtime.status.textContent, /requires HTTPS/);
    assert.deepEqual(runtime.writes.cookies, []);
  });
}

for (const hostname of ['analytics.other.com', 'badexample.com']) {
  test(`cookie domain validation prevents marking from ${hostname}`, () => {
    const runtime = loadMarker({config: {cookieDomain: '.example.com'}, location: {hostname}});

    assert.match(runtime.status.textContent, /outside the configured cookie domain/);
    runtime.clickSet();
    runtime.clickRemove();

    assert.match(runtime.status.textContent, /outside the configured cookie domain/);
    assert.deepEqual(runtime.writes.cookies, []);
  });
}

for (const storage of ['cookie', 'local_storage']) {
  test(`downloaded file URLs cannot set or remove a ${storage} marker`, () => {
    const runtime = loadMarker({config: {storage}, location: {protocol: 'file:', hostname: ''}});

    assert.match(runtime.status.textContent, /Opening a downloaded file cannot mark/);
    runtime.clickSet();
    runtime.clickRemove();

    assert.deepEqual(runtime.writes.cookies, []);
    assert.deepEqual(runtime.writes.local, []);
    assert.deepEqual(runtime.writes.localRemovals, []);
  });
}

test('local storage enrollment uses only the configured entry and ignores cookie-domain settings', () => {
  const runtime = loadMarker({
    config: {storage: 'local_storage', name: 'staff', value: 'enabled', cookieDomain: 'unrelated.example'},
    cookies: {staff: 'enabled'},
    localValues: {aggregate_visitor_id: 'visitor'},
    blockCookieRead: true
  });

  assert.match(runtime.status.textContent, /not marked/);
  runtime.clickSet();
  assert.equal(runtime.localValues.get('staff'), 'enabled');
  assert.deepEqual(runtime.writes.local, [['staff', 'enabled']]);
  assert.match(runtime.status.textContent, /^Marker saved\./);

  runtime.clickRemove();
  assert.equal(runtime.localValues.has('staff'), false);
  assert.equal(runtime.localValues.get('aggregate_visitor_id'), 'visitor');
  assert.match(runtime.status.textContent, /^Marker removed\./);
  assert.equal(runtime.writes.cookieReads, 0);
  assert.deepEqual(runtime.writes.cookies, []);
});

test('silently rejected cookie writes report failure instead of successful enrollment', () => {
  const runtime = loadMarker({ignoreCookieWrite: true});

  runtime.clickSet();

  assert.match(runtime.status.textContent, /^Could not mark this browser\./);
  assert.match(runtime.status.textContent, /did not retain the marker/);
  assert.equal(runtime.cookies.has('orgInternalTraffic'), false);
});

test('blocked cookie reads or writes report errors without uncaught exceptions', () => {
  for (const options of [{blockCookieRead: true}, {blockCookieWrite: true}]) {
    const runtime = loadMarker(options);

    runtime.clickSet();
    assert.match(runtime.status.textContent, /^Could not mark this browser\./);
    runtime.clickRemove();
    assert.match(runtime.status.textContent, /^Could not remove this browser/);
  }
});

test('blocked or silently rejected local-storage writes report failure', () => {
  for (const options of [{blockLocalRead: true}, {blockLocalWrite: true}, {ignoreLocalWrite: true}]) {
    const runtime = loadMarker(Object.assign({config: {storage: 'local_storage'}}, options));

    runtime.clickSet();

    assert.match(runtime.status.textContent, /^Could not mark this browser\./);
  }
});

test('a marker that survives removal is reported instead of claiming successful removal', () => {
  const runtimes = [
    loadMarker({cookies: {orgInternalTraffic: 'true'}, ignoreCookieWrite: true}),
    loadMarker({
      config: {storage: 'local_storage'},
      localValues: {orgInternalTraffic: 'true'},
      ignoreLocalRemove: true
    }),
    loadMarker({
      config: {storage: 'local_storage'},
      localValues: {orgInternalTraffic: 'true'},
      blockLocalRemove: true
    })
  ];

  for (const runtime of runtimes) {
    runtime.clickRemove();

    assert.match(runtime.status.textContent, /^Could not remove this browser/);
  }
});

test('a matching cookie remaining under another scope prevents a false removal confirmation', () => {
  const runtime = loadMarker({
    config: {cookieDomain: '.example.com'},
    cookies: {orgInternalTraffic: 'true'},
    cookieSuffix: 'orgInternalTraffic=true'
  });

  runtime.clickRemove();

  assert.match(runtime.status.textContent, /matching marker still exists/);
});

test('malformed cookie values do not hide another exact marker match', () => {
  const runtime = loadMarker({cookies: {orgInternalTraffic: '%invalid'}, cookieSuffix: 'orgInternalTraffic=true'});

  assert.match(runtime.status.textContent, /browser is marked/);
});

for (const value of ['TRUE', '1', ' true', 'true ', 'true-other', '%invalid']) {
  test(`cookie enrollment status requires an exact configured value: ${JSON.stringify(value)}`, () => {
    const runtime = loadMarker({cookies: {orgInternalTraffic: value}});

    assert.match(runtime.status.textContent, /not marked/);
  });
}
