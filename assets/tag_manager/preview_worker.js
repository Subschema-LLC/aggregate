/*
 * "Test this tag" on the tag manager page (see App\Service\TagManagerPreview).
 *
 * Runs public/tag-manager.js, with one tag and its variable probes compiled in
 * by the server, against stand-ins for the page: window and document objects,
 * the tracker's emit(), and each library method the tag calls. Scripts are
 * recorded and reported as loaded, never fetched. The worker posts back what
 * the tag did; the dashboard ends it after a few seconds either way.
 *
 * Message in: {scriptUrl, namespace, methods, probeMethod, categories, consent,
 *   trigger: {type, event?}, sample, baseURI}
 * Message out: {records: {scripts, emits, calls, custom, probes, warnings, errors}} or {error}
 */
'use strict';

// The only script the test loads: this installation's own preview script for one run.
function previewScriptUrl(value) {
    try {
        var url = new URL(String(value), self.location.href);
        if (url.origin === self.location.origin && url.search === '' && url.hash === ''
            && /\/dashboard\/tag-manager\/preview\/[a-f0-9]{32}\.js$/.test(url.pathname)) return url.href;
    } catch (error) {}
    return null;
}

// A library.method path as the server allows it: identifiers, no prototype keys.
function methodPath(value) {
    var parts = String(value).split('.');
    if (parts.length < 2 || parts.length > 8) return null;
    for (var i = 0; i < parts.length; i++) {
        var part = parts[i];
        if (!/^[A-Za-z_$][A-Za-z0-9_$]*$/.test(part) || part === '__proto__' || part === 'prototype' || part === 'constructor') return null;
    }
    return parts;
}

function copy(value) {
    try {
        return JSON.parse(JSON.stringify(value));
    } catch (error) {
        return String(value);
    }
}

self.onmessage = function (message) {
    var job = message.data || {};
    var records = {scripts: [], emits: [], calls: [], custom: [], probes: {}, warnings: [], errors: []};
    var listeners = {document: Object.create(null), window: Object.create(null)};
    var finished = false;

    function finish(result) {
        if (finished) return;
        finished = true;
        self.postMessage(result);
    }

    function listen(target) {
        return function (type, callback) {
            (listeners[target][type] = listeners[target][type] || []).push(callback);
        };
    }

    function dispatch(target, type, detail) {
        (listeners[target][type] || []).forEach(function (callback) {
            try {
                callback({type: type, detail: detail});
            } catch (error) {
                records.errors.push(String(error && error.message || error));
            }
        });
    }

    function line(parts) {
        return Array.prototype.map.call(parts, function (part) {
            return part instanceof Error ? part.message : String(part);
        }).join(' ');
    }

    // A script is recorded, then reported as loaded so tags chained after it run.
    var head = {
        appendChild: function (element) {
            records.scripts.push({tag: element.attributes['data-aggregate-tag'] || '', src: String(element.src || '')});
            setTimeout(function () {
                if (typeof element.listeners.load === 'function') element.listeners.load({type: 'load'});
            }, 0);
            return element;
        }
    };
    var fakeDocument = {
        readyState: 'loading',
        currentScript: {nonce: ''},
        head: head,
        body: head,
        documentElement: head,
        baseURI: typeof job.baseURI === 'string' ? job.baseURI : 'https://www.example.com/',
        createElement: function (tagName) {
            return {
                tagName: String(tagName),
                attributes: {},
                listeners: {},
                setAttribute: function (name, value) { this.attributes[name] = String(value); },
                addEventListener: function (name, callback) { this.listeners[name] = callback; }
            };
        },
        addEventListener: listen('document')
    };
    var fakeWindow = {
        addEventListener: listen('window'),
        console: {
            warn: function () { records.warnings.push(line(arguments)); },
            error: function () { records.errors.push(line(arguments)); },
            log: function () {},
            info: function () {}
        }
    };

    // The tracker, as the tag manager finds it once it has loaded.
    var namespace = typeof job.namespace === 'string' && job.namespace ? job.namespace : 'Aggregate';
    fakeWindow[namespace] = {
        emit: function () {
            records.emits.push(copy(Array.prototype.slice.call(arguments)));
            return true;
        }
    };
    // Each method the tag or a probe calls, as a recorder.
    (Array.isArray(job.methods) ? job.methods : []).forEach(function (path) {
        var parts = methodPath(path);
        if (!parts) return;
        var receiver = fakeWindow;
        for (var i = 0; i < parts.length - 1; i++) {
            var name = parts[i];
            if (!Object.prototype.hasOwnProperty.call(receiver, name) || !receiver[name] || typeof receiver[name] !== 'object') {
                Object.defineProperty(receiver, name, {value: {}, writable: true, enumerable: true, configurable: true});
            }
            receiver = receiver[name];
        }
        Object.defineProperty(receiver, parts[parts.length - 1], {writable: true, enumerable: true, configurable: true, value: path === job.probeMethod
            ? function (id, value) { records.probes[String(id)] = copy(value); }
            : function () { records.calls.push({method: path, args: copy(Array.prototype.slice.call(arguments))}); }});
    });
    // Stand-in for custom JavaScript, which the server compiles in instead of the tag's code.
    self.AggregateTagPreviewCustom = function (id) { records.custom.push(String(id)); };

    var trigger = job.trigger || {};
    var lifecycle = trigger.type === 'dom_ready' || trigger.type === 'window_load';
    fakeWindow.dataLayer = lifecycle && job.sample && typeof job.sample === 'object' ? [job.sample] : [];
    self.window = fakeWindow;
    self.document = fakeDocument;

    var scriptUrl = previewScriptUrl(job.scriptUrl);
    if (!scriptUrl) {
        finish({error: 'The test was given a script it does not load.'});
        return;
    }
    try {
        importScripts(scriptUrl);
    } catch (error) {
        finish({error: 'The test could not load the tag manager. Run it again; if it still fails, reload the page.'});
        return;
    }
    var api = fakeWindow.AggregateTags;
    if (!api || typeof api.setConsent !== 'function') {
        finish({error: 'The tag manager did not start with these settings.'});
        return;
    }

    var granted = {};
    if (job.consent) {
        (Array.isArray(job.categories) ? job.categories : []).forEach(function (category) { granted[category] = true; });
        granted.analytics = true;
    }
    try {
        api.setConsent(granted);
        dispatch('document', 'aggregate:tracker-ready', {namespace: namespace});
        fakeDocument.readyState = 'interactive';
        dispatch('document', 'DOMContentLoaded');
        fakeDocument.readyState = 'complete';
        dispatch('window', 'load');
        if (trigger.type === 'data_layer') {
            fakeWindow.dataLayer.push(job.sample);
        } else if (trigger.type === 'document_event') {
            dispatch('document', trigger.event, job.sample);
        } else if (trigger.type === 'window_event') {
            dispatch('window', trigger.event, job.sample);
        }
    } catch (error) {
        records.errors.push(String(error && error.message || error));
    }
    // Chained tags wait for a script's load, and held events for the tracker: let both settle.
    setTimeout(function () { finish({records: records}); }, 250);
};
