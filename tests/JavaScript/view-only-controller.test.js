'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../../assets/controllers/components/layout/view_only_controller.js'), 'utf8');
const ViewOnly = vm.runInNewContext(source.replace(/^import .*;\n/m, '').replace('export default class', 'class ViewOnlyController') + '\nViewOnlyController;', {Controller: class {}});

function control(form, {disabled = false} = {}) {
  const attributes = {};
  return {
    form,
    disabled,
    setAttribute(name, value) { attributes[name] = value; },
    attributes
  };
}

function form(method, {allowed = false} = {}) {
  return {method, hasAttribute: name => allowed && name === 'data-view-only-allowed'};
}

function page(controls) {
  let observed = null;
  const element = {
    querySelectorAll: selector => {
      assert.equal(selector, 'input, select, textarea, button');
      return controls;
    },
    ownerDocument: {
      defaultView: {
        MutationObserver: class {
          constructor(callback) { this.callback = callback; observed = this; }
          observe(target, options) { this.target = target; this.options = options; }
          disconnect() { this.disconnected = true; }
        }
      }
    }
  };
  const instance = Object.assign(new ViewOnly(), {element});
  return {instance, observer: () => observed};
}

test('turns off the controls of forms that save, and keeps filters and choices working', () => {
  const save = form('post');
  const filter = form('get');
  const suggestions = form('post', {allowed: true});
  const controls = [control(save), control(save), control(filter), control(suggestions), control(null)];
  const {instance, observer} = page(controls);

  instance.connect();

  assert.deepEqual(controls.map(c => c.disabled), [true, true, false, false, false]);
  assert.equal(controls[0].attributes['aria-disabled'], 'true');
  assert.equal(JSON.stringify(observer().options), JSON.stringify({childList: true, subtree: true}));
});

test('also turns off controls added later, such as new rows', () => {
  const save = form('post');
  const controls = [control(save)];
  const {instance, observer} = page(controls);
  instance.connect();

  controls.push(control(save));
  observer().callback();

  assert.equal(controls[1].disabled, true);
  instance.disconnect();
  assert.equal(observer().disconnected, true);
});
