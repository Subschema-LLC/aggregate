import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        this.restoreDraftIfAvailable();

        const forms = this.element.querySelectorAll('form');
        forms.forEach(form => {
            form.addEventListener('submit', () => {
                const actionBtn = form.querySelector('button[type="submit"]:focus') || form.querySelector('button[type="submit"]');
                const action = actionBtn ? actionBtn.value : '';
                if (action === 'reset') {
                    this.clearDraft();
                } else {
                    setTimeout(() => this.clearDraft(), 2000);
                }
            });
        });
    }

    saveDraft() {
        try {
            const drafts = {};
            const inputs = this.element.querySelectorAll('input[name], textarea[name]');
            inputs.forEach(input => {
                if (input.name === '_csrf_token' || input.type === 'hidden') return;
                const form = input.closest('form');
                const code = form ? form.querySelector('input[name="code"]')?.value : '';
                const subject = form ? form.querySelector('input[name="subject"]')?.value : '';
                const key = subject && code ? subject + '_' + code + '_' + input.name : input.name;
                if (input.value) {
                    drafts[key] = input.value;
                }
            });

            sessionStorage.setItem('aggregate_bi_glossary_draft_v1', JSON.stringify({
                drafts: drafts,
                timestamp: Date.now()
            }));
        } catch (e) {}
    }

    restoreDraftIfAvailable() {
        try {
            const raw = sessionStorage.getItem('aggregate_bi_glossary_draft_v1');
            if (!raw) return;
            const data = JSON.parse(raw);
            if (!data || !data.drafts || (Date.now() - data.timestamp) > 86400000) return;

            const inputs = this.element.querySelectorAll('input[name], textarea[name]');
            inputs.forEach(input => {
                if (input.name === '_csrf_token' || input.type === 'hidden') return;
                const form = input.closest('form');
                const code = form ? form.querySelector('input[name="code"]')?.value : '';
                const subject = form ? form.querySelector('input[name="subject"]')?.value : '';
                const key = subject && code ? subject + '_' + code + '_' + input.name : input.name;
                if (data.drafts[key] !== undefined && !input.value) {
                    input.value = data.drafts[key];
                    const details = input.closest('details');
                    if (details) details.open = true;
                    const parentBox = details && details.parentElement ? details.parentElement.closest('details') : null;
                    if (parentBox) parentBox.open = true;
                }
            });
        } catch (e) {}
    }

    clearDraft() {
        try {
            sessionStorage.removeItem('aggregate_bi_glossary_draft_v1');
        } catch (e) {}
    }
}
