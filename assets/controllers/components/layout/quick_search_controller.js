import { Controller } from '@hotwired/stimulus';

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
    }

    disconnect() {
        document.removeEventListener('keydown', this.boundKeydown);
        document.removeEventListener('click', this.boundOutsideClick);
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
    }

    hide() {
        this.resultsTarget.hidden = true;
        this.selectedIndex = -1;
    }

    filter() {
        const query = this.inputTarget.value.trim().toLowerCase();
        const items = this.hasItemsValue ? this.itemsValue : [];

        let matches;
        if (!query) {
            matches = items.slice(0, 8);
        } else {
            matches = items.filter(item => {
                const title = (item.title || '').toLowerCase();
                const category = (item.category || '').toLowerCase();
                const desc = (item.description || '').toLowerCase();
                const keywords = (item.keywords || '').toLowerCase();
                return title.includes(query) || category.includes(query) || desc.includes(query) || keywords.includes(query);
            }).slice(0, 10);
        }

        this.renderResults(matches, query);
    }

    renderResults(matches, query) {
        this.resultsTarget.replaceChildren();
        this.selectedIndex = -1;

        if (matches.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'quick-search-empty';
            empty.textContent = 'No results found for "' + query + '"';
            this.resultsTarget.appendChild(empty);
            this.resultsTarget.hidden = false;
            return;
        }

        matches.forEach((item, index) => {
            const link = document.createElement('a');
            link.href = item.url;
            link.className = 'quick-search-item';
            link.setAttribute('role', 'option');
            link.setAttribute('id', 'quick-search-opt-' + index);
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
            titleSpan.textContent = item.title;

            const catSpan = document.createElement('span');
            catSpan.className = 'tag is-small quick-search-item-category';
            catSpan.textContent = item.category;

            headerDiv.appendChild(titleSpan);
            headerDiv.appendChild(catSpan);

            const descDiv = document.createElement('div');
            descDiv.className = 'quick-search-item-desc';
            descDiv.textContent = item.description;

            contentDiv.appendChild(headerDiv);
            contentDiv.appendChild(descDiv);

            link.appendChild(iconSpan);
            link.appendChild(contentDiv);

            link.addEventListener('mouseenter', () => {
                this.setSelectedIndex(index);
            });

            this.resultsTarget.appendChild(link);
        });

        this.resultsTarget.hidden = false;
    }

    navigate(event) {
        const items = this.resultsTarget.querySelectorAll('.quick-search-item');
        if (items.length === 0) return;

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            this.setSelectedIndex((this.selectedIndex + 1) % items.length);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            this.setSelectedIndex((this.selectedIndex - 1 + items.length) % items.length);
        } else if (event.key === 'Enter') {
            if (this.selectedIndex >= 0 && items[this.selectedIndex]) {
                event.preventDefault();
                items[this.selectedIndex].click();
            }
        } else if (event.key === 'Escape') {
            event.preventDefault();
            this.hide();
            this.inputTarget.blur();
        }
    }

    setSelectedIndex(index) {
        const items = this.resultsTarget.querySelectorAll('.quick-search-item');
        items.forEach((item, idx) => {
            if (idx === index) {
                item.classList.add('is-active');
                item.scrollIntoView({ block: 'nearest' });
            } else {
                item.classList.remove('is-active');
            }
        });
        this.selectedIndex = index;
    }
}
