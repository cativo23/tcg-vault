// Pure theme decisions for app.js. Kept DOM-free so node --test can cover them.

/** `light` / `dark` only; anything else is "no theme". */
export function validTheme(value) {
    return value === 'light' || value === 'dark' ? value : null;
}

/** The explicit `?theme=` of a URL search string, when it is valid. */
export function themeFromSearch(search) {
    return validTheme(new URLSearchParams(search || '').get('theme'));
}

/**
 * Which theme to re-apply after a navigation event. An explicit `?theme=` seen on first load (the override) wins
 * over the stored choice until the visitor clicks the toggle, which clears it.
 */
export function themeAfterNavigation({ override, stored }) {
    return validTheme(override) || validTheme(stored);
}
