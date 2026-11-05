// Helpers for the activity page. They touch only the element-like object they are handed (setAttribute, dataset, getAttribute, src, remove), so `npm run test:js` covers them with plain objects.
import { fallbackSrc } from './collection-helpers.js';

/** Index of the point whose x (0-100) is nearest to x, or -1 when there are none. A tie keeps the earlier point. */
export function nearestIndex(xs, x) {
    let best = -1;
    let bestDistance = Infinity;
    xs.forEach((value, i) => {
        const distance = Math.abs(value - x);
        if (distance < bestDistance) {
            bestDistance = distance;
            best = i;
        }
    });
    return best;
}

/** The reading a key moves to, clamped at the ends, or null for keys that do nothing. Up steps like Right and Down like Left, as a slider does. With no reading yet, an arrow steps from the point the chart rests on, the newest. */
export function readingStep(key, current, count) {
    if (count < 1) return null;
    if (key === 'Home') return 0;
    if (key === 'End') return count - 1;
    const direction = { ArrowRight: 1, ArrowUp: 1, ArrowLeft: -1, ArrowDown: -1 }[key];
    if (direction === undefined) return null;
    const from = current < 0 ? count - 1 : current;
    return Math.max(0, Math.min(count - 1, from + direction));
}

/** What the reading slot says for one point. */
export function readingText(point) {
    return `${point.d} · ${point.v}`;
}

/** Whether leaving the chart ends its reading. A pointer leaving a chart that still has keyboard focus keeps it, so a keyboard reading survives a mouse moving away; losing focus always ends it. */
export function stopsReadingOnLeave(eventType, chartIsFocused) {
    return !(eventType === 'pointerout' && chartIsFocused);
}

/** Moves the chart slider: its value is the index of the day being read, its value text what the readout says. */
export function setValue(slider, index, text) {
    slider.setAttribute('aria-valuenow', String(index));
    slider.setAttribute('aria-valuetext', text);
}

/** A thumbnail that failed to load is retried once at its fallback address; a second failure, or no fallback to try, removes it. */
export function degradeThumb(img) {
    const retry = img.dataset.retried ? null : fallbackSrc(img.getAttribute('src'));
    if (retry) {
        img.dataset.retried = '1';
        img.src = retry;
        return;
    }

    img.remove();
}
