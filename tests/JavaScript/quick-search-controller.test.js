'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

function createMockElement(tag = 'div') {
  const el = {
    tagName: tag.toUpperCase(),
    children: [],
    attributes: {},
    dataset: {},
    hidden: false,
    value: '',
    textContent: '',
    innerHTML: '',
    classList: {
      classes: new Set(),
      add(c) { this.classes.add(c); },
      remove(c) { this.classes.delete(c); },
      contains(c) { return this.classes.has(c); }
    },
    set id(val) { this.attributes['id'] = val; },
    get id() { return this.attributes['id'] || ''; },
    set className(val) {
      this._className = val;
      this.classList.classes.clear();
      if (val) {
        val.split(/\s+/).filter(Boolean).forEach(c => this.classList.classes.add(c));
      }
    },
    get className() {
      return this._className || Array.from(this.classList.classes).join(' ');
    },
    setAttribute(name, val) { this.attributes[name] = String(val); },
    getAttribute(name) { return this.attributes[name] || null; },
    removeAttribute(name) { delete this.attributes[name]; },
    appendChild(child) { this.children.push(child); return child; },
    replaceChildren(...newChildren) { this.children = [...newChildren]; },
    querySelectorAll(selector) {
      const found = [];
      function recurse(node) {
        if (!node) return;
        if (selector === '.quick-search-item' && node.classList && node.classList.contains('quick-search-item')) {
          found.push(node);
        }
        if (node.children) {
          for (const ch of node.children) recurse(ch);
        }
      }
      if (this.children) {
        for (const ch of this.children) recurse(ch);
      }
      return found;
    },
    querySelector(selector) {
      const all = this.querySelectorAll(selector);
      return all.length ? all[0] : null;
    },
    addEventListener() {},
    removeEventListener() {},
    scrollIntoView() {},
    focus() {},
    blur() {},
    click() { if (this.onclick) this.onclick(); }
  };
  return el;
}

const mockDoc = {
  createElement: createMockElement,
  addEventListener() {},
  removeEventListener() {}
};

function loadController() {
  const source = fs.readFileSync(path.join(__dirname, '../../assets/controllers/components/layout/quick_search_controller.js'), 'utf8');
  return vm.runInNewContext(
    source.replace(/^import .*;\r?\n/m, '').replace('export default class', 'class QuickSearchController') + '\nQuickSearchController;',
    {
      Controller: class {},
      document: mockDoc,
      navigator: { platform: 'Linux x86_64', userAgent: 'Linux' }
    }
  );
}

const QuickSearchController = loadController();

test('quick search filters items, ranks matches, highlights query, and renders footer', () => {
  const instance = new QuickSearchController();
  const input = createMockElement('input');
  const results = createMockElement('div');
  const wrapper = createMockElement('div');
  const clearBtn = createMockElement('button');
  clearBtn.className = 'quick-search-clear';
  const shortcut = createMockElement('span');
  shortcut.className = 'quick-search-shortcut';
  const kbd = createMockElement('kbd');
  shortcut.children.push(kbd);

  wrapper.children.push(input, clearBtn, shortcut, results);
  wrapper.querySelector = (sel) => {
    if (sel === '.quick-search-clear') return clearBtn;
    if (sel === '.quick-search-shortcut') return shortcut;
    if (sel === '.quick-search-shortcut kbd') return kbd;
    return null;
  };

  instance.element = wrapper;
  instance.inputTarget = input;
  instance.resultsTarget = results;
  instance.hasInputTarget = true;
  instance.hasResultsTarget = true;
  instance.hasItemsValue = true;
  instance.itemsValue = [
    { title: 'Branding Settings', category: 'Settings', description: 'Customize colors and logo', keywords: 'theme design', url: '/settings/branding' },
    { title: 'Privacy Settings', category: 'Settings', description: 'Manage consent and privacy', keywords: 'gdpr consent', url: '/settings/privacy' },
    { title: 'Documentation', category: 'Help', description: 'Read integration guides', keywords: 'api manual', url: '/docs' }
  ];

  // Test platform shortcut
  instance.updatePlatformShortcut();
  assert.equal(kbd.textContent, 'Ctrl+K');
  assert.equal(input.getAttribute('placeholder'), 'Search... (Ctrl+K)');

  // Test filter with match
  input.value = 'brand';
  instance.filter();

  assert.equal(results.hidden, false);
  const items = results.querySelectorAll('.quick-search-item');
  assert.equal(items.length, 1);
  assert.equal(items[0].getAttribute('role'), 'option');
  assert.equal(instance.selectedIndex, 0);
  assert.equal(items[0].classList.contains('is-active'), true);
  assert.equal(items[0].getAttribute('aria-selected'), 'true');
  assert.equal(input.getAttribute('aria-activedescendant'), 'quick-search-opt-0');

  // Verify match highlight
  function findMark(node) {
    if (!node) return false;
    if (node.innerHTML && node.innerHTML.includes('quick-search-highlight')) return true;
    if (node.children) {
      for (const ch of node.children) {
        if (findMark(ch)) return true;
      }
    }
    return false;
  }
  assert.ok(findMark(items[0]), 'Matched item contains highlight mark element');

  // Verify footer exists
  const footer = results.children.find(c => c.className === 'quick-search-footer');
  assert.ok(footer, 'Footer rendered in results');

  // Test navigation
  instance.navigate({ key: 'Escape', preventDefault() {} });
  // Escape clears non-empty input
  assert.equal(input.value, '');
});

test('quick search renders empty state when no items match', () => {
  const instance = new QuickSearchController();
  const input = createMockElement('input');
  const results = createMockElement('div');
  const wrapper = createMockElement('div');

  wrapper.querySelector = () => null;
  instance.element = wrapper;
  instance.inputTarget = input;
  instance.resultsTarget = results;
  instance.hasInputTarget = true;
  instance.hasResultsTarget = true;
  instance.hasItemsValue = true;
  instance.itemsValue = [
    { title: 'Branding Settings', category: 'Settings', description: 'Customize colors and logo', keywords: '', url: '/settings/branding' }
  ];

  input.value = 'nonexistentxyz';
  instance.filter();

  assert.equal(results.hidden, false);
  const empty = results.children.find(c => c.className === 'quick-search-empty');
  assert.ok(empty, 'Empty state container rendered');
  const title = empty.children.find(c => c.textContent === 'No matching results');
  assert.ok(title, 'Empty state title rendered');
});
