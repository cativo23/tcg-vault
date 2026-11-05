// Client-only behaviour of the activity page. The range itself, the chart and the feed are Livewire (server-side).
import { tabKeyTarget, fallbackSrc } from './collection-helpers.js';
import { nearestIndex, readingStep, readingText } from './activity-helpers.js';

/* ---------- range tabs: keyboard with manual activation ---------- */
document.addEventListener('keydown', (event) => {
    const strip = event.target.closest?.('[data-act-tabs]');
    if (!strip) return;

    const tabs = [...strip.querySelectorAll('[role="tab"]')];
    const next = tabKeyTarget(event, tabs.indexOf(document.activeElement), tabs.length);
    if (next === null) return;

    event.preventDefault();
    tabs.forEach((tab, i) => { tab.tabIndex = i === next ? 0 : -1; });
    tabs[next].focus(); // Enter or Space then selects it: a Livewire round trip only when asked for
});

/* ---------- reading the chart: pointer, arrows, Home/End, Esc ---------- */
const pointsOf = (chart) => {
    try {
        return JSON.parse(chart.dataset.points || '[]');
    } catch {
        return [];
    }
};
const slotOf = (chart) => chart.closest('[data-act-history]')?.querySelector('[data-act-read]');

// The chart is a slider: its value is the index of the day being read, its value text what the visible readout says.
const setValue = (chart, index, text) => {
    chart.setAttribute('aria-valuenow', String(index));
    chart.setAttribute('aria-valuetext', text);
};

// `announce` is true for keyboard readings only: they move the slider's value, which a screen reader speaks once per key
// press. Pointer readings never touch it, so a mouse sweep does not flood a screen reader.
const readAt = (chart, index, announce = false) => {
    const point = pointsOf(chart)[index];
    if (!point) return;
    chart.dataset.reading = String(index);
    chart.style.setProperty('--act-rx', `${point.x}%`);
    chart.style.setProperty('--act-ry', `${point.y}%`);
    chart.classList.add('is-reading');
    const slot = slotOf(chart);
    if (slot) slot.textContent = readingText(point);
    if (announce) setValue(chart, index, readingText(point));
};

// Leaving the chart puts the slider back on its resting value, the newest day.
const stopReading = (chart) => {
    chart.classList.remove('is-reading');
    delete chart.dataset.reading;
    const slot = slotOf(chart);
    if (slot) slot.textContent = '';
    const points = pointsOf(chart);
    const last = points.length - 1;
    if (last >= 0) setValue(chart, last, readingText(points[last]));
};

document.addEventListener('pointermove', (event) => {
    const chart = event.target.closest?.('[data-act-chart]');
    if (!chart) return;
    const box = chart.getBoundingClientRect();
    if (box.width === 0) return;
    const index = nearestIndex(pointsOf(chart).map((p) => p.x), ((event.clientX - box.left) / box.width) * 100);
    if (index >= 0) readAt(chart, index);
});

const leave = (event) => {
    const chart = event.target.closest?.('[data-act-chart]');
    if (chart && !chart.contains(event.relatedTarget)) stopReading(chart);
};
document.addEventListener('pointerout', leave);
document.addEventListener('focusout', leave);

document.addEventListener('keydown', (event) => {
    const chart = event.target.matches?.('[data-act-chart]') ? event.target : null;
    if (!chart || event.altKey || event.ctrlKey || event.metaKey) return;
    if (event.key === 'Escape') {
        stopReading(chart);
        return;
    }
    const current = chart.dataset.reading === undefined ? -1 : Number(chart.dataset.reading);
    const next = readingStep(event.key, current, pointsOf(chart).length);
    if (next === null) return;
    event.preventDefault();
    readAt(chart, next, true);
});

/* ---------- thumbnails: a missing low.webp is retried once as high.webp ---------- */
const retryThumb = (img) => {
    if (img.dataset.retried) return false;
    const retry = fallbackSrc(img.getAttribute('src'));
    if (!retry) return false;
    img.dataset.retried = '1';
    img.src = retry;
    return true;
};

// The window's capture phase runs before app.js's document-level capture handler, which removes a failed
// `data-optional` image on the spot: stopping the event here lets the retry happen first. A second failure
// (retried is set) falls through untouched and app.js removes the picture, the paper frame staying.
window.addEventListener('error', (event) => {
    const img = event.target;
    if (img instanceof HTMLImageElement && img.matches('.act-thumb img') && retryThumb(img)) event.stopImmediatePropagation();
}, true);

// Pictures that failed before this deferred module ran never raised an event it could see; this top-level sweep
// runs before app.js's DOMContentLoaded sweep removes them.
document.querySelectorAll('.act-thumb img').forEach((img) => {
    if (img.complete && img.naturalWidth === 0 && img.getAttribute('src')) retryThumb(img);
});

/* ---------- the draw-in plays once per page load ---------- */
// <html> is never morphed by Livewire, so a range change keeps this class and does not replay the cover.
document.addEventListener('animationend', (event) => {
    if (event.target.classList?.contains('act-cover')) document.documentElement.classList.add('act-drawn');
});
