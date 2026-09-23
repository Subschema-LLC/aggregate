import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    reveal(event) {
        let section = event.target.closest('details');
        while (section && this.element.contains(section)) {
            section.open = true;
            section = section.parentElement?.closest('details');
        }
    }
}
