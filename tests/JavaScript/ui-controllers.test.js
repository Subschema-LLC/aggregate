'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

function controller(name) {
  const source = fs.readFileSync(path.join(__dirname, '../../assets/controllers/components/ui/' + name + '_controller.js'), 'utf8');
  return vm.runInNewContext(source.replace(/^import .*;\n/m, '').replace('export default class', 'class UiController') + '\nUiController;', {Controller: class {}});
}

const Clipboard = controller('copy_button');
const Confirm = controller('confirm');
const Validation = controller('validation');
const Notification = controller('notification');
const Cards = controller('cards');
const EmailSettings = vm.runInNewContext(
  fs.readFileSync(path.join(__dirname, '../../assets/controllers/pages/email_settings/index_controller.js'), 'utf8')
    .replace(/^import .*;\n/m, '')
    .replace('export default class', 'class EmailSettingsController') + '\nEmailSettingsController;',
  {Controller: class {}}
);

function clipboard({source = {textContent: 'Example snippet'}, writeText = async () => {}, disabled = false} = {}) {
  const selections = [];
  const selection = {
    removeAllRanges() { selections.length = 0; },
    addRange(range) { selections.push(range); }
  };
  const feedback = {textContent: '', role: 'status', ariaLive: 'polite'};
  Object.defineProperty(feedback, 'innerHTML', {set() { assert.fail('Feedback must be plain text in the existing live region'); }});
  const elements = new Map([['copy-source', source], ['copy-feedback', feedback]]);
  const document = {
    getElementById: id => elements.get(id),
    defaultView: {navigator: {clipboard: writeText === null ? undefined : {writeText}}, getSelection: () => selection},
    createRange: () => ({selectNodeContents(node) { this.node = node; }})
  };
  const instance = Object.assign(new Clipboard(), {
    element: {ownerDocument: document, disabled},
    sourceValue: 'copy-source', statusValue: 'copy-feedback',
    successValue: 'Copied.', fallbackValue: 'Automatic copying is unavailable. Use your browser’s Copy command.'
  });
  return {instance, document, feedback, source, elements, selections};
}

test('clipboard copies rendered text exactly and uses current input values instead of stale text content', async () => {
  const copied = [];
  const snippet = '<script src="https://example.test/aggregate.js?a=1&b=2"></script>\n';
  const text = clipboard({source: {textContent: snippet, innerHTML: '&lt;script&gt;escaped markup&lt;/script&gt;'}, writeText: async value => copied.push(value)});
  await text.instance.copy();
  const input = clipboard({source: {value: 'https://example.test/current?token=public', textContent: 'old input value'}, writeText: async value => copied.push(value)});
  await input.instance.copy();
  const empty = clipboard({source: {value: '', textContent: 'stale default'}, writeText: async value => copied.push(value)});
  await empty.instance.copy();
  assert.deepEqual(copied, [snippet, 'https://example.test/current?token=public', '']);
  assert.equal(text.feedback.textContent, 'Copied.');
  assert.equal(input.feedback.textContent, 'Copied.');
  assert.equal(empty.feedback.textContent, 'Copied.');
  assert.equal(text.selections.length, 0, 'successful copying leaves the page selection alone');
});

test('a pending clipboard operation disables its button and restores the prior state on completion', async () => {
  for (const initiallyDisabled of [false, true]) {
    let finish;
    const pending = new Promise(resolve => { finish = resolve; });
    const app = clipboard({disabled: initiallyDisabled, writeText: () => pending});
    app.feedback.textContent = 'Previous feedback';
    const operation = app.instance.copy();
    assert.equal(app.instance.element.disabled, true);
    assert.equal(app.feedback.textContent, 'Previous feedback', 'success is not announced before clipboard completion');
    finish();
    await operation;
    assert.equal(app.instance.element.disabled, initiallyDisabled);
    assert.equal(app.feedback.textContent, 'Copied.');
  }
});

test('clipboard permission denial selects the current input and announces manual copying after the async failure', async () => {
  let reject;
  let selections = 0;
  const app = clipboard({
    source: {value: 'https://example.test/share', select() { selections++; }},
    writeText: () => new Promise((_resolve, fail) => { reject = fail; })
  });
  const operation = app.instance.copy();
  assert.equal(app.instance.element.disabled, true);
  assert.equal(selections, 0);
  reject(new Error('NotAllowedError'));
  await operation;
  assert.equal(selections, 1);
  assert.equal(app.instance.element.disabled, false);
  assert.equal(app.feedback.textContent, app.instance.fallbackValue);
  assert.equal(app.feedback.role, 'status');
  assert.equal(app.feedback.ariaLive, 'polite');
});

test('without the Clipboard API a text block is selected using a range and the status stays available', async () => {
  const app = clipboard({writeText: null});
  app.selections.push({node: {textContent: 'Previous unrelated selection'}});
  await app.instance.copy();
  assert.equal(app.selections.length, 1);
  assert.equal(app.selections[0].node, app.source);
  assert.equal(app.feedback.textContent, app.instance.fallbackValue);
  assert.equal(app.instance.element.disabled, false);
});

test('missing copy content or feedback leaves the control enabled without invoking the clipboard', async () => {
  for (const missing of ['copy-source', 'copy-feedback']) {
    const app = clipboard({writeText: () => assert.fail('An incomplete copy control must not write to the clipboard')});
    app.elements.delete(missing);
    await app.instance.copy();
    assert.equal(app.instance.element.disabled, false);
    assert.equal(app.feedback.textContent, '');
    assert.equal(app.selections.length, 0);
  }
});

test('a rejected form confirmation cancels submission and accepting preserves the same submit event', () => {
  const prompts = [];
  let accepted = false;
  const instance = Object.assign(new Confirm(), {
    messageValue: 'Delete this website token?',
    element: {ownerDocument: {defaultView: {confirm(message) { prompts.push(message); return accepted; }}}}
  });
  const dispatchSubmit = () => {
    const event = {defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }};
    instance.confirm(event);
    return !event.defaultPrevented;
  };
  assert.equal(dispatchSubmit(), false);
  accepted = true;
  assert.equal(dispatchSubmit(), true);
  assert.deepEqual(prompts, ['Delete this website token?', 'Delete this website token?']);
});

test('native invalid events reveal every enclosing detail inside the form and leave outside sections closed', () => {
  const outside = {open: false, parentElement: null};
  const outer = {open: false, parentElement: {closest: () => outside}};
  const inner = {open: false, parentElement: {closest: () => outer}};
  const unrelated = {open: false};
  const instance = Object.assign(new Validation(), {element: {contains: section => [inner, outer, unrelated].includes(section)}});
  const event = {target: {closest: selector => { assert.equal(selector, 'details'); return inner; }}};
  instance.reveal(event);
  assert.equal(inner.open, true);
  assert.equal(outer.open, true);
  assert.equal(outside.open, false);
  assert.equal(unrelated.open, false);
  instance.reveal({target: {closest: () => null}});
  assert.equal(unrelated.open, false, 'ordinary visible fields do not expand unrelated sections');
});

test('dismissing a notification removes only its own component', () => {
  const notifications = [];
  const notice = () => ({remove() { const index = notifications.indexOf(this); if (index !== -1) notifications.splice(index, 1); }});
  const first = notice();
  const second = notice();
  notifications.push(first, second);
  const instance = Object.assign(new Notification(), {element: first});
  instance.dismiss();
  assert.deepEqual(notifications, [second]);
  instance.dismiss();
  assert.deepEqual(notifications, [second]);
});

test('cards controller enhances cards and expands or collapses all cards on the page', () => {
  const makeClassList = (initial = []) => {
    const set = new Set(initial);
    return {
      add: (...names) => names.forEach(n => set.add(n)),
      remove: (...names) => names.forEach(n => set.delete(n)),
      contains: n => set.has(n),
      toggle: (n, force) => {
        const next = force === undefined ? !set.has(n) : Boolean(force);
        if (next) set.add(n); else set.delete(n);
        return next;
      }
    };
  };

  const listeners = {};
  const header = {
    tagName: 'H2',
    dataset: {},
    attributes: {},
    classList: makeClassList(['title']),
    hasAttribute(k) { return k in this.attributes; },
    setAttribute(k, v) { this.attributes[k] = v; },
    querySelector: () => null,
    appendChild() {},
    addEventListener(type, cb) { listeners[type] = cb; }
  };

  const card = {
    tagName: 'DIV',
    dataset: {},
    hidden: false,
    classList: makeClassList(['box']),
    children: [header],
    querySelector: sel => sel === '.collapsible-card-header' ? header : header
  };

  const detailsA = {tagName: 'DETAILS', open: false, hidden: false};
  const detailsB = {tagName: 'DETAILS', open: true, hidden: false};
  const toolbar1 = {hidden: false};
  const toolbar2 = {hidden: false};

  const container = {
    ownerDocument: {createElement: () => ({setAttribute() {}})},
    querySelectorAll(selector) {
      if (selector === '.box, .card') return [card];
      if (selector === '[data-card-controls]') return [toolbar1, toolbar2];
      if (selector === '.is-collapsible-card') return [card];
      if (selector.startsWith('details.')) return [detailsA, detailsB];
      return [];
    }
  };

  const instance = Object.assign(new Cards(), {element: container});
  instance.connect();

  assert.equal(card.classList.contains('is-collapsible-card'), true);
  assert.equal(header.classList.contains('collapsible-card-header'), true);
  assert.equal(header.attributes['aria-expanded'], 'true');
  assert.equal(toolbar1.hidden, true, 'duplicate fallback toolbar is hidden when page has its own toolbar');
  assert.equal(toolbar2.hidden, false);

  // Clicking the header toggles the card
  listeners.click({target: header});
  assert.equal(card.classList.contains('is-collapsed'), true);
  assert.equal(header.attributes['aria-expanded'], 'false');

  // Expand all opens both details and enhanced cards
  instance.expandAll();
  assert.equal(detailsA.open, true);
  assert.equal(detailsB.open, true);
  assert.equal(card.classList.contains('is-collapsed'), false);
  assert.equal(header.attributes['aria-expanded'], 'true');

  // Collapse all closes both details and enhanced cards
  instance.collapseAll();
  assert.equal(detailsA.open, false);
  assert.equal(detailsB.open, false);
  assert.equal(card.classList.contains('is-collapsed'), true);
  assert.equal(header.attributes['aria-expanded'], 'false');
});

test('email settings controller places the selected email service card first and collapses all others', () => {
  const makePanel = (provider, open = false) => {
    const badge = {hidden: !open};
    return {
      dataset: {provider},
      open,
      hidden: false,
      querySelector: sel => sel === '[data-provider-selected-badge]' ? badge : null,
      badge
    };
  };

  const postmark = makePanel('postmark', false);
  const sendgrid = makePanel('sendgrid', false);
  const smtp = makePanel('smtp', false);
  const order = [postmark, sendgrid, smtp];
  const providerList = {
    get firstElementChild() { return order[0]; },
    prepend(panel) {
      const idx = order.indexOf(panel);
      if (idx !== -1) order.splice(idx, 1);
      order.unshift(panel);
    }
  };

  const instance = Object.assign(new EmailSettings(), {
    hasProviderTarget: true,
    hasProviderListTarget: true,
    providerTarget: {value: 'sendgrid'},
    providerListTarget: providerList,
    panelTargets: order
  });

  instance.connect();

  assert.equal(order[0], sendgrid, 'selected provider card is moved first');
  assert.equal(sendgrid.open, true, 'selected provider card is expanded');
  assert.equal(sendgrid.badge.hidden, false);
  assert.equal(postmark.open, false, 'other provider cards are collapsed');
  assert.equal(postmark.badge.hidden, true);
  assert.equal(smtp.open, false, 'other provider cards are collapsed');
  assert.equal(postmark.hidden, false, 'other provider cards remain collapsible instead of hidden');
});
