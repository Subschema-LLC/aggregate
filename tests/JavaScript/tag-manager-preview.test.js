'use strict';

// "Test this tag": the worker (assets/tag_manager/preview_worker.js) running the
// real public/tag-manager.js with settings shaped as TagManagerPreview::plan()
// builds them, so a change to the runtime that the test depends on fails here.

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const workerSource = fs.readFileSync(path.join(__dirname, '../../assets/tag_manager/preview_worker.js'), 'utf8');
const runtimeSource = fs.readFileSync(path.join(__dirname, '../../public/tag-manager.js'), 'utf8');
const probeMethod = 'AggregateTagPreview.read';

// As TagManagerPreview::script() compiles a custom tag's stand-in.
function standIn(id) {
  return JSON.stringify(id) + ": function (tag) { 'use strict'; if (typeof self.AggregateTagPreviewCustom === 'function') self.AggregateTagPreviewCustom(tag.id); }";
}

function probe(id, alias, trigger, from) {
  return {id, type: 'call', method: probeMethod, args: [id, from === 'event' ? {$var: alias, from: 'event'} : {$var: alias}], consent: 'none', trigger};
}

function run({tags, variables = {}, custom = [], namespace = 'Aggregate', job}) {
  const config = {enabled: true, variables, tags, namespace};
  const script = runtimeSource
    .replace('var tagManagerConfig = {enabled: false, tags: []};', 'var tagManagerConfig = ' + JSON.stringify(config) + ';')
    .replace('var customScripts = {};', 'var customScripts = {' + custom.map(standIn).join(',\n') + '};');
  return new Promise((resolve) => {
    const context = vm.createContext({setTimeout, clearTimeout, URL});
    context.self = context;
    context.importScripts = () => vm.runInContext(script, context);
    // The worker's objects come from the VM's realm; compare them as plain data.
    context.postMessage = (message) => resolve(JSON.parse(JSON.stringify(message)));
    vm.runInContext(workerSource, context);
    context.onmessage({data: {scriptUrl: '/dashboard/tag-manager/preview/x.js', namespace, probeMethod, methods: [probeMethod], categories: [], consent: true, baseURI: 'https://www.gtu.example/', ...job}});
  });
}

const submit = {type: 'data_layer', event: 'form_submit'};
const requestInfo = {id: 'request-info', type: 'event', consent: 'analytics', trigger: submit,
  event: {eventName: 'request_info', customData: {program: {$var: 'program'}, campus: {$var: 'campus', from: 'event'}}}};
const requestTags = [requestInfo, probe('aggregate-preview-probe-1', 'program', submit), probe('aggregate-preview-probe-2', 'campus', submit, 'event')];
const requestVariables = {program: 'program_name', campus: 'campus'};

test('an event tag sends its event with values read by the runtime, and every probe reports its variable', async () => {
  const result = await run({tags: requestTags, variables: requestVariables, job: {trigger: submit, categories: ['analytics'], sample: {event: 'form_submit', program_name: 'Nursing', campus: 'Red Wing'}}});

  assert.equal(result.error, undefined);
  assert.deepEqual(result.records.emits, [[{eventName: 'request_info', customData: {program: 'Nursing', campus: 'Red Wing'}}]]);
  assert.deepEqual(result.records.probes, {'aggregate-preview-probe-1': 'Nursing', 'aggregate-preview-probe-2': 'Red Wing'});
  assert.deepEqual(result.records.scripts, []);
});

test('a missing value skips the tag, and only its probe is silent', async () => {
  const result = await run({tags: requestTags, variables: requestVariables, job: {trigger: submit, categories: ['analytics'], sample: {event: 'form_submit', campus: 'Red Wing'}}});

  assert.deepEqual(result.records.emits, []);
  assert.deepEqual(result.records.probes, {'aggregate-preview-probe-2': 'Red Wing'});
});

test('"this event\'s value" must come from the pushed event itself', async () => {
  const result = await run({tags: requestTags, variables: requestVariables, job: {trigger: submit, categories: ['analytics'], sample: {event: 'form_submit', program_name: 'Nursing'}}});

  assert.deepEqual(result.records.emits, [], 'campus is not in the pushed event');
  assert.deepEqual(result.records.probes, {'aggregate-preview-probe-1': 'Nursing'});
});

test('without consent the tag waits, while the probes, which need none, still read the sample', async () => {
  const result = await run({tags: requestTags, variables: requestVariables, job: {trigger: submit, categories: ['analytics'], consent: false, sample: {event: 'form_submit', program_name: 'Nursing', campus: 'Red Wing'}}});

  assert.deepEqual(result.records.emits, []);
  assert.equal(Object.keys(result.records.probes).length, 2);
});

test('a script tag is recorded with its variables substituted and never fetched', async () => {
  const trigger = {type: 'dom_ready'};
  const result = await run({
    variables: {page: 'page_type'},
    tags: [{id: 'helper', type: 'script', src: 'https://scripts.example/helper.js?page={{page}}', consent: 'none', trigger}, probe('aggregate-preview-probe-1', 'page', trigger)],
    job: {trigger, sample: {page_type: 'program detail'}}
  });

  assert.deepEqual(result.records.scripts, [{tag: 'helper', src: 'https://scripts.example/helper.js?page=program%20detail'}]);
  assert.deepEqual(result.records.probes, {'aggregate-preview-probe-1': 'program detail'});
});

test('a chained tag runs once the stand-in for the tag it waits for has loaded, and a method tag calls its recorder', async () => {
  const result = await run({
    tags: [
      {id: 'tracker', type: 'script', src: 'https://preview.invalid/tracker.js', consent: 'none', trigger: {type: 'dom_ready'}},
      {id: 'pixel', type: 'call', method: 'Acme.track', args: ['lead', {form: 'rfi'}], consent: 'marketing', trigger: submit, after: 'tracker'}
    ],
    job: {trigger: submit, methods: ['Acme.track', probeMethod], categories: ['marketing'], sample: {event: 'form_submit'}}
  });

  assert.deepEqual(result.records.calls, [{method: 'Acme.track', args: ['lead', {form: 'rfi'}]}]);
  assert.equal(result.records.scripts[0].tag, 'tracker');
});

test('custom JavaScript is a stand-in that only records that it ran', async () => {
  const trigger = {type: 'document_event', event: 'plan:selected'};
  const result = await run({
    variables: {plan: 'event.detail.plan'},
    custom: ['custom-one'],
    tags: [{id: 'custom-one', type: 'custom', consent: 'none', trigger}, probe('aggregate-preview-probe-1', 'plan', trigger)],
    job: {trigger, sample: {plan: 'team'}}
  });

  assert.deepEqual(result.records.custom, ['custom-one']);
  assert.deepEqual(result.records.probes, {'aggregate-preview-probe-1': 'team'}, 'a document event detail is read as event.detail');
});

test('settings the runtime refuses are reported, not run', async () => {
  const result = await run({tags: [{id: 'bad id!', type: 'script', src: 'https://x.example/a.js', consent: 'none'}], job: {trigger: {type: 'dom_ready'}, sample: {}}});

  assert.deepEqual(result.records.scripts, []);
  assert.deepEqual(result.records.emits, []);
});
