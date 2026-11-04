import { Controller } from '@hotwired/stimulus';

const aliasPattern = /^[A-Za-z][A-Za-z0-9_]{0,63}$/;
// The runtime's variable path syntax (pathParts in public/tag-manager.js); the server checks it again on save.
const pathPattern = /^[A-Za-z_$][A-Za-z0-9_$]*(?:(?:\.[A-Za-z_$][A-Za-z0-9_$]*)|(?:\[(?:0|[1-9][0-9]*)\]))*$/;

/**
 * The tag manager page: fills the empty new-tag row with the selected
 * website's tracker URL, adds and discards unsaved tag and variable rows, and
 * creates a variable a tag row asks for. Nothing is saved until the form is.
 */
export default class extends Controller {
    static targets = ['status', 'tagRows', 'tagTemplate', 'addTagButton', 'rowStatus', 'variableRows', 'variableTemplate', 'addVariableButton'];
    static values = { newRow: Number, maxTags: Number, maxVariables: Number };

    addTracker(event) {
        const document = this.element.ownerDocument;
        const field = (name) => document.getElementById(`tag-${this.newRowValue}-${name}`);
        const id = field('id');
        const type = field('type');
        const source = field('src');
        const method = field('method');
        const trigger = field('trigger');
        const consent = field('consent');
        const consentChoice = field('consent-choice');
        const { url, id: suggestedId } = event.params;
        if (!this.hasNewRowValue || !source || !url) return;

        const inUse = (source.value !== '' && source.value !== url)
            || (id && id.value !== '' && id.value !== suggestedId)
            || (method && method.value !== '');
        if (inUse) {
            this.say('The new tag row already has other values. Save or clear it, then add the tracker, or paste the copied URL into a row yourself.');
            return;
        }

        if (id && id.value === '') id.value = suggestedId;
        for (const [select, value] of [[type, 'script'], [trigger, 'dom_ready']]) {
            if (!select || select.value === value) continue;
            select.value = value;
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }
        this.activateTab('tags');
        source.value = url;
        source.scrollIntoView?.({ block: 'center' });
        (consentChoice ?? consent ?? source).focus?.();

        const manager = document.getElementById('tag-manager-enabled');
        const category = consent?.value || 'analytics';
        this.say(`Added to the new tag row with the ${category} consent category. Choose No consent required to allow anonymous tracking before a choice, then save.`
            + (manager && manager.value !== '1' ? ' The tag manager is disabled, so enable it too before the tracker can load.' : ''));
    }

    // Keeps every tag chaining list ("Run after …") in step with the IDs typed into the rows.
    refreshRunAfter(event) {
        const field = event?.target;
        if (!field || typeof field.matches !== 'function' || !field.matches('input[name^="tags["][name$="[id]"]')) return;
        this.syncRunAfter();
    }

    syncRunAfter() {
        const rows = Array.from(this.element.querySelectorAll('[data-tag-row]'));
        const idOf = (row) => (row.querySelector('input[name$="[id]"]')?.value ?? '').trim();
        const ids = rows.map(idOf).filter((id) => /^[A-Za-z][A-Za-z0-9_-]{0,63}$/.test(id));
        for (const row of rows) {
            const select = row.querySelector('select[data-tag-after]');
            if (!select) continue;
            const own = idOf(row);
            const chosen = select.value;
            const choices = ids.filter((id, index) => id !== own && ids.indexOf(id) === index);
            if (chosen && !choices.includes(chosen)) choices.push(chosen);
            const document = this.element.ownerDocument;
            const options = [select.options[0], ...choices.map((id) => {
                const option = document.createElement('option');
                option.value = id;
                option.textContent = `Run after ${id}`;
                return option;
            })];
            select.replaceChildren(...options);
            select.value = chosen;
        }
    }

    // Keeps the variable alias datalist in step with the aliases typed on the Variables tab.
    refreshVariableAliases(event) {
        const field = event?.target;
        if (!field || typeof field.matches !== 'function' || !field.matches('input[name^="variables["][name$="[alias]"]')) return;
        this.syncVariableAliases();
    }

    syncVariableAliases() {
        const document = this.element.ownerDocument;
        const list = typeof document?.getElementById === 'function' ? document.getElementById('tag-variable-aliases') : null;
        if (!list || typeof list.replaceChildren !== 'function') return;
        const inputs = Array.from(this.element.querySelectorAll('input[name^="variables["][name$="[alias]"]'));
        const aliases = inputs
            .map((input) => (input.value ?? '').trim())
            .filter((alias, index, all) => /^[A-Za-z][A-Za-z0-9_]{0,63}$/.test(alias) && all.indexOf(alias) === index);
        list.replaceChildren(...aliases.map((alias) => {
            const option = document.createElement('option');
            option.value = alias;
            return option;
        }));
        // Tag rows offering to create a variable check again.
        const view = document?.defaultView;
        if (view && typeof view.CustomEvent === 'function' && typeof document.dispatchEvent === 'function') {
            document.dispatchEvent(new view.CustomEvent('tag-manager:variables-changed'));
        }
    }

    // Adds a blank tag row, numbered after the others, without saving.
    addTagRow() {
        const row = this.appendRow('tag');
        if (!row) return;
        this.syncRunAfter();
        row.querySelector?.('input[name$="[id]"]')?.focus?.();
    }

    addVariableRow() {
        const row = this.appendRow('variable');
        row?.querySelector?.('input[name$="[alias]"]')?.focus?.();
    }

    // Removes a row added on this page, before it was ever saved. The server
    // takes the rows as a list numbered from 0, so later rows move up.
    discardRow(event) {
        const target = event?.currentTarget ?? event?.target ?? null;
        const row = typeof target?.closest === 'function' ? target.closest('[data-tag-row], [data-variable-row]') : null;
        if (!row) return;
        const kind = row.matches?.('[data-tag-row]') ? 'tag' : 'variable';
        const box = row.matches?.('[data-tag-row]') ? row.closest?.('.box') ?? row : row;
        const rows = this.rows(kind);
        const index = rows.indexOf(box);
        if (index === -1) return;
        box.remove();
        rows.slice(index + 1).forEach((later, offset) => this.renumber(later, kind, index + 1 + offset, index + offset));
        if (kind === 'tag') this.syncRunAfter();
        else this.syncVariableAliases();
        this.updateAddButtons();
        this.sayRows(kind === 'tag' ? 'Discarded the new tag.' : 'Discarded the new variable.');
    }

    // A tag row asks for a variable it uses that is not defined yet: fill an
    // empty variable row, or add one, and tell the row how it went.
    createVariable(event) {
        const detail = event?.detail ?? {};
        const alias = String(detail.alias ?? '').trim();
        const path = String(detail.path ?? '').trim();
        if (!aliasPattern.test(alias) || path.length > 256 || !pathPattern.test(path)) {
            detail.error = 'Enter a data path such as page_type or ecommerce.items[0].category.';
            return;
        }
        const inputs = (row) => [row.querySelector?.('input[name$="[alias]"]'), row.querySelector?.('input[name$="[path]"]')];
        const rows = this.rows('variable');
        if (rows.some((row) => (inputs(row)[0]?.value ?? '').trim() === alias)) {
            detail.error = alias + ' is already on the Variables tab.';
            return;
        }
        let row = rows.find((candidate) => inputs(candidate).every((input) => input && input.value === ''));
        row = row ?? this.appendRow('variable');
        if (!row) {
            detail.error = 'There is no room for another variable. Remove one on the Variables tab first.';
            return;
        }
        const [aliasInput, pathInput] = inputs(row);
        if (!aliasInput || !pathInput) {
            detail.error = 'The Variables tab could not take the variable.';
            return;
        }
        aliasInput.value = alias;
        pathInput.value = path;
        this.syncVariableAliases();
        detail.added = true;
    }

    appendRow(kind) {
        const container = kind === 'tag' ? (this.hasTagRowsTarget ? this.tagRowsTarget : null) : (this.hasVariableRowsTarget ? this.variableRowsTarget : null);
        const template = kind === 'tag' ? (this.hasTagTemplateTarget ? this.tagTemplateTarget : null) : (this.hasVariableTemplateTarget ? this.variableTemplateTarget : null);
        const limit = kind === 'tag' ? this.maxTagsValue : this.maxVariablesValue;
        if (!container || !template) return null;
        const rows = this.rows(kind);
        if (limit && rows.length >= limit) {
            this.sayRows(kind === 'tag' ? `A website can have up to ${limit} tags.` : `A website can have up to ${limit} variables.`);
            this.updateAddButtons();
            return null;
        }
        const document = this.element.ownerDocument;
        const holder = document.createElement('template');
        holder.innerHTML = template.innerHTML.replaceAll('__INDEX__', String(rows.length));
        const row = holder.content.firstElementChild;
        if (!row) return null;
        container.appendChild(row);
        this.updateAddButtons();
        this.sayRows(kind === 'tag' ? `Added tag ${rows.length + 1}. Save to keep it.` : `Added variable ${rows.length + 1}. Save to keep it.`);
        return row;
    }

    /** The row boxes of one kind, in order. */
    rows(kind) {
        const container = kind === 'tag' ? (this.hasTagRowsTarget ? this.tagRowsTarget : null) : (this.hasVariableRowsTarget ? this.variableRowsTarget : null);
        if (!container) return [];
        if (kind === 'variable') return Array.from(container.querySelectorAll('[data-variable-row]'));
        return Array.from(container.querySelectorAll('[data-tag-row]')).map((fieldset) => fieldset.closest?.('.box') ?? fieldset);
    }

    // Moves a row's field names and IDs from one number to another.
    renumber(row, kind, from, to) {
        const pattern = kind === 'tag'
            ? [[`tags[${from}]`, `tags[${to}]`], [`tag-${from}-`, `tag-${to}-`]]
            : [[`variables[${from}]`, `variables[${to}]`], [`variable-${from}-`, `variable-${to}-`]];
        const elements = [row, ...Array.from(row.querySelectorAll?.('*') ?? [])];
        for (const element of elements) {
            for (const name of ['id', 'name', 'for', 'aria-describedby', 'aria-labelledby']) {
                const value = element.getAttribute?.(name);
                if (typeof value !== 'string') continue;
                let next = value;
                for (const [before, after] of pattern) next = next.split(before).join(after);
                if (next !== value) element.setAttribute(name, next);
            }
        }
    }

    updateAddButtons() {
        if (this.hasAddTagButtonTarget) this.addTagButtonTarget.disabled = !!this.maxTagsValue && this.rows('tag').length >= this.maxTagsValue;
        if (this.hasAddVariableButtonTarget) this.addVariableButtonTarget.disabled = !!this.maxVariablesValue && this.rows('variable').length >= this.maxVariablesValue;
    }

    sayRows(message) {
        if (this.hasRowStatusTarget) this.rowStatusTarget.textContent = message;
    }

    say(message) {
        if (this.hasStatusTarget) this.statusTarget.textContent = message;
    }

    switchWorkspaceTab(event) {
        if (event && typeof event.preventDefault === 'function') event.preventDefault();
        const target = event?.currentTarget ?? event?.target ?? null;
        const link = target && typeof target.closest === 'function' ? target.closest('[data-tab-name], a') : target;
        const name = link?.dataset?.tabName || (typeof link?.getAttribute === 'function' ? (link.getAttribute('href') || '').replace(/^#/, '') : '');
        if (name) this.activateTab(name);
    }

    activateTab(name) {
        if (!this.element || typeof this.element.querySelectorAll !== 'function') return;
        const tabs = Array.from(this.element.querySelectorAll('[data-toggle-tab], [data-workspace-tab]'));
        for (const tab of tabs) {
            const tabId = tab.dataset?.toggleTab || tab.dataset?.workspaceTab;
            const isActive = tabId === name;
            tab.classList?.toggle('is-active', isActive);
            const link = typeof tab.querySelector === 'function' ? tab.querySelector('a') : null;
            if (link && typeof link.setAttribute === 'function') {
                link.setAttribute('aria-selected', isActive ? 'true' : 'false');
            }
        }
        const panes = Array.from(this.element.querySelectorAll('[data-toggle-pane], [data-pane-name]'));
        for (const pane of panes) {
            const paneId = pane.dataset?.togglePane || pane.dataset?.paneName;
            const isActive = paneId === name;
            pane.classList?.toggle('is-active', isActive);
            pane.hidden = !isActive;
            if (isActive) pane.removeAttribute?.('hidden');
            else pane.setAttribute?.('hidden', '');
        }
    }
}
