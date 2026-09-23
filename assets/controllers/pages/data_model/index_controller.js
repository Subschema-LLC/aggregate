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
}
