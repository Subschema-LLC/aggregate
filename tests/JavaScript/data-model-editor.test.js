'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../../assets/controllers/pages/data_model/index_controller.js'), 'utf8');
const Editor = vm.runInNewContext(source.replace(/^import .*;\n/m, '').replace('export default class', 'class Editor') + '\nEditor;', {Controller: class {}});

function container() {
  return {children: [], appendChild(row) { row.parent = this; this.children.push(row); }};
}

function template(kind) {
  return {content: {firstElementChild: {cloneNode() {
    const fields = (kind === 'property' ? ['key', 'type', 'consent_required', 'description', 'column', 'numeric_column'] : ['parameter', 'property'])
      .map(field => ({dataset: {field}, id: kind === 'property' ? 'property-new-' + (field === 'consent_required' ? 'consent' : field) : '', value: '', focus() { this.focused = true; }}));
    const labels = new Map(fields.filter(field => field.id).map(field => [field.id, {htmlFor: field.id}]));
    const summary = {textContent: 'New property'};
    return {
      fields, labels, summary,
      querySelectorAll: () => fields,
      querySelector(selector) {
        if (selector === 'input') return fields[0];
        if (selector === '[data-property-label]') return summary;
        return labels.get(selector.slice('label[for="'.length, -2));
      },
      remove() { this.parent.children.splice(this.parent.children.indexOf(this), 1); }
    };
  }}}};
}

function editor() {
  const propertyRows = container();
  const mappingRows = container();
  return Object.assign(new Editor(), {
    propertyRowsTarget: propertyRows, mappingRowsTarget: mappingRows,
    propertyTemplateTarget: template('property'), mappingTemplateTarget: template('mapping'),
    propertyIndexValue: 7, mappingIndexValue: 3,
    element: {contains: row => [...propertyRows.children, ...mappingRows.children].includes(row)}
  });
}

test('new property and mapping rows get independent unique names and matching accessible labels', () => {
  const controller = editor();
  controller.addProperty();
  controller.addMapping();
  const property = controller.propertyRowsTarget.children[0];
  const mapping = controller.mappingRowsTarget.children[0];
  for (const field of property.fields) {
    assert.equal(field.name, 'properties[7][' + field.dataset.field + ']');
    assert.equal(field.id, 'property-7-' + field.dataset.field);
    assert.ok([...property.labels.values()].some(label => label.htmlFor === field.id));
  }
  for (const field of mapping.fields) assert.equal(field.name, 'mappings[3][' + field.dataset.field + ']');
  assert.equal(property.fields[0].focused, true);
  assert.equal(mapping.fields[0].focused, true);
  controller.removeRow({currentTarget: {closest: () => property}});
  controller.addProperty();
  assert.equal(controller.propertyRowsTarget.children[0].fields[0].name, 'properties[8][key]', 'removed indices are never reused');
});

test('row limits prevent extra additions and removing one row permits another', () => {
  const controller = editor();
  for (let i = 0; i < 101; i++) {
    controller.addProperty();
    controller.addMapping();
  }
  assert.equal(controller.propertyRowsTarget.children.length, 50);
  assert.equal(controller.mappingRowsTarget.children.length, 100);
  assert.equal(controller.propertyIndexValue, 57);
  assert.equal(controller.mappingIndexValue, 103);
  const row = controller.propertyRowsTarget.children[0];
  controller.removeRow({currentTarget: {closest: () => row}});
  controller.addProperty();
  assert.equal(controller.propertyRowsTarget.children.length, 50);
  assert.equal(controller.propertyRowsTarget.children.at(-1).fields[0].name, 'properties[57][key]');
});

test('editing a property updates only its summary as text and an empty key restores the prompt', () => {
  const controller = editor();
  controller.addProperty();
  controller.addProperty();
  const [first, second] = controller.propertyRowsTarget.children;
  const input = {closest: () => first, value: '<img src=x>'};
  controller.renameProperty({currentTarget: input});
  assert.equal(first.summary.textContent, '<img src=x>');
  assert.equal(second.summary.textContent, 'New property');
  input.value = '';
  controller.renameProperty({currentTarget: input});
  assert.equal(first.summary.textContent, 'New property');
});
