import { Controller } from '@hotwired/stimulus';

const STORAGE_KEY = 'aggregate_selected_scope';
const COOKIE_SCOPE = 'aggregate_scope';
const COOKIE_SITE = 'aggregate_site';
const COOKIE_TOKEN = 'aggregate_token';

/**
 * Global website/group selector:
 * - Persists chosen scope in localStorage and cookies (no DB storage)
 * - Expands matching website and group cards across views without hiding other items
 * - Sorts matching sites and groups to the top of list views
 * - Keeps in-page feature switchers (Tag Manager, Consent Manager, Data Model, Collection) in sync
 */
export default class extends Controller {
    connect() {
        this.selectElement = this.element.tagName === 'SELECT' ? this.element : this.element.querySelector('select');
        if (!this.selectElement) return;

        this.initOriginalOrder();
        this.bindInPageSwitchers();
        this.restoreSelection();
    }

    initOriginalOrder() {
        const root = this.element.ownerDocument || document;
        const websiteCards = root.querySelectorAll('[data-website-card]');
        websiteCards.forEach((card, index) => {
            if (card.dataset && !card.dataset.originalIndex) {
                card.dataset.originalIndex = String(index);
            }
        });

        const groupCards = root.querySelectorAll('[data-group-card]');
        groupCards.forEach((card, index) => {
            if (card.dataset && !card.dataset.originalIndex) {
                card.dataset.originalIndex = String(index);
            }
        });
    }

    bindInPageSwitchers() {
        const root = this.element.ownerDocument || document;

        // Tag Manager
        const tagSelect = root.querySelector('#tag-manager-site');
        if (tagSelect && !tagSelect.dataset?.globalSyncBound) {
            if (tagSelect.dataset) tagSelect.dataset.globalSyncBound = '1';
            tagSelect.addEventListener?.('change', () => {
                const siteId = tagSelect.value;
                const scopeVal = siteId ? `website:${siteId}` : 'all';
                this.saveScope(scopeVal);
                this.syncAllSelects(scopeVal);
            });
        }

        // Consent Manager
        const consentSelect = root.querySelector('#consent-manager-site');
        if (consentSelect && !consentSelect.dataset?.globalSyncBound) {
            if (consentSelect.dataset) consentSelect.dataset.globalSyncBound = '1';
            consentSelect.addEventListener?.('change', () => {
                const siteId = consentSelect.value;
                const scopeVal = siteId ? `website:${siteId}` : 'all';
                this.saveScope(scopeVal);
                this.syncAllSelects(scopeVal);
            });
        }

        // Data Model / Collection Settings
        const scopeSelect = root.querySelector('#website-scope');
        if (scopeSelect && !scopeSelect.dataset?.globalSyncBound) {
            if (scopeSelect.dataset) scopeSelect.dataset.globalSyncBound = '1';
            scopeSelect.addEventListener?.('change', () => {
                const val = scopeSelect.value;
                const scopeVal = val === 'deployment' ? 'all' : val;
                this.saveScope(scopeVal);
                this.syncAllSelects(scopeVal);
                scopeSelect.form?.submit();
            });
        }
    }

    restoreSelection() {
        const root = this.element.ownerDocument || document;

        // If an in-page feature switcher is already present and active, it reflects current page view
        const tagSelect = root.querySelector('#tag-manager-site');
        const consentSelect = root.querySelector('#consent-manager-site');
        const scopeSelect = root.querySelector('#website-scope');

        let activeScope = null;
        if (tagSelect && tagSelect.value) {
            activeScope = `website:${tagSelect.value}`;
        } else if (consentSelect && consentSelect.value) {
            activeScope = `website:${consentSelect.value}`;
        } else if (scopeSelect && scopeSelect.value && scopeSelect.value !== 'deployment') {
            activeScope = scopeSelect.value;
        }

        const savedScope = activeScope || this.getStoredScope();
        if (savedScope) {
            const hasOption = Array.from(this.selectElement.options || []).some((opt) => opt.value === savedScope);
            if (hasOption) {
                this.selectElement.value = savedScope;
                this.saveScope(savedScope);
                this.syncAllSelects(savedScope);
                this.applyScope(savedScope, false);
                return;
            }
        }

        const currentVal = this.selectElement.value || 'all';
        this.applyScope(currentVal, false);
    }

    change(event) {
        const val = event?.target?.value || this.selectElement.value || 'all';
        this.saveScope(val);
        this.syncAllSelects(val);
        this.applyScope(val, true);
    }

    getStoredScope() {
        try {
            if (typeof localStorage !== 'undefined' && localStorage) {
                return localStorage.getItem(STORAGE_KEY);
            }
        } catch (_) {}
        return null;
    }

    saveScope(val) {
        try {
            if (typeof localStorage !== 'undefined' && localStorage) {
                localStorage.setItem(STORAGE_KEY, val);
            }
        } catch (_) {}

        const selectedOption = this.selectElement ? this.selectElement.querySelector(`option[value="${val}"]`) : null;
        const siteId = selectedOption?.dataset?.siteId || (val.startsWith('website:') ? val.slice(8) : '');
        const token = selectedOption?.dataset?.token || '';

        const scopeCookieValue = val === 'all' ? 'deployment' : val;
        this.setCookie(COOKIE_SCOPE, scopeCookieValue);
        this.setCookie(COOKIE_SITE, siteId);
        this.setCookie(COOKIE_TOKEN, token);
    }

    setCookie(name, value) {
        const maxAge = value ? 31536000 : 0;
        const doc = this.element.ownerDocument || document;
        if (doc && typeof doc === 'object') {
            doc.cookie = `${name}=${encodeURIComponent(value)}; path=/; max-age=${maxAge}; SameSite=Lax`;
        }
    }

    syncAllSelects(val) {
        if (this.selectElement && this.selectElement.value !== val) {
            this.selectElement.value = val;
        }
        const root = this.element.ownerDocument || document;
        const selects = root.querySelectorAll('.global-website-select');
        selects.forEach((sel) => {
            if (sel.value !== val) {
                sel.value = val;
            }
        });
    }

    applyScope(val, isUserAction) {
        const root = this.element.ownerDocument || document;

        // 1. Apply to Website cards
        const websiteCards = Array.from(root.querySelectorAll('[data-website-card]'));
        if (websiteCards.length > 0) {
            this.applyToWebsiteCards(websiteCards, val);
        }

        // 2. Apply to Website Group cards
        const groupCards = Array.from(root.querySelectorAll('[data-group-card]'));
        if (groupCards.length > 0) {
            this.applyToGroupCards(groupCards, val);
        }

        // 3. If triggered by direct user action and on a feature page, sync in-page view
        if (isUserAction) {
            this.handleInPageNavigation(val);
        }
    }

    applyToWebsiteCards(cards, val) {
        const selectedOption = this.selectElement?.querySelector?.(`option[value="${val}"]`);
        const targetSiteId = selectedOption?.dataset?.siteId || (val.startsWith('website:') ? val.slice(8) : null);
        const targetToken = selectedOption?.dataset?.token || null;
        const targetGroupId = selectedOption?.dataset?.groupId || (val.startsWith('group:') ? val.slice(6) : null);

        const container = cards[0]?.parentElement;

        if (val === 'all' || !val) {
            cards.sort((a, b) => Number(a.dataset?.originalIndex || 0) - Number(b.dataset?.originalIndex || 0));
            cards.forEach((card) => {
                card.classList?.remove('is-scope-matched');
                const badge = card.querySelector?.('[data-scope-matched-badge]');
                if (badge) badge.hidden = true;
                if (container && card.parentElement === container) {
                    container.appendChild(card);
                }
            });
            return;
        }

        const matching = [];
        const nonMatching = [];

        cards.forEach((card) => {
            const cardToken = card.dataset?.websiteToken;
            const cardSiteId = card.dataset?.websiteId;
            const cardGroup = card.dataset?.websiteGroup;

            let isMatch = false;
            if (val.startsWith('website:')) {
                isMatch = (targetSiteId && cardSiteId === targetSiteId) || (targetToken && cardToken === targetToken);
            } else if (val.startsWith('group:')) {
                isMatch = Boolean(targetGroupId && cardGroup === targetGroupId);
            }

            const badge = card.querySelector?.('[data-scope-matched-badge]');

            if (isMatch) {
                card.open = true;
                card.classList?.add('is-scope-matched');
                if (badge) badge.hidden = false;
                matching.push(card);
            } else {
                card.open = false;
                card.classList?.remove('is-scope-matched');
                if (badge) badge.hidden = true;
                nonMatching.push(card);
            }
        });

        // Arrange selected sites / groups on top, keeping relative original order
        matching.sort((a, b) => Number(a.dataset?.originalIndex || 0) - Number(b.dataset?.originalIndex || 0));
        nonMatching.sort((a, b) => Number(a.dataset?.originalIndex || 0) - Number(b.dataset?.originalIndex || 0));

        if (container) {
            matching.forEach((card) => container.appendChild(card));
            nonMatching.forEach((card) => container.appendChild(card));
        }
    }

    applyToGroupCards(groupCards, val) {
        const selectedOption = this.selectElement?.querySelector?.(`option[value="${val}"]`);
        const targetGroupId = val.startsWith('group:')
            ? val.slice(6)
            : (selectedOption?.dataset?.groupId || null);

        const container = groupCards[0]?.parentElement;

        if (val === 'all' || !targetGroupId) {
            groupCards.sort((a, b) => Number(a.dataset?.originalIndex || 0) - Number(b.dataset?.originalIndex || 0));
            groupCards.forEach((card) => {
                card.classList?.remove('is-scope-matched');
                const badge = card.querySelector?.('[data-scope-matched-badge]');
                if (badge) badge.hidden = true;
                if (container && card.parentElement === container) {
                    container.appendChild(card);
                }
            });
            return;
        }

        const matching = [];
        const nonMatching = [];

        groupCards.forEach((card) => {
            const isMatch = card.dataset?.groupCard === targetGroupId;
            const badge = card.querySelector?.('[data-scope-matched-badge]');

            if (isMatch) {
                card.classList?.add('is-scope-matched');
                if (badge) badge.hidden = false;
                if (card.classList?.contains('is-collapsible-card')) {
                    card.classList.remove('is-collapsed');
                    const header = card.querySelector?.('.collapsible-card-header');
                    if (header) header.setAttribute('aria-expanded', 'true');
                } else if ('open' in card) {
                    card.open = true;
                }
                matching.push(card);
            } else {
                card.classList?.remove('is-scope-matched');
                if (badge) badge.hidden = true;
                nonMatching.push(card);
            }
        });

        matching.sort((a, b) => Number(a.dataset?.originalIndex || 0) - Number(b.dataset?.originalIndex || 0));
        nonMatching.sort((a, b) => Number(a.dataset?.originalIndex || 0) - Number(b.dataset?.originalIndex || 0));

        if (container) {
            matching.forEach((card) => container.appendChild(card));
            nonMatching.forEach((card) => container.appendChild(card));
        }
    }

    handleInPageNavigation(val) {
        const root = this.element.ownerDocument || document;

        // Tag Manager
        const tagSelect = root.querySelector('#tag-manager-site');
        if (tagSelect) {
            const selectedOption = this.selectElement?.querySelector?.(`option[value="${val}"]`);
            const targetSiteId = selectedOption?.dataset?.siteId || (val.startsWith('website:') ? val.slice(8) : '');
            if (targetSiteId && tagSelect.value !== targetSiteId) {
                tagSelect.value = targetSiteId;
                tagSelect.form?.submit();
                return;
            }
        }

        // Consent Manager
        const consentSelect = root.querySelector('#consent-manager-site');
        if (consentSelect) {
            const selectedOption = this.selectElement?.querySelector?.(`option[value="${val}"]`);
            const targetSiteId = selectedOption?.dataset?.siteId || (val.startsWith('website:') ? val.slice(8) : '');
            if (targetSiteId && consentSelect.value !== targetSiteId) {
                consentSelect.value = targetSiteId;
                consentSelect.form?.submit();
                return;
            }
        }

        // Data Model / Collection Settings
        const scopeSelect = root.querySelector('#website-scope');
        if (scopeSelect) {
            const targetScope = val === 'all' ? 'deployment' : val;
            if (scopeSelect.value !== targetScope) {
                scopeSelect.value = targetScope;
                scopeSelect.form?.submit();
            }
        }
    }
}
