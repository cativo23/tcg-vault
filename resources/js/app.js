// Count-up for the headline value in a stat band (design.md motion stance).
// Numbers are formatted server-side; the script only animates the digits,
// so the resting DOM is always the real value even with JS off.
const countUp = (el) => {
    const target = Number(el.dataset.countup);
    const text = el.textContent;
    const match = text.match(/[\d,]+(\.\d+)?/);

    if (!Number.isFinite(target) || !match) return;

    const decimals = match[1] ? match[1].length - 1 : 0;
    const format = (n) => n.toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
    const start = performance.now();
    const duration = 1100;

    const tick = (now) => {
        const p = Math.min(1, (now - start) / duration);
        const eased = 1 - Math.pow(1 - p, 3);
        el.textContent = text.replace(match[0], format(target * eased));
        if (p < 1) requestAnimationFrame(tick);
    };

    requestAnimationFrame(tick);
};

const run = () => {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    document.querySelectorAll('[data-countup]:not([data-counted])').forEach((el) => {
        el.dataset.counted = '1';
        countUp(el);
    });
};

document.addEventListener('DOMContentLoaded', run);
document.addEventListener('livewire:navigated', run);

// A card image that fails to load (tcgdex outage, a deleted photo) falls
// back to design.md's empty-image state instead of a blank frame. Capture
// phase, because `error` events on <img> do not bubble.
document.addEventListener(
    'error',
    (event) => {
        const img = event.target;
        if (!(img instanceof HTMLImageElement)) return;
        const wrap = img.closest('.imgwrap');
        if (!wrap || wrap.classList.contains('empty')) return;

        wrap.classList.add('empty');
        wrap.setAttribute('role', 'img');
        wrap.setAttribute('aria-label', `Image unavailable for ${img.alt || 'this card'}`);
        img.remove();

        wrap.insertAdjacentHTML(
            'beforeend',
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
                + '<rect x="3.5" y="2.5" width="17" height="19" rx="2.5"></rect><path d="M3.5 16l4.5-4.5 3.5 3.5"></path>'
                + '<circle cx="15" cy="8" r="1.6"></circle><path d="M2 22L22 2"></path></svg><span>Image unavailable</span>',
        );
    },
    true,
);
