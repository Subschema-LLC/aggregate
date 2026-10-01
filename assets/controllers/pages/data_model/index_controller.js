import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['propertyRows', 'mappingRows', 'propertyTemplate', 'mappingTemplate', 'reservedDetails'];
    static values = {propertyIndex: Number, mappingIndex: Number, reservedColumns: Array};

    connect() {
        this.restoreDraftIfAvailable();
        this.validateAll();

        const form = this.element.querySelector('form');
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
        if (container.children.length >= (isProperty ? 50 : 100)) return;

        const template = isProperty ? this.propertyTemplateTarget : this.mappingTemplateTarget;
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
        if (input) input.focus();
    }

    removeRow(event) {
        const row = event.currentTarget.closest('[data-property-row], tr');
        if (row && this.element.contains(row)) {
            row.remove();
            this.validateAll();
            this.saveDraft();
        }
    }

    renameProperty(event) {
        const row = event.currentTarget.closest('[data-property-row]');
        if (row) {
            row.querySelector('[data-property-label]').textContent = event.currentTarget.value || 'New property';
        }
    }

    showReservedColumns(event) {
        if (event) event.preventDefault();
        const details = this.hasReservedDetailsTarget
            ? this.reservedDetailsTarget
            : document.getElementById('reserved-columns-list');
        if (details) {
            details.open = true;
            details.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    validateKey(event) {
        const input = event.currentTarget;
        const value = input.value.trim();
        const feedback = input.closest('.column')?.querySelector('[data-key-feedback]');

        const allKeyInputs = Array.from(this.element.querySelectorAll('[data-field="key"]'));
        const isDuplicate = value !== '' && allKeyInputs.filter(el => el.value.trim().toLowerCase() === value.toLowerCase()).length > 1;
        const isValidFormat = value === '' || /^[a-zA-Z][a-zA-Z0-9_.-]{0,63}$/.test(value);

        if (isDuplicate) {
            input.classList.add('is-danger');
            if (feedback) {
                feedback.innerHTML = '<span class="has-text-danger"><i class="fas fa-triangle-exclamation mr-1" aria-hidden="true"></i> Duplicate key: "<strong>' + this.escape(value) + '</strong>" is already used by another property.</span>';
            }
        } else if (!isValidFormat) {
            input.classList.add('is-danger');
            if (feedback) {
                feedback.innerHTML = '<span class="has-text-danger"><i class="fas fa-triangle-exclamation mr-1" aria-hidden="true"></i> Must begin with a letter and use up to 64 letters, numbers, underscores, dots, or hyphens.</span>';
            }
        } else {
            input.classList.remove('is-danger');
            if (feedback) feedback.innerHTML = '';
        }
    }

    validateColumn(event) {
        const input = event.currentTarget;
        this.checkColumnField(input);
    }

    validateAll() {
        const columnInputs = this.element.querySelectorAll('[data-field="column"], [data-field="numeric_column"]');
        columnInputs.forEach(input => this.checkColumnField(input));

        const keyInputs = this.element.querySelectorAll('[data-field="key"]');
        keyInputs.forEach(input => {
            const value = input.value.trim();
            const feedback = input.closest('.column')?.querySelector('[data-key-feedback]');
            const isDuplicate = value !== '' && Array.from(keyInputs).filter(el => el.value.trim().toLowerCase() === value.toLowerCase()).length > 1;
            if (isDuplicate) {
                input.classList.add('is-danger');
                if (feedback) {
                    feedback.innerHTML = '<span class="has-text-danger"><i class="fas fa-triangle-exclamation mr-1" aria-hidden="true"></i> Duplicate key: "<strong>' + this.escape(value) + '</strong>" is already used.</span>';
                }
            } else if (input.classList.contains('is-danger') && feedback && feedback.textContent.includes('Duplicate')) {
                input.classList.remove('is-danger');
                feedback.innerHTML = '';
            }
        });
    }

    checkColumnField(input) {
        const value = input.value.trim().toLowerCase();
        const isNumeric = input.dataset.field === 'numeric_column';
        const feedbackSelector = isNumeric ? '[data-numeric-column-feedback]' : '[data-column-feedback]';
        const feedback = input.closest('.column')?.querySelector(feedbackSelector);

        if (value === '') {
            input.classList.remove('is-danger');
            if (feedback) feedback.innerHTML = '';
            return;
        }

        const reservedList = this.reservedColumnsValue || [];
        const isReserved = reservedList.includes(value);

        const allColumnInputs = Array.from(this.element.querySelectorAll('[data-field="column"], [data-field="numeric_column"]'));
        const isDuplicate = allColumnInputs.filter(el => el.value.trim().toLowerCase() === value).length > 1;
        const isValidFormat = /^[a-z][a-z0-9_]{0,62}$/.test(value);

        if (isReserved) {
            input.classList.add('is-danger');
            if (feedback) {
                feedback.innerHTML = '<span class="has-text-danger"><i class="fas fa-triangle-exclamation mr-1" aria-hidden="true"></i> "<strong>' + this.escape(value) + '</strong>" is a built-in column name and cannot be reused. <a href="#reserved-columns-list" data-action="pages--data-model--index#showReservedColumns">View built-in list</a></span>';
            }
        } else if (isDuplicate) {
            input.classList.add('is-danger');
            if (feedback) {
                feedback.innerHTML = '<span class="has-text-danger"><i class="fas fa-triangle-exclamation mr-1" aria-hidden="true"></i> Duplicate column: "<strong>' + this.escape(value) + '</strong>" is already used as a reporting column.</span>';
            }
        } else if (!isValidFormat) {
            input.classList.add('is-danger');
            if (feedback) {
                feedback.innerHTML = '<span class="has-text-danger"><i class="fas fa-triangle-exclamation mr-1" aria-hidden="true"></i> Must be lowercase, start with a letter, and use only letters, numbers, and underscores (max 63 chars).</span>';
            }
        } else {
            input.classList.remove('is-danger');
            if (feedback) feedback.innerHTML = '';
        }
    }

    saveDraft() {
        try {
            const form = this.element.querySelector('form');
            if (!form) return;
            const entries = [];
            const rows = this.propertyRowsTarget.querySelectorAll('[data-property-row]');
            rows.forEach(row => {
                const key = row.querySelector('[data-field="key"]')?.value ?? '';
                const type = row.querySelector('[data-field="type"]')?.value ?? 'scalar';
                const consent = row.querySelector('[data-field="consent_required"]')?.value ?? '1';
                const desc = row.querySelector('[data-field="description"]')?.value ?? '';
                const col = row.querySelector('[data-field="column"]')?.value ?? '';
                const numCol = row.querySelector('[data-field="numeric_column"]')?.value ?? '';
                entries.push({ key, type, consent, desc, col, numCol });
            });

            sessionStorage.setItem('aggregate_data_model_draft_v1', JSON.stringify({
                properties: entries,
                timestamp: Date.now()
            }));
        } catch (e) {
        }
    }

    restoreDraftIfAvailable() {
        try {
            const raw = sessionStorage.getItem('aggregate_data_model_draft_v1');
            if (!raw) return;
            const draft = JSON.parse(raw);
            if (!draft || !draft.properties || (Date.now() - draft.timestamp) > 86400000) {
                return;
            }

            const currentRows = this.propertyRowsTarget.querySelectorAll('[data-property-row]');
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
        } catch (e) {
        }
    }

    clearDraft() {
        try {
            sessionStorage.removeItem('aggregate_data_model_draft_v1');
        } catch (e) {}
    }

    expandAllProperties() {
        this.propertyRowsTarget.querySelectorAll('[data-property-row]').forEach(row => {
            row.open = true;
        });
    }

    collapseAllProperties() {
        this.propertyRowsTarget.querySelectorAll('[data-property-row]').forEach(row => {
            row.open = false;
        });
    }

    filterProperties(event) {
        const query = event.currentTarget.value.trim().toLowerCase();
        const rows = this.propertyRowsTarget.querySelectorAll('[data-property-row]');
        rows.forEach(row => {
            const label = (row.querySelector('[data-property-label]')?.textContent || '').toLowerCase();
            const inputs = Array.from(row.querySelectorAll('input, select')).map(i => i.value.toLowerCase()).join(' ');
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
