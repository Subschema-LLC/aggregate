import { Controller } from '@hotwired/stimulus';

// BigQuery sync page: shows the fields of the chosen sign-in method, asks for
// confirmation only when a private view is newly selected, and reloads the
// status table when a running sync finishes. After “Sync now” (since), it
// waits for the background run to start too. Without JavaScript every panel
// stays visible and the server still requires the confirmation.
export default class extends Controller {
    static targets = ['auth', 'panel', 'private', 'confirmation'];
    static values = { statusUrl: String, running: Boolean, since: Number };

    connect() {
        this.showAuth();
        this.togglePrivate();
        if (this.runningValue) this.poll();
    }

    disconnect() {
        if (this.timer) clearTimeout(this.timer);
    }

    showAuth() {
        const selected = this.authTargets.find((input) => input.checked);
        if (!selected) return;
        this.authTargets.forEach((input) => input.closest('label')?.classList.toggle('is-selected', input === selected));
        this.panelTargets.forEach((panel) => {
            panel.hidden = panel.dataset.auth !== selected.value;
        });
    }

    togglePrivate() {
        if (!this.hasConfirmationTarget) return;
        const added = this.privateTargets.some((input) => input.checked && input.dataset.saved !== '1');
        this.confirmationTarget.hidden = !added;
        const checkbox = this.confirmationTarget.querySelector('input');
        if (checkbox) checkbox.required = added;
    }

    poll(attempt = 0) {
        if (attempt >= 120) return;
        this.timer = setTimeout(async () => {
            try {
                const response = await fetch(this.statusUrlValue, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    cache: 'no-store',
                });
                const body = response.ok ? await response.json() : null;
                const started = !this.sinceValue || (typeof body?.last_run === 'number' && body.last_run >= this.sinceValue);
                if (body && body.running === false && started) {
                    window.location.reload();
                    return;
                }
            } catch (error) {
                // Keep the last status; try again shortly.
            }
            this.poll(attempt + 1);
        }, 5000);
    }
}
