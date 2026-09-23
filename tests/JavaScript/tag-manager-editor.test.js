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
