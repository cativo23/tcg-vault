// Client-only behaviour of the collection page. Filtering, counts and batching are Livewire (server-side).
import { tabKeyTarget, nextTabIndex, revealScrollLeft, overflowsRight, copyText, fallbackSrc } from './collection-helpers.js';

const reduced = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/* ---------- copy link ---------- */
const legacyCopy = (text) => {
    const field = document.createElement('textarea');
    field.value = text;
    field.setAttribute('readonly', '');
    field.style.position = 'fixed';
    field.style.opacity = '0';
    document.body.appendChild(field);
    field.select();
    let ok = false;
    try { ok = document.execCommand('copy'); } catch { ok = false; }
    document.body.removeChild(field);
    return ok;
};

let copyTimer;
document.addEventListener('click', async (event) => {
    const button = event.target.closest?.('[data-col-copy]');
    if (!button) return;

    const ok = await copyText(location.origin + location.pathname, {
        clipboard: navigator.clipboard, secure: window.isSecureContext, legacy: legacyCopy,
    });
    const label = button.querySelector('.col-copy__label');
    const status = document.querySelector('[data-col-copy-status]');

    button.classList.toggle('is-done', ok);
    button.classList.toggle('is-failed', !ok);
    label.textContent = ok ? 'Link copied' : 'Copy from the address bar';
    status.textContent = ok ? 'Link copied' : 'Could not copy the link';

    clearTimeout(copyTimer);
    copyTimer = setTimeout(() => {
        button.classList.remove('is-done', 'is-failed');
        label.textContent = 'Copy link';
        status.textContent = '';
    }, 2600);
});

/* ---------- set tabs: keyboard (manual activation), scroll into view, edge fade ---------- */
const reveal = (strip, tab) => {
    const to = revealScrollLeft({ tabLeft: tab.offsetLeft, tabWidth: tab.offsetWidth, scrollLeft: strip.scrollLeft, viewWidth: strip.clientWidth });
    if (to !== null) strip.scrollTo({ left: to, behavior: reduced() ? 'auto' : 'smooth' });
};

document.addEventListener('keydown', (event) => {
    const strip = event.target.closest?.('[data-col-tabs]');
    if (!strip) return;

    const tabs = [...strip.querySelectorAll('[role="tab"]')];
    const next = tabKeyTarget(event, tabs.indexOf(document.activeElement), tabs.length);
    if (next === null) return;

    event.preventDefault();
    tabs.forEach((tab, i) => { tab.tabIndex = i === next ? 0 : -1; });
    tabs[next].focus(); // Enter or Space then activates it, a Livewire round trip only when asked for
    reveal(strip, tabs[next]);
});

const syncEdge = () => document.querySelectorAll('[data-col-tabs-wrap]').forEach((wrap) => {
    const strip = wrap.querySelector('[data-col-tabs]');
    wrap.classList.toggle('is-more-right', overflowsRight(strip.scrollLeft, strip.clientWidth, strip.scrollWidth));
});

const revealSelected = () => document.querySelectorAll('[data-col-tabs]').forEach((strip) => {
    const tab = strip.querySelector('[role="tab"][aria-selected="true"]');
    if (tab) reveal(strip, tab);
});

// scroll does not bubble, so listen in the capture phase
document.addEventListener('scroll', (event) => {
    if (event.target.matches?.('[data-col-tabs]')) syncEdge();
}, true);
window.addEventListener('resize', syncEdge);

/* ---------- image optional, data mandatory: a failed picture becomes the hatched face ---------- */
const degrade = (img) => {
    const pic = img.closest('.col-pic');
    if (!pic || pic.classList.contains('col-pic--empty')) return;

    // A tcgdex low.webp that is missing is retried once at high.webp before giving up on the picture.
    const retry = img.dataset.retried ? null : fallbackSrc(img.getAttribute('src'));
    if (retry) {
        img.dataset.retried = '1';
        img.src = retry;
        return;
    }

    pic.classList.add('col-pic--empty');
    const name = document.createElement('b');
    name.textContent = img.dataset.name || '';
    const note = document.createElement('span');
    note.textContent = 'No image';
    img.remove();
    pic.append(name, note);
};

// `error` does not bubble on <img>: capture phase.
document.addEventListener('error', (event) => {
    if (event.target instanceof HTMLImageElement) degrade(event.target);
}, true);

const sweepBrokenImages = () => document.querySelectorAll('.col-pic img').forEach((img) => {
    if (img.complete && img.naturalWidth === 0 && img.getAttribute('src')) degrade(img);
});

/* ---------- re-apply after every Livewire morph (the server HTML resets all of the above) ---------- */
const refresh = () => {
    syncEdge();
    sweepBrokenImages();
};

// Same event app.js uses for its morph hook; Livewire is not guaranteed to exist when this module runs.
document.addEventListener('livewire:init', () => window.Livewire.hook('morphed', () => {
    refresh();
    revealSelected();
}));

refresh();
revealSelected();
if (document.fonts?.ready) document.fonts.ready.then(syncEdge);
window.addEventListener('load', syncEdge);
