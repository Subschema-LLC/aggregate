import { Controller } from '@hotwired/stimulus';

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>"']/g, m => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
    })[m]);
}

function highlightMatches(text, terms) {
    if (!text) return '';
    if (!terms || terms.length === 0) return escapeHtml(text);
    const escapedTerms = terms
        .map(t => t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))
        .filter(Boolean);
    if (!escapedTerms.length) return escapeHtml(text);
    const regex = new RegExp('(' + escapedTerms.join('|') + ')', 'gi');
    return escapeHtml(text).replace(regex, '<mark class="quick-search-highlight">$1</mark>');
}

export default class extends Controller {
    static targets = ['input', 'results'];
    static values = {
        items: Array
    };

    connect() {
        this.selectedIndex = -1;
        this.boundKeydown = this.handleGlobalKeydown.bind(this);
        this.boundOutsideClick = this.handleOutsideClick.bind(this);
        document.addEventListener('keydown', this.boundKeydown);
        document.addEventListener('click', this.boundOutsideClick);

        this.updatePlatformShortcut();
        this.updateClearButton();
    }

    disconnect() {
        document.removeEventListener('keydown', this.boundKeydown);
        document.removeEventListener('click', this.boundOutsideClick);
    }

    updatePlatformShortcut() {
        const isMac = /(Mac|iPhone|iPod|iPad)/i.test(
            (typeof navigator !== 'undefined' && (navigator.platform || navigator.userAgent)) || ''
        );
        const shortcutLabel = isMac ? '⌘K' : 'Ctrl+K';

        const kbd = this.element.querySelector('.quick-search-shortcut kbd');
        if (kbd) {
            kbd.textContent = shortcutLabel;
        }

        if (this.hasInputTarget) {
            this.inputTarget.setAttribute('placeholder', 'Search... (' + shortcutLabel + ')');
            this.inputTarget.setAttribute('aria-expanded', 'false');
        }
    }

    handleGlobalKeydown(event) {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            this.inputTarget.focus();
            this.show();
        } else if (event.key === '/' && !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName)) {
            event.preventDefault();
            this.inputTarget.focus();
            this.show();
        }
    }

    handleOutsideClick(event) {
        if (!this.element.contains(event.target)) {
            this.hide();
        }
    }

    show() {
        this.filter();
        this.resultsTarget.hidden = false;
        if (this.hasInputTarget) {
            this.inputTarget.setAttribute('aria-expanded', 'true');
        }
    }

    hide() {
        this.resultsTarget.hidden = true;
        this.selectedIndex = -1;
        if (this.hasInputTarget) {
            this.inputTarget.setAttribute('aria-expanded', 'false');
            this.inputTarget.removeAttribute('aria-activedescendant');
        }
    }

    clear() {
        if (this.hasInputTarget) {
            this.inputTarget.value = '';
            this.inputTarget.focus();
            this.filter();
        }
    }

    updateClearButton() {
        const clearBtn = this.element.querySelector('.quick-search-clear');
        const shortcut = this.element.querySelector('.quick-search-shortcut');
        const hasText = Boolean(this.hasInputTarget && this.inputTarget.value && this.inputTarget.value.trim().length > 0);

        if (clearBtn) {
            clearBtn.hidden = !hasText;
            if (hasText) {
                clearBtn.removeAttribute('hidden');
                clearBtn.style.display = 'inline-flex';
            } else {
                clearBtn.setAttribute('hidden', '');
                clearBtn.style.display = 'none';
            }
        }
        if (shortcut) {
            shortcut.hidden = hasText;
            if (hasText) {
                shortcut.setAttribute('hidden', '');
                shortcut.style.display = 'none';
            } else {
                shortcut.removeAttribute('hidden');
                shortcut.style.display = 'inline-flex';
            }
        }
    }

    filter() {
        this.updateClearButton();
        const rawQuery = this.inputTarget.value.trim();
        const query = rawQuery.toLowerCase();
        const items = this.hasItemsValue ? this.itemsValue : [];

        let matches;
        let terms = [];

        if (!query) {
            matches = items.slice(0, 8);
        } else {
            terms = query.split(/\s+/).filter(Boolean);

            const scoredMatches = [];
            for (const item of items) {
                const title = (item.title || '').toLowerCase();
                const category = (item.category || '').toLowerCase();
                const desc = (item.description || '').toLowerCase();
                const keywords = (item.keywords || '').toLowerCase();

                const allTermsMatch = terms.every(
                    term => title.includes(term) || category.includes(term) || desc.includes(term) || keywords.includes(term)
                );

                if (allTermsMatch) {
                    let score = 0;
                    if (title === query) score += 120;
                    else if (title.startsWith(query)) score += 80;
                    else if (title.includes(query)) score += 50;

                    if (category.includes(query)) score += 30;
                    if (desc.includes(query)) score += 20;

                    for (const term of terms) {
                        if (title.includes(term)) score += 25;
                        if (category.includes(term)) score += 15;
                        if (desc.includes(term)) score += 10;
                        if (keywords.includes(term)) score += 8;
                    }

                    scoredMatches.push({ item, score });
                }
            }

            scoredMatches.sort((a, b) => b.score - a.score);
            matches = scoredMatches.slice(0, 10).map(entry => entry.item);
        }

        this.renderResults(matches, rawQuery, terms);

        if (query && matches.length > 0) {
            this.setSelectedIndex(0);
        } else {
            this.selectedIndex = -1;
        }
    }

    renderResults(matches, query, terms = []) {
        this.resultsTarget.replaceChildren();

        if (matches.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'quick-search-empty';

            const emptyIcon = document.createElement('div');
            emptyIcon.className = 'quick-search-empty-icon';
            const icon = document.createElement('i');
            icon.className = 'fas fa-magnifying-glass';
            icon.setAttribute('aria-hidden', 'true');
            emptyIcon.appendChild(icon);

            const emptyTitle = document.createElement('strong');
            emptyTitle.className = 'quick-search-empty-title';
            emptyTitle.textContent = 'No matching results';

            const emptyDesc = document.createElement('span');
            emptyDesc.className = 'quick-search-empty-desc';
            emptyDesc.textContent = query
                ? 'No results found for "' + query + '". Try searching for settings or documentation.'
                : 'No search results available.';

            empty.appendChild(emptyIcon);
            empty.appendChild(emptyTitle);
            empty.appendChild(emptyDesc);

            this.resultsTarget.appendChild(empty);
            this.resultsTarget.hidden = false;
            return;
        }

        const listContainer = document.createElement('div');
        listContainer.className = 'quick-search-list';
        listContainer.setAttribute('role', 'listbox');

        matches.forEach((item, index) => {
            const link = document.createElement('a');
            link.href = item.url;
            link.className = 'quick-search-item';
            link.setAttribute('role', 'option');
            link.setAttribute('id', 'quick-search-opt-' + index);
            link.setAttribute('aria-selected', 'false');
            link.dataset.index = index;

            const iconSpan = document.createElement('span');
            iconSpan.className = 'quick-search-item-icon';
            const icon = document.createElement('i');
            icon.className = item.icon || 'fas fa-arrow-right';
            icon.setAttribute('aria-hidden', 'true');
            iconSpan.appendChild(icon);

            const contentDiv = document.createElement('div');
            contentDiv.className = 'quick-search-item-content';

            const headerDiv = document.createElement('div');
            headerDiv.className = 'quick-search-item-header';

            const titleSpan = document.createElement('strong');
            titleSpan.className = 'quick-search-item-title';
            titleSpan.innerHTML = highlightMatches(item.title, terms);

            const metaDiv = document.createElement('div');
            metaDiv.className = 'quick-search-item-meta';

            if (item.status) {
                const statusPill = document.createElement('span');
                statusPill.className = 'quick-search-item-status status-' + item.status;
                const statusTitle = (item.status_label || item.status) + (item.status_time ? ' · ' + item.status_time : '');
                statusPill.setAttribute('title', statusTitle);

                const dot = document.createElement('span');
                dot.className = 'quick-search-status-dot';
                dot.setAttribute('aria-hidden', 'true');
                statusPill.appendChild(dot);

                const statusLabel = document.createElement('span');
                statusLabel.className = 'quick-search-status-text';
                statusLabel.textContent = item.status_label || item.status;
                statusPill.appendChild(statusLabel);

                metaDiv.appendChild(statusPill);
            }

            const catSpan = document.createElement('span');
            catSpan.className = 'tag is-small quick-search-item-category';
            catSpan.textContent = item.category;
            metaDiv.appendChild(catSpan);

            headerDiv.appendChild(titleSpan);
            headerDiv.appendChild(metaDiv);

            const descDiv = document.createElement('div');
            descDiv.className = 'quick-search-item-desc';
            descDiv.innerHTML = highlightMatches(item.description, terms);

            contentDiv.appendChild(headerDiv);
            contentDiv.appendChild(descDiv);

            const actionSpan = document.createElement('span');
            actionSpan.className = 'quick-search-item-action';
            actionSpan.setAttribute('aria-hidden', 'true');
            const actionIcon = document.createElement('i');
            actionIcon.className = 'fas fa-chevron-right';
            actionSpan.appendChild(actionIcon);

            link.appendChild(iconSpan);
            link.appendChild(contentDiv);
            link.appendChild(actionSpan);

            link.addEventListener('mouseenter', () => {
                this.setSelectedIndex(index);
            });

            listContainer.appendChild(link);
        });

        this.resultsTarget.appendChild(listContainer);

        const footer = document.createElement('div');
        footer.className = 'quick-search-footer';
        footer.innerHTML = `
            <div class="quick-search-footer-item"><kbd>↑</kbd><kbd>↓</kbd> <span>Navigate</span></div>
            <div class="quick-search-footer-item"><kbd>↵</kbd> <span>Select</span></div>
            <div class="quick-search-footer-item"><kbd>ESC</kbd> <span>Close</span></div>
        `;
        this.resultsTarget.appendChild(footer);

        this.resultsTarget.hidden = false;
    }

    navigate(event) {
        const items = this.resultsTarget.querySelectorAll('.quick-search-item');
        if (items.length === 0 && event.key !== 'Escape') return;

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            const nextIndex = this.selectedIndex < 0 ? 0 : (this.selectedIndex + 1) % items.length;
            this.setSelectedIndex(nextIndex);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            const prevIndex = this.selectedIndex <= 0 ? items.length - 1 : this.selectedIndex - 1;
            this.setSelectedIndex(prevIndex);
        } else if (event.key === 'Enter') {
            event.preventDefault();
            const targetIndex = this.selectedIndex >= 0 ? this.selectedIndex : 0;
            if (items[targetIndex]) {
                items[targetIndex].click();
            }
        } else if (event.key === 'Escape') {
            event.preventDefault();
            if (this.inputTarget.value.trim().length > 0) {
                this.clear();
            } else {
                this.hide();
                this.inputTarget.blur();
            }
        }
    }

    setSelectedIndex(index) {
        const items = this.resultsTarget.querySelectorAll('.quick-search-item');
        items.forEach((item, idx) => {
            if (idx === index) {
                item.classList.add('is-active');
                item.setAttribute('aria-selected', 'true');
                item.scrollIntoView({ block: 'nearest' });
            } else {
                item.classList.remove('is-active');
                item.setAttribute('aria-selected', 'false');
            }
        });
        this.selectedIndex = index;

        if (this.hasInputTarget) {
            if (index >= 0 && items[index]) {
                this.inputTarget.setAttribute('aria-activedescendant', items[index].id);
            } else {
                this.inputTarget.removeAttribute('aria-activedescendant');
            }
        }
    }
}
