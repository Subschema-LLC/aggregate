import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['trigger', 'dialog'];

    connect() {
        this.returnFocus = null;
    }

    disconnect() {
        this.reset();
    }

    open() {
        if (this.dialogTarget.open) return;
        this.returnFocus = this.element.ownerDocument.activeElement;
        this.dialogTarget.showModal();
    }

    close() {
        this.dialogTarget.close();
    }

    closed() {
        if (this.returnFocus?.isConnected) this.returnFocus.focus();
        this.returnFocus = null;
    }

    reset() {
        this.returnFocus = null;
        if (this.dialogTarget.open) this.dialogTarget.close();
    }
}
