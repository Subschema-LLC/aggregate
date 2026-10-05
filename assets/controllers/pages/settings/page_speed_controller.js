import { Controller } from '@hotwired/stimulus';

// Checks whether the web server compresses the tracker the way visitors'
// browsers receive it. The request is same-origin, so Content-Encoding is
// readable; the script is downloaded, never run.
export default class extends Controller {
    static targets = ['compression', 'compressionDetail', 'compressionHelp'];
    static values = { url: String };

    connect() {
        this.check();
    }

    async check() {
        let encoding = null;
        let transferred = null;
        try {
            const url = new URL(this.urlValue, window.location.href);
            const response = await fetch(url, { cache: 'no-store', credentials: 'same-origin' });
            await response.arrayBuffer();
            if (!response.ok) throw new Error('Tracker request failed');
            encoding = (response.headers.get('Content-Encoding') || '').trim().toLowerCase();
            const timing = typeof performance !== 'undefined' && performance.getEntriesByName
                ? performance.getEntriesByName(url.href).pop() : null;
            if (timing && timing.encodedBodySize > 0) transferred = timing.encodedBodySize;
        } catch (error) {
            this.show('is-light', 'Not checked', 'The tracker could not be requested from this page.', false);
            return;
        }

        const size = transferred ? ` (${(transferred / 1024).toFixed(1)} KB over the network)` : '';
        if (['br', 'gzip', 'zstd', 'deflate'].includes(encoding)) {
            const name = { br: 'Brotli', gzip: 'gzip', zstd: 'Zstandard', deflate: 'deflate' }[encoding];
            this.show('is-success', 'On', `Your web server sends the tracker with ${name}${size}.`, false);
        } else {
            this.show('is-warning', 'Not detected', `Your web server sends the tracker uncompressed${size}.`, true);
        }
    }

    show(style, label, detail, needsHelp) {
        this.compressionTarget.className = `tag ${style}`;
        this.compressionTarget.textContent = label;
        this.compressionDetailTarget.textContent = detail;
        this.compressionHelpTarget.hidden = !needsHelp;
    }
}
