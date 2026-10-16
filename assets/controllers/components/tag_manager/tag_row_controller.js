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

// The tracker's emit(name, properties, goal) takes flat properties and drops
// any that hold an object or a list, so a customData wrapper sends the event
// without them. The server makes the same check on save
// (TagManagerValues::emitProblem); keep the messages the same.
const propertyWrappers = ['customData', 'eventData'];

function isPlainObject(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function isReference(value) {
    return isPlainObject(value) && Object.keys(value).length === 1 && Object.prototype.hasOwnProperty.call(value, '$var');
}

/** @returns {{message: string, wrapper: string|null, fixed: string|null}|null} */
function emitProblem(method, argumentsJson, namespaces) {
    if (!namespaces.some((namespace) => method.trim() === namespace + '.emit')) return null;
    let args;
    try {
        args = JSON.parse(argumentsJson.trim() || '[]');
    } catch (error) {
        // The server explains invalid JSON on save.
        return null;
    }
    if (!Array.isArray(args)) return null;
    const problem = (message, wrapper = null, fixed = null) => ({message, wrapper, fixed});
    if (typeof args[0] !== 'string' && !isReference(args[0])) return problem('The first argument is the event name, such as "login".');
    const properties = args.length > 1 ? args[1] : null;
    if (properties === null) return null;
    if (isReference(properties)) return problem('A variable gives one value, but the second argument needs an object of properties. Name each property and use a variable for its value: {"plan": {"$var": "plan"}}.');
    // JSON {} and [] both decode to an empty array on the server; neither sends anything.
    if (!isPlainObject(properties) && !(Array.isArray(properties) && properties.length === 0)) {
        return problem('The second argument holds the event properties and must be an object, such as {"plan": "pro"}, or null. A goal goes third.');
    }
    const keys = Object.keys(properties);
    const nested = keys.filter((key) => properties[key] !== null && typeof properties[key] === 'object' && !isReference(properties[key]));
    if (nested.length === 0) return null;
    const wrapper = nested[0];
    if (keys.length === 1 && propertyWrappers.includes(wrapper) && (isPlainObject(properties[wrapper]) || properties[wrapper].length === 0)) {
        const fixedArgs = [...args];
        fixedArgs[1] = Array.isArray(properties[wrapper]) ? {} : properties[wrapper];
        return problem('Remove the "' + wrapper + '" wrapper and put its properties directly in the second argument. The tracker puts the properties in customData itself and drops a property that holds an object, so this event would be sent without them.',
            wrapper, JSON.stringify(fixedArgs, null, 4));
    }
    const names = nested.map((name) => '"' + name + '"');
    const last = names.pop();
    return problem('The tracker drops ' + (names.length ? names.join(', ') + ' and ' + last : last) + ': a property must be text, a number, true, false or null, not an object or a list. Give each value its own property.');
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
        'method', 'arguments', 'argumentsStatus', 'argumentsMessage', 'argumentsFix'];

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
        this.checkCode();
        this.checkArguments();
    }

    // Warns, without blocking the save, when a call to the tracker's emit()
    // would lose its properties, and offers to remove a customData wrapper.
    checkArguments() {
        if (!this.hasArgumentsStatusTarget || !this.hasMethodTarget || !this.hasArgumentsTarget) return;
        const namespaces = this.hasNamespacesValue && this.namespacesValue.length ? this.namespacesValue : ['Aggregate'];
        const problem = this.argumentsTarget.disabled ? null : emitProblem(this.methodTarget.value, this.argumentsTarget.value, namespaces);
        this.fixedArguments = problem ? problem.fixed : null;
        this.argumentsStatusTarget.hidden = problem === null;
        if (this.hasArgumentsMessageTarget) this.argumentsMessageTarget.textContent = problem ? problem.message : '';
        if (this.hasArgumentsFixTarget) {
            this.argumentsFixTarget.hidden = this.fixedArguments === null;
            this.argumentsFixTarget.textContent = problem && problem.wrapper ? 'Remove the ' + problem.wrapper + ' wrapper' : '';
        }
    }

    fixArguments() {
        if (!this.fixedArguments || !this.hasArgumentsTarget) return;
        this.argumentsTarget.value = this.fixedArguments;
        this.checkArguments();
        if (typeof this.argumentsTarget.focus === 'function') this.argumentsTarget.focus();
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
