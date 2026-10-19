import { Controller } from '@hotwired/stimulus';

// Email page: places the selected email service card first and expands it,
// while collapsing all other email service cards by default.
export default class extends Controller {
    static targets = ['provider', 'panel', 'providerList'];

    connect() {
        this.showProvider();
    }

    showProvider() {
        if (!this.hasProviderTarget) return;
        const chosen = this.providerTarget.value;
        const panels = [...this.panelTargets];

        panels.forEach((panel) => {
            const isSelected = chosen !== '' && panel.dataset.provider === chosen;
            panel.hidden = false;
            panel.open = isSelected;
            const badge = typeof panel.querySelector === 'function'
                ? panel.querySelector('[data-provider-selected-badge]')
                : null;
            if (badge) {
                badge.hidden = !isSelected;
                badge.classList?.toggle('is-hidden', !isSelected);
            }
        });

        const container = this.hasProviderListTarget
            ? this.providerListTarget
            : panels[0]?.parentElement;
        if (container && typeof container.prepend === 'function' && chosen !== '') {
            const selectedPanel = panels.find((panel) => panel.dataset.provider === chosen);
            if (selectedPanel && container.firstElementChild !== selectedPanel) {
                container.prepend(selectedPanel);
            }
        }
    }
}
