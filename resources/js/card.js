// Client-only behaviour of the card page: Copy link, switching between the collector's photo and the official art,
// the range tabs' keyboard, and reading the price chart. Prices, variants and ranges stay server-side in the
// CardShow Livewire component; the plate tools (Actual size, calibration, loupe, tilt) are a separate module.
import { copyText, tabKeyTarget } from './collection-helpers.js';
import { nearestIndex, setValue, shareUrl, stepReading, stopsReadingOnLeave } from './card-helpers.js';
import { degradeImage, legacyCopy } from './dom-helpers.js';

const root = document.documentElement;

/* ---------- entrances play once: the first interaction (or a quiet moment) settles the page ---------- */
const settle = () => root.classList.add('crd-settled');
['pointerdown', 'keydown'].forEach((type) => document.addEventListener(type, settle, { once: true, capture: true }));
window.addEventListener('load', () => setTimeout(settle, 1500));

/* ---------- copy link ---------- */
let copyTimer;
document.addEventListener('click', async (event) => {
    const button = event.target.closest?.('[data-crd-copy]');
    if (!button) return;

    const ok = await copyText(shareUrl(location.href), {
        clipboard: navigator.clipboard, secure: window.isSecureContext, legacy: legacyCopy,
    });
    const label = button.querySelector('.crd-copy__label');
    const status = document.querySelector('[data-crd-copy-status]');
    if (!label || !status) return; // the copy itself has run; without its feedback nodes there is nothing to update

    button.classList.toggle('is-done', ok);
    button.classList.toggle('is-failed', !ok);
    label.textContent = ok ? 'Link copied' : 'Copy from the address bar';
    // Clear first, then set on the next frame, so a repeat copy is announced again.
    status.textContent = '';
    const message = ok ? 'Link copied' : 'Could not copy the link';
    requestAnimationFrame(() => { status.textContent = message; });

    clearTimeout(copyTimer);
    copyTimer = setTimeout(() => {
        button.classList.remove('is-done', 'is-failed');
        label.textContent = 'Copy link';
        status.textContent = '';
    }, 2600);
});

/* ---------- the plate's views: the collector's photo or the official art ---------- */
// Said once per user action, never on load or resize.
const announce = (text) => {
    const status = document.querySelector('[data-crd-status]');
    if (!status) return;
    status.textContent = '';
    requestAnimationFrame(() => { status.textContent = text; });
};

const failedPlates = new WeakMap(); // hatched face -> the plate <img> it replaced

document.addEventListener('click', (event) => {
    const button = event.target.closest?.('[data-crd-view]');
    if (!button || button.getAttribute('aria-pressed') === 'true') return;

    const col = button.closest('.crd-plate-col');
    const plate = col?.querySelector('[data-crd-plate]');
    // A picture that failed became the hatched face: put its <img> back so the other view can try its own source.
    const failed = plate?.querySelector('.crd-noimg');
    if (failed && failedPlates.has(failed)) failed.replaceWith(failedPlates.get(failed));
    const img = plate?.querySelector('[data-crd-img]');
    if (!plate || !img) return;

    const kind = button.dataset.crdKind;
    col.querySelectorAll('[data-crd-view]').forEach((b) => b.setAttribute('aria-pressed', String(b === button)));
    img.dataset.crdKind = kind;
    img.classList.toggle('is-photo', kind === 'photo');
    img.alt = button.dataset.crdAlt || img.alt;
    img.src = button.dataset.crdView;
    plate.dataset.view = kind;
    const note = col.querySelector('[data-crd-note]');
    if (note) note.hidden = kind !== 'photo';
    announce(kind === 'photo' ? 'Showing your photo of this copy.' : 'Showing the official art.');
    plate.dispatchEvent(new CustomEvent('crd:view', { bubbles: true, detail: { kind } }));
});

/* ---------- range tabs: arrows move focus only, Enter or Space selects (one Livewire round trip) ---------- */
document.addEventListener('keydown', (event) => {
    const strip = event.target.closest?.('[data-crd-tabs]');
    if (!strip) return;

    const tabs = [...strip.querySelectorAll('[role="tab"]')];
    const next = tabKeyTarget(event, tabs.indexOf(document.activeElement), tabs.length);
    if (next === null) return;

    event.preventDefault();
    tabs.forEach((tab, i) => { tab.tabIndex = i === next ? 0 : -1; });
    tabs[next].focus(); // Enter or Space then activates it, a Livewire round trip only when asked for
});

/* ---------- reading the chart: pointer, arrows, Home/End, Esc; the server re-renders the chart on every change ---------- */
const readingsOf = (chart) => {
    try { return JSON.parse(chart.dataset.points || '[]'); } catch { return []; }
};
const sheetOf = (chart) => chart.closest('.crd-chart-sheet');

// The chart is a slider: its value is the index of the day being read, its value text that day's reading.
// `announce` is true for keyboard readings only: they move the slider's value, which a screen reader speaks once per
// key press. Pointer readings never touch it, so a mouse sweep does not flood a screen reader.
const readAt = (chart, index, announce = false) => {
    const points = readingsOf(chart);
    if (index < 0 || index >= points.length) return;
    const point = points[index];
    chart.dataset.readIndex = String(index);
    chart.querySelector('[data-crd-cross]').style.left = `${point.x}%`;
    const dot = chart.querySelector('[data-crd-hover-dot]');
    dot.style.left = `${point.x}%`;
    dot.style.top = `${point.y}%`;
    chart.classList.add('is-reading');
    const out = sheetOf(chart)?.querySelector('[data-crd-chart-read]');
    if (out) out.textContent = point.read;
    if (announce) setValue(chart, index, point.read);
};

// Leaving the chart puts the slider back on its resting value, the newest day.
const stopReading = (chart) => {
    chart.classList.remove('is-reading');
    delete chart.dataset.readIndex;
    const out = sheetOf(chart)?.querySelector('[data-crd-chart-read]');
    if (out) out.textContent = '';
    const points = readingsOf(chart);
    const last = points.length - 1;
    if (last >= 0) setValue(chart, last, points[last].read);
};

document.addEventListener('pointermove', (event) => {
    const chart = event.target.closest?.('[data-crd-chart]');
    if (!chart) return;
    const rect = chart.querySelector('.crd-plot').getBoundingClientRect();
    if (rect.width === 0) return;
    const at = ((event.clientX - rect.left) / rect.width) * 100;
    readAt(chart, nearestIndex(readingsOf(chart).map((p) => p.x), at), false);
});

// A pointer leaving a chart that still has keyboard focus keeps the keyboard reading; losing focus always ends it.
const leave = (event) => {
    const chart = event.target.closest?.('[data-crd-chart]');
    if (!chart || chart.contains(event.relatedTarget)) return;
    if (stopsReadingOnLeave(event.type, document.activeElement === chart)) stopReading(chart);
};
document.addEventListener('pointerout', leave);
document.addEventListener('focusout', leave);

document.addEventListener('keydown', (event) => {
    const chart = event.target.matches?.('[data-crd-chart]') ? event.target : null;
    if (!chart || event.altKey || event.ctrlKey || event.metaKey) return; // Alt+Left is the browser's Back
    if (event.key === 'Escape') {
        stopReading(chart);
        return;
    }
    const current = chart.dataset.readIndex === undefined ? null : Number(chart.dataset.readIndex);
    const next = stepReading(current, readingsOf(chart).length, event.key);
    if (next === null) return;
    event.preventDefault();
    readAt(chart, next, true);
});

/* ---------- images: optional, data mandatory ---------- */
// The plate's picture becomes the hatched face with the card's name; the face remembers its <img> so a view switch can restore it.
const plateFailed = (img) => {
    const face = document.createElement('div');
    face.className = 'crd-noimg';
    const name = document.createElement('b');
    name.textContent = img.dataset.name || '';
    const note = document.createElement('span');
    note.textContent = 'No image';
    face.append(name, note);
    failedPlates.set(face, img);
    img.replaceWith(face);
};

// `error` does not bubble on <img>: capture phase. The strip's tiles are the collection's (`.col-pic`).
document.addEventListener('error', (event) => {
    const img = event.target;
    if (!(img instanceof HTMLImageElement)) return;
    if (img.matches('[data-crd-img]')) plateFailed(img);
    else degradeImage(img);
}, true);

const sweepBrokenImages = () => document.querySelectorAll('[data-crd-img], .col-pic img').forEach((img) => {
    if (!(img.complete && img.naturalWidth === 0 && img.getAttribute('src'))) return;
    if (img.matches('[data-crd-img]')) plateFailed(img);
    else degradeImage(img);
});

// A Livewire morph restores the server's <img> in the strip; sweep again after each one.
document.addEventListener('livewire:init', () => window.Livewire.hook('morphed', sweepBrokenImages));
sweepBrokenImages();
