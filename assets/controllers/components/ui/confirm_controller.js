import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = { message: String };

    confirm(event) {
        if (!this.element.ownerDocument.defaultView.confirm(this.messageValue)) {
            event.preventDefault();
        }
    }
}
