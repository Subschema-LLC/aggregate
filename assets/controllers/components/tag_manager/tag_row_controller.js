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
        'customDataRows', 'addCustomDataButton', 'goalEventType', 'goalEventInput', 'pagePathType', 'pagePathInput'];

    static values = { namespaces: Array, defaultId: String, defaultSrc: String };

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
