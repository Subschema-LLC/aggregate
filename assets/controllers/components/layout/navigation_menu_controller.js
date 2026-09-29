import { Controller } from '@hotwired/stimulus';

// Collapses the main navigation behind a menu button on narrow screens. The
// full menu stays visible until this connects, so navigation works without it.
export default class extends Controller {
    static targets = ['toggle'];

    connect() {
        this.element.classList.add('is-collapsible');
        this.toggleTarget.hidden = false;
        this.wide = this.element.ownerDocument.defaultView.matchMedia('(min-width: 1366px)');
        this.boundWideChange = () => { if (this.wide.matches) this.close(); };
        this.wide.addEventListener('change', this.boundWideChange);
        this.setOpen(false);
    }

    disconnect() {
        this.wide?.removeEventListener('change', this.boundWideChange);
        this.element.classList.remove('is-collapsible', 'is-open');
        this.toggleTarget.hidden = true;
    }

    toggle() {
        this.setOpen(!this.element.classList.contains('is-open'));
    }

    close(event) {
        if (!this.element.classList.contains('is-open')) return;
        this.setOpen(false);
        // Return focus to the button only when Escape was pressed inside the menu.
        if (event?.type === 'keydown') this.toggleTarget.focus();
    }

    setOpen(open) {
        this.element.classList.toggle('is-open', open);
        this.toggleTarget.setAttribute('aria-expanded', String(open));
    }
}
