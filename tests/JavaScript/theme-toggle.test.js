'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const bootstrap = fs.readFileSync(path.join(__dirname, '../../assets/theme-preference.js'), 'utf8');
const source = fs.readFileSync(path.join(__dirname, '../../assets/controllers/components/layout/theme_toggle_controller.js'), 'utf8');
const ThemeToggle = vm.runInNewContext(
    source.replace(/^import .*;\n/m, '').replace('export default class', 'class ThemeToggle') + '\nThemeToggle;',
    {Controller: class {}}
);

function browser({site = 'light', saved = null, blocked = false} = {}) {
    const values = new Map(saved === null ? [] : [['aggregate.ui.theme', saved]]);
    values.set('unrelated-setting', 'preserved');
    const storage = {
        getItem: key => values.get(key) ?? null,
        setItem: (key, value) => values.set(key, value),
        removeItem: key => values.delete(key)
    };
    const window = {};
    Object.defineProperty(window, 'localStorage', {get() {
        if (blocked) throw new Error('Storage is unavailable');
        return storage;
    }});
    const root = {dataset: {theme: site, themeDefault: site, themeStorageKey: 'aggregate.ui.theme'}};
    const document = {documentElement: root, defaultView: window};
    vm.runInNewContext(bootstrap, {document, window});
    const toggle = {attributes: {}, setAttribute(key, value) { this.attributes[key] = value; }};
    const reset = {hidden: true};
    const element = {ownerDocument: document, hidden: true};
    const instance = Object.assign(new ThemeToggle(), {element, toggleTarget: toggle, resetTarget: reset});
    instance.connect();
    return {instance, root, values, storage, toggle, reset, element};
}

test('configured light and dark themes are the default, even with invalid saved preferences', () => {
    for (const site of ['light', 'dark']) {
        for (const saved of [null, '', 'system', 'false', '<script>']) {
            const app = browser({site, saved});
            assert.equal(app.root.dataset.theme, site);
            assert.equal(app.toggle.attributes['aria-pressed'], String(site === 'dark'));
            assert.equal(app.reset.hidden, true);
            assert.equal(app.element.hidden, false);
        }
    }
});

test('saved preferences apply before the controller and persist across navigation', () => {
    for (const site of ['light', 'dark']) {
        const app = browser({site});
        app.instance.toggle();
        const chosen = site === 'light' ? 'dark' : 'light';
        assert.equal(app.root.dataset.theme, chosen);
        assert.equal(app.root.dataset.themeDefault, site);
        assert.equal(app.values.get('aggregate.ui.theme'), chosen);
        assert.equal(app.toggle.attributes['aria-pressed'], String(chosen === 'dark'));
        assert.equal(app.reset.hidden, false);
        const nextPage = browser({site, saved: app.values.get('aggregate.ui.theme')});
        assert.equal(nextPage.root.dataset.theme, chosen);
        assert.equal(nextPage.reset.hidden, false);
        app.instance.disconnect();
        assert.equal(app.element.hidden, true);
        app.instance.connect();
        assert.equal(app.root.dataset.theme, chosen);
    }
});

test('site default removes only this preference and restores the configured palette', () => {
    const app = browser({site: 'dark', saved: 'light'});
    app.instance.reset();
    assert.equal(app.root.dataset.theme, 'dark');
    assert.equal(app.root.dataset.themePreference, undefined);
    assert.equal(app.values.has('aggregate.ui.theme'), false);
    assert.equal(app.values.get('unrelated-setting'), 'preserved');
    assert.equal(app.reset.hidden, true);
    assert.equal(app.toggle.attributes['aria-pressed'], 'true');
});

test('blocked storage still allows switching, reconnecting, and resetting for the current page', () => {
    const app = browser({site: 'dark', blocked: true});
    assert.equal(app.root.dataset.theme, 'dark');
    app.instance.toggle();
    assert.equal(app.root.dataset.theme, 'light');
    app.instance.disconnect();
    app.instance.connect();
    assert.equal(app.root.dataset.theme, 'light');
    app.instance.reset();
    assert.equal(app.root.dataset.theme, 'dark');
});

test('another tab can change or clear the theme without reacting to unrelated storage', () => {
    const app = browser({site: 'dark'});
    const event = {key: 'aggregate.ui.theme', newValue: 'light', storageArea: app.storage};
    app.instance.sync({...event, key: 'unrelated-setting'});
    app.instance.sync({...event, storageArea: {}});
    assert.equal(app.root.dataset.theme, 'dark');
    app.instance.sync(event);
    assert.equal(app.root.dataset.theme, 'light');
    assert.equal(app.reset.hidden, false);
    app.instance.sync({...event, newValue: 'invalid'});
    assert.equal(app.root.dataset.theme, 'dark');
    assert.equal(app.reset.hidden, true);
    app.instance.sync(event);
    app.instance.sync({...event, key: null, newValue: null});
    assert.equal(app.root.dataset.theme, 'dark');
    assert.equal(app.reset.hidden, true);
});
