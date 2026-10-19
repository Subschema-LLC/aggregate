import { Controller } from '@hotwired/stimulus';

// Email page: shows only the chosen service's settings. Without JavaScript,
// or before a service is chosen, every service's panel stays visible.
export default class extends Controller {
    static targets = ['provider', 'panel'];

    connect() {
        this.showProvider();
    }

    showProvider() {
        if (!this.hasProviderTarget) return;
        const chosen = this.providerTarget.value;
        this.panelTargets.forEach((panel) => {
            panel.hidden = chosen !== '' && panel.dataset.provider !== chosen;
        });
    }
}
