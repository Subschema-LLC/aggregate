'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

function loadController(sandbox = {}) {
  const source = fs.readFileSync(
    path.join(__dirname, '../../assets/controllers/components/ui/website_selector_controller.js'),
    'utf8'
  );
  return vm.runInNewContext(
    source.replace(/^import .*;\n/m, '').replace('export default class', 'class WebsiteSelectorController') +
      '\nWebsiteSelectorController;',
    { Controller: class {}, ...sandbox }
  );
}

function createMockDom({ savedScope = null, cards = [], groupCards = [], inPageSelect = null } = {}) {
  const storage = new Map();
  if (savedScope) {
    storage.set('aggregate_selected_scope', savedScope);
  }

  const cookies = [];
  const classListFor = (initial = []) => {
    const set = new Set(initial);
    return {
      add: (...names) => names.forEach((n) => set.add(n)),
      remove: (...names) => names.forEach((n) => set.delete(n)),
      contains: (n) => set.has(n),
      toggle: (n, force) => {
        const next = force === undefined ? !set.has(n) : Boolean(force);
        if (next) set.add(n);
        else set.delete(n);
        return next;
      },
    };
  };

  const options = [
    { value: 'all', textContent: 'All websites', dataset: {} },
    { value: 'group:retail', textContent: 'Retail', dataset: { groupId: 'retail' } },
    { value: 'website:site-1', textContent: 'Shop US', dataset: { siteId: 'site-1', token: 'tok-1', groupId: 'retail' } },
    { value: 'website:site-2', textContent: 'Blog', dataset: { siteId: 'site-2', token: 'tok-2', groupId: '' } },
  ];

  const select = {
    tagName: 'SELECT',
    value: 'all',
    options,
    querySelector: (sel) => {
      const match = sel.match(/option\[value="([^"]+)"\]/);
      if (match) {
        return options.find((o) => o.value === match[1]) || null;
      }
      return null;
    },
  };

  // Mock container for cards
  const container = {
    children: [...cards],
    appendChild(child) {
      const idx = this.children.indexOf(child);
      if (idx !== -1) this.children.splice(idx, 1);
      this.children.push(child);
    },
  };

  cards.forEach((c) => {
    c.parentElement = container;
    c.classList = classListFor(c.classList || []);
    if (!c.querySelector) {
      c.querySelector = (sel) => (sel === '[data-scope-matched-badge]' ? c.badge : null);
    }
  });

  const groupContainer = {
    children: [...groupCards],
    appendChild(child) {
      const idx = this.children.indexOf(child);
      if (idx !== -1) this.children.splice(idx, 1);
      this.children.push(child);
    },
  };

  groupCards.forEach((g) => {
    g.parentElement = groupContainer;
    g.classList = classListFor(g.classList || []);
    if (!g.querySelector) {
      g.querySelector = (sel) => {
        if (sel === '.collapsible-card-header') return g.header;
        if (sel === '[data-scope-matched-badge]') return g.badge;
        return null;
      };
    }
  });

  const doc = {
    get cookie() {
      return cookies.join('; ');
    },
    set cookie(val) {
      cookies.push(val);
    },
    querySelectorAll(sel) {
      if (sel === '[data-website-card]') return cards;
      if (sel === '[data-group-card]') return groupCards;
      if (sel === '.global-website-select') return [select];
      return [];
    },
    querySelector(sel) {
      if (sel === '#tag-manager-site') return inPageSelect?.id === 'tag-manager-site' ? inPageSelect : null;
      if (sel === '#consent-manager-site') return inPageSelect?.id === 'consent-manager-site' ? inPageSelect : null;
      if (sel === '#website-scope') return inPageSelect?.id === 'website-scope' ? inPageSelect : null;
      return null;
    },
  };

  select.ownerDocument = doc;

  const mockLocalStorage = {
    getItem: (k) => storage.get(k) || null,
    setItem: (k, v) => storage.set(k, String(v)),
  };

  return { select, doc, container, groupContainer, mockLocalStorage, storage, cookies };
}

test('website selector restores saved selection and expands matching website card on top', () => {
  const badge1 = { hidden: true };
  const card1 = {
    dataset: { websiteCard: '', websiteId: 'site-1', websiteToken: 'tok-1', websiteGroup: 'retail' },
    open: false,
    badge: badge1,
  };

  const badge2 = { hidden: true };
  const card2 = {
    dataset: { websiteCard: '', websiteId: 'site-2', websiteToken: 'tok-2', websiteGroup: '' },
    open: true,
    badge: badge2,
  };

  const { select, mockLocalStorage, container, cookies } = createMockDom({
    savedScope: 'website:site-1',
    cards: [card1, card2],
  });

  const WebsiteSelector = loadController({ localStorage: mockLocalStorage });
  const instance = Object.assign(new WebsiteSelector(), { element: select });
  instance.connect();

  assert.equal(select.value, 'website:site-1');
  assert.equal(card1.open, true, 'matching website card must be expanded');
  assert.equal(card1.classList.contains('is-scope-matched'), true);
  assert.equal(badge1.hidden, false, 'matching badge shown');

  assert.equal(card2.open, false, 'non-matching website card must be collapsed');
  assert.equal(card2.classList.contains('is-scope-matched'), false);
  assert.equal(badge2.hidden, true);
  assert.notEqual(card2.hidden, true, 'non-matching card must NOT be hidden');

  // Matching card should be first in container
  assert.equal(container.children[0], card1, 'matching card must be placed at the top');
  assert.equal(container.children[1], card2);

  // Verify cookies were written
  const cookieString = select.ownerDocument.cookie;
  assert.ok(cookieString.includes('aggregate_scope=website%3Asite-1'));
  assert.ok(cookieString.includes('aggregate_site=site-1'));
});

test('website selector expands all website cards in group when group is chosen', () => {
  const card1 = {
    dataset: { websiteCard: '', websiteId: 'site-1', websiteToken: 'tok-1', websiteGroup: 'retail' },
    open: false,
    badge: { hidden: true },
  };
  const card2 = {
    dataset: { websiteCard: '', websiteId: 'site-2', websiteToken: 'tok-2', websiteGroup: '' },
    open: true,
    badge: { hidden: true },
  };

  const { select, mockLocalStorage, container } = createMockDom({
    savedScope: 'group:retail',
    cards: [card1, card2],
  });

  const WebsiteSelector = loadController({ localStorage: mockLocalStorage });
  const instance = Object.assign(new WebsiteSelector(), { element: select });
  instance.connect();

  assert.equal(select.value, 'group:retail');
  assert.equal(card1.open, true, 'card in group must be opened');
  assert.equal(card1.classList.contains('is-scope-matched'), true);
  assert.equal(card2.open, false, 'card not in group must be collapsed');
  assert.equal(container.children[0], card1, 'group card must be sorted first');
});

test('website selector restores original order when switching to All websites', () => {
  const card1 = {
    dataset: { websiteCard: '', websiteId: 'site-1', websiteToken: 'tok-1', websiteGroup: 'retail' },
    open: false,
    badge: { hidden: true },
  };
  const card2 = {
    dataset: { websiteCard: '', websiteId: 'site-2', websiteToken: 'tok-2', websiteGroup: '' },
    open: false,
    badge: { hidden: true },
  };

  const { select, mockLocalStorage, container } = createMockDom({
    savedScope: 'website:site-2',
    cards: [card1, card2],
  });

  const WebsiteSelector = loadController({ localStorage: mockLocalStorage });
  const instance = Object.assign(new WebsiteSelector(), { element: select });
  instance.connect();

  // With site-2 selected, card2 was moved first
  assert.equal(container.children[0], card2);

  // Now user switches to 'all'
  select.value = 'all';
  instance.change({ target: select });

  assert.equal(container.children[0], card1, 'original order restored');
  assert.equal(container.children[1], card2);
  assert.equal(card1.classList.contains('is-scope-matched'), false);
  assert.equal(card2.classList.contains('is-scope-matched'), false);
});

test('website selector expands matching group card on Groups page', () => {
  const groupCard = {
    dataset: { groupCard: 'retail' },
    classList: ['is-collapsible-card', 'is-collapsed'],
    badge: { hidden: true },
    header: {
      setAttribute(k, v) { this[k] = v; },
    },
  };

  const { select, mockLocalStorage, groupContainer } = createMockDom({
    savedScope: 'group:retail',
    groupCards: [groupCard],
  });

  const WebsiteSelector = loadController({ localStorage: mockLocalStorage });
  const instance = Object.assign(new WebsiteSelector(), { element: select });
  instance.connect();

  assert.equal(groupCard.classList.contains('is-collapsed'), false, 'group card expanded');
  assert.equal(groupCard.classList.contains('is-scope-matched'), true);
  assert.equal(groupCard.header['aria-expanded'], 'true');
  assert.equal(groupCard.badge.hidden, false);
});

test('website selector triggers form submission when user changes selection on Tag Manager page', () => {
  let submitted = false;
  const inPageSelect = {
    id: 'tag-manager-site',
    value: 'site-1',
    dataset: {},
    form: {
      submit: () => { submitted = true; },
    },
    addEventListener() {},
  };

  const { select, mockLocalStorage } = createMockDom({
    inPageSelect,
  });

  const WebsiteSelector = loadController({ localStorage: mockLocalStorage });
  const instance = Object.assign(new WebsiteSelector(), { element: select });
  instance.connect();

  // User changes global select to site-2
  select.value = 'website:site-2';
  instance.change({ target: select });

  assert.equal(inPageSelect.value, 'site-2');
  assert.equal(submitted, true, 'tag manager form must be submitted on user selection change');
});

test('in-page switcher change updates saved scope and syncs global select', () => {
  let listener = null;
  const inPageSelect = {
    id: 'tag-manager-site',
    value: 'site-1',
    dataset: {},
    addEventListener(evt, fn) {
      if (evt === 'change') listener = fn;
    },
  };

  const { select, mockLocalStorage, storage } = createMockDom({
    inPageSelect,
  });

  const WebsiteSelector = loadController({ localStorage: mockLocalStorage });
  const instance = Object.assign(new WebsiteSelector(), { element: select });
  instance.connect();

  assert.ok(listener, 'change listener must be attached to in-page select');

  // Simulate user picking site-2 directly in Tag Manager dropdown
  inPageSelect.value = 'site-2';
  listener();

  assert.equal(storage.get('aggregate_selected_scope'), 'website:site-2');
  assert.equal(select.value, 'website:site-2', 'global select must reflect in-page choice');
});

