import { Controller } from '@hotwired/stimulus';

/** Fills the empty new-tag row with the selected website's tracker URL. Nothing is saved until the form is. */
export default class extends Controller {
    static targets = ['status'];
    static values = { newRow: Number };

    addTracker(event) {
        const document = this.element.ownerDocument;
        const field = (name) => document.getElementById(`tag-${this.newRowValue}-${name}`);
        const id = field('id');
        const type = field('type');
        const source = field('src');
        const method = field('method');
        const trigger = field('trigger');
        const consent = field('consent');
        const { url, id: suggestedId } = event.params;
        if (!this.hasNewRowValue || !source || !url) return;

        const inUse = (source.value !== '' && source.value !== url)
            || (id && id.value !== '' && id.value !== suggestedId)
            || (method && method.value !== '');
        if (inUse) {
            this.say('The new tag row already has other values. Save or clear it, then add the tracker, or paste the copied URL into a row yourself.');
            return;
        }

        if (id && id.value === '') id.value = suggestedId;
        for (const [select, value] of [[type, 'script'], [trigger, 'dom_ready']]) {
            if (!select || select.value === value) continue;
            select.value = value;
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }
        source.value = url;
        source.scrollIntoView?.({ block: 'center' });
        (consent ?? source).focus?.();

        const manager = document.getElementById('tag-manager-enabled');
        const category = consent?.value || 'analytics';
        this.say(`Added to the new tag row with the ${category} consent category. Change it to none to allow anonymous tracking before a choice, then save.`
            + (manager && manager.value !== '1' ? ' The tag manager is disabled, so enable it too before the tracker can load.' : ''));
    }

    // Keeps every tag chaining list ("Run after …") in step with the IDs typed into the rows.
    refreshRunAfter(event) {
        const field = event?.target;
        if (!field || typeof field.matches !== 'function' || !field.matches('input[name^="tags["][name$="[id]"]')) return;
        const rows = Array.from(this.element.querySelectorAll('[data-tag-row]'));
        const idOf = (row) => (row.querySelector('input[name$="[id]"]')?.value ?? '').trim();
        const ids = rows.map(idOf).filter((id) => /^[A-Za-z][A-Za-z0-9_-]{0,63}$/.test(id));
        for (const row of rows) {
            const select = row.querySelector('select[data-tag-after]');
            if (!select) continue;
            const own = idOf(row);
            const chosen = select.value;
            const choices = ids.filter((id, index) => id !== own && ids.indexOf(id) === index);
            if (chosen && !choices.includes(chosen)) choices.push(chosen);
            const document = this.element.ownerDocument;
            const options = [select.options[0], ...choices.map((id) => {
                const option = document.createElement('option');
                option.value = id;
                option.textContent = `Run after ${id}`;
                return option;
            })];
            select.replaceChildren(...options);
            select.value = chosen;
        }
    }

    say(message) {
        if (this.hasStatusTarget) this.statusTarget.textContent = message;
    }
}
