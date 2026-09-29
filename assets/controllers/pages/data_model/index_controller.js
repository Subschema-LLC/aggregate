import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['propertyRows', 'mappingRows', 'propertyTemplate', 'mappingTemplate'];
    static values = {propertyIndex: Number, mappingIndex: Number};

    addProperty() {
        this.addRow('property');
    }

    addMapping() {
        this.addRow('mapping');
    }

    addRow(kind) {
        const isProperty = kind === 'property';
        const container = isProperty ? this.propertyRowsTarget : this.mappingRowsTarget;
        if (container.children.length >= (isProperty ? 50 : 100)) return;

        const template = isProperty ? this.propertyTemplateTarget : this.mappingTemplateTarget;
        const row = template.content.firstElementChild.cloneNode(true);
        const index = isProperty ? this.propertyIndexValue++ : this.mappingIndexValue++;
        for (const field of row.querySelectorAll('[data-field]')) {
            field.name = `${isProperty ? 'properties' : 'mappings'}[${index}][${field.dataset.field}]`;
            if (isProperty) {
                const previousId = field.id;
                field.id = `property-${index}-${field.dataset.field}`;
                const label = row.querySelector(`label[for="${previousId}"]`);
                if (label) label.htmlFor = field.id;
            }
        }
        container.appendChild(row);
        row.querySelector('input').focus();
    }

    removeRow(event) {
        const row = event.currentTarget.closest('[data-property-row], tr');
        if (row && this.element.contains(row)) row.remove();
    }

    renameProperty(event) {
        const row = event.currentTarget.closest('[data-property-row]');
        if (row) row.querySelector('[data-property-label]').textContent = event.currentTarget.value || 'New property';
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
}