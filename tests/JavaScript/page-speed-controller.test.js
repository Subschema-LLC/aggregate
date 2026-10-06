'use strict';

// The page speed panel reports whether the web server compresses the tracker,
// from the response a browser gets for the configured script.

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../../assets/controllers/pages/settings/page_speed_controller.js'), 'utf8');

async function check({encoding = null, ok = true, fails = false, encodedBodySize = 0} = {}) {
  const requests = [];
  const element = () => ({className: '', textContent: '', hidden: true});
  const targets = {compression: element(), compressionDetail: element(), compressionHelp: element()};
  const PageSpeed = vm.runInNewContext(source.replace(/^import .*;\n/m, '').replace('export default class', 'class PageSpeed') + '\nPageSpeed;', {
    Controller: class {},
    URL,
    window: {location: {href: 'https://analytics.example.test/dashboard/settings'}},
    performance: {getEntriesByName: (name) => encodedBodySize ? [{name, encodedBodySize}] : []},
    fetch: async (url, options) => {
      requests.push({url: String(url), options});
      if (fails) throw new TypeError('Network unavailable');
      return {ok, headers: {get: (name) => name === 'Content-Encoding' ? encoding : null}, arrayBuffer: async () => new ArrayBuffer(0)};
    }
  });
  const instance = Object.assign(new PageSpeed(), {
    urlValue: '/aggregate.js?min=1',
    compressionTarget: targets.compression,
    compressionDetailTarget: targets.compressionDetail,
    compressionHelpTarget: targets.compressionHelp
  });
  await instance.check();
  return {targets, requests};
}

test('compressed responses are reported with their encoding and size', async () => {
  const {targets, requests} = await check({encoding: 'br', encodedBodySize: 6006});
  assert.deepEqual(requests.map((request) => request.url), ['https://analytics.example.test/aggregate.js?min=1']);
  assert.equal(requests[0].options.cache, 'no-store', 'the check sees the server, not a cached copy');
  assert.equal(targets.compression.textContent, 'On');
  assert.match(targets.compression.className, /is-success/);
  assert.equal(targets.compressionDetail.textContent, 'Your web server sends the tracker with Brotli (5.9 KB over the network).');
  assert.equal(targets.compressionHelp.hidden, true);
  assert.equal((await check({encoding: 'gzip'})).targets.compressionDetail.textContent, 'Your web server sends the tracker with gzip.');
});

test('uncompressed responses point to the compression guide', async () => {
  const {targets} = await check({encoding: null, encodedBodySize: 18155});
  assert.equal(targets.compression.textContent, 'Not detected');
  assert.match(targets.compression.className, /is-warning/);
  assert.equal(targets.compressionDetail.textContent, 'Your web server sends the tracker uncompressed (17.7 KB over the network).');
  assert.equal(targets.compressionHelp.hidden, false);
});

test('a failed request is not mistaken for missing compression', async () => {
  for (const options of [{fails: true}, {ok: false, encoding: 'gzip'}]) {
    const {targets} = await check(options);
    assert.equal(targets.compression.textContent, 'Not checked');
    assert.equal(targets.compressionHelp.hidden, true);
  }
});
