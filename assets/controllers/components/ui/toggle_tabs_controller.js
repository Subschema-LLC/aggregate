import { Controller } from '@hotwired/stimulus';

/**
 * Nested in-page toggle tabs (.tabs.is-toggle.is-fullwidth) that switch
 * between associated tabpanels without leaving the current form or page.
 */
export default class extends Controller {
    connect() {
        this.syncFromHash();

        const view = this.view();
        if (view && typeof view.addEventListener === 'function') {
            this.boundHashChange = () => this.syncFromHash();
            view.addEventListener('hashchange', this.boundHashChange);
        }

        const scope = this.scopeRoot();
        if (scope && typeof scope.addEventListener === 'function') {
            this.boundInvalid = (event) => this.revealInvalid(event);
            scope.addEventListener('invalid', this.boundInvalid, true);
        }
    }

    disconnect() {
        const view = this.view();
        if (view && this.boundHashChange && typeof view.removeEventListener === 'function') {
            view.removeEventListener('hashchange', this.boundHashChange);
        }

        const scope = this.scopeRoot();
        if (scope && this.boundInvalid && typeof scope.removeEventListener === 'function') {
            scope.removeEventListener('invalid', this.boundInvalid, true);
        }
    }

    switchTab(event) {
        if (event && typeof event.preventDefault === 'function') {
            event.preventDefault();
        }
        const target = event?.currentTarget ?? event?.target ?? null;
        const link = target && typeof target.closest === 'function' ? target.closest('[data-tab-name], a') : target;
        const tabName = this.tabNameFromLink(link);
        if (tabName) {
            this.activateTab(tabName);
        }
    }

    onKeydown(event) {
        if (!event || !['ArrowRight', 'ArrowLeft', 'Home', 'End'].includes(event.key)) return;
        const tabs = this.tabs();
        if (tabs.length === 0) return;

        const currentLink = event.currentTarget ?? event.target;
        const links = tabs.map((tab) => (typeof tab.querySelector === 'function' ? tab.querySelector('a') : null)).filter(Boolean);
        const currentIndex = links.indexOf(currentLink);
        if (currentIndex === -1) return;

        if (typeof event.preventDefault === 'function') event.preventDefault();
        let nextIndex = currentIndex;
        if (event.key === 'ArrowRight') nextIndex = (currentIndex + 1) % links.length;
        else if (event.key === 'ArrowLeft') nextIndex = (currentIndex - 1 + links.length) % links.length;
        else if (event.key === 'Home') nextIndex = 0;
        else if (event.key === 'End') nextIndex = links.length - 1;

        const nextLink = links[nextIndex];
        const tabName = this.tabNameFromLink(nextLink);
        if (tabName) {
            this.activateTab(tabName);
            nextLink.focus?.();
        }
    }

    activateTab(name, { updateHash = true } = {}) {
        const tabs = this.tabs();
        if (tabs.length === 0) return;

        const names = tabs.map((tab) => tab.dataset?.toggleTab || tab.dataset?.workspaceTab).filter(Boolean);
        if (!names.includes(name)) return;

        const doc = this.element?.ownerDocument ?? (typeof document !== 'undefined' ? document : null);
        const scope = this.scopeRoot();

        for (const tab of tabs) {
            const tabId = tab.dataset?.toggleTab || tab.dataset?.workspaceTab;
            const isActive = tabId === name;
            tab.classList?.toggle('is-active', isActive);
            const link = typeof tab.querySelector === 'function' ? tab.querySelector('a') : null;
            if (link && typeof link.setAttribute === 'function') {
                link.setAttribute('aria-selected', isActive ? 'true' : 'false');
            }
            const paneId = link && typeof link.getAttribute === 'function' ? link.getAttribute('aria-controls') : null;
            const pane = (paneId && doc && typeof doc.getElementById === 'function' ? doc.getElementById(paneId) : null)
                || (scope && typeof scope.querySelector === 'function' ? scope.querySelector(`[data-toggle-pane="${tabId}"], [data-pane-name="${tabId}"]`) : null);
            if (pane) {
                this.setPaneState(pane, isActive);
            }
        }

        if (updateHash) {
            const view = this.view();
            if (view && view.location && view.history && typeof view.history.replaceState === 'function') {
                view.history.replaceState(null, '', '#' + name);
            }
        }
    }

    revealInvalid(event) {
        const target = event?.target;
        if (!target || typeof target.closest !== 'function') return;
        const pane = target.closest('[data-toggle-pane], [data-pane-name]');
        if (!pane) return;
        const paneName = pane.dataset?.togglePane || pane.dataset?.paneName;
        if (paneName) {
            this.activateTab(paneName, { updateHash: false });
        }
    }

    syncFromHash() {
        const view = this.view();
        const rawHash = view?.location?.hash ? String(view.location.hash).replace(/^#/, '') : '';
        if (rawHash) {
            this.activateTab(rawHash, { updateHash: false });
        }
    }

    setPaneState(pane, isActive) {
        pane.classList?.toggle('is-active', isActive);
        pane.hidden = !isActive;
        if (isActive) {
            pane.removeAttribute?.('hidden');
        } else {
            pane.setAttribute?.('hidden', '');
        }
    }

    tabs() {
        if (!this.element || typeof this.element.querySelectorAll !== 'function') return [];
        return Array.from(this.element.querySelectorAll('[data-toggle-tab], [data-workspace-tab]'));
    }

    tabNameFromLink(link) {
        if (!link) return null;
        if (link.dataset?.tabName) return link.dataset.tabName;
        if (typeof link.getAttribute === 'function') {
            const href = link.getAttribute('href') || '';
            if (href.startsWith('#')) return href.slice(1);
        }
        return null;
    }

    scopeRoot() {
        if (!this.element) return null;
        if (typeof this.element.closest === 'function') {
            const closest = this.element.closest('[data-toggle-tabs-scope], form');
            if (closest) return closest;
        }
        return this.element.parentElement ?? this.element.ownerDocument ?? null;
    }

    view() {
        return this.element?.ownerDocument?.defaultView ?? (typeof window !== 'undefined' ? window : null);
    }
}
