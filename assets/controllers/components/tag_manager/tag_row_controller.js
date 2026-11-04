import { Controller } from '@hotwired/stimulus';

// `&amp;` copied out of an HTML snippet would be requested literally; the
// server applies the same correction (TagManagerVariables::normalizeSource).
const encodedAmpersand = /&(?:amp|#0*38|#x0*26);/gi;

function normalizeSource(value) {
    let current = String(value);
    for (let previous = null; current !== previous;) {
        previous = current;
        current = current.replace(encodedAmpersand, '&');
    }
    return current;
}

// Compiles, without running, the code exactly as the tag manager will wrap it.
// The server parses it again on save; this gives immediate feedback.
function codeProblem(code, view) {
    if (/^\s*</.test(code)) return 'This looks like HTML. Remove <script> and </script> and keep only the JavaScript between them.';
    const FunctionConstructor = (view && view.Function) || Function;
    try {
        new FunctionConstructor('tag', "'use strict';\n" + code);
    } catch (error) {
        // A page policy that forbids compiling code leaves the check to the server.
        if (error && error.name === 'EvalError') return '';
        return 'Syntax error: ' + error.message;
    }
    return '';
}

// A method tag calling the tracker's emit(name, properties, goal) loses
// properties that hold an object or a list, such as a customData wrapper. The
// server makes the same check on save (TagManagerValues::emitProblem); keep
// the messages the same. Such a tag converts to a Send an Aggregate event
// action, which takes {eventName, customData, goalEvent, pagePath}.
const propertyWrappers = ['customData', 'eventData'];
const eventFields = ['eventName', 'customData', 'goalEvent', 'pagePath'];
const trackerFields = ['websiteToken', 'referrerChannel', 'deviceClass', 'viewportBucket', 'consentState', 'visitorId', 'sessionId', 'screenWidth', 'org_internal_traffic'];
const emptyEvent = '{\n    "eventName": "",\n    "customData": {}\n}';

function utf8Bytes(text) {
    try { return encodeURIComponent(text).replace(/%[0-9A-F]{2}/g, 'x').length; } catch (error) { return Infinity; }
}

function isPlainObject(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function isReference(value) {
    if (!isPlainObject(value) || !Object.prototype.hasOwnProperty.call(value, '$var')) return false;
    const keys = Object.keys(value);
    if (keys.length === 1) return true;
    return keys.length === 2 && Object.prototype.hasOwnProperty.call(value, 'from') && ['recent', 'event'].includes(value.from);
}

function isVariableType(type) {
    return type === 'var' || type === 'var_event';
}

function referenceType(value) {
    return value.from === 'event' ? 'var_event' : 'var';
}

function buildReference(alias, type) {
    return type === 'var_event' ? { $var: alias, from: 'event' } : { $var: alias };
}

function isValidAlias(alias) {
    return typeof alias === 'string' && /^[A-Za-z][A-Za-z0-9_]{0,63}$/.test(alias);
}

/**
 * Turns an event JSON string into the state for the key-value editor, or null
 * when the JSON is malformed or contains unsupported fields/nesting.
 */
function parseEventForKv(json) {
    if (!json.trim()) {
        return { eventNameType: 'string', eventName: '', customData: [], goalEventType: 'string', goalEvent: '', pagePathType: 'string', pagePath: '' };
    }
    let event;
    try {
        event = JSON.parse(json);
    } catch (error) {
        return null;
    }
    if (!isPlainObject(event) || Object.keys(event).some((key) => !eventFields.includes(key))) return null;
    let eventNameType = 'string';
    let eventName = '';
    if (Object.prototype.hasOwnProperty.call(event, 'eventName')) {
        if (isReference(event.eventName) && typeof event.eventName.$var === 'string') {
            eventNameType = referenceType(event.eventName);
            eventName = event.eventName.$var;
        } else if (typeof event.eventName === 'string') {
            eventName = event.eventName;
        } else {
            return null;
        }
    }
    const customData = [];
    if (Object.prototype.hasOwnProperty.call(event, 'customData')) {
        const data = event.customData;
        if (Array.isArray(data) && data.length === 0) {
            // Empty list is treated as no custom properties.
        } else if (isPlainObject(data)) {
            for (const [key, val] of Object.entries(data)) {
                if (isReference(val) && typeof val.$var === 'string') {
                    customData.push({ key, type: referenceType(val), value: val.$var });
                } else if (val === null) {
                    customData.push({ key, type: 'null', value: '' });
                } else if (typeof val === 'boolean') {
                    customData.push({ key, type: 'boolean', value: val ? 'true' : 'false' });
                } else if (typeof val === 'number' && Number.isFinite(val)) {
                    customData.push({ key, type: 'number', value: String(val) });
                } else if (typeof val === 'string') {
                    customData.push({ key, type: 'string', value: val });
                } else {
                    return null;
                }
            }
        } else {
            return null;
        }
    }
    let goalEventType = 'string';
    let goalEvent = '';
    if (Object.prototype.hasOwnProperty.call(event, 'goalEvent')) {
        if (event.goalEvent === null) {
            goalEventType = 'null';
        } else if (isReference(event.goalEvent) && typeof event.goalEvent.$var === 'string') {
            goalEventType = referenceType(event.goalEvent);
            goalEvent = event.goalEvent.$var;
        } else if (typeof event.goalEvent === 'string') {
            goalEvent = event.goalEvent;
        } else {
            return null;
        }
    }
    let pagePathType = 'string';
    let pagePath = '';
    if (Object.prototype.hasOwnProperty.call(event, 'pagePath')) {
        if (isReference(event.pagePath) && typeof event.pagePath.$var === 'string') {
            pagePathType = referenceType(event.pagePath);
            pagePath = event.pagePath.$var;
        } else if (typeof event.pagePath === 'string') {
            pagePath = event.pagePath;
        } else {
            return null;
        }
    }
    return { eventNameType, eventName, customData, goalEventType, goalEvent, pagePathType, pagePath };
}

/**
 * Builds the event JSON string and any key-value row validation message from
 * the structured key-value editor state.
 */
function buildEventFromKv(state) {
    let error = '';
    const event = {};
    if (isVariableType(state.eventNameType)) {
        const alias = state.eventName.trim();
        event.eventName = buildReference(alias, state.eventNameType);
        if (!isValidAlias(alias)) error = error || 'The event: eventName needs a valid variable alias, such as "event_name".';
    } else {
        event.eventName = state.eventName;
    }

    const customData = {};
    const seenKeys = new Set();
    for (const row of state.customData) {
        const key = row.key;
        const type = row.type || 'string';
        const rawValue = row.value ?? '';
        if (key === '' && rawValue === '' && !['boolean', 'null'].includes(type)) continue;
        if (key === '') {
            error = error || 'The event: enter a property name in customData, or clear the row.';
            continue;
        }
        if (seenKeys.has(key)) {
            error = error || 'The event: customData has duplicate property "' + key + '". Give each property a unique name.';
        }
        seenKeys.add(key);
        if (isVariableType(type)) {
            const alias = String(rawValue).trim();
            customData[key] = buildReference(alias, type);
            if (!isValidAlias(alias)) error = error || 'The event: customData.' + key + ' needs a valid variable alias, such as "plan".';
        } else if (type === 'null') {
            customData[key] = null;
        } else if (type === 'boolean') {
            customData[key] = rawValue === 'true' || rawValue === true;
        } else if (type === 'number') {
            const trimmed = String(rawValue).trim();
            const parsed = trimmed === '' ? NaN : Number(trimmed);
            if (!Number.isFinite(parsed) || (Number.isInteger(parsed) && !Number.isSafeInteger(parsed))) {
                error = error || 'The event: customData.' + key + ' must be a finite number, such as 1299 or 0.5.';
                customData[key] = rawValue;
            } else {
                customData[key] = parsed;
            }
        } else {
            customData[key] = String(rawValue);
        }
    }
    event.customData = customData;

    if (state.goalEventType === 'null') {
        event.goalEvent = null;
    } else if (isVariableType(state.goalEventType)) {
        const alias = state.goalEvent.trim();
        if (alias !== '') {
            event.goalEvent = buildReference(alias, state.goalEventType);
            if (!isValidAlias(alias)) error = error || 'The event: goalEvent needs a valid variable alias.';
        }
    } else if (state.goalEvent !== '') {
        event.goalEvent = state.goalEvent;
    }

    if (isVariableType(state.pagePathType)) {
        const alias = state.pagePath.trim();
        if (alias !== '') {
            event.pagePath = buildReference(alias, state.pagePathType);
            if (!isValidAlias(alias)) error = error || 'The event: pagePath needs a valid variable alias.';
        }
    } else if (state.pagePath !== '') {
        event.pagePath = state.pagePath;
    }

    if (event.eventName === '' && Object.keys(event.customData).length === 0
        && !Object.prototype.hasOwnProperty.call(event, 'goalEvent')
        && !Object.prototype.hasOwnProperty.call(event, 'pagePath')) {
        return { json: emptyEvent, error };
    }

    return { json: JSON.stringify(event, null, 4), error };
}

/** The variable aliases an event refers to, in order, without repeats. */
function eventAliases(json) {
    let event;
    try {
        event = JSON.parse(json);
    } catch (error) {
        return [];
    }
    if (!isPlainObject(event)) return [];
    const values = [event.eventName, event.goalEvent, event.pagePath];
    if (isPlainObject(event.customData)) values.push(...Object.values(event.customData));
    const aliases = values.filter((value) => isReference(value) && isValidAlias(value.$var)).map((value) => value.$var);
    return aliases.filter((alias, index) => aliases.indexOf(alias) === index);
}

/** [alias, from] pairs a value refers to, in order, without repeats. */
function references(value, found = []) {
    if (isReference(value) && isValidAlias(value.$var)) {
        const from = value.from === 'event' ? 'event' : 'recent';
        if (!found.some(([alias, source]) => alias === value.$var && source === from)) found.push([value.$var, from]);
    } else if (value !== null && typeof value === 'object') {
        for (const member of Object.values(value)) references(member, found);
    }
    return found;
}

// Splits a variable path the way the runtime does (pathParts in public/tag-manager.js).
function pathSegments(path) {
    if (typeof path !== 'string' || !/^[A-Za-z_$][A-Za-z0-9_$]*(?:(?:\.[A-Za-z_$][A-Za-z0-9_$]*)|(?:\[(?:0|[1-9][0-9]*)\]))*$/.test(path)) return null;
    return path.match(/[A-Za-z_$][A-Za-z0-9_$]*|[0-9]+/g);
}

function setSampleValue(target, segments, value) {
    let node = target;
    segments.forEach((segment, index) => {
        const last = index === segments.length - 1;
        if (last) {
            if (!(segment in node)) node[segment] = value;
            return;
        }
        const nextIsIndex = /^[0-9]+$/.test(segments[index + 1]);
        if (node[segment] === null || typeof node[segment] !== 'object') node[segment] = nextIsIndex ? [] : {};
        node = node[segment];
    });
}

/**
 * A starting sample for a test: the dataLayer push (or the page event's
 * detail, or the data layer when the page is ready) with an example value at
 * each path the tag's variables read.
 */
function sampleFor(trigger, aliases, paths) {
    const sample = trigger.type === 'data_layer' ? {event: trigger.event || 'example_event'} : {};
    const domEvent = trigger.type === 'document_event' || trigger.type === 'window_event';
    for (const alias of aliases) {
        let segments = pathSegments(paths[alias]);
        if (!segments) continue;
        if (domEvent) {
            if (segments[0] !== 'event' || segments[1] !== 'detail' || segments.length < 3) continue;
            segments = segments.slice(2);
        } else if (segments[0] === 'event' && segments.length === 1) {
            continue;
        }
        setSampleValue(sample, segments, 'example');
    }
    return sample;
}

const sampleLabels = {
    data_layer: ['Sample dataLayer push', 'What the page pushes, such as {"event": "purchase", "plan": "pro"}. Its event must match the event this tag listens for.'],
    document_event: ['Sample event detail', 'The detail of the event the page fires on the document. Variables read it as event.detail.…'],
    window_event: ['Sample event detail', 'The detail of the event the page fires on the window. Variables read it as event.detail.…'],
    dom_ready: ['Data layer when the page is ready', 'What the page has pushed to the data layer by then, merged into one object.'],
    window_load: ['Data layer when the page has loaded', 'What the page has pushed to the data layer by then, merged into one object.']
};

function parsedArguments(method, argumentsJson, namespaces) {
    if (!namespaces.some((namespace) => method.trim() === namespace + '.emit')) return null;
    try {
        const args = JSON.parse(argumentsJson.trim() || '[]');
        return Array.isArray(args) ? args : null;
    } catch (error) {
        // The server explains invalid JSON on save.
        return null;
    }
}

/** Why emit(name, properties, goal) with these arguments would lose properties, or ''. */
function emitProblem(args) {
    if (typeof args[0] !== 'string' && !isReference(args[0])) return 'The first argument is the event name, such as "login".';
    const properties = args.length > 1 ? args[1] : null;
    if (properties === null) return '';
    if (isReference(properties)) return 'A variable gives one value, but the second argument needs an object of properties. Name each property and use a variable for its value: {"plan": {"$var": "plan"}}.';
    // JSON {} and [] both decode to an empty array on the server; neither sends anything.
    if (!isPlainObject(properties) && !(Array.isArray(properties) && properties.length === 0)) {
        return 'The second argument holds the event properties and must be an object, such as {"plan": "pro"}, or null. A goal goes third.';
    }
    const keys = Object.keys(properties);
    const nested = keys.filter((key) => properties[key] !== null && typeof properties[key] === 'object' && !isReference(properties[key]));
    if (nested.length === 0) return '';
    const wrapper = nested[0];
    if (keys.length === 1 && propertyWrappers.includes(wrapper) && (isPlainObject(properties[wrapper]) || properties[wrapper].length === 0)) {
        return 'Remove the "' + wrapper + '" wrapper and put its properties directly in the second argument. The tracker puts the properties in customData itself and drops a property that holds an object, so this event would be sent without them.';
    }
    const names = nested.map((name) => '"' + name + '"');
    const last = names.pop();
    return 'The tracker drops ' + (names.length ? names.join(', ') + ' and ' + last : last) + ': a property must be text, a number, true, false or null, not an object or a list. Give each value its own property.';
}

/** The event object for emit(name, properties, goal) arguments, or null when they do not map to one. */
function eventFromArguments(args) {
    const [name, properties, goal] = args;
    if (args.length > 3 || (typeof name !== 'string' && !isReference(name))) return null;
    const event = {eventName: name};
    let data = properties === undefined ? null : properties;
    if (isPlainObject(data) && !isReference(data)) {
        const keys = Object.keys(data);
        if (keys.length === 1 && propertyWrappers.includes(keys[0])) data = Array.isArray(data[keys[0]]) && data[keys[0]].length === 0 ? {} : data[keys[0]];
    } else if (Array.isArray(data) && data.length === 0) data = {};
    if (data !== null && (!isPlainObject(data) || isReference(data))) return null;
    if (data && Object.keys(data).length) event.customData = data;
    if (goal !== undefined && goal !== null) {
        if (typeof goal !== 'string' && !isReference(goal)) return null;
        event.goalEvent = goal;
    }
    return event;
}

/** Why the server would refuse this event (TagManagerValues::validateEvent), or ''. */
function eventProblem(json) {
    if (!json.trim()) return '';
    let event;
    try {
        event = JSON.parse(json);
    } catch (error) {
        event = undefined;
    }
    if (!isPlainObject(event)) return 'The event must be a JSON object, for example {"eventName": "login", "customData": {"method": "password"}}.';
    const unknown = Object.keys(event).find((key) => !eventFields.includes(key));
    if (unknown !== undefined) {
        return 'The event has ' + unknown + ', but it takes only eventName, customData, goalEvent and pagePath. '
            + (trackerFields.includes(unknown) ? 'The tracker sets ' + unknown + ' itself.' : 'Put the event\'s properties in customData.');
    }
    const literal = (value, valid, label, rule) => {
        if (isPlainObject(value) && Object.prototype.hasOwnProperty.call(value, '$var')) {
            return isReference(value) ? '' : label + ' uses {"$var": …}; an optional "from" field must be "recent" or "event".';
        }
        return valid(value) ? '' : label + ' ' + rule + (value !== null && typeof value === 'object' ? '. To read a value from the page, use {"$var": "alias"}.' : '.');
    };
    if (!Object.prototype.hasOwnProperty.call(event, 'eventName')) return 'The event needs an eventName, such as "login".';
    const name = literal(event.eventName, (value) => typeof value === 'string' && /^[A-Za-z][A-Za-z0-9_.:-]{0,99}$/.test(value), 'The event: eventName',
        'must be a fixed name such as "login": a letter, then up to 99 letters, digits, _ . : or -');
    if (name) return name;
    if (Object.prototype.hasOwnProperty.call(event, 'customData')) {
        const data = event.customData;
        if (!isPlainObject(data) && !(Array.isArray(data) && data.length === 0)) return 'The event: customData must be an object of properties, such as {"method": "password"}.';
        const keys = Object.keys(data);
        if (keys.length > 32) return 'The event: customData may hold at most 32 properties.';
        for (const key of keys) {
            if (!/^[A-Za-z][A-Za-z0-9_.-]{0,63}$/.test(key) || ['constructor', 'prototype', '__proto__'].includes(key)) return 'The event: customData.' + key.slice(0, 64) + ' is not a property name. Use a letter, then up to 63 letters, digits, _ . or -.';
            if (key === 'org_internal_traffic' || key === 'page_sequence') return 'The event: the tracker adds customData.' + key + ' itself; leave it out.';
            const value = literal(data[key], (item) => item === null || typeof item === 'boolean' || (typeof item === 'number' && Number.isFinite(item))
                || (typeof item === 'string' && utf8Bytes(item) <= 500), 'The event: customData.' + key,
                'must be text of at most 500 bytes, a number, true, false or null, not an object or a list');
            if (value) return value;
        }
    }
    if (Object.prototype.hasOwnProperty.call(event, 'goalEvent')) {
        const goal = literal(event.goalEvent, (value) => value === null || (typeof value === 'string' && value !== '' && value.trim() === value && value.length <= 100),
            'The event: goalEvent', 'must name a goal from config/goals.yaml, or be null');
        if (goal) return goal;
    }
    if (Object.prototype.hasOwnProperty.call(event, 'pagePath')) {
        return literal(event.pagePath, (value) => typeof value === 'string' && value.length <= 512 && /^\/(?!\/)[^?#\\\u0000-\u001f\u007f]*$/.test(value),
            'The event: pagePath', 'must be a path on the site such as /checkout/step-2, without a query or fragment');
    }
    return '';
}

function templates(document) {
    try {
        const groups = JSON.parse(document.getElementById('tag-custom-templates').textContent);
        return Object.values(groups).flat();
    } catch (error) {
        return [];
    }
}

export default class extends Controller {
    static targets = ['actionType', 'trigger', 'actionFields', 'eventField', 'source', 'sourceNote', 'template', 'code', 'codeStatus',
        'method', 'arguments', 'argumentsStatus', 'argumentsMessage', 'argumentsFix', 'event', 'eventStatus',
        'eventModeButton', 'eventJsonPane', 'eventKvPane', 'eventNameType', 'eventNameInput',
        'customDataRows', 'addCustomDataButton', 'goalEventType', 'goalEventInput', 'pagePathType', 'pagePathInput',
        'eventLabel', 'consentChoice', 'consentOther', 'consent',
        'newVariable', 'newVariableName', 'newVariablePath', 'newVariableStatus',
        'preview', 'previewSample', 'previewSampleLabel', 'previewSampleHelp', 'previewConsent', 'previewRun', 'previewResult'];

    static values = { namespaces: Array, defaultId: String, defaultSrc: String, previewUrl: String, workerUrl: String, previewBase: String };

    connect() {
        this.eventMode = 'json';
        this.kvError = '';
        this.update();
        // Events open in the key-value editor, the easier of the two. An event
        // it cannot show, such as JSON that does not parse yet, stays in JSON.
        if (this.hasEventKvPaneTarget && this.hasEventTarget && parseEventForKv(this.eventTarget.value) !== null) {
            this.switchEventMode('kv');
        }
    }

    update() {
        for (const fields of this.actionFieldsTargets) {
            const selected = fields.dataset.actionFields === this.actionTypeTarget.value;
            fields.hidden = !selected;
            for (const input of fields.querySelectorAll('input, textarea, select, button')) {
                input.disabled = !selected;
            }
        }
        this.syncDefaultTracker();
        this.syncConsentChoice();
        const eventNeeded = ['document_event', 'window_event', 'data_layer'].includes(this.triggerTarget.value);
        this.eventFieldTarget.hidden = !eventNeeded;
        this.eventFieldTarget.querySelector('input').disabled = !eventNeeded;
        // A new event starts from its two usual fields.
        if (this.hasEventTarget && this.actionTypeTarget.value === 'event' && !this.eventTarget.value.trim()) this.eventTarget.value = emptyEvent;
        if (this.actionTypeTarget.value === 'event') {
            if (this.eventMode !== 'kv') this.syncKvFromEvent();
            this.updateKvControls();
        }
        this.checkCode();
        this.checkArguments();
        this.checkEvent();
    }

    // The consent list sets the submitted category; "Another category…" shows
    // the field for a name of the administrator's own.
    chooseConsent() {
        if (!this.hasConsentChoiceTarget || !this.hasConsentTarget) return;
        const choice = this.consentChoiceTarget.value;
        const other = choice === '';
        if (!other) {
            this.consentTarget.value = choice;
        } else if (Array.from(this.consentChoiceTarget.options ?? []).some((option) => option.value !== '' && option.value === this.consentTarget.value)) {
            this.consentTarget.value = '';
        }
        if (this.hasConsentOtherTarget) this.consentOtherTarget.hidden = !other;
        if (other) this.consentTarget.focus?.();
    }

    // A typed category stays in its field while typing, even one the list
    // has; the next page load shows a listed one selected in the list.
    typeConsent() {
        this.syncConsentChoice();
    }

    // Shows the submitted category in the list, for values set by a template
    // or the tracker suggestion as well as typed ones.
    syncConsentChoice() {
        if (!this.hasConsentChoiceTarget || !this.hasConsentTarget) return;
        const value = this.consentTarget.value;
        const listed = Array.from(this.consentChoiceTarget.options ?? []).some((option) => option.value !== '' && option.value === value);
        const otherShown = this.hasConsentOtherTarget && !this.consentOtherTarget.hidden;
        if (listed && !otherShown) {
            this.consentChoiceTarget.value = value;
        } else if (!listed) {
            this.consentChoiceTarget.value = '';
            if (this.hasConsentOtherTarget) this.consentOtherTarget.hidden = false;
        }
    }

    // Clears the prepopulated tracker ID and URL on the new-tag row when the
    // user switches its action away from script, and restores them if switched
    // back while still empty.
    syncDefaultTracker() {
        if (!this.hasDefaultSrcValue || !this.defaultSrcValue || !this.hasSourceTarget) return;
        const idInput = this.element?.querySelector?.('input[name$="[id]"]');
        const defaultId = this.hasDefaultIdValue ? this.defaultIdValue : '';
        if (this.actionTypeTarget.value !== 'script') {
            if (this.sourceTarget.value === this.defaultSrcValue && (!idInput || !defaultId || idInput.value === defaultId)) {
                this.sourceTarget.value = '';
                if (idInput && defaultId && idInput.value === defaultId) idInput.value = '';
                this._clearedDefaultTracker = true;
            }
        } else if (this._clearedDefaultTracker && this.sourceTarget.value === '' && (!idInput || idInput.value === '')) {
            this.sourceTarget.value = this.defaultSrcValue;
            if (idInput && defaultId) idInput.value = defaultId;
            this._clearedDefaultTracker = false;
        }
    }

    // For a method tag calling the tracker's emit(), says what would be lost,
    // without blocking the save, and offers a Send an Aggregate event instead.
    checkArguments() {
        if (!this.hasArgumentsStatusTarget || !this.hasMethodTarget || !this.hasArgumentsTarget) return;
        const namespaces = this.hasNamespacesValue && this.namespacesValue.length ? this.namespacesValue : ['Aggregate'];
        const args = this.argumentsTarget.disabled ? null : parsedArguments(this.methodTarget.value, this.argumentsTarget.value, namespaces);
        const problem = args ? emitProblem(args) : '';
        this.convertedEvent = args && this.hasEventTarget ? eventFromArguments(args) : null;
        const shown = problem !== '' || this.convertedEvent !== null;
        this.argumentsStatusTarget.hidden = !shown;
        this.argumentsStatusTarget.classList.toggle('is-warning', problem !== '');
        this.argumentsStatusTarget.classList.toggle('is-info', problem === '');
        if (this.hasArgumentsMessageTarget) {
            this.argumentsMessageTarget.textContent = problem || (shown ? 'A Send an Aggregate event action does this with {"eventName": …, "customData": {…}}, and waits for the tracker to load, so it needs no tag chaining.' : '');
        }
        if (this.hasArgumentsFixTarget) {
            this.argumentsFixTarget.hidden = this.convertedEvent === null;
            this.argumentsFixTarget.textContent = 'Convert to Send an Aggregate event';
        }
    }

    // Moves the call's name, properties and goal into an event object, taking
    // the properties out of a customData wrapper.
    convertToEvent() {
        if (!this.convertedEvent || !this.hasEventTarget) return;
        this.eventTarget.value = JSON.stringify(this.convertedEvent, null, 4);
        this.kvError = '';
        this.syncKvFromEvent();
        this.actionTypeTarget.value = 'event';
        this.update();
        if (typeof this.eventTarget.focus === 'function') this.eventTarget.focus();
    }

    // Toggles the event tag editor between raw JSON and structured key-value pairs.
    switchEventMode(event) {
        const mode = typeof event === 'string' ? event : (event?.params?.mode || event?.currentTarget?.dataset?.eventMode);
        if (mode !== 'kv' && mode !== 'json') return false;
        if (mode === 'kv') {
            if (!this.hasEventTarget) return false;
            const parsed = parseEventForKv(this.eventTarget.value);
            if (parsed === null) {
                this.eventMode = 'json';
                this.checkEvent();
                if (this.hasEventStatusTarget && !this.eventStatusTarget.textContent) {
                    const fallback = 'Fix the JSON object before switching to key-value pairs.';
                    this.eventStatusTarget.textContent = fallback;
                    this.eventStatusTarget.classList.add('is-danger');
                }
                return false;
            }
            this.kvError = '';
            this.applyKvState(parsed);
            this.eventMode = 'kv';
        } else {
            if (this.eventMode === 'kv') this.syncEventFromKv();
            this.eventMode = 'json';
        }
        if (this.hasEventJsonPaneTarget) this.eventJsonPaneTarget.hidden = this.eventMode === 'kv';
        if (this.hasEventKvPaneTarget) this.eventKvPaneTarget.hidden = this.eventMode !== 'kv';
        // The label names the field that is showing.
        if (this.hasEventLabelTarget) {
            const field = this.eventMode === 'kv' && this.hasEventNameInputTarget ? this.eventNameInputTarget : this.eventTarget;
            if (field?.id) this.eventLabelTarget.setAttribute?.('for', field.id);
        }
        if (this.hasEventModeButtonTarget) {
            for (const button of this.eventModeButtonTargets) {
                const active = button.dataset?.eventMode === this.eventMode;
                button.classList?.toggle('is-primary', active);
                button.classList?.toggle('is-selected', active);
                button.setAttribute?.('aria-pressed', active ? 'true' : 'false');
            }
        }
        this.updateKvControls();
        this.checkEvent();
        return true;
    }

    syncKvFromEvent() {
        if (!this.hasEventTarget || !this.hasCustomDataRowsTarget) return false;
        const parsed = parseEventForKv(this.eventTarget.value);
        if (parsed === null) return false;
        this.applyKvState(parsed);
        return true;
    }

    applyKvState(parsed) {
        if (this.hasEventNameTypeTarget) this.eventNameTypeTarget.value = parsed.eventNameType;
        if (this.hasEventNameInputTarget) this.eventNameInputTarget.value = parsed.eventName;
        if (this.hasGoalEventTypeTarget) this.goalEventTypeTarget.value = parsed.goalEventType;
        if (this.hasGoalEventInputTarget) this.goalEventInputTarget.value = parsed.goalEvent;
        if (this.hasPagePathTypeTarget) this.pagePathTypeTarget.value = parsed.pagePathType;
        if (this.hasPagePathInputTarget) this.pagePathInputTarget.value = parsed.pagePath;
        if (this.hasCustomDataRowsTarget) {
            const entries = parsed.customData.length ? parsed.customData : [{ key: '', type: 'string', value: '' }];
            const rows = entries.map((entry) => this.createCustomDataRow(entry)).filter(Boolean);
            if (typeof this.customDataRowsTarget.replaceChildren === 'function') {
                this.customDataRowsTarget.replaceChildren(...rows);
            }
        }
        this.updateKvControls();
    }

    createCustomDataRow(entry = { key: '', type: 'string', value: '' }) {
        if (!this.hasCustomDataRowsTarget) return null;
        if (typeof this.customDataRowsTarget.createRow === 'function') {
            return this.customDataRowsTarget.createRow(entry);
        }
        if (!this._rowTemplate) {
            const first = this.customDataRowsTarget.querySelector?.('[data-custom-data-row]');
            if (first && typeof first.cloneNode === 'function') this._rowTemplate = first.cloneNode(true);
        }
        if (!this._rowTemplate || typeof this._rowTemplate.cloneNode !== 'function') return null;
        const row = this._rowTemplate.cloneNode(true);
        const keyInput = row.querySelector('[data-cd-key]');
        const typeSelect = row.querySelector('[data-cd-type]');
        const valueInput = row.querySelector('[data-cd-value]');
        const boolSelect = row.querySelector('[data-cd-bool]');
        if (keyInput) keyInput.value = entry.key ?? '';
        if (typeSelect) typeSelect.value = entry.type ?? 'string';
        if (valueInput) valueInput.value = ['boolean', 'null'].includes(entry.type) ? '' : (entry.value ?? '');
        if (boolSelect) boolSelect.value = entry.value === 'false' ? 'false' : 'true';
        return row;
    }

    addCustomDataRow() {
        if (!this.hasCustomDataRowsTarget) return;
        const current = Array.from(this.customDataRowsTarget.querySelectorAll('[data-custom-data-row]'));
        if (current.length >= 32) return;
        const row = this.createCustomDataRow({ key: '', type: 'string', value: '' });
        if (!row) return;
        this.customDataRowsTarget.appendChild?.(row);
        this.updateKvControls();
        row.querySelector?.('[data-cd-key]')?.focus?.();
    }

    removeCustomDataRow(event) {
        if (!this.hasCustomDataRowsTarget) return;
        const target = event?.currentTarget ?? event?.target ?? null;
        const row = target && typeof target.closest === 'function' ? target.closest('[data-custom-data-row]') : null;
        const rows = Array.from(this.customDataRowsTarget.querySelectorAll('[data-custom-data-row]'));
        if (!row || !rows.includes(row)) return;
        if (rows.length <= 1) {
            const keyInput = row.querySelector?.('[data-cd-key]');
            const typeSelect = row.querySelector?.('[data-cd-type]');
            const valueInput = row.querySelector?.('[data-cd-value]');
            const boolSelect = row.querySelector?.('[data-cd-bool]');
            if (keyInput) keyInput.value = '';
            if (typeSelect) typeSelect.value = 'string';
            if (valueInput) valueInput.value = '';
            if (boolSelect) boolSelect.value = 'true';
        } else {
            row.remove?.();
        }
        this.syncEventFromKv();
    }

    updateKvControls() {
        const active = this.actionTypeTarget?.value === 'event';
        if (this.hasEventNameTypeTarget && this.hasEventNameInputTarget) {
            const isVar = isVariableType(this.eventNameTypeTarget.value);
            this.eventNameInputTarget.placeholder = isVar ? 'event_name' : 'login';
            if (isVar) this.eventNameInputTarget.setAttribute?.('list', 'tag-variable-aliases');
            else this.eventNameInputTarget.removeAttribute?.('list');
        }
        if (this.hasGoalEventTypeTarget && this.hasGoalEventInputTarget) {
            const type = this.goalEventTypeTarget.value;
            const isVar = isVariableType(type);
            this.goalEventInputTarget.disabled = !active || type === 'null';
            if (type === 'null') this.goalEventInputTarget.value = '';
            this.goalEventInputTarget.placeholder = isVar ? 'goal_code' : (type === 'null' ? 'null' : 'signup');
            if (isVar) this.goalEventInputTarget.setAttribute?.('list', 'tag-variable-aliases');
            else this.goalEventInputTarget.removeAttribute?.('list');
        }
        if (this.hasPagePathTypeTarget && this.hasPagePathInputTarget) {
            const isVar = isVariableType(this.pagePathTypeTarget.value);
            this.pagePathInputTarget.placeholder = isVar ? 'page_path' : '/checkout/step-2';
            if (isVar) this.pagePathInputTarget.setAttribute?.('list', 'tag-variable-aliases');
            else this.pagePathInputTarget.removeAttribute?.('list');
        }
        if (!this.hasCustomDataRowsTarget) return;
        const rows = Array.from(this.customDataRowsTarget.querySelectorAll('[data-custom-data-row]'));
        for (const row of rows) {
            const type = row.querySelector?.('[data-cd-type]')?.value || 'string';
            const isVar = isVariableType(type);
            const valueWrap = row.querySelector?.('[data-cd-value-wrap]');
            const boolWrap = row.querySelector?.('[data-cd-bool-wrap]');
            const valueInput = row.querySelector?.('[data-cd-value]');
            const boolSelect = row.querySelector?.('[data-cd-bool]');
            if (valueWrap) valueWrap.hidden = type === 'boolean';
            if (boolWrap) boolWrap.hidden = type !== 'boolean';
            if (valueInput) {
                valueInput.disabled = !active || type === 'null' || type === 'boolean';
                if (type === 'null') valueInput.value = '';
                valueInput.placeholder = isVar ? 'Variable alias (e.g. plan)' : (type === 'number' ? '0' : (type === 'null' ? 'null' : 'Value'));
                if (isVar) valueInput.setAttribute?.('list', 'tag-variable-aliases');
                else valueInput.removeAttribute?.('list');
            }
            if (boolSelect) boolSelect.disabled = !active || type !== 'boolean';
        }
        if (this.hasAddCustomDataButtonTarget) {
            this.addCustomDataButtonTarget.disabled = !active || rows.length >= 32;
        }
    }

    readKvState() {
        const rows = this.hasCustomDataRowsTarget
            ? Array.from(this.customDataRowsTarget.querySelectorAll('[data-custom-data-row]')).map((row) => {
                const type = row.querySelector?.('[data-cd-type]')?.value || 'string';
                const value = type === 'boolean'
                    ? (row.querySelector?.('[data-cd-bool]')?.value || 'true')
                    : (type === 'null' ? '' : (row.querySelector?.('[data-cd-value]')?.value ?? ''));
                return { key: row.querySelector?.('[data-cd-key]')?.value ?? '', type, value };
            })
            : [];
        return {
            eventNameType: this.hasEventNameTypeTarget ? this.eventNameTypeTarget.value : 'string',
            eventName: this.hasEventNameInputTarget ? this.eventNameInputTarget.value : '',
            customData: rows,
            goalEventType: this.hasGoalEventTypeTarget ? this.goalEventTypeTarget.value : 'string',
            goalEvent: this.hasGoalEventInputTarget ? this.goalEventInputTarget.value : '',
            pagePathType: this.hasPagePathTypeTarget ? this.pagePathTypeTarget.value : 'string',
            pagePath: this.hasPagePathInputTarget ? this.pagePathInputTarget.value : ''
        };
    }

    syncEventFromKv() {
        if (!this.hasEventTarget) return;
        this.updateKvControls();
        const { json, error } = buildEventFromKv(this.readKvState());
        this.kvError = error;
        this.eventTarget.value = json;
        this.checkEvent();
    }

    // Checks the event as the server will, and blocks saving one it would refuse.
    checkEvent() {
        if (!this.hasEventTarget) return;
        this.offerNewVariable();
        if (this.eventMode !== 'kv') this.kvError = '';
        // The untouched starting point is not an error yet; the server skips it on an empty new row.
        const untouched = !this.kvError && this.eventTarget.value.replace(/\s+/g, '') === emptyEvent.replace(/\s+/g, '');
        const message = this.eventTarget.disabled || untouched ? '' : (this.kvError || eventProblem(this.eventTarget.value));
        const kvVisible = this.eventMode === 'kv' && this.hasEventNameInputTarget;
        if (typeof this.eventTarget.setCustomValidity === 'function') this.eventTarget.setCustomValidity(kvVisible ? '' : message);
        if (this.hasEventNameInputTarget && typeof this.eventNameInputTarget.setCustomValidity === 'function') {
            this.eventNameInputTarget.setCustomValidity(kvVisible ? message : '');
        }
        if (!this.hasEventStatusTarget) return;
        this.eventStatusTarget.textContent = message;
        this.eventStatusTarget.classList.toggle('is-danger', message !== '');
    }

    // The aliases defined on the Variables tab, as the page keeps them in its list.
    knownAliases() {
        const document = this.element?.ownerDocument;
        const list = typeof document?.getElementById === 'function' ? document.getElementById('tag-variable-aliases') : null;
        return new Set(Array.from(list?.options ?? list?.children ?? []).map((option) => option.value));
    }

    // Offers to create the first variable this event uses that is not defined yet.
    offerNewVariable() {
        if (!this.hasNewVariableTarget || !this.hasEventTarget) return;
        const known = this.knownAliases();
        const missing = this.eventTarget.disabled ? undefined : eventAliases(this.eventTarget.value).find((alias) => !known.has(alias));
        if (missing !== this.offeredAlias && this.hasNewVariableStatusTarget) this.newVariableStatusTarget.textContent = '';
        this.offeredAlias = missing;
        this.newVariableTarget.hidden = missing === undefined;
        if (missing !== undefined && this.hasNewVariableNameTarget) this.newVariableNameTarget.textContent = missing;
    }

    // Asks the page to add the variable to the Variables tab; it is saved with the form.
    createVariable() {
        if (!this.offeredAlias || !this.hasNewVariablePathTarget) return;
        const alias = this.offeredAlias;
        const detail = {alias, path: this.newVariablePathTarget.value.trim()};
        if (typeof this.dispatch === 'function') this.dispatch('createVariable', {detail});
        const status = this.hasNewVariableStatusTarget ? this.newVariableStatusTarget : null;
        if (!detail.added) {
            if (status) {
                status.textContent = detail.error || 'The variable could not be added. Add it on the Variables tab.';
                status.classList?.toggle('is-success', false);
                status.classList?.toggle('is-danger', true);
            }
            this.newVariablePathTarget.focus?.();
            return;
        }
        this.newVariablePathTarget.value = '';
        // Offers the next missing variable, if any; the message below stays.
        this.offerNewVariable();
        if (status) {
            status.textContent = 'Added ' + alias + ' on the Variables tab. Save to keep it.';
            status.classList?.toggle('is-danger', false);
            status.classList?.toggle('is-success', true);
        }
    }

    // ---- Test this tag ----

    /** The trigger as the row shows it. */
    previewTrigger() {
        const type = this.triggerTarget.value;
        const input = this.eventFieldTarget?.querySelector?.('input');
        return ['document_event', 'window_event', 'data_layer'].includes(type) ? {type, event: (input?.value ?? '').trim()} : {type};
    }

    /** The variables this tag uses, from its event, arguments or script URL. */
    previewAliases() {
        const type = this.actionTypeTarget.value;
        if (type === 'event' && this.hasEventTarget) {
            try { return references(JSON.parse(this.eventTarget.value)).map(([alias]) => alias); } catch (error) { return []; }
        }
        if (type === 'call' && this.hasArgumentsTarget) {
            try { return references(JSON.parse(this.argumentsTarget.value || '[]')).map(([alias]) => alias); } catch (error) { return []; }
        }
        if (type === 'script' && this.hasSourceTarget) {
            return Array.from(this.sourceTarget.value.matchAll(/\{\{([A-Za-z][A-Za-z0-9_]{0,63})\}\}/g), (match) => match[1]);
        }
        return [];
    }

    /** Alias => data path, as typed on the Variables tab. */
    variablePaths() {
        const document = this.element?.ownerDocument;
        const paths = {};
        const aliases = typeof document?.querySelectorAll === 'function' ? document.querySelectorAll('input[name^="variables["][name$="[alias]"]') : [];
        for (const input of aliases) {
            const path = input.closest?.('[data-variable-row]')?.querySelector?.('input[name$="[path]"]');
            const alias = (input.value ?? '').trim();
            if (alias && path) paths[alias] = (path.value ?? '').trim();
        }
        return paths;
    }

    openPreview() {
        if (!this.hasPreviewTarget || !this.previewTarget.open) return;
        if (this.hasPreviewSampleTarget && !this.previewSampleTarget.value.trim()) this.resetPreviewSample();
        else this.labelPreviewSample();
    }

    resetPreviewSample() {
        if (!this.hasPreviewSampleTarget) return;
        this.previewSampleTarget.value = JSON.stringify(sampleFor(this.previewTrigger(), this.previewAliases(), this.variablePaths()), null, 2);
        this.labelPreviewSample();
    }

    labelPreviewSample() {
        const [label, help] = sampleLabels[this.triggerTarget.value] || sampleLabels.data_layer;
        if (this.hasPreviewSampleLabelTarget) this.previewSampleLabelTarget.textContent = label;
        if (this.hasPreviewSampleHelpTarget) this.previewSampleHelpTarget.textContent = help;
    }

    /** The row's number in the form, from its field names. */
    rowIndex() {
        const name = this.element?.querySelector?.('input[name$="[id]"]')?.name ?? '';
        const match = /^tags\[(\d+)\]/.exec(name);
        return match ? match[1] : null;
    }

    async runPreview() {
        if (!this.hasPreviewResultTarget) return;
        const document = this.element.ownerDocument;
        const view = document.defaultView;
        const form = this.element.closest?.('form');
        const index = this.rowIndex();
        let sample;
        try {
            sample = JSON.parse(this.hasPreviewSampleTarget ? this.previewSampleTarget.value : '{}');
        } catch (error) {
            sample = undefined;
        }
        if (sample === null || typeof sample !== 'object' || Array.isArray(sample)) {
            this.showPreview({error: 'The sample must be a JSON object, such as {"event": "purchase"}.'});
            return;
        }
        if (!form || index === null || !this.previewUrlValue || !this.workerUrlValue || typeof view?.fetch !== 'function' || typeof view.Worker !== 'function') {
            this.showPreview({error: 'This browser cannot run the test.'});
            return;
        }
        // The key-value editor writes the event as you type; make sure the JSON is current.
        if (this.actionTypeTarget.value === 'event' && this.eventMode === 'kv') this.syncEventFromKv();
        if (this.hasPreviewRunTarget) this.previewRunTarget.disabled = true;
        this.showPreview({pending: true});
        try {
            const body = new view.FormData(form);
            body.append('preview_tag', index);
            const response = await view.fetch(this.previewUrlValue, {method: 'POST', body, credentials: 'same-origin', headers: {Accept: 'application/json'}});
            const plan = await response.json().catch(() => ({error: 'The test could not be prepared.'}));
            if (!response.ok || plan.error) {
                this.showPreview({error: plan.error || 'The test could not be prepared.'});
                return;
            }
            const records = await this.runWorker(view, plan, sample);
            this.showPreview({plan, sample, records, consent: !this.hasPreviewConsentTarget || this.previewConsentTarget.checked});
        } catch (error) {
            this.showPreview({error: error?.message || 'The test could not run.'});
        } finally {
            if (this.hasPreviewRunTarget) this.previewRunTarget.disabled = false;
        }
    }

    runWorker(view, plan, sample) {
        return new Promise((resolve, reject) => {
            const worker = new view.Worker(this.workerUrlValue);
            const timer = view.setTimeout(() => {
                worker.terminate();
                reject(new Error('The test did not finish within a few seconds, so it was stopped.'));
            }, 4000);
            worker.onmessage = (message) => {
                view.clearTimeout(timer);
                worker.terminate();
                if (message.data?.error) reject(new Error(message.data.error));
                else resolve(message.data?.records ?? {});
            };
            worker.onerror = () => {
                view.clearTimeout(timer);
                worker.terminate();
                reject(new Error('The test could not start in this browser.'));
            };
            worker.postMessage({
                scriptUrl: new view.URL(plan.script, view.location.href).href,
                namespace: plan.namespace,
                methods: plan.methods,
                probeMethod: plan.probeMethod,
                categories: plan.categories,
                consent: !this.hasPreviewConsentTarget || this.previewConsentTarget.checked,
                trigger: plan.tag.trigger,
                sample,
                baseURI: this.previewBaseValue || 'https://www.example.com/'
            });
        });
    }

    /** What the test found, for showPreview(): whether the tag ran, what it did and why not. */
    static previewOutcome({plan, sample, records, consent}) {
        const tag = plan.tag;
        const done = [];
        if (tag.type === 'event') {
            for (const args of records.emits ?? []) done.push({label: 'Sends this event to Aggregate', detail: JSON.stringify(args.length === 1 ? args[0] : args, null, 2)});
        } else if (tag.type === 'script') {
            for (const script of (records.scripts ?? []).filter((entry) => entry.tag === tag.id)) done.push({label: 'Loads this script', detail: script.src});
        } else if (tag.type === 'call') {
            for (const call of (records.calls ?? []).filter((entry) => entry.method === tag.method)) done.push({label: 'Calls ' + call.method + ' with', detail: JSON.stringify(call.args, null, 2)});
            for (const args of records.emits ?? []) done.push({label: 'Calls ' + tag.method + ' with', detail: JSON.stringify(args, null, 2)});
        } else if (tag.type === 'custom' && (records.custom ?? []).includes(tag.id)) {
            done.push({label: 'Runs its custom JavaScript', detail: ''});
        }
        // Variables are read only when the trigger fires, so an event that does not match checks none.
        const triggered = tag.trigger.type !== 'data_layer' || sample.event === tag.trigger.event;
        const variables = Object.entries(plan.probes ?? {}).map(([id, probe]) => {
            const found = Object.prototype.hasOwnProperty.call(records.probes ?? {}, id);
            return {...probe, checked: triggered, found, value: found ? records.probes[id] : undefined};
        });
        const reasons = [];
        if (done.length === 0) {
            if (tag.consent !== 'none' && !consent) reasons.push('The visitor has not allowed ' + tag.consent + ', so the tag does not run.');
            if (!triggered) {
                reasons.push(typeof sample.event === 'string'
                    ? 'The sample pushes the event "' + sample.event + '", but this tag listens for "' + tag.trigger.event + '".'
                    : 'The sample has no "event", so it does not trigger the tag. Add "event": "' + tag.trigger.event + '".');
            }
            const missing = triggered ? variables.filter((variable) => !variable.found).map((variable) => variable.alias + (variable.path ? ' (' + variable.path + ')' : '')) : [];
            if (missing.length) reasons.push('A variable it uses has no value in the sample, so the tag is skipped: ' + missing.join(', ') + '.');
            if (!reasons.length) reasons.push('Nothing in the sample triggers it. Check the trigger, the event name and the sample.');
        }
        return {ran: done.length > 0, done, variables, reasons, notes: [...(plan.notes ?? []), ...(records.warnings ?? [])], errors: records.errors ?? []};
    }

    showPreview(state) {
        if (!this.hasPreviewResultTarget) return;
        const document = this.element.ownerDocument;
        const make = (tag, text, className) => {
            const node = document.createElement(tag);
            if (text !== undefined) node.textContent = text;
            if (className) node.className = className;
            return node;
        };
        const box = make('div', undefined, 'notification is-light mt-2 mb-0');
        if (state.pending) {
            box.classList.add('is-info');
            box.append(make('p', 'Testing…'));
        } else if (state.error) {
            box.classList.add('is-danger');
            box.append(make('p', state.error));
        } else {
            const outcome = this.constructor.previewOutcome(state);
            box.classList.add(outcome.ran ? 'is-success' : 'is-warning');
            box.append(make('p', outcome.ran ? 'This tag would run.' : 'This tag would not run.', 'has-text-weight-semibold'));
            for (const action of outcome.done) {
                box.append(make('p', action.label + (action.detail ? ':' : '.'), 'mt-2'));
                if (action.detail) box.append(make('pre', action.detail, 'mt-1 p-2'));
            }
            for (const reason of outcome.reasons) box.append(make('p', reason, 'mt-2'));
            if (outcome.variables.length) {
                const table = make('table', undefined, 'table is-narrow is-fullwidth mt-2 mb-0');
                const head = make('tr');
                for (const title of ['Variable', 'Path', 'Value in the sample']) head.append(make('th', title));
                table.append(make('thead'));
                table.firstChild.append(head);
                const body = make('tbody');
                for (const variable of outcome.variables) {
                    const row = make('tr');
                    row.append(make('td', variable.alias + (variable.from === 'event' ? " (this event's value)" : '')));
                    row.append(make('td', variable.path));
                    row.append(make('td', !variable.checked ? 'Not checked: the tag was not triggered' : (variable.found ? JSON.stringify(variable.value) : 'Not found')));
                    body.append(row);
                }
                table.append(body);
                box.append(table);
            }
            for (const note of outcome.notes) box.append(make('p', note, 'help mt-2'));
            for (const error of outcome.errors) box.append(make('p', error, 'help has-text-danger mt-1'));
        }
        this.previewResultTarget.replaceChildren(box);
    }

    checkCode() {
        if (!this.hasCodeTarget) return;
        const code = this.codeTarget.value;
        const message = code.trim() && !this.codeTarget.disabled ? codeProblem(code, this.element.ownerDocument.defaultView) : '';
        if (typeof this.codeTarget.setCustomValidity === 'function') this.codeTarget.setCustomValidity(message);
        if (!this.hasCodeStatusTarget) return;
        this.codeStatusTarget.textContent = message || (code.trim() && !this.codeTarget.disabled ? 'No syntax errors found. The server checks the code again when you save.' : '');
        this.codeStatusTarget.classList.toggle('is-danger', message !== '');
        this.codeStatusTarget.classList.toggle('is-success', message === '' && code.trim() !== '' && !this.codeTarget.disabled);
    }

    applyTemplate() {
        if (!this.hasTemplateTarget || !this.hasCodeTarget) return;
        const document = this.element.ownerDocument;
        const template = templates(document).find((entry) => entry.id === this.templateTarget.value);
        this.templateTarget.value = '';
        if (!template) return;
        const current = this.codeTarget.value.trim();
        const view = document.defaultView;
        if (current && current !== template.code.trim()
            && !(view && typeof view.confirm === 'function' && view.confirm('Replace the current code with the "' + template.label + '" template?'))) return;

        this.codeTarget.value = template.code;
        const id = this.element.querySelector('input[name$="[id]"]');
        if (id && !id.value) id.value = template.id;
        const consent = this.element.querySelector('input[name$="[consent]"]');
        if (consent) consent.value = template.consent;
        this.triggerTarget.value = template.trigger.type;
        const event = this.eventFieldTarget.querySelector('input');
        if (event) event.value = template.trigger.event || '';
        this.update();
        if (typeof this.codeTarget.focus === 'function') this.codeTarget.focus();
    }

    normalizeSource() {
        if (!this.hasSourceTarget) return;
        const normalized = normalizeSource(this.sourceTarget.value);
        if (normalized === this.sourceTarget.value) return;
        this.sourceTarget.value = normalized;
        if (this.hasSourceNoteTarget) this.sourceNoteTarget.hidden = false;
    }
}
