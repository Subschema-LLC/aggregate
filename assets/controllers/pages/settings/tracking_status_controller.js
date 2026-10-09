import { Controller } from '@hotwired/stimulus';

// Fills in the tracking failure status after the Collection controls page has
// rendered, so the page itself (and its kill switch) never waits on the
// database. The server renders the status from a Twig partial.
export default class extends Controller {
    static values = { url: String };

    connect() {
        this.load();
    }

    async load() {
        let html;
        try {
            const response = await fetch(this.urlValue, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store',
            });
            if (!response.ok) throw new Error('Tracking failure status request failed');
            const body = await response.json();
            html = body && typeof body.html === 'string' ? body.html : null;
        } catch (error) {
            html = null;
        }

        if (html === null) {
            this.unavailable();
            return;
        }
        this.element.innerHTML = html;
    }

    unavailable() {
        const placeholder = this.element.querySelector('[data-tracking-status-placeholder]');
        if (placeholder) placeholder.textContent = 'Tracking failure status is unavailable; see the application log.';
    }
}
