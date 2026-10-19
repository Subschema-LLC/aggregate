import { Controller } from '@hotwired/stimulus';

/**
 * Makes cards (.box, .card, details.box, details.card, etc.) collapsible across
 * every page and wires top-of-page "Expand all" and "Collapse all" controls.
 */
export default class extends Controller {
    connect() {
        this.enhanceCards();
        this.deduplicateToolbars();
    }

    enhanceCards() {
        if (!this.element || typeof this.element.querySelectorAll !== 'function') return;

        const candidates = this.element.querySelectorAll('.box, .card');
        candidates.forEach((card) => {
            if (!this.shouldEnhance(card)) return;
            const header = this.findHeader(card);
            if (!header) return;

            card.classList.add('is-collapsible-card');
            header.classList.add('collapsible-card-header');
            if (!header.hasAttribute('role')) {
                header.setAttribute('role', 'button');
            }
            if (!header.hasAttribute('tabindex')) {
                header.setAttribute('tabindex', '0');
            }

            const startCollapsed = card.dataset?.collapsed === 'true' || card.classList.contains('is-collapsed');
            card.classList.toggle('is-collapsed', startCollapsed);
            header.setAttribute('aria-expanded', startCollapsed ? 'false' : 'true');

            if (!header.querySelector || !header.querySelector('.collapsible-card-chevron')) {
                const doc = this.element.ownerDocument;
                if (doc && typeof doc.createElement === 'function' && typeof header.appendChild === 'function') {
                    const chevron = doc.createElement('span');
                    chevron.className = 'collapsible-card-chevron';
                    chevron.setAttribute('aria-hidden', 'true');
                    header.appendChild(chevron);
                }
            }

            if (!header.dataset?.collapsibleBound) {
                if (header.dataset) header.dataset.collapsibleBound = '1';
                if (typeof header.addEventListener === 'function') {
                    header.addEventListener('click', (event) => {
                        if (this.isInteractiveTarget(event.target, header)) return;
                        this.toggleCard(card, header);
                    });
                    header.addEventListener('keydown', (event) => {
                        if (this.isInteractiveTarget(event.target, header)) return;
                        if (event.key === 'Enter' || event.key === ' ') {
                            if (typeof event.preventDefault === 'function') event.preventDefault();
                            this.toggleCard(card, header);
                        }
                    });
                }
            }
        });
    }

    shouldEnhance(card) {
        if (!card || !card.tagName) return false;
        const tag = String(card.tagName).toLowerCase();
        if (tag === 'details' || tag === 'label') return false;
        if (card.dataset?.noCollapse !== undefined) return false;
        if (card.classList?.contains('is-collapsible-card')) return false;
        if (card.classList?.contains('bigquery-auth-option')) return false;
        return true;
    }

    findHeader(card) {
        if (!card || typeof card.querySelector !== 'function') return null;

        // Direct card-header for Bulma .card elements
        const cardHeader = Array.from(card.children || []).find(
            (child) => child.classList && child.classList.contains('card-header')
        );
        if (cardHeader) return cardHeader;

        // Direct heading or .level containing a heading
        const children = Array.from(card.children || []);
        for (const child of children) {
            const tag = String(child.tagName || '').toLowerCase();
            if (['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'legend'].includes(tag)) {
                return child;
            }
            if (child.classList && (child.classList.contains('title') || child.classList.contains('level'))) {
                if (child.classList.contains('title') || (child.querySelector && child.querySelector('h1, h2, h3, h4, h5, h6, .title'))) {
                    return child;
                }
            }
            if (tag === 'fieldset') {
                const legend = Array.from(child.children || []).find(
                    (c) => String(c.tagName || '').toLowerCase() === 'legend' || c.classList?.contains('title')
                );
                if (legend) return legend;
            }
        }

        // First heading inside .card-content or first column
        const nestedHeading = card.querySelector('h1, h2, h3, h4, .title');
        return nestedHeading || null;
    }

    isInteractiveTarget(target, header) {
        if (!target || target === header || typeof target.closest !== 'function') return false;
        const interactive = target.closest('a, button, input, select, textarea, label');
        return Boolean(interactive && interactive !== header);
    }

    toggleCard(card, header) {
        const collapsed = !card.classList.contains('is-collapsed');
        this.setCardState(card, !collapsed, header);
    }

    setCardState(card, expanded, header = null) {
        card.classList.toggle('is-collapsed', !expanded);
        const h = header || (typeof card.querySelector === 'function' ? card.querySelector('.collapsible-card-header') : null);
        if (h && typeof h.setAttribute === 'function') {
            h.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        }
    }

    deduplicateToolbars() {
        if (!this.element || typeof this.element.querySelectorAll !== 'function') return;
        const toolbars = Array.from(this.element.querySelectorAll('[data-card-controls]'));
        if (toolbars.length > 1) {
            toolbars[0].hidden = true;
        }
    }

    expandAll() {
        this.setAll(true);
    }

    collapseAll() {
        this.setAll(false);
    }

    setAll(expanded) {
        if (!this.element || typeof this.element.querySelectorAll !== 'function') return;

        const detailsList = this.element.querySelectorAll(
            'details.box, details.card, details.account-card, details.data-structure-section, details[data-property-row], details.tag-row, details.glossary-entry, details.message'
        );
        detailsList.forEach((details) => {
            if (details.hidden) return;
            details.open = expanded;
        });

        const enhancedCards = this.element.querySelectorAll('.is-collapsible-card');
        enhancedCards.forEach((card) => {
            if (card.hidden) return;
            this.setCardState(card, expanded);
        });
    }

    revealInvalid(event) {
        const target = event?.target;
        if (!target || typeof target.closest !== 'function') return;

        let details = target.closest('details');
        while (details && (!this.element.contains || this.element.contains(details))) {
            details.open = true;
            details = details.parentElement?.closest ? details.parentElement.closest('details') : null;
        }

        let card = target.closest('.is-collapsible-card');
        while (card && (!this.element.contains || this.element.contains(card))) {
            this.setCardState(card, true);
            card = card.parentElement?.closest ? card.parentElement.closest('.is-collapsible-card') : null;
        }
    }
}
