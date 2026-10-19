'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../../assets/controllers/components/layout/navigation_menu_controller.js'), 'utf8');
const NavigationMenu = vm.runInNewContext(
    source.replace(/^import .*;\n/m, '').replace('export default class', 'class NavigationMenu') + '\nNavigationMenu;',
    {Controller: class {}}
);

function menu({wide = false} = {}) {
    const listeners = [];
    const media = {
        matches: wide,
        addEventListener: (type, listener) => listeners.push(listener),
        removeEventListener: (type, listener) => listeners.splice(listeners.indexOf(listener), 1),
    };
    const classes = new Set();
    const element = {
        classList: {
            add: (...names) => names.forEach((name) => classes.add(name)),
            remove: (...names) => names.forEach((name) => classes.delete(name)),
            contains: (name) => classes.has(name),
            toggle: (name, force) => (force ? classes.add(name) : classes.delete(name)),
        },
        ownerDocument: {defaultView: {matchMedia: (query) => { media.query = query; return media; }}},
    };
    let focused = 0;
    const toggle = {hidden: true, attributes: {}, setAttribute(key, value) { this.attributes[key] = value; }, focus: () => { focused++; }};
    const instance = Object.assign(new NavigationMenu(), {element, toggleTarget: toggle});
    instance.connect();
    return {instance, classes, toggle, media, listeners, focused: () => focused};
}

test('the menu button appears only once the controller enhances the navigation', () => {
    const app = menu();

    assert.equal(app.toggle.hidden, false);
    assert.equal(app.classes.has('is-collapsible'), true);
    assert.equal(app.classes.has('is-open'), false);
    assert.equal(app.toggle.attributes['aria-expanded'], 'false');
    assert.equal(app.media.query, '(min-width: 1366px)');

    app.instance.disconnect();
    assert.equal(app.toggle.hidden, true);
    assert.equal(app.classes.has('is-collapsible'), false);
    assert.equal(app.listeners.length, 0);
});

test('toggling opens and closes the menu and keeps aria-expanded in sync', () => {
    const app = menu();

    app.instance.toggle();
    assert.equal(app.classes.has('is-open'), true);
    assert.equal(app.toggle.attributes['aria-expanded'], 'true');

    app.instance.toggle();
    assert.equal(app.classes.has('is-open'), false);
    assert.equal(app.toggle.attributes['aria-expanded'], 'false');
});

test('Escape closes an open menu and returns focus to the menu button', () => {
    const app = menu();
    app.instance.close({type: 'keydown'});
    assert.equal(app.focused(), 0, 'a closed menu ignores Escape');

    app.instance.toggle();
    app.instance.close({type: 'keydown'});
    assert.equal(app.classes.has('is-open'), false);
    assert.equal(app.toggle.attributes['aria-expanded'], 'false');
    assert.equal(app.focused(), 1);
});

test('widening past the desktop breakpoint closes the menu', () => {
    const app = menu();
    app.instance.toggle();

    app.media.matches = true;
    app.listeners.forEach((listener) => listener());

    assert.equal(app.classes.has('is-open'), false);
    assert.equal(app.toggle.attributes['aria-expanded'], 'false');
});

test('active route link and its parent group are marked active and outside clicks close open groups', () => {
    const groupA = {open: true, classes: new Set(), classList: {add: (n) => groupA.classes.add(n)}};
    const groupB = {open: true, classes: new Set(), classList: {add: (n) => groupB.classes.add(n)}};
    const linkA = {
        attrs: {href: '/dashboard/email'},
        classes: new Set(),
        getAttribute(k) { return this.attrs[k]; },
        setAttribute(k, v) { this.attrs[k] = v; },
        classList: {add: (n) => linkA.classes.add(n)},
        closest: (sel) => sel === 'details.app-navigation-group' ? groupA : null,
    };
    const classes = new Set();
    let docClick = null;
    const element = {
        classList: {
            add: (...names) => names.forEach((n) => classes.add(n)),
            remove: (...names) => names.forEach((n) => classes.delete(n)),
            contains: (n) => classes.has(n),
            toggle: (n, force) => (force ? classes.add(n) : classes.delete(n)),
        },
        querySelectorAll(sel) {
            if (sel === '.app-navigation-items a.navbar-item[href]') return [linkA];
            if (sel === 'details.app-navigation-group[open]') return [groupA, groupB].filter((g) => g.open);
            return [];
        },
        ownerDocument: {
            addEventListener(type, cb) { if (type === 'click') docClick = cb; },
            removeEventListener() {},
            defaultView: {
                location: {pathname: '/dashboard/email'},
                matchMedia: () => ({matches: false, addEventListener() {}, removeEventListener() {}}),
            },
        },
    };
    const toggle = {hidden: true, setAttribute() {}, focus() {}};
    const instance = Object.assign(new NavigationMenu(), {element, toggleTarget: toggle});
    instance.connect();

    assert.equal(linkA.classes.has('is-active'), true);
    assert.equal(linkA.attrs['aria-current'], 'page');
    assert.equal(groupA.classes.has('is-active'), true);

    // Clicking outside any group closes all open dropdown groups
    docClick({target: {closest: () => null}});
    assert.equal(groupA.open, false);
    assert.equal(groupB.open, false);
});
