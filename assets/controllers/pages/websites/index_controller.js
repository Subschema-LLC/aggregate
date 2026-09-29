import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['trigger', 'dialog'];

    connect() {
        this.returnFocus = null;
    }

    disconnect() {
        this.reset();
    }

    open() {
        if (this.dialogTarget.open) return;
        this.returnFocus = this.element.ownerDocument.activeElement;
        this.dialogTarget.showModal();
    }

    close() {
        this.dialogTarget.close();
    }

    closed() {
        if (this.returnFocus?.isConnected) this.returnFocus.focus();
        this.returnFocus = null;
    }

    reset() {
        this.returnFocus = null;
        if (this.dialogTarget.open) this.dialogTarget.close();
    }
    expandAll() {
        this.element.querySelectorAll('details.box:not(.mt-5)').forEach(d => {
            d.open = true;
        });
    }

    collapseAll() {
        this.element.querySelectorAll('details.box:not(.mt-5)').forEach(d => {
            d.open = false;
        });
    }

    filter(event) {
        const query = event.currentTarget.value.trim().toLowerCase();
        const cards = this.element.querySelectorAll('details.box:not(.mt-5)');
        const emptyState = this.element.querySelector('#websites-filter-empty');
        let visibleCount = 0;

        cards.forEach(card => {
            const text = card.textContent.toLowerCase();
            const match = !query || text.includes(query);
            card.hidden = !match;
            if (match) {
                visibleCount++;
                if (query) card.open = true;
            }
        });

        if (emptyState) {
            emptyState.hidden = visibleCount > 0 || cards.length === 0;
        }
    }
}