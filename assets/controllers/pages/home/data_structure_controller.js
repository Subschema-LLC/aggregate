import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input', 'counter'];

    connect() {
        this.sections = this.element.querySelectorAll('details.data-structure-section');
    }

    filter() {
        const query = this.inputTarget.value.trim().toLowerCase();
        let totalMatches = 0;
        let matchedSections = 0;

        if (!query) {
            this.sections.forEach(section => {
                section.hidden = false;
                section.querySelectorAll('tbody tr').forEach(tr => {
                    tr.hidden = false;
                });
            });
            if (this.hasCounterTarget) {
                this.counterTarget.textContent = 'Showing all 8 sections';
            }
            return;
        }

        this.sections.forEach(section => {
            const rows = section.querySelectorAll('tbody tr');
            let sectionMatches = 0;

            if (rows.length > 0) {
                rows.forEach(tr => {
                    const text = tr.textContent.toLowerCase();
                    const match = text.includes(query);
                    tr.hidden = !match;
                    if (match) {
                        sectionMatches++;
                        totalMatches++;
                    }
                });
            } else {
                const text = section.textContent.toLowerCase();
                if (text.includes(query)) {
                    sectionMatches = 1;
                    totalMatches++;
                }
            }

            if (sectionMatches > 0) {
                section.hidden = false;
                section.open = true;
                matchedSections++;
            } else {
                section.hidden = true;
            }
        });

        if (this.hasCounterTarget) {
            this.counterTarget.textContent = totalMatches > 0
                ? (totalMatches + (totalMatches === 1 ? ' match' : ' matches') + ' in ' + matchedSections + (matchedSections === 1 ? ' section' : ' sections'))
                : 'No matches found';
        }
    }

    expandAll() {
        this.sections.forEach(section => {
            section.open = true;
        });
    }

    collapseAll() {
        this.sections.forEach(section => {
            section.open = false;
        });
    }

    clear() {
        this.inputTarget.value = '';
        this.filter();
        this.inputTarget.focus();
    }
}
