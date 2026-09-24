import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['toggle', 'reset'];

    connect() {
        this.root = this.element.ownerDocument.documentElement;
        this.refresh();
        this.element.hidden = false;
    }

    disconnect() {
        this.element.hidden = true;
    }

    toggle() {
        this.setPreference(this.root.dataset.theme === 'dark' ? 'light' : 'dark');
    }

    reset() {
        this.setPreference(null);
    }

    setPreference(preference) {
        this.apply(preference);
        try {
            const storage = this.element.ownerDocument.defaultView.localStorage;
            const key = this.root.dataset.themeStorageKey;
            if (preference) storage.setItem(key, preference);
            else storage.removeItem(key);
        } catch {
            // Switching still works for this page when storage is unavailable.
        }
    }

    sync(event) {
        if (event.key !== null && event.key !== this.root.dataset.themeStorageKey) return;
        try {
            if (event.storageArea !== this.element.ownerDocument.defaultView.localStorage) return;
        } catch {
            return;
        }
        this.apply(event.newValue);
    }

    apply(preference) {
        if (preference === 'light' || preference === 'dark') {
            this.root.dataset.themePreference = preference;
            this.root.dataset.theme = preference;
        } else {
            delete this.root.dataset.themePreference;
            this.root.dataset.theme = this.root.dataset.themeDefault;
        }
        this.refresh();
    }

    refresh() {
        this.toggleTarget.setAttribute('aria-pressed', String(this.root.dataset.theme === 'dark'));
        this.resetTarget.hidden = !this.root.dataset.themePreference;
    }
}
