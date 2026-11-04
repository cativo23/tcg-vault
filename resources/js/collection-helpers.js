// Pure helpers for the collection page; no DOM access, so `npm run test:js` covers them.

/** The tab a key moves focus to (wrapping), or null for keys and states that do nothing. */
export function nextTabIndex(key, current, count) {
    if (count < 1 || current < 0) return null;
    const target = { ArrowRight: current + 1, ArrowLeft: current - 1, Home: 0, End: count - 1 }[key];
    if (target === undefined) return null;
    return (target + count) % count;
}

/** Where to scroll a tab strip so a tab is fully visible with `pad` to spare, or null if it already is. */
export function revealScrollLeft({ tabLeft, tabWidth, scrollLeft, viewWidth, pad = 24 }) {
    const left = tabLeft - pad;
    const right = tabLeft + tabWidth + pad;
    if (left < scrollLeft) return Math.max(0, left);
    if (right > scrollLeft + viewWidth) return right - viewWidth;
    return null;
}

/** Whether the strip runs past its right edge (2px of slop for sub-pixel layout). */
export function overflowsRight(scrollLeft, viewWidth, scrollWidth) {
    return scrollLeft + viewWidth < scrollWidth - 2;
}

/** The high-resolution twin of a failed tcgdex low.webp, or null when there is nothing to retry. */
export function fallbackSrc(src) {
    const prefix = 'https://assets.tcgdex.net/';
    if (typeof src !== 'string' || !src.startsWith(prefix) || !src.endsWith('/low.webp')) return null;
    return src.slice(0, -'low.webp'.length) + 'high.webp';
}

/** Copies text: the clipboard API when usable, else the legacy path. Resolves to whether it worked. */
export async function copyText(text, { clipboard, secure, legacy }) {
    if (clipboard && secure) {
        try {
            await clipboard.writeText(text);
            return true;
        } catch {
            // denied or unavailable: fall through to the legacy path
        }
    }
    try {
        return legacy(text) === true;
    } catch {
        return false;
    }
}
