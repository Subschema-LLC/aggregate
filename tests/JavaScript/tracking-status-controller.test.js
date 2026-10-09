'use strict';

// The Collection controls page loads the tracking failure status after it has
// rendered, so the page and its kill switch never wait on the database.

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../../assets/controllers/pages/settings/tracking_status_controller.js'), 'utf8');

async function load(respond) {
  const requests = [];
  const Status = vm.runInNewContext(source.replace(/^import .*;\n/m, '').replace('export default class', 'class Status') + '\nStatus;', {
    Controller: class {},
    fetch: async (url, options) => { requests.push({url, options}); return respond(); }
  });
  const placeholder = {textContent: 'Checking for recorded tracking failures…'};
  const element = {innerHTML: null, querySelector: (selector) => selector === '[data-tracking-status-placeholder]' ? placeholder : null};
  const controller = Object.assign(new Status(), {element, urlValue: '/dashboard/collection/tracking-status'});
  await controller.load();
  return {element, placeholder, requests};
}

test('the status rendered by the server replaces the placeholder', async () => {
  const {element, requests} = await load(() => ({ok: true, json: async () => ({html: '<p>No retry run has been recorded yet.</p>'})}));
  assert.equal(requests.length, 1);
  assert.equal(requests[0].url, '/dashboard/collection/tracking-status');
  assert.equal(requests[0].options.credentials, 'same-origin');
  assert.equal(requests[0].options.cache, 'no-store');
  assert.equal(element.innerHTML, '<p>No retry run has been recorded yet.</p>');
});

test('an unreadable status says so instead of waiting', async () => {
  for (const respond of [
    () => ({ok: false, json: async () => ({error: 'Tracking failure status is unavailable.'})}),
    () => { throw new Error('offline'); },
    () => ({ok: true, json: async () => ({html: 42})})
  ]) {
    const {element, placeholder} = await load(respond);
    assert.equal(element.innerHTML, null);
    assert.match(placeholder.textContent, /unavailable/);
  }
});
