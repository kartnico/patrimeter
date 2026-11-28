// Sharing the report picture (assets/js/report.js) in a simulated DOM: one native share action on a phone, the
// networks on a computer, a silent cancellation and a refusal that says how to share anyway. The canvas is stubbed:
// jsdom does not draw.
import { afterEach, beforeAll, describe, expect, test } from 'vitest';

const REFUSED = "Le partage n'a pas abouti : enregistrez l'image, puis ajoutez-la à votre publication.";
const SAVED = 'Image enregistrée : ajoutez-la à votre publication.';
const opened = [];

beforeAll(async () => {
    const context = new Proxy(
        {},
        { get: (target, name) => (name === 'measureText' ? () => ({ width: 10 }) : () => {}) },
    );
    HTMLCanvasElement.prototype.getContext = () => context;
    HTMLCanvasElement.prototype.toBlob = (done) => done(new Blob(['png'], { type: 'image/png' }));
    if (!('viewBox' in SVGSVGElement.prototype)) {
        Object.defineProperty(SVGSVGElement.prototype, 'viewBox', {
            get: () => ({ baseVal: { width: 100, height: 80 } }),
        });
    }
    URL.createObjectURL = () => 'blob:report';
    URL.revokeObjectURL = () => {};
    window.open = (url) => opened.push(url);
    await import('../../assets/js/report.js');
});

afterEach(() => {
    opened.length = 0;
});

/** The report page on a device: whether files can be shared, a touch screen, and what the share sheet answers. */
async function page({ canShare, coarse, answer = () => Promise.resolve() }) {
    Object.defineProperty(navigator, 'canShare', { value: () => canShare, configurable: true });
    Object.defineProperty(navigator, 'share', { value: () => answer(), configurable: true });
    window.matchMedia = (query) => ({ matches: coarse && query.includes('coarse') });
    document.body.innerHTML = `
        <article data-report-card><p class="report-brand">Patrimeter</p><h1>Patrimoine <span>novembre 2026</span></h1>
            <svg class="pyramid" viewBox="0 0 100 80"></svg><footer class="report-foot"><p>Établi avec Patrimeter</p></footer>
        </article>
        <section data-report-share hidden data-share-text="Ma pyramide" data-share-file="patrimeter-2026-11.png"
                 data-share-saved="${SAVED}" data-share-failed="Échec" data-share-refused="${REFUSED}" data-share-invalid="Instance ?">
            <button type="button" data-share-native hidden>Partager l'image…</button>
            <button type="button" data-share-download>Enregistrer l'image</button>
            <div data-share-networks>
                <button type="button" data-share-network="mastodon">Mastodon</button>
                <button type="button" data-share-network="x">X (Twitter)</button>
                <form data-share-mastodon hidden><input name="instance"></form>
            </div>
            <p role="status" data-share-status></p>
        </section>`;
    document.dispatchEvent(new Event('DOMContentLoaded'));
    await new Promise((resolve) => setTimeout(resolve, 0));
    return document.querySelector('[data-report-share]');
}

const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

describe('report picture', () => {
    test('a phone gets one share action, not network buttons that would all open the same sheet', async () => {
        const panel = await page({ canShare: true, coarse: true });

        expect(panel.hidden).toBe(false);
        expect(panel.querySelector('[data-share-native]').hidden).toBe(false);
        expect(panel.querySelector('[data-share-networks]').hidden).toBe(true);
        expect(panel.querySelector('[data-share-download]').hidden).toBe(false);
    });

    test('closing the share sheet says nothing', async () => {
        const panel = await page({
            canShare: true,
            coarse: true,
            answer: () => Promise.reject(new DOMException('', 'AbortError')),
        });

        panel.querySelector('[data-share-native]').click();
        await settle();

        expect(panel.querySelector('[data-share-status]').textContent).toBe('');
    });

    test('a refused share says how to share anyway', async () => {
        const panel = await page({
            canShare: true,
            coarse: true,
            answer: () => Promise.reject(new DOMException('', 'NotAllowedError')),
        });

        panel.querySelector('[data-share-native]').click();
        await settle();

        expect(panel.querySelector('[data-share-status]').textContent).toBe(REFUSED);
    });

    test('a computer keeps the networks: each opens its own site, the picture saved first', async () => {
        const panel = await page({ canShare: false, coarse: false });

        expect(panel.querySelector('[data-share-native]').hidden).toBe(true);
        expect(panel.querySelector('[data-share-networks]').hidden).toBe(false);
        panel.querySelector('[data-share-network="x"]').click();

        expect(opened).toEqual(['https://x.com/intent/post?text=Ma%20pyramide']);
        expect(panel.querySelector('[data-share-status]').textContent).toBe(SAVED);
    });
});
