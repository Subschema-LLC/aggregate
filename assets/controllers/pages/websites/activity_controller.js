import { Controller } from '@hotwired/stimulus';

// Fills in each website's data reception status after the Websites page has
// rendered, so the page itself never waits on the events database. The server
// renders each badge and banner from the same Twig partials as the page.
export default class extends Controller {
    static values = { url: String };

    connect() {
        if (this.element.querySelector('[data-website-activity-badge]')) this.load();
    }

    async load() {
        let websites;
        try {
            const response = await fetch(this.urlValue, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store',
            });
            if (!response.ok) throw new Error('Website activity request failed');
            const body = await response.json();
            websites = body && typeof body.websites === 'object' && body.websites !== null ? body.websites : {};
        } catch (error) {
            this.unavailable();
            return;
        }

        const entry = (token) => (Object.prototype.hasOwnProperty.call(websites, token) ? websites[token] : null);
        this.element.querySelectorAll('[data-website-activity-badge]').forEach((badge) => {
            const status = entry(badge.dataset.websiteActivityBadge);
            if (status && typeof status.badge === 'string') badge.outerHTML = status.badge;
        });
        this.element.querySelectorAll('[data-website-activity-banner]').forEach((banner) => {
            const status = entry(banner.dataset.websiteActivityBanner);
            if (status && typeof status.banner === 'string') banner.outerHTML = status.banner;
        });
    }

    // Placeholders still checking say so instead of spinning on.
    unavailable() {
        this.element.querySelectorAll('[data-website-activity-badge].status-checking').forEach((badge) => {
            badge.classList.replace('status-checking', 'status-unavailable');
            const label = badge.querySelector('.activity-label');
            if (label) label.textContent = 'Status unavailable';
        });
    }
}
