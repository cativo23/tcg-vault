// Pure helpers for the card page's plate tools; no DOM access, so `npm run test:js` covers them.

/** CSS fixes 96 px to the inch, so this many CSS pixels make a millimetre. */
export const PX_PER_MM = 96 / 25.4;

/** A Pokémon card's printed size. */
export const CARD_MM = { w: 63, h: 88 };

/** The calibration slider's range; anything outside it is treated as unset. */
export const CAL_MIN = 0.6;
export const CAL_MAX = 1.6;

/** Below this the loupe would show barely more than the plate already does. */
export const LOUPE_MIN_ZOOM = 1.15;

/** A stored calibration factor, or 1 when it is missing, unreadable or out of range. */
export function parseCalibration(raw) {
    const value = typeof raw === 'string' && raw.trim() !== '' ? Number(raw) : Number.NaN;
    return Number.isFinite(value) && value >= CAL_MIN && value <= CAL_MAX ? value : 1;
}

/**
 * Where the card sits on the plate and how tall the plate is. "actual" draws the card at 63 × 88 mm times the
 * calibration; "fit" scales it into the plate. Margins and heights are the approved mockup's.
 */
export function plateLayout({ plateWidth, viewportWidth, viewportHeight, mode, cal }) {
    const narrow = viewportWidth < 960;
    const cardW = CARD_MM.w * PX_PER_MM * cal;
    const cardH = CARD_MM.h * PX_PER_MM * cal;
    const margin = narrow ? 40 : Math.max(48, plateWidth * 0.1);
    const maxH = narrow ? Math.min(viewportHeight * 0.64, 600) : Math.max(420, viewportHeight - 56 - 32 - 140);
    const fitW = Math.max(0, Math.min(plateWidth - 2 * margin, (maxH - 2 * margin) * CARD_MM.w / CARD_MM.h));
    const scale = mode === 'actual' ? 1 : fitW / cardW;
    const w = cardW * scale;
    const h = cardH * scale;
    const height = Math.round(Math.max(fitW * CARD_MM.h / CARD_MM.w, mode === 'actual' ? h : 0) + 2 * margin);
    return { x: Math.round((plateWidth - w) / 2), y: Math.round((height - h) / 2), w, h, scale, height };
}

/** How much the loupe magnifies when it shows one image pixel per device pixel; 0 when nothing is known yet. */
export function loupeZoom({ naturalWidth, devicePixelRatio, shownWidth }) {
    if (!naturalWidth || !shownWidth || shownWidth <= 0) return 0;
    return naturalWidth / (devicePixelRatio || 1) / shownWidth;
}

/** Background size and offset of the loupe, and where it sits, for a point `at` (0..1 across the card). */
export function loupeView({ card, zoom, at, size }) {
    const px = at.x * card.w;
    const py = at.y * card.h;
    return {
        bgWidth: card.w * zoom,
        bgHeight: card.h * zoom,
        bgX: size / 2 - px * zoom,
        bgY: size / 2 - py * zoom,
        left: card.x + px - size / 2,
        top: card.y + py - size / 2,
    };
}

export const clamp01 = (value) => Math.max(0, Math.min(1, value));

/** The loupe's point after an arrow key (Shift for a bigger step), kept on the card; null for any other key. */
export function nudgeLoupe(at, key, shift) {
    const step = shift ? 0.1 : 0.03;
    const move = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] }[key];
    if (!move) return null;
    return { x: clamp01(at.x + move[0]), y: clamp01(at.y + move[1]) };
}

/** A pointer position as a share of the card (0..1 inside it, beyond that outside). */
export function pointOnCard({ clientX, clientY, rect, card }) {
    return { x: (clientX - rect.left - card.x) / card.w, y: (clientY - rect.top - card.y) / card.h };
}

// toFixed then back to a number, and + 0 turns a -0 into 0.
const fixed = (value, digits) => Number(value.toFixed(digits)) + 0;

/** The tilt and glare offset for a pointer near the card, or null once it has wandered off. */
export function tiltFor(point) {
    if (point.x <= -0.15 || point.x >= 1.15 || point.y <= -0.1 || point.y >= 1.1) return null;
    const cx = clamp01(point.x) - 0.5;
    const cy = clamp01(point.y) - 0.5;
    return { rx: fixed(-cy * 11, 2), ry: fixed(cx * 14, 2), gx: fixed(cx * 70, 1), gy: fixed(cy * 40, 1) };
}

/** Which note sits under the plate. */
export function plateNoteKind({ loupeOn, loupeAvailable, mode, view }) {
    if (loupeOn) return 'loupe';
    if (view === 'photo') return 'photo';
    if (mode === 'actual') return 'actual';
    return loupeAvailable ? 'fit' : 'no-loupe';
}

/**
 * The note under the plate as plain segments: strings, `{ b }` bold, `{ mono }` numerals and `{ calibrate }` the
 * "Match a real card" button. Kept pure so the wording is tested; plate.js only turns segments into nodes. A photo has
 * no note here: its sentence is the page's own (`[data-crd-note]`), so the approved copy lives in one place.
 */
export function noteParts(kind, { zoom = 0, scale = 1, naturalWidth = 0, naturalHeight = 0 } = {}) {
    if (kind === 'loupe') return [{ b: 'Loupe ' }, { mono: `×${zoom.toFixed(2)}` }, ': one pixel of the scan per pixel of your screen, which is all the detail the scan has. Arrow keys move it, Esc puts it away.'];
    if (kind === 'photo') return [];
    if (kind === 'actual') return ['Printed size, ', { mono: '63 × 88' }, ' mm, as near as this screen allows. ', { calibrate: 'Match a real card' }];
    if (kind === 'no-loupe') return ['No loupe at this size: the screen already shows every pixel of the ', { mono: `${naturalWidth} × ${naturalHeight}` }, ' scan. Switch to ', { b: 'Actual size' }, ' to use it.'];
    return ['Shown at ', { mono: `${Math.round(scale * 100)}%` }, ' of its printed size. The marks give the real size, ', { mono: '63 × 88' }, ' mm.'];
}
