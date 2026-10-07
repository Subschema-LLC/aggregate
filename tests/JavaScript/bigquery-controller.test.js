'use strict';

// The BigQuery page shows only the chosen sign-in method's fields, asks for a
// confirmation only when a private view is newly selected, and reloads when a
// running sync finishes.

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../../assets/controllers/pages/bigquery/index_controller.js'), 'utf8');

function load({fetch = async () => ({ok: true, json: async () => ({running: false})}), timers = []} = {}) {
  const reloads = [];
  const Page = vm.runInNewContext(source.replace(/^import .*;\n/m, '').replace('export default class', 'class Page') + '\nPage;', {
    Controller: class {},
    fetch,
    setTimeout: (callback) => timers.push(callback),
    clearTimeout: () => {},
    window: {location: {reload: () => reloads.push(true)}}
  });
  return {Page, reloads, timers};
}

function radio(value, checked) {
  const label = {classList: {values: new Set(), toggle(name, on) { on ? this.values.add(name) : this.values.delete(name); }}};
  return {value, checked, closest: () => label, label};
}

test('only the chosen sign-in method shows its fields', () => {
  const {Page} = load();
  const auth = [radio('service_account', false), radio('google_cloud', false), radio('google_sign_in', true)];
  const panels = ['service_account', 'google_cloud', 'google_sign_in'].map((name) => ({dataset: {auth: name}, hidden: false}));
  const page = Object.assign(new Page(), {authTargets: auth, panelTargets: panels});

  page.showAuth();
  assert.deepEqual(panels.map((panel) => panel.hidden), [true, true, false]);
  assert.equal(auth[2].label.classList.values.has('is-selected'), true);
  assert.equal(auth[0].label.classList.values.has('is-selected'), false);

  auth[2].checked = false;
  auth[0].checked = true;
  page.showAuth();
  assert.deepEqual(panels.map((panel) => panel.hidden), [false, true, true]);
});

test('a confirmation is required only for newly added private views', () => {
  const {Page} = load();
  const checkbox = {required: false};
  const confirmation = {hidden: true, querySelector: () => checkbox};
  const saved = {checked: true, dataset: {saved: '1'}};
  const added = {checked: false, dataset: {saved: '0'}};
  const page = Object.assign(new Page(), {privateTargets: [saved, added], confirmationTarget: confirmation, hasConfirmationTarget: true});

  page.togglePrivate();
  assert.equal(confirmation.hidden, true);
  assert.equal(checkbox.required, false);

  added.checked = true;
  page.togglePrivate();
  assert.equal(confirmation.hidden, false);
  assert.equal(checkbox.required, true);
});

test('after Sync now, the page waits for the background run to start and finish', async () => {
  const answers = [{running: false, last_run: 100}, {running: true, last_run: 205}, {running: false, last_run: 205}];
  const timers = [];
  const {Page, reloads} = load({timers, fetch: async () => ({ok: true, json: async () => answers.shift()})});
  const page = Object.assign(new Page(), {statusUrlValue: '/status', sinceValue: 200});

  page.poll();
  await timers.shift()();
  assert.deepEqual(reloads, [], 'the run has not started yet');
  await timers.shift()();
  assert.deepEqual(reloads, [], 'the run is still going');
  await timers.shift()();
  assert.deepEqual(reloads, [true]);
});

test('a running sync is polled until it finishes, then the page reloads', async () => {
  const answers = [{running: true}, {running: false}];
  const requests = [];
  const timers = [];
  const {Page, reloads} = load({
    timers,
    fetch: async (url, options) => {
      requests.push([url, options.cache]);
      return {ok: true, json: async () => answers.shift()};
    }
  });
  const page = Object.assign(new Page(), {statusUrlValue: '/dashboard/bigquery/status'});

  page.poll();
  await timers.shift()();
  assert.deepEqual(reloads, []);
  assert.equal(timers.length, 1, 'still running: check again');
  await timers.shift()();
  assert.deepEqual(reloads, [true]);
  assert.deepEqual(requests, [['/dashboard/bigquery/status', 'no-store'], ['/dashboard/bigquery/status', 'no-store']]);
});
