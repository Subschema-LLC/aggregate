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
    return isPlainObject(value) && Object.keys(value).length === 1 && Object.prototype.hasOwnProperty.call(value, '$var');
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
    const literal = (value, valid, label, rule) => isReference(value) || valid(value) ? '' : label + ' ' + rule + (value !== null && typeof value === 'object' ? '. To read a value from the page, use {"$var": "alias"}.' : '.');
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
        'method', 'arguments', 'argumentsStatus', 'argumentsMessage', 'argumentsFix', 'event', 'eventStatus'];

    static values = { namespaces: Array };

    connect() {
        this.update();
    }

    update() {
        for (const fields of this.actionFieldsTargets) {
            const selected = fields.dataset.actionFields === this.actionTypeTarget.value;
            fields.hidden = !selected;
            for (const input of fields.querySelectorAll('input, textarea')) {
                input.disabled = !selected;
            }
        }
        const eventNeeded = ['document_event', 'window_event', 'data_layer'].includes(this.triggerTarget.value);
        this.eventFieldTarget.hidden = !eventNeeded;
        this.eventFieldTarget.querySelector('input').disabled = !eventNeeded;
        // A new event starts from its two usual fields.
        if (this.hasEventTarget && this.actionTypeTarget.value === 'event' && !this.eventTarget.value.trim()) this.eventTarget.value = emptyEvent;
        this.checkCode();
        this.checkArguments();
        this.checkEvent();
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
        this.actionTypeTarget.value = 'event';
        this.update();
        if (typeof this.eventTarget.focus === 'function') this.eventTarget.focus();
    }

    // Checks the event as the server will, and blocks saving one it would refuse.
    checkEvent() {
        if (!this.hasEventTarget) return;
        // The untouched starting point is not an error yet; the server skips it on an empty new row.
        const untouched = this.eventTarget.value.replace(/\s+/g, '') === emptyEvent.replace(/\s+/g, '');
        const message = this.eventTarget.disabled || untouched ? '' : eventProblem(this.eventTarget.value);
        if (typeof this.eventTarget.setCustomValidity === 'function') this.eventTarget.setCustomValidity(message);
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
