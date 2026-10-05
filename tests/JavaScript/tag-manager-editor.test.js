'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../../assets/controllers/components/tag_manager/tag_row_controller.js'), 'utf8');
const Editor = vm.runInNewContext(source.replace(/^import .*;\n/m, '').replace('export default class', 'class Editor') + '\nEditor;', {Controller: class {}});

function editor(action = 'script', trigger = 'dom_ready') {
  const scriptInput = {value: 'https://scripts.example.test/library.js'};
  const methodInput = {value: 'Acme.track'};
  const argumentInput = {value: '["purchase"]'};
  const eventInput = {value: 'purchase'};
  const controller = Object.assign(new Editor(), {
    actionTypeTarget: {value: action}, triggerTarget: {value: trigger},
    actionFieldsTargets: [
      {dataset: {actionFields: 'script'}, querySelectorAll: () => [scriptInput]},
      {dataset: {actionFields: 'call'}, querySelectorAll: () => [methodInput, argumentInput]}
    ],
    eventFieldTarget: {querySelector: () => eventInput}
  });
  controller.connect();
  return {controller, scriptInput, methodInput, argumentInput, eventInput};
}

test('switching a tag action submits only its applicable fields and keeps entered values', () => {
  const first = editor();
  const second = editor('call');
  assert.equal(first.scriptInput.disabled, false);
  assert.equal(first.methodInput.disabled, true);
  assert.equal(first.argumentInput.disabled, true);
  assert.equal(first.controller.actionFieldsTargets[1].hidden, true);
  first.controller.actionTypeTarget.value = 'call';
  first.controller.update();
  assert.equal(first.scriptInput.disabled, true);
  assert.equal(first.methodInput.disabled, false);
  assert.equal(first.argumentInput.disabled, false);
  assert.equal(first.controller.actionFieldsTargets[0].hidden, true);
  assert.equal(first.scriptInput.value, 'https://scripts.example.test/library.js');
  first.controller.actionTypeTarget.value = 'script';
  first.controller.update();
  assert.equal(first.scriptInput.disabled, false);
  assert.equal(first.argumentInput.value, '["purchase"]');
  assert.equal(second.scriptInput.disabled, true, 'another row keeps its own action');
  assert.equal(second.methodInput.disabled, false);
});

test('lifecycle triggers omit event names while every event trigger restores the same name', () => {
  const row = editor();
  for (const trigger of ['dom_ready', 'window_load', 'document_event', 'window_event', 'data_layer']) {
    row.controller.triggerTarget.value = trigger;
    row.controller.update();
    const lifecycle = ['dom_ready', 'window_load'].includes(trigger);
    assert.equal(row.eventInput.disabled, lifecycle, trigger);
    assert.equal(row.controller.eventFieldTarget.hidden, lifecycle, trigger);
    assert.equal(row.eventInput.value, 'purchase');
  }
});

const tracker = 'https://analytics.example/aggregate.js?min=1&endpoint=https%3A%2F%2Fanalytics.example%2Fapi%2Freceive&token=public-token&consent=0';

test('a script URL pasted from an HTML snippet gets plain query separators and a visible note', () => {
  const row = editor();
  const note = {hidden: true};
  Object.assign(row.controller, {hasSourceTarget: true, sourceTarget: row.scriptInput, hasSourceNoteTarget: true, sourceNoteTarget: note});
  row.scriptInput.value = tracker.replaceAll('&', '&amp;');
  row.controller.normalizeSource();
  assert.equal(row.scriptInput.value, tracker);
  assert.equal(note.hidden, false);

  const typed = editor();
  const quiet = {hidden: true};
  Object.assign(typed.controller, {hasSourceTarget: true, sourceTarget: typed.scriptInput, hasSourceNoteTarget: true, sourceNoteTarget: quiet});
  for (const [value, expected] of [
    ['https://scripts.example/a.js?x=1&#38;y=2&#x26;z=3&AMP;w=4', 'https://scripts.example/a.js?x=1&y=2&z=3&w=4'],
    ['https://scripts.example/a.js?x=1&amp;amp;y=2', 'https://scripts.example/a.js?x=1&y=2']
  ]) {
    typed.scriptInput.value = value;
    typed.controller.normalizeSource();
    assert.equal(typed.scriptInput.value, expected);
  }
  const untouched = editor();
  const hidden = {hidden: true};
  Object.assign(untouched.controller, {hasSourceTarget: true, sourceTarget: untouched.scriptInput, hasSourceNoteTarget: true, sourceNoteTarget: hidden});
  untouched.scriptInput.value = tracker + '&q=&lt;';
  untouched.controller.normalizeSource();
  assert.equal(untouched.scriptInput.value, tracker + '&q=&lt;', 'other entities and plain separators are left alone');
  assert.equal(hidden.hidden, true);
});

const pageSource = fs.readFileSync(path.join(__dirname, '../../assets/controllers/pages/tag_manager/index_controller.js'), 'utf8');
const Page = vm.runInNewContext(pageSource.replace(/^import .*;\n/m, '').replace('export default class', 'class Page') + '\nPage;', {
  Controller: class {},
  Event: class { constructor(type) { this.type = type; } }
});

function page(rowValues = {}, managerEnabled = '0') {
  const fields = {
    id: {value: ''}, type: {value: 'script'}, src: {value: ''}, method: {value: ''},
    trigger: {value: 'dom_ready'}, consent: {value: 'analytics'}, ...Object.fromEntries(Object.entries(rowValues).map(([k, v]) => [k, {value: v}]))
  };
  const changes = [];
  for (const [name, input] of Object.entries(fields)) {
    input.dispatchEvent = (event) => changes.push([name, event.type, input.value]);
    input.focus = () => { input.focused = true; };
  }
  const elements = {'tag-manager-enabled': {value: managerEnabled}};
  for (const [name, input] of Object.entries(fields)) elements[`tag-3-${name}`] = input;
  const status = {textContent: ''};
  const controller = Object.assign(new Page(), {
    element: {ownerDocument: {getElementById: (id) => elements[id] ?? null}},
    hasNewRowValue: true, newRowValue: 3, hasStatusTarget: true, statusTarget: status
  });
  return {controller, fields, changes, status};
}

const addEvent = {params: {url: tracker, id: 'aggregate-tracker'}};

test('adding the tracker fills only the empty new-tag row and explains what is left to do', () => {
  const empty = page({type: 'call', trigger: 'window_load'});
  empty.controller.addTracker(addEvent);
  assert.equal(empty.fields.src.value, tracker);
  assert.equal(empty.fields.id.value, 'aggregate-tracker');
  assert.equal(empty.fields.type.value, 'script');
  assert.equal(empty.fields.trigger.value, 'dom_ready');
  assert.deepEqual(empty.changes, [['type', 'change', 'script'], ['trigger', 'change', 'dom_ready']], 'row controls update their visible fields');
  assert.equal(empty.fields.consent.focused, true);
  assert.match(empty.status.textContent, /analytics consent category/);
  assert.match(empty.status.textContent, /tag manager is disabled/);

  const enabled = page({id: 'my-tracker'}, '1');
  enabled.controller.addTracker(addEvent);
  assert.equal(enabled.fields.src.value, '', 'a row with another ID is in use');
  assert.match(enabled.status.textContent, /already has other values/);

  for (const values of [{src: 'https://scripts.example/other.js'}, {method: 'Acme.track'}]) {
    const busy = page(values, '1');
    busy.controller.addTracker(addEvent);
    assert.equal(busy.fields.id.value, '');
    assert.match(busy.status.textContent, /already has other values/);
  }

  const again = page({}, '1');
  again.controller.addTracker(addEvent);
  again.controller.addTracker(addEvent);
  assert.equal(again.fields.src.value, tracker, 'adding twice is harmless');
  assert.doesNotMatch(again.status.textContent, /disabled/);
});
