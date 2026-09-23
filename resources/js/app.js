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

// wire:navigate swaps the document without re-running the inline <head>
// script that sets data-theme from localStorage on a full load — so a
// stored preference that disagrees with prefers-color-scheme gets lost
// on every SPA navigation until the next hard reload. Re-apply it here,
// same event bindThemeToggle already re-binds on.
document.addEventListener('livewire:navigated', () => applyTheme(localStorage.getItem(THEME_KEY)));

// Sparkline tooltip: nearest-point tracking on pointermove, matching the
// x-sparkline component's own coordinate space (a fixed 600-wide viewBox
// regardless of the rendered width) so we scale by clientWidth instead of
// re-deriving points from the DOM.
const bindSparklineTooltips = () => {
    document.querySelectorAll('[data-sparkline]').forEach((wrap) => {
        if (wrap.dataset.sparklineBound) return;
        wrap.dataset.sparklineBound = '1';

        let points;
        try {
            points = JSON.parse(wrap.dataset.sparkline);
        } catch {
            return;
        }
        if (!points.length) return;

        const svg = wrap.querySelector('svg');
        const guide = wrap.querySelector('[data-guide]');
        const dot = wrap.querySelector('[data-hover-dot]');
        const endDot = wrap.querySelector('[data-end-dot]');
        const tip = wrap.querySelector('[data-tip]');
        const tipDate = wrap.querySelector('[data-tip-date]');
        const tipValue = wrap.querySelector('[data-tip-value]');
        const viewboxWidth = svg.viewBox.baseVal.width || 600;

        const nearest = (clientX) => {
            const rect = svg.getBoundingClientRect();
            const x = ((clientX - rect.left) / rect.width) * viewboxWidth;
            let closest = points[0];
            let closestDist = Infinity;
            for (const p of points) {
                const dist = Math.abs(p.x - x);
                if (dist < closestDist) { closest = p; closestDist = dist; }
            }
            return { point: closest, rectWidth: rect.width };
        };

        const show = (clientX) => {
            const { point, rectWidth } = nearest(clientX);
            guide.setAttribute('x1', point.x);
            guide.setAttribute('x2', point.x);
            guide.setAttribute('opacity', '1');
            dot.setAttribute('cx', point.x);
            dot.setAttribute('cy', point.y);
            dot.setAttribute('opacity', '1');
            if (endDot) endDot.style.opacity = point.x === points[points.length - 1].x ? '1' : '0.35';

            tipDate.textContent = point.date;
            tipValue.textContent = point.value;
            tip.hidden = false;
            const tipWidth = tip.offsetWidth;
            const scale = rectWidth / viewboxWidth;
            let left = point.x * scale - tipWidth / 2;
            left = Math.max(0, Math.min(rectWidth - tipWidth, left));
            tip.style.left = `${left}px`;
        };

        const hide = () => {
            guide.setAttribute('opacity', '0');
            dot.setAttribute('opacity', '0');
            if (endDot) endDot.style.opacity = '1';
            tip.hidden = true;
        };

        svg.addEventListener('pointermove', (e) => show(e.clientX));
        svg.addEventListener('pointerdown', (e) => show(e.clientX));
        svg.addEventListener('pointerleave', hide);
    });
};

document.addEventListener('DOMContentLoaded', bindSparklineTooltips);
document.addEventListener('livewire:navigated', bindSparklineTooltips);

// The "Saved" tag next to an autosaved field (variant-row partial) —
// shown for ~2s and faded, mirroring the discreet feedback the old
// inline Qty/Notes editing gave with no persistent banner.
document.addEventListener('row-saved', (event) => {
    const rows = document.querySelectorAll(`[wire\\:key="editingRows.${event.detail.index}"]`);
    rows.forEach((row) => {
        const tag = row.querySelector('[data-autosave-tag]');
        if (!tag) return;
        tag.style.display = 'flex';
        tag.style.opacity = '1';
        clearTimeout(tag._fadeTimer);
        tag._fadeTimer = setTimeout(() => { tag.style.opacity = '0'; }, 1600);
    });
});
