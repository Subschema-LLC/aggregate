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

function callRow(method, args, namespaces = ['Optin', 'Aggregate']) {
  const row = editor('call', 'data_layer');
  const status = {hidden: true, classList: classes()};
  const message = {textContent: ''};
  const fix = {hidden: true, textContent: ''};
  const eventInput = {value: '', focus() { eventInput.focused = true; }, setCustomValidity(text) { eventInput.validity = text; }};
  const eventStatus = {textContent: '', classList: classes()};
  row.methodInput.value = method;
  row.argumentInput.value = args;
  row.controller.actionFieldsTargets.push({dataset: {actionFields: 'event'}, querySelectorAll: () => [eventInput]});
  Object.assign(row.controller, {
    hasMethodTarget: true, methodTarget: row.methodInput,
    hasArgumentsTarget: true, argumentsTarget: row.argumentInput,
    hasArgumentsStatusTarget: true, argumentsStatusTarget: status,
    hasArgumentsMessageTarget: true, argumentsMessageTarget: message,
    hasArgumentsFixTarget: true, argumentsFixTarget: fix,
    hasEventTarget: true, eventTarget: eventInput,
    hasEventStatusTarget: true, eventStatusTarget: eventStatus,
    hasNamespacesValue: true, namespacesValue: namespaces
  });
  row.controller.update();
  return {...row, status, message, fix, eventInput, eventStatus};
}

function classes() {
  const names = new Set();
  return {toggle: (name, on) => { if (on) names.add(name); else names.delete(name); }, contains: (name) => names.has(name)};
}

test('a customData wrapper in a tracker emit call is explained and converts to an Aggregate event in one click', () => {
  const args = ['login', {customData: {app: 'optin_model_facilitation_app', app_env: 'prod', method: {$var: 'login_method'}, user_role: {$var: 'user_role'}}}];
  const row = callRow('Optin.emit', JSON.stringify(args, null, 4));
  assert.equal(row.status.hidden, false);
  assert.equal(row.status.classList.contains('is-warning'), true);
  assert.equal(row.message.textContent, 'Remove the "customData" wrapper and put its properties directly in the second argument. The tracker puts the properties in customData itself and drops a property that holds an object, so this event would be sent without them.');
  assert.equal(row.fix.hidden, false);
  assert.equal(row.fix.textContent, 'Convert to Send an Aggregate event');

  row.controller.convertToEvent();
  assert.equal(row.controller.actionTypeTarget.value, 'event');
  assert.equal(row.eventInput.value, JSON.stringify({eventName: 'login', customData: args[1].customData}, null, 4));
  assert.equal(row.eventInput.focused, true);
  assert.equal(row.eventInput.disabled, false);
  assert.equal(row.argumentInput.disabled, true, 'the call fields are no longer submitted');
  assert.equal(row.status.hidden, true);
  assert.equal(row.eventStatus.textContent, '', 'the converted event passes the checks');

  const flat = callRow('Aggregate.emit', '["signup_click", {"plan": "pro"}, "signup"]');
  assert.equal(flat.status.hidden, false, 'a working call is offered the event action too');
  assert.equal(flat.status.classList.contains('is-info'), true);
  assert.ok(flat.message.textContent.startsWith('A Send an Aggregate event action does this'));
  flat.controller.convertToEvent();
  assert.deepEqual(JSON.parse(flat.eventInput.value), {eventName: 'signup_click', customData: {plan: 'pro'}, goalEvent: 'signup'});
  const bare = callRow('Aggregate.emit', '["login", {"eventData": []}]');
  bare.controller.convertToEvent();
  assert.deepEqual(JSON.parse(bare.eventInput.value), {eventName: 'login'});
});

test('only tracker emit calls that would lose properties are flagged, with the server messages', () => {
  for (const [method, args, expected, convertible] of [
    ['Aggregate.emit', '["purchase", {"items": [{"sku": "x"}], "plan": "pro", "meta": {"a": 1}, "tags": ["x"]}]', 'The tracker drops "items", "meta" and "tags": a property must be text, a number, true, false or null, not an object or a list. Give each value its own property.', true],
    ['Aggregate.emit', '["login", {"customData": {"app": "a"}, "plan": "pro"}]', 'The tracker drops "customData": ', true],
    ['Aggregate.emit', '["login", {"customData": ["a"]}]', 'The tracker drops "customData": ', false],
    [' Optin.emit ', '["purchase", ["pro"]]', 'The second argument holds the event properties and must be an object, such as {"plan": "pro"}, or null. A goal goes third.', false],
    ['Aggregate.emit', '["signup_click", "signup"]', 'The second argument holds the event properties', false],
    ['Aggregate.emit', '["login", {"$var": "props"}]', 'A variable gives one value, but the second argument needs an object of properties.', false],
    ['Aggregate.emit', '[{"method": "password"}]', 'The first argument is the event name, such as "login".', false],
    ['Aggregate.emit', '', 'The first argument is the event name', false]
  ]) {
    const row = callRow(method, args);
    assert.equal(row.status.hidden, false, args);
    assert.ok(row.message.textContent.startsWith(expected), row.message.textContent);
    assert.equal(row.fix.hidden, !convertible, 'convertible: ' + args);
  }

  for (const [method, args, namespaces] of [
    ['Acme.emit', '["login", {"customData": {"app": "a"}}]'],
    ['Optin.configure', '[{"customData": {"propertyTypes": {}}}]'],
    ['Optin.emit', '["login", {"customData": {"app": "a"}}]', ['Aggregate']],
    ['Aggregate.emit', '["login", {"customData": ']
  ]) {
    assert.equal(callRow(method, args, namespaces).status.hidden, true, method + ' ' + args);
  }
});

test('the call notice follows the method and action as they change', () => {
  const row = callRow('Acme.track', '["login", {"customData": {"app": "a"}}]', []);
  assert.equal(row.status.hidden, true);
  row.methodInput.value = 'Aggregate.emit';
  row.controller.checkArguments();
  assert.equal(row.status.hidden, false, 'without a namespace the default Aggregate is used');
  row.controller.actionTypeTarget.value = 'script';
  row.controller.update();
  assert.equal(row.status.hidden, true, 'a script action does not send these arguments');
  row.controller.actionTypeTarget.value = 'call';
  row.controller.update();
  assert.equal(row.status.hidden, false);
});

test('a new Aggregate event starts from eventName and customData and is checked as the server will', () => {
  const row = callRow('', '[]');
  row.controller.actionTypeTarget.value = 'event';
  row.controller.update();
  assert.equal(row.eventInput.value, '{\n    "eventName": "",\n    "customData": {}\n}');
  assert.equal(row.eventStatus.textContent, '', 'the untouched starting point is not an error yet');
  row.eventInput.value = '{"eventName": "", "customData": {"plan": "pro"}}';
  row.controller.checkEvent();
  assert.match(row.eventStatus.textContent, /^The event: eventName must be a fixed name such as "login"/);
  assert.equal(row.eventInput.validity, row.eventStatus.textContent, 'saving waits until it is fixed');

  for (const [event, expected] of [
    ['{"eventName": "login", "customData": {"app": "a", "method": {"$var": "login_method"}, "attempts": 2, "admin": true, "none": null}, "goalEvent": "signup", "pagePath": "/account/login"}', ''],
    ['{"eventName": {"$var": "event_name"}}', ''],
    ['{"eventName": "login", "customData": []}', ''],
    ['', ''],
    ['["login"]', 'The event must be a JSON object, for example {"eventName": "login", "customData": {"method": "password"}}.'],
    ['{"eventName": "login",', 'The event must be a JSON object'],
    ['{"customData": {}}', 'The event needs an eventName, such as "login".'],
    ['{"eventName": "login", "method": "password"}', "The event has method, but it takes only eventName, customData, goalEvent and pagePath. Put the event's properties in customData."],
    ['{"eventName": "login", "pagePath": "/x", "deviceClass": "bot"}', 'The event has deviceClass, but it takes only eventName, customData, goalEvent and pagePath. The tracker sets deviceClass itself.'],
    ['{"eventName": "login", "customData": {"customData": {"a": 1}}}', 'The event: customData.customData must be text of at most 500 bytes, a number, true, false or null, not an object or a list. To read a value from the page, use {"$var": "alias"}.'],
    ['{"eventName": "login", "customData": {"page_sequence": 2}}', 'The event: the tracker adds customData.page_sequence itself; leave it out.'],
    ['{"eventName": "login", "customData": {"1st": "a"}}', 'The event: customData.1st is not a property name.'],
    ['{"eventName": "login", "customData": "a"}', 'The event: customData must be an object of properties'],
    ['{"eventName": "login", "goalEvent": " signup"}', 'The event: goalEvent must name a goal from config/goals.yaml, or be null.'],
    ['{"eventName": "login", "pagePath": "/search?q=x"}', 'The event: pagePath must be a path on the site such as /checkout/step-2, without a query or fragment.'],
    ['{"eventName": "login", "pagePath": "https://site.example/x"}', 'The event: pagePath must be a path']
  ]) {
    row.eventInput.value = event;
    row.controller.checkEvent();
    assert.ok(expected === '' ? row.eventStatus.textContent === '' : row.eventStatus.textContent.startsWith(expected), event + ' → ' + row.eventStatus.textContent);
    assert.equal(row.eventStatus.classList.contains('is-danger'), expected !== '');
  }
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
  const tabs = ['variables', 'tags', 'triggers'].map((id) => {
    const active = id === 'variables';
    const set = new Set(active ? ['is-active'] : []);
    const link = {attrs: {'aria-selected': active ? 'true' : 'false'}, setAttribute(k, v) { this.attrs[k] = v; }};
    return {
      dataset: {toggleTab: id, workspaceTab: id},
      classList: {toggle: (cls, on) => (on ? set.add(cls) : set.delete(cls)), contains: (cls) => set.has(cls)},
      querySelector: () => link,
      link
    };
  });
  const panes = ['variables', 'tags', 'triggers'].map((id) => {
    const active = id === 'variables';
    const set = new Set(active ? ['is-active'] : []);
    return {
      dataset: {togglePane: id, paneName: id},
      hidden: !active,
      classList: {toggle: (cls, on) => (on ? set.add(cls) : set.delete(cls)), contains: (cls) => set.has(cls)}
    };
  });
  const controller = Object.assign(new Page(), {
    element: {
      ownerDocument: {getElementById: (id) => elements[id] ?? null},
      querySelectorAll: (sel) => sel.includes('toggle-tab') ? tabs : (sel.includes('toggle-pane') ? panes : [])
    },
    hasNewRowValue: true, newRowValue: 3, hasStatusTarget: true, statusTarget: status
  });
  return {controller, fields, changes, status, tabs, panes};
}

const addEvent = {params: {url: tracker, id: 'aggregate-tracker'}};

test('adding the tracker fills only the empty new-tag row and explains what is left to do', () => {
  const empty = page({type: 'call', trigger: 'window_load'});
  empty.controller.addTracker(addEvent);
  assert.equal(empty.tabs[1].classList.contains('is-active'), true, 'adding the tracker switches to the tags tab');
  assert.equal(empty.panes[1].hidden, false);
  assert.equal(empty.panes[0].hidden, true);
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

function codeEditor({code = '', disabled = false, templatesJson, confirm = () => true, id = '', consent = 'analytics'} = {}) {
  const classes = new Set();
  const status = {textContent: '', classList: {toggle: (name, on) => (on ? classes.add(name) : classes.delete(name))}};
  const codeInput = {value: code, disabled, validity: '', setCustomValidity(message) { this.validity = message; }, focus() { this.focused = true; }};
  const fields = {id: {value: id}, consent: {value: consent}};
  const eventInput = {value: ''};
  const asked = [];
  const document = {
    getElementById: (name) => (name === 'tag-custom-templates' && templatesJson !== undefined ? {textContent: templatesJson} : null),
    defaultView: {Function, confirm: (message) => { asked.push(message); return confirm(); }}
  };
  const controller = Object.assign(new Editor(), {
    element: {ownerDocument: document, querySelector: (selector) => (selector.includes('[id]') ? fields.id : selector.includes('[consent]') ? fields.consent : null)},
    actionTypeTarget: {value: 'custom'}, triggerTarget: {value: 'dom_ready'},
    actionFieldsTargets: [], eventFieldTarget: {querySelector: () => eventInput},
    hasCodeTarget: true, codeTarget: codeInput, hasCodeStatusTarget: true, codeStatusTarget: status,
    hasTemplateTarget: true, templateTarget: {value: ''}
  });
  return {controller, codeInput, status, classes, fields, eventInput, asked};
}

test('the code editor compiles without running, reports syntax errors and HTML, and blocks submitting them', () => {
  const valid = codeEditor({code: "tag.emit('x'); window.ranInEditor = true;"});
  valid.controller.checkCode();
  assert.equal(valid.codeInput.validity, '');
  assert.match(valid.status.textContent, /No syntax errors found/);
  assert.deepEqual([...valid.classes], ['is-success']);
  assert.equal(globalThis.ranInEditor, undefined, 'checking never runs the code');

  for (const [code, expected] of [['const a = ;', /^Syntax error: /], ['with (a) {}', /^Syntax error: /], ['let tag = 1;', /^Syntax error: /],
    ['}); alert(1); (function () {', /^Syntax error: /], ['<script>tag.emit(1)</script>', /looks like HTML/]]) {
    const row = codeEditor({code});
    row.controller.checkCode();
    assert.match(row.codeInput.validity, expected, code);
    assert.equal(row.status.textContent, row.codeInput.validity);
    assert.deepEqual([...row.classes], ['is-danger']);
  }

  const hidden = codeEditor({code: 'const a = ;', disabled: true});
  hidden.controller.checkCode();
  assert.equal(hidden.codeInput.validity, '', 'a hidden action never blocks the form');
  assert.equal(hidden.status.textContent, '');
});

test('a template fills the code, trigger, consent and an empty ID, and asks before replacing other code', () => {
  const templatesJson = JSON.stringify({'Third-party vendors': [{id: 'image-pixel', label: 'Fire an image pixel', consent: 'marketing',
    trigger: {type: 'window_load'}, code: "// Pixel\nnew Image().src = 'https://pixel.example/x';"}]});
  const fresh = codeEditor({templatesJson});
  fresh.controller.templateTarget.value = 'image-pixel';
  fresh.controller.applyTemplate();
  assert.equal(fresh.codeInput.value, "// Pixel\nnew Image().src = 'https://pixel.example/x';");
  assert.equal(fresh.fields.id.value, 'image-pixel');
  assert.equal(fresh.fields.consent.value, 'marketing');
  assert.equal(fresh.controller.triggerTarget.value, 'window_load');
  assert.equal(fresh.controller.templateTarget.value, '', 'the picker resets for the next choice');
  assert.equal(fresh.codeInput.focused, true);
  assert.deepEqual(fresh.asked, []);

  const kept = codeEditor({templatesJson, code: 'tag.emit("mine");', id: 'my-tag', confirm: () => false});
  kept.controller.templateTarget.value = 'image-pixel';
  kept.controller.applyTemplate();
  assert.equal(kept.codeInput.value, 'tag.emit("mine");');
  assert.equal(kept.fields.id.value, 'my-tag');
  assert.match(kept.asked[0], /Replace the current code with the "Fire an image pixel" template\?/);

  const replaced = codeEditor({templatesJson, code: 'tag.emit("mine");', id: 'my-tag'});
  replaced.controller.templateTarget.value = 'image-pixel';
  replaced.controller.applyTemplate();
  assert.match(replaced.codeInput.value, /^\/\/ Pixel/);
  assert.equal(replaced.fields.id.value, 'my-tag', 'an existing ID is kept');

  const unknown = codeEditor({templatesJson: '{not json'});
  unknown.controller.templateTarget.value = 'image-pixel';
  unknown.controller.applyTemplate();
  assert.equal(unknown.codeInput.value, '');
});

// Rows with an ID field and a "Run after" list, as the page renders them.
function chainPage(rows) {
  const document = {createElement: () => ({value: '', textContent: ''})};
  const built = rows.map(({id, after = ''}) => {
    const idField = {value: id, matches: (selector) => selector === 'input[name^="tags["][name$="[id]"]'};
    const select = {
      options: [{value: '', textContent: 'Not chained: run on the trigger'}],
      value: after,
      replaceChildren(...options) { this.options = options; }
    };
    if (after) select.options.push({value: after, textContent: after});
    return {idField, select, row: {querySelector: (selector) => selector.startsWith('input') ? idField : select}};
  });
  const controller = Object.assign(new Page(), {element: {ownerDocument: document, querySelectorAll: () => built.map((entry) => entry.row)}});
  return {controller, rows: built, choices: (index) => built[index].select.options.map((option) => option.value)};
}

test('"Run after" lists follow the IDs typed into other rows and keep the current choice', () => {
  const chain = chainPage([{id: 'tracker'}, {id: 'views', after: 'tracker'}, {id: ''}]);
  chain.rows[2].idField.value = 'pixel';
  chain.controller.refreshRunAfter({target: chain.rows[2].idField});
  assert.deepEqual(chain.choices(0), ['', 'views', 'pixel']);
  assert.deepEqual(chain.choices(1), ['', 'tracker', 'pixel']);
  assert.equal(chain.rows[1].select.value, 'tracker');
  assert.deepEqual(chain.choices(2), ['', 'tracker', 'views']);
  assert.deepEqual(chain.rows[2].select.options.map((option) => option.textContent), ['Not chained: run on the trigger', 'Run after tracker', 'Run after views']);

  chain.rows[0].idField.value = 'renamed';
  chain.controller.refreshRunAfter({target: chain.rows[0].idField});
  assert.deepEqual(chain.choices(1), ['', 'renamed', 'pixel', 'tracker'], 'a choice whose tag was renamed stays visible until changed');

  const other = chainPage([{id: 'tracker'}]);
  other.controller.refreshRunAfter({target: {matches: () => false}});
  assert.deepEqual(other.choices(0), [''], 'typing in other fields changes nothing');
});
