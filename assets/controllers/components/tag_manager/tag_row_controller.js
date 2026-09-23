import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['actionType', 'trigger', 'actionFields', 'eventField'];

    connect() {
        this.update();
    }

    update() {
        for (const fields of this.actionFieldsTargets) {
            const selected = fields.dataset.actionFields === this.actionTypeTarget.value;
            fields.hidden = !selected;
            for (const input of fields.querySelectorAll('input, textarea')) {
                input.disabled = !selected;
            }
        }
        const eventNeeded = ['document_event', 'window_event', 'data_layer'].includes(this.triggerTarget.value);
        this.eventFieldTarget.hidden = !eventNeeded;
        this.eventFieldTarget.querySelector('input').disabled = !eventNeeded;
    }
}
