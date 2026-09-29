import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = { source: String, status: String, success: String, fallback: String };

    async copy() {
        const document = this.element.ownerDocument;
        const source = document.getElementById(this.sourceValue);
        const feedback = document.getElementById(this.statusValue);
        if (!source || !feedback) return;

        const wasDisabled = this.element.disabled;
        this.element.disabled = true;
        try {
            const clipboard = document.defaultView.navigator.clipboard;
            if (!clipboard?.writeText) throw new Error('Clipboard unavailable');
            await clipboard.writeText('value' in source ? source.value : source.textContent);
            feedback.textContent = this.successValue;
            this.element?.classList?.add?.('is-success');
            if (this.element?.classList?.remove) {
                setTimeout(() => this.element?.classList?.remove?.('is-success'), 2000);
            }
        } catch (_) {
            if (typeof source.select === 'function') {
                source.select();
            } else {
                const selection = document.defaultView.getSelection();
                const range = document.createRange();
                range.selectNodeContents(source);
                selection?.removeAllRanges();
                selection?.addRange(range);
            }
            feedback.textContent = this.fallbackValue;
        } finally {
            this.element.disabled = wasDisabled;
        }
    }
}
