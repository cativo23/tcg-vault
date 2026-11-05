// Pure helpers for the card page; no DOM access, so `npm run test:js` covers them.

/**
 * The address to share: the page itself plus the `variant=` pair exactly as the URL holds it (re-encoding it could
 * change what the server reads), never the range or display switches.
 */
export function shareUrl(href) {
    const url = new URL(href);
    const pair = url.search.slice(1).split('&').find((part) => part.startsWith('variant=') && part.length > 'variant='.length);
    return url.origin + url.pathname + (pair ? `?${pair}` : '');
}

/** The index of the reading nearest to x, or -1 when there is none. */
export function nearestIndex(xs, x) {
    let best = -1;
    let distance = Number.POSITIVE_INFINITY;
    xs.forEach((value, i) => {
        const d = Math.abs(value - x);
        if (d < distance) {
            distance = d;
            best = i;
        }
    });
    return best;
}

/**
 * The reading a key moves to: one day left or right (Up steps like Right and Down like Left, as a slider does),
 * Home and End for the ends; null for any other key or an empty chart. With no reading yet (current is null) an
 * arrow steps from the newest day, where the chart rests.
 */
export function stepReading(current, count, key) {
    if (count < 1) return null;
    if (key === 'Home') return 0;
    if (key === 'End') return count - 1;
    const delta = { ArrowLeft: -1, ArrowDown: -1, ArrowRight: 1, ArrowUp: 1 }[key];
    if (delta === undefined) return null;
    const from = current === null ? count - 1 : current;
    return Math.max(0, Math.min(count - 1, from + delta));
}

/**
 * Whether leaving the chart ends its reading. A pointer leaving a chart that still has keyboard focus keeps it, so a
 * keyboard reading survives a mouse moving away; losing focus always ends it.
 */
export function stopsReadingOnLeave(eventType, chartIsFocused) {
    return !(eventType === 'pointerout' && chartIsFocused);
}

/** Moves the chart slider: its value is the index of the day being read, its value text what the readout says. */
export function setValue(slider, index, text) {
    slider.setAttribute('aria-valuenow', String(index));
    slider.setAttribute('aria-valuetext', text);
}
