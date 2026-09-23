import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['start', 'tip', 'callout', 'title', 'description', 'position', 'previous', 'next'];

    connect() {
        this.index = -1;
        this.activeTip = null;
        this.priorDescription = null;
        this.reset();
        this.startTarget.hidden = this.tipTargets.length === 0;
    }

    disconnect() {
        this.reset();
        this.startTarget.hidden = true;
    }

    start() {
        this.show(0);
    }

    previous() {
        if (this.index > 0) this.show(this.index - 1);
    }

    next() {
        if (this.index + 1 < this.tipTargets.length) this.show(this.index + 1);
        else this.close();
    }

    escape(event) {
        if (this.calloutTarget.hidden) return;
        event.preventDefault();
        this.close();
    }

    close() {
        this.reset();
        this.startTarget.focus();
    }

    reset() {
        this.clearTip();
        this.index = -1;
        this.calloutTarget.hidden = true;
        this.calloutTarget.style.removeProperty('left');
        this.calloutTarget.style.removeProperty('top');
        this.calloutTarget.style.removeProperty('--setup-arrow-left');
        delete this.calloutTarget.dataset.side;
    }

    clearTip() {
        if (!this.activeTip) return;
        this.activeTip.classList.remove('setup-tour-target');
        if (this.priorDescription === null) this.activeTip.removeAttribute('aria-describedby');
        else this.activeTip.setAttribute('aria-describedby', this.priorDescription);
        this.activeTip = null;
        this.priorDescription = null;
    }

    show(index) {
        const target = this.tipTargets[index];
        if (!target) return;
        this.clearTip();
        this.index = index;
        this.activeTip = target;
        this.priorDescription = target.getAttribute('aria-describedby');
        target.setAttribute('aria-describedby', `${this.priorDescription || ''} ${this.descriptionTarget.id}`.trim());
        target.classList.add('setup-tour-target');
        this.titleTarget.textContent = target.dataset.tourTitle;
        this.descriptionTarget.textContent = target.dataset.tourText;
        this.positionTarget.textContent = `Tip ${index + 1} of ${this.tipTargets.length}`;
        this.previousTarget.disabled = index === 0;
        this.nextTarget.textContent = index === this.tipTargets.length - 1 ? 'Finish walkthrough' : 'Next tip';
        this.calloutTarget.hidden = false;
        target.scrollIntoView({ block: 'center', behavior: 'auto' });
        this.place();
        this.titleTarget.focus({ preventScroll: true });
    }

    place() {
        if (!this.activeTip || this.calloutTarget.hidden) return;
        const viewport = this.element.ownerDocument.defaultView;
        const rect = this.activeTip.getBoundingClientRect();
        const width = this.calloutTarget.offsetWidth;
        const height = this.calloutTarget.offsetHeight;
        const left = Math.max(16, Math.min(rect.left, viewport.innerWidth - width - 16));
        const below = rect.bottom + height + 16 <= viewport.innerHeight;
        const top = below ? rect.bottom + 12 : Math.max(16, rect.top - height - 12);
        this.calloutTarget.style.left = `${left}px`;
        this.calloutTarget.style.top = `${Math.min(top, Math.max(16, viewport.innerHeight - height - 16))}px`;
        this.calloutTarget.dataset.side = below ? 'below' : 'above';
        this.calloutTarget.style.setProperty('--setup-arrow-left', `${Math.max(10, Math.min(width - 20, rect.left + rect.width / 2 - left))}px`);
    }
}
