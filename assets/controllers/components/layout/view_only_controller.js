import { Controller } from '@hotwired/stimulus';

/*
 * On a page the signed-in user can only view, turns off the controls of every
 * form that would save a change (method="post"), including buttons outside a
 * form that submit one. Forms that only filter or choose (GET) keep working.
 * Presentation only: the server refuses the change either way.
 */
export default class extends Controller {
    connect() {
        this.disable();
        this.observer = new this.element.ownerDocument.defaultView.MutationObserver(() => this.disable());
        this.observer.observe(this.element, {childList: true, subtree: true});
    }

    disconnect() {
        this.observer?.disconnect();
    }

    disable() {
        for (const control of this.element.querySelectorAll('input, select, textarea, button')) {
            const form = control.form;
            if (!form || String(form.method).toLowerCase() !== 'post' || control.disabled) continue;
            if (form.hasAttribute('data-view-only-allowed')) continue;
            control.disabled = true;
            control.setAttribute('aria-disabled', 'true');
        }
    }
}
