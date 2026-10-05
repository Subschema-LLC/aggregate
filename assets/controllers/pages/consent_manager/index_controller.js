import { Controller } from '@hotwired/stimulus';

// The same checks as ConsentAppearance::theme(); saving enforces them.
const readable = [['text', 'background', 'Text'], ['accent', 'background', 'Links and focus outline'], ['button_text', 'button_background', 'Button text']];

function luminance(hex) {
    const channel = (offset) => {
        const value = parseInt(hex.slice(offset, offset + 2), 16) / 255;
        return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
    };
    return 0.2126 * channel(1) + 0.7152 * channel(3) + 0.0722 * channel(5);
}

export function contrastRatio(first, second) {
    const [lighter, darker] = [luminance(first), luminance(second)].sort((a, b) => b - a);
    return (lighter + 0.05) / (darker + 0.05);
}

/** Live preview of the built-in banner. Nothing here is saved; the form is. */
export default class extends Controller {
    static targets = ['banner', 'title', 'description', 'details', 'privacy', 'categories', 'legend', 'buttons', 'reopen', 'contrast', 'colorValue', 'nameInput'];

    connect() {
        this.update();
    }

    field(name) {
        return this.element.querySelector(`[name="${name}"]`);
    }

    // An empty field shows its default, as the banner will.
    text(name) {
        const input = this.field(name);
        if (!input) return '';
        return input.value.trim() || input.dataset.default || '';
    }

    update() {
        const document = this.element.ownerDocument;
        const name = this.hasNameInputTarget ? this.nameInputTarget.value.trim() : '';
        this.titleTarget.textContent = this.text('text[title]').split('{name}').join(name);
        this.descriptionTarget.textContent = this.text('text[description]');
        const paragraphs = Array.from(this.element.querySelectorAll('[name="text[details][]"]'), (input) => input.value.trim()).filter(Boolean);
        this.detailsTarget.replaceChildren(...paragraphs.map((paragraph) => {
            const node = document.createElement('p');
            node.textContent = paragraph;
            return node;
        }));

        const url = (this.field('privacy_policy_url')?.value || '').trim();
        this.privacyTarget.hidden = !url.startsWith('https://');
        this.privacyTarget.querySelector('a').textContent = this.text('text[privacy_link]');

        const show = (this.field('buttons[show]')?.value || 'reject,accept,save').split(',');
        this.categoriesTarget.hidden = !show.includes('save');
        this.legendTarget.textContent = this.text('text[categories_legend]');
        for (const label of this.categoriesTarget.querySelectorAll('[data-category]')) {
            label.textContent = this.text(`text[categories][${label.dataset.category}]`);
        }
        this.buttonsTarget.replaceChildren(...show.map((button) => {
            const node = document.createElement('button');
            node.type = 'button';
            node.textContent = this.text(`text[${button}]`);
            return node;
        }));

        const reopen = this.field('buttons[reopen]')?.value || 'bottom-left';
        this.reopenTarget.hidden = reopen === 'hidden';
        this.reopenTarget.classList.toggle('ac-consent-open--right', reopen === 'bottom-right');
        this.reopenTarget.textContent = this.text('text[reopen]');

        const colors = {};
        for (const input of this.element.querySelectorAll('input[type="color"][data-theme-key]')) {
            const key = input.dataset.themeKey;
            colors[key] = input.value.toUpperCase();
            for (const node of [this.bannerTarget, this.reopenTarget]) node.style.setProperty('--ac-' + key.replaceAll('_', '-'), input.value);
        }
        for (const code of this.colorValueTargets) code.textContent = colors[code.dataset.themeKey] || '';
        this.contrastTarget.textContent = this.contrastMessage(colors);
    }

    contrastMessage(colors) {
        if (!colors.background) return '';
        const problems = [];
        for (const [foreground, background, label] of readable) {
            const ratio = contrastRatio(colors[foreground], colors[background]);
            if (ratio < 4.5) problems.push(`${label}: ${ratio.toFixed(2)}:1, needs 4.5:1`);
        }
        const edge = Math.max(contrastRatio(colors.button_background, colors.background), contrastRatio(colors.button_border, colors.background));
        if (edge < 3) problems.push(`Button edge against the background: ${edge.toFixed(2)}:1, needs 3:1`);
        return problems.length ? 'Contrast too low. ' + problems.join('. ') + '.' : 'Contrast checks pass.';
    }

    resetColors() {
        for (const input of this.element.querySelectorAll('input[type="color"][data-default]')) input.value = input.dataset.default;
        this.update();
    }
}
