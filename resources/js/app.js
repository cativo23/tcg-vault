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
// back to design.md's empty-image state instead of a blank frame; a
// decorative image (set logo) marked data-optional simply disappears.
const EMPTY_STATE_MARKUP =
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
    + '<rect x="3.5" y="2.5" width="17" height="19" rx="2.5"></rect><path d="M3.5 16l4.5-4.5 3.5 3.5"></path>'
    + '<circle cx="15" cy="8" r="1.6"></circle><path d="M2 22L22 2"></path></svg><span>Image unavailable</span>';

const degradeImage = (img) => {
    if (img.dataset.degraded) return;
    img.dataset.degraded = '1';

    if ('optional' in img.dataset) {
        img.remove();
        return;
    }

    const wrap = img.closest('.imgwrap');
    if (!wrap || wrap.classList.contains('empty')) return;

    wrap.classList.add('empty');
    wrap.setAttribute('role', 'img');
    wrap.setAttribute('aria-label', `Image unavailable for ${img.alt || 'this card'}`);
    img.remove();
    // Static markup only — nothing from the page or the image goes in here.
    wrap.insertAdjacentHTML('beforeend', EMPTY_STATE_MARKUP);
};

// Capture phase, because `error` events on <img> do not bubble.
document.addEventListener('error', (event) => {
    if (event.target instanceof HTMLImageElement) degradeImage(event.target);
}, true);

// Images that already failed before this module ran (it is deferred).
const sweepBrokenImages = () => {
    document.querySelectorAll('img').forEach((img) => {
        if (img.complete && img.naturalWidth === 0 && img.getAttribute('src')) degradeImage(img);
    });
};
document.addEventListener('DOMContentLoaded', sweepBrokenImages);
document.addEventListener('livewire:navigated', sweepBrokenImages);

// Hero cursor-spotlight (design.md's HP3 pattern, scoped to the hero
// only). A no-op on touch devices — there's no pointermove to track, so
// the glow simply stays at its CSS default position, which is fine.
const bindHeroSpotlight = () => {
    const hero = document.querySelector('[data-hero-spotlight]');
    if (!hero || hero.dataset.spotlightBound) return;
    hero.dataset.spotlightBound = '1';

    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    hero.addEventListener('pointermove', (e) => {
        const r = hero.getBoundingClientRect();
        hero.style.setProperty('--mx', `${e.clientX - r.left}px`);
        hero.style.setProperty('--my', `${e.clientY - r.top}px`);
    });
};

document.addEventListener('DOMContentLoaded', bindHeroSpotlight);
document.addEventListener('livewire:navigated', bindHeroSpotlight);

// The nav's fade-edge hint (app.css .nw-nav.is-scrollable::after) must
// only show when the nav genuinely overflows — a username long enough
// to push it into scroll on a narrow viewport, not every viewport.
// Re-checked on resize since a real overflow/no-overflow boundary
// depends on both viewport width and the current username's length.
const markScrollableNav = () => {
    document.querySelectorAll('.nw-nav').forEach((nav) => {
        nav.classList.toggle('is-scrollable', nav.scrollWidth > nav.clientWidth + 1);
    });
};

document.addEventListener('DOMContentLoaded', markScrollableNav);
document.addEventListener('livewire:navigated', markScrollableNav);
window.addEventListener('resize', markScrollableNav);

// Theme toggle. The attribute itself is set synchronously by an inline
// <head> script (before this module loads) so there's no flash of the
// wrong theme; this only handles the click. No stored preference means
// "follow the OS", which app.css's prefers-color-scheme block already
// does on its own — this never writes a value until the user actually
// clicks the toggle.
const THEME_KEY = 'tcg-vault-theme';

const applyTheme = (theme) => {
    if (theme) document.documentElement.setAttribute('data-theme', theme);
    else document.documentElement.removeAttribute('data-theme');
};

const currentTheme = () => document.documentElement.getAttribute('data-theme')
    || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');

const bindThemeToggle = () => {
    document.querySelectorAll('[data-theme-toggle]').forEach((btn) => {
        if (btn.dataset.themeBound) return;
        btn.dataset.themeBound = '1';
        btn.addEventListener('click', () => {
            const next = currentTheme() === 'dark' ? 'light' : 'dark';
            localStorage.setItem(THEME_KEY, next);
            applyTheme(next);
        });
    });
};

document.addEventListener('DOMContentLoaded', bindThemeToggle);
document.addEventListener('livewire:navigated', bindThemeToggle);
