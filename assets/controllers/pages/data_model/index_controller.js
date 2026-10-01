import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['propertyRows', 'mappingRows', 'propertyTemplate', 'mappingTemplate', 'reservedDetails'];
    static values = {propertyIndex: Number, mappingIndex: Number, reservedColumns: Array};

    connect() {
        this.restoreDraftIfAvailable();
        this.validateAll();

        const form = this.element && typeof this.element.querySelector === 'function' ? this.element.querySelector('form') : null;
        if (form) {
            form.addEventListener('submit', () => {
                setTimeout(() => this.clearDraft(), 2000);
            });
        }
    }

    addProperty() {
        this.addRow('property');
        this.saveDraft();
    }

    addMapping() {
        this.addRow('mapping');
        this.saveDraft();
    }

    addRow(kind) {
        const isProperty = kind === 'property';
        const container = isProperty ? this.propertyRowsTarget : this.mappingRowsTarget;
        if (!container || container.children.length >= (isProperty ? 50 : 100)) return;

        const template = isProperty ? this.propertyTemplateTarget : this.mappingTemplateTarget;
        if (!template || !template.content || !template.content.firstElementChild) return;
        const row = template.content.firstElementChild.cloneNode(true);
        const index = isProperty ? this.propertyIndexValue++ : this.mappingIndexValue++;
        for (const field of row.querySelectorAll('[data-field]')) {
            field.name = (isProperty ? 'properties' : 'mappings') + '[' + index + '][' + field.dataset.field + ']';
            if (isProperty) {
                const previousId = field.id;
                field.id = 'property-' + index + '-' + field.dataset.field;
                const label = row.querySelector('label[for="' + previousId + '"]');
                if (label) label.htmlFor = field.id;
            }
        }
        container.appendChild(row);
        const input = row.querySelector('input');
        if (input && typeof input.focus === 'function') input.focus();
    }

    removeRow(event) {
        const row = event && event.currentTarget && typeof event.currentTarget.closest === 'function'
            ? event.currentTarget.closest('[data-property-row], tr')
            : null;
        if (row && this.element && typeof this.element.contains === 'function' && this.element.contains(row)) {
            row.remove();
            this.validateAll();
            this.saveDraft();
        }
    }

    renameProperty(event) {
        const row = event && event.currentTarget && typeof event.currentTarget.closest === 'function'
            ? event.currentTarget.closest('[data-property-row]')
            : null;
        if (row) {
            const label = row.querySelector('[data-property-label]');
            if (label) {
                label.textContent = event.currentTarget.value || 'New property';
            }
        }
    }

    showReservedColumns(event) {
        if (event && typeof event.preventDefault === 'function') event.preventDefault();
        const details = this.hasReservedDetailsTarget
            ? this.reservedDetailsTarget
            : (typeof document !== 'undefined' ? document.getElementById('reserved-columns-list') : null);
        if (details) {
            details.open = true;
            if (typeof details.scrollIntoView === 'function') {
                details.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        }
    }

    validateKey(event) {
        const input = event && event.currentTarget ? event.currentTarget : null;
        if (!input) return;
        const value = (input.value || '').trim();
        const feedback = input.closest && typeof input.closest === 'function'
            ? input.closest('.column')?.querySelector('[data-key-feedback]')
            : null;

        const allKeyInputs = this.element && typeof this.element.querySelectorAll === 'function'
            ? Array.from(this.element.querySelectorAll('[data-field="key"]'))
            : [];
        const isDuplicate = value !== '' && allKeyInputs.filter(el => (el.value || '').trim().toLowerCase() === value.toLowerCase()).length > 1;
        const isValidFormat = value === '' || /^[a-zA-Z][a-zA-Z0-9_.-]{0,63}$/.test(value);

        if (isDuplicate) {
            if (input.classList && typeof input.classList.add === 'function') input.classList.add('is-danger');
            if (feedback) {
                feedback.innerHTML = '<span class="has-text-danger"><i class="fas fa-triangle-exclamation mr-1" aria-hidden="true"></i> Duplicate key: "<strong>' + this.escape(value) + '</strong>" is already used by another property.</span>';
            }
        } else if (!isValidFormat) {
            if (input.classList && typeof input.classList.add === 'function') input.classList.add('is-danger');
            if (feedback) {
                feedback.innerHTML = '<span class="has-text-danger"><i class="fas fa-triangle-exclamation mr-1" aria-hidden="true"></i> Must begin with a letter and use up to 64 letters, numbers, underscores, dots, or hyphens.</span>';
            }
        } else {
            if (input.classList && typeof input.classList.remove === 'function') input.classList.remove('is-danger');
            if (feedback) feedback.innerHTML = '';
        }
    }

    validateColumn(event) {
        const input = event && event.currentTarget ? event.currentTarget : null;
        if (input) this.checkColumnField(input);
    }

    validateAll() {
        if (!this.element || typeof this.element.querySelectorAll !== 'function') return;

        const columnInputs = this.element.querySelectorAll('[data-field="column"], [data-field="numeric_column"]');
        columnInputs.forEach(input => this.checkColumnField(input));

        const keyInputs = this.element.querySelectorAll('[data-field="key"]');
        keyInputs.forEach(input => {
            const value = (input.value || '').trim();
            const feedback = input.closest && typeof input.closest === 'function'
                ? input.closest('.column')?.querySelector('[data-key-feedback]')
                : null;
            const isDuplicate = value !== '' && Array.from(keyInputs).filter(el => (el.value || '').trim().toLowerCase() === value.toLowerCase()).length > 1;
            if (isDuplicate) {
                if (input.classList && typeof input.classList.add === 'function') input.classList.add('is-danger');
                if (feedback) {
                    feedback.innerHTML = '<span class="has-text-danger"><i class="fas fa-triangle-exclamation mr-1" aria-hidden="true"></i> Duplicate key: "<strong>' + this.escape(value) + '</strong>" is already used.</span>';
                }
            } else if (input.classList && typeof input.classList.contains === 'function' && input.classList.contains('is-danger') && feedback && feedback.textContent.includes('Duplicate')) {
                input.classList.remove('is-danger');
                feedback.innerHTML = '';
            }
        });
    }

    checkColumnField(input) {
        if (!input) return;
        const value = (input.value || '').trim().toLowerCase();
        const isNumeric = input.dataset ? input.dataset.field === 'numeric_column' : false;
        const feedbackSelector = isNumeric ? '[data-numeric-column-feedback]' : '[data-column-feedback]';
        const feedback = input.closest && typeof input.closest === 'function'
            ? input.closest('.column')?.querySelector(feedbackSelector)
            : null;

        if (value === '') {
            if (input.classList && typeof input.classList.remove === 'function') input.classList.remove('is-danger');
            if (feedback) feedback.innerHTML = '';
            return;
        }

        const reservedList = this.reservedColumnsValue || [];
        const isReserved = reservedList.includes(value);

        const allColumnInputs = this.element && typeof this.element.querySelectorAll === 'function'
            ? Array.from(this.element.querySelectorAll('[data-field="column"], [data-field="numeric_column"]'))
            : [];
        const isDuplicate = allColumnInputs.filter(el => (el.value || '').trim().toLowerCase() === value).length > 1;
        const isValidFormat = /^[a-z][a-z0-9_]{0,62}$/.test(value);

        if (isReserved) {
            if (input.classList && typeof input.classList.add === 'function') input.classList.add('is-danger');
            if (feedback) {
                feedback.innerHTML = '<span class="has-text-danger"><i class="fas fa-triangle-exclamation mr-1" aria-hidden="true"></i> "<strong>' + this.escape(value) + '</strong>" is a built-in column name and cannot be reused. <a href="#reserved-columns-list" data-action="pages--data-model--index#showReservedColumns">View built-in list</a></span>';
            }
        } else if (isDuplicate) {
            if (input.classList && typeof input.classList.add === 'function') input.classList.add('is-danger');
            if (feedback) {
                feedback.innerHTML = '<span class="has-text-danger"><i class="fas fa-triangle-exclamation mr-1" aria-hidden="true"></i> Duplicate column: "<strong>' + this.escape(value) + '</strong>" is already used as a reporting column.</span>';
            }
        } else if (!isValidFormat) {
            if (input.classList && typeof input.classList.add === 'function') input.classList.add('is-danger');
            if (feedback) {
                feedback.innerHTML = '<span class="has-text-danger"><i class="fas fa-triangle-exclamation mr-1" aria-hidden="true"></i> Must be lowercase, start with a letter, and use only letters, numbers, and underscores (max 63 chars).</span>';
            }
        } else {
            if (input.classList && typeof input.classList.remove === 'function') input.classList.remove('is-danger');
            if (feedback) feedback.innerHTML = '';
        }
    }

    saveDraft() {
        if (typeof sessionStorage === 'undefined' || !this.propertyRowsTarget) return;
        try {
            const form = this.element && typeof this.element.querySelector === 'function' ? this.element.querySelector('form') : null;
            if (!form) return;
            const entries = [];
            const rows = this.propertyRowsTarget && typeof this.propertyRowsTarget.querySelectorAll === 'function'
                ? this.propertyRowsTarget.querySelectorAll('[data-property-row]')
                : [];
            rows.forEach(row => {
                const key = row.querySelector('[data-field="key"]')?.value ?? '';
                const type = row.querySelector('[data-field="type"]')?.value ?? 'scalar';
                const consent = row.querySelector('[data-field="consent_required"]')?.value ?? '1';
                const desc = row.querySelector('[data-field="description"]')?.value ?? '';
                const col = row.querySelector('[data-field="column"]')?.value ?? '';
                const numCol = row.querySelector('[data-field="numeric_column"]')?.value ?? '';
                entries.push({ key: key, type: type, consent: consent, desc: desc, col: col, numCol: numCol });
            });

            sessionStorage.setItem('aggregate_data_model_draft_v1', JSON.stringify({
                properties: entries,
                timestamp: Date.now()
            }));
        } catch (e) {}
    }

    restoreDraftIfAvailable() {
        if (typeof sessionStorage === 'undefined' || !this.propertyRowsTarget) return;
        try {
            const raw = sessionStorage.getItem('aggregate_data_model_draft_v1');
            if (!raw) return;
            const draft = JSON.parse(raw);
            if (!draft || !draft.properties || (Date.now() - draft.timestamp) > 86400000) return;

            const currentRows = this.propertyRowsTarget && typeof this.propertyRowsTarget.querySelectorAll === 'function'
                ? this.propertyRowsTarget.querySelectorAll('[data-property-row]')
                : [];
            if (currentRows.length === draft.properties.length) {
                draft.properties.forEach((prop, idx) => {
                    const row = currentRows[idx];
                    if (!row) return;
                    const keyInput = row.querySelector('[data-field="key"]');
                    const descInput = row.querySelector('[data-field="description"]');
                    const colInput = row.querySelector('[data-field="column"]');
                    const numColInput = row.querySelector('[data-field="numeric_column"]');
                    const typeInput = row.querySelector('[data-field="type"]');
                    const consentInput = row.querySelector('[data-field="consent_required"]');

                    if (keyInput && prop.key && !keyInput.value) keyInput.value = prop.key;
                    if (descInput && prop.desc && !descInput.value) descInput.value = prop.desc;
                    if (colInput && prop.col && !colInput.value) colInput.value = prop.col;
                    if (numColInput && prop.numCol && !numColInput.value) numColInput.value = prop.numCol;
                    if (typeInput && prop.type) typeInput.value = prop.type;
                    if (consentInput && prop.consent) consentInput.value = prop.consent;

                    const label = row.querySelector('[data-property-label]');
                    if (label && prop.key) label.textContent = prop.key;
                });
            }
        } catch (e) {}
    }

    clearDraft() {
        if (typeof sessionStorage === 'undefined') return;
        try {
            sessionStorage.removeItem('aggregate_data_model_draft_v1');
        } catch (e) {}
    }

    expandAllProperties() {
        if (!this.propertyRowsTarget || typeof this.propertyRowsTarget.querySelectorAll !== 'function') return;
        const rows = this.propertyRowsTarget.querySelectorAll('[data-property-row]');
        rows.forEach(row => { row.open = true; });
    }

    collapseAllProperties() {
        if (!this.propertyRowsTarget || typeof this.propertyRowsTarget.querySelectorAll !== 'function') return;
        const rows = this.propertyRowsTarget.querySelectorAll('[data-property-row]');
        rows.forEach(row => { row.open = false; });
    }

    filterProperties(event) {
        if (!this.propertyRowsTarget || typeof this.propertyRowsTarget.querySelectorAll !== 'function') return;
        const query = (event?.currentTarget?.value || '').trim().toLowerCase();
        const rows = this.propertyRowsTarget.querySelectorAll('[data-property-row]');
        rows.forEach(row => {
            const label = (row.querySelector('[data-property-label]')?.textContent || '').toLowerCase();
            const inputs = Array.from(row.querySelectorAll ? row.querySelectorAll('input, select') : []).map(i => (i.value || '').toLowerCase()).join(' ');
            const match = !query || label.includes(query) || inputs.includes(query);
            row.hidden = !match;
            if (match && query) {
                row.open = true;
            }
        });
    }

    escape(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
}
