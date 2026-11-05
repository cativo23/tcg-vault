// Pure helpers for the activity page; no DOM access, so `npm run test:js` covers them.

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

/** The reading a key moves to, clamped at the ends, or null for keys that do nothing. With no reading yet, an arrow steps from the point the chart rests on, the newest. */
export function readingStep(key, current, count) {
    if (count < 1) return null;
    if (key === 'Home') return 0;
    if (key === 'End') return count - 1;
    if (key !== 'ArrowLeft' && key !== 'ArrowRight') return null;
    const from = current < 0 ? count - 1 : current;
    return Math.max(0, Math.min(count - 1, from + (key === 'ArrowRight' ? 1 : -1)));
}

/** What the reading slot says for one point. */
export function readingText(point) {
    return `${point.d} · ${point.v}`;
}
