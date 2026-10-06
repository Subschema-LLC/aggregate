'use strict';

// The Websites page loads each website's data reception status after it has
// rendered, swapping in the badge and banner the server renders.

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../../assets/controllers/pages/websites/activity_controller.js'), 'utf8');

function placeholder(kind, token) {
  const label = {textContent: 'Checking data reception…'};
  const classes = new Set(kind === 'badge' ? ['website-activity-badge', 'status-checking'] : []);
  return {
    kind,
    dataset: kind === 'badge' ? {websiteActivityBadge: token} : {websiteActivityBanner: token},
    outerHTML: null,
    label,
    classes,
    classList: {replace: (from, to) => { if (!classes.delete(from)) return false; classes.add(to); return true; }},
    querySelector: (selector) => selector === '.activity-label' ? label : null
  };
}

async function load(respond, tokens = ['token-a', 'token-b']) {
  const elements = tokens.flatMap((token) => [placeholder('badge', token), placeholder('banner', token)]);
  const requests = [];
  const Activity = vm.runInNewContext(source.replace(/^import .*;\n/m, '').replace('export default class', 'class Activity') + '\nActivity;', {
    Controller: class {},
    fetch: async (url, options) => { requests.push({url, options}); return respond(); }
  });
  const element = {
    querySelector: (selector) => elements.find((node) => selector === '[data-website-activity-badge]' && node.kind === 'badge') || null,
    querySelectorAll: (selector) => elements.filter((node) => {
      if (selector === '[data-website-activity-badge]') return node.kind === 'badge';
      if (selector === '[data-website-activity-banner]') return node.kind === 'banner';
      if (selector === '[data-website-activity-badge].status-checking') return node.kind === 'badge' && node.classes.has('status-checking');
      return false;
    })
  };
  const controller = Object.assign(new Activity(), {element, urlValue: '/dashboard/websites/activity'});
  await controller.load();
  return {elements, requests};
}

test('each website gets the badge and banner rendered by the server', async () => {
  const {elements, requests} = await load(() => ({ok: true, json: async () => ({websites: {
    'token-a': {status: 'active', badge: '<span data-website-activity-badge="token-a">Receiving data</span>', banner: '<div data-website-activity-banner="token-a">Data reception</div>'}
  }})}));
  assert.equal(requests.length, 1);
  assert.equal(requests[0].url, '/dashboard/websites/activity');
  assert.equal(requests[0].options.credentials, 'same-origin');
  assert.equal(requests[0].options.cache, 'no-store');
  assert.equal(elements[0].outerHTML, '<span data-website-activity-badge="token-a">Receiving data</span>');
  assert.equal(elements[1].outerHTML, '<div data-website-activity-banner="token-a">Data reception</div>');
  assert.equal(elements[2].outerHTML, null, 'a website missing from the reply keeps its placeholder');
  assert.equal(elements[3].outerHTML, null);
});

test('a failed request says the status is unavailable instead of checking forever', async () => {
  for (const respond of [() => ({ok: false, json: async () => ({error: 'Data reception status is unavailable.'})}), () => { throw new TypeError('offline'); }]) {
    const {elements} = await load(respond);
    for (const badge of elements.filter((node) => node.kind === 'badge')) {
      assert.ok(badge.classes.has('status-unavailable'));
      assert.equal(badge.label.textContent, 'Status unavailable');
      assert.equal(badge.outerHTML, null);
    }
  }
});

test('inherited names in the reply are never used as websites', async () => {
  const {elements} = await load(() => ({ok: true, json: async () => ({websites: {}})}), ['toString', '__proto__']);
  assert.deepEqual(elements.map((node) => node.outerHTML), [null, null, null, null]);
});
