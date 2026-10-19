import { Controller } from '@hotwired/stimulus';

// Collapses the main navigation behind a menu button on narrow screens. The
// full menu stays visible until this connects, so navigation works without it.
export default class extends Controller {
    static targets = ['toggle'];

    connect() {
        this.element.classList.add('is-collapsible');
        this.toggleTarget.hidden = false;
        const doc = this.element.ownerDocument;
        const win = doc?.defaultView;
        this.wide = win?.matchMedia ? win.matchMedia('(min-width: 1366px)') : null;
        this.boundWideChange = () => { if (this.wide?.matches) this.close(); };
        this.wide?.addEventListener('change', this.boundWideChange);
        this.boundOutsideClick = (event) => this.handleOutsideClick(event);
        doc?.addEventListener?.('click', this.boundOutsideClick);
        this.markActiveItem(win?.location?.pathname);
        this.setOpen(false);
    }

    disconnect() {
        this.wide?.removeEventListener('change', this.boundWideChange);
        this.element.ownerDocument?.removeEventListener?.('click', this.boundOutsideClick);
        this.element.classList.remove('is-collapsible', 'is-open');
        this.toggleTarget.hidden = true;
    }

    toggle() {
        this.setOpen(!this.element.classList.contains('is-open'));
    }

    close(event) {
        this.closeGroups();
        if (!this.element.classList.contains('is-open')) return;
        this.setOpen(false);
        // Return focus to the button only when Escape was pressed inside the menu.
        if (event?.type === 'keydown') this.toggleTarget.focus();
    }

    closeGroups(except = null) {
        if (typeof this.element.querySelectorAll !== 'function') return;
        this.element.querySelectorAll('details.app-navigation-group[open]').forEach((group) => {
            if (group !== except) group.open = false;
        });
    }

    handleOutsideClick(event) {
        const target = event?.target;
        if (!target || typeof target.closest !== 'function') return;
        const openGroup = target.closest('details.app-navigation-group');
        this.closeGroups(openGroup);
    }

    markActiveItem(pathname) {
        if (!pathname || typeof this.element.querySelectorAll !== 'function') return;
        this.element.querySelectorAll('.app-navigation-items a.navbar-item[href]').forEach((link) => {
            const href = link.getAttribute ? link.getAttribute('href') : link.href;
            if (!href || href === '/') return;
            const cleanHref = href.split('?')[0].split('#')[0];
            if (cleanHref === pathname) {
                link.classList?.add('is-active');
                link.setAttribute?.('aria-current', 'page');
                const group = link.closest ? link.closest('details.app-navigation-group') : null;
                group?.classList?.add('is-active');
            }
        });
    }

    setOpen(open) {
        this.element.classList.toggle('is-open', open);
        this.toggleTarget.setAttribute('aria-expanded', String(open));
    }
}
