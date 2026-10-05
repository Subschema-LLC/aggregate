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

function templates(document) {
    try {
        const groups = JSON.parse(document.getElementById('tag-custom-templates').textContent);
        return Object.values(groups).flat();
    } catch (error) {
        return [];
    }
}

export default class extends Controller {
    static targets = ['actionType', 'trigger', 'actionFields', 'eventField', 'source', 'sourceNote', 'template', 'code', 'codeStatus'];

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
