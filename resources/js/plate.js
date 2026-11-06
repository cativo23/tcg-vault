// The card page's plate tools: Fitted or Actual size, calibration, the loupe and the pointer tilt. card.js owns the
// views (photo or official art) and tells this module through `data-view` and the `crd:view` event; the pure logic is
// in plate-helpers.js. Nothing here runs without JS: the plate stays the static picture the server rendered.
import {
    CAL_MAX, CAL_MIN, LOUPE_MIN_ZOOM, clamp01, loupeView, loupeZoom, noteParts, nudgeLoupe,
    parseCalibration, plateLayout, plateNoteKind, pointOnCard, tiltFor,
} from './plate-helpers.js';

const CAL_KEY = 'tcg-vault-cal';
/** The card's move between sizes: --dur-slow, the page's motion cap. */
const MOVE_MS = 520;
const still = () => document.documentElement.classList.contains('still');

const node = (tag, text, className) => {
    const el = document.createElement(tag);
    if (className) el.className = className;
    if (text !== undefined) el.textContent = text;
    return el;
};

function initPlate(plate) {
    const col = plate.closest('.crd-plate-col');
    const pos = plate.querySelector('[data-crd-pos]');
    const tilt = plate.querySelector('[data-crd-tilt]');
    const marks = plate.querySelector('[data-crd-marks]');
    const loupe = plate.querySelector('[data-crd-loupe]');
    const note = col.querySelector('[data-crd-tool-note]');
    const status = col.querySelector('[data-crd-status]');
    const sizeGroup = col.querySelector('[data-crd-size-group]');
    const loupeButton = col.querySelector('[data-crd-loupe-toggle]');
    const calib = col.querySelector('[data-crd-calib]');
    const slider = col.querySelector('[data-crd-cal]');
    const calValue = col.querySelector('[data-crd-cal-value]');
    const calReset = col.querySelector('[data-crd-cal-reset]');
    const finePointer = window.matchMedia('(hover: hover) and (pointer: fine)').matches;

    // Part A draws the marks inside the card's box; laid out, they sit beside it so scaling never thickens them.
    if (marks.parentElement !== plate) plate.append(marks);

    let img = plate.querySelector('[data-crd-img]');
    let mode = 'fit';
    let loupeOn = false;
    let geo = null;
    let at = { x: 0.5, y: 0.32 };
    let cal = 1;
    let shown = '';
    try { cal = parseCalibration(localStorage.getItem(CAL_KEY)); } catch { cal = 1; }

    const view = () => plate.dataset.view || 'official';
    const zoom = () => (img && geo
        ? loupeZoom({ naturalWidth: img.naturalWidth, devicePixelRatio: window.devicePixelRatio || 1, shownWidth: geo.w })
        : 0);

    // Said once per user action, never on load, resize or while the slider moves.
    const announce = (text) => {
        if (!status) return;
        status.textContent = '';
        requestAnimationFrame(() => { status.textContent = text; });
    };

    const untilt = () => {
        tilt?.classList.remove('is-live');
        tilt?.style.setProperty('--crd-rx', '0deg');
        tilt?.style.setProperty('--crd-ry', '0deg');
    };

    const moveLoupe = () => {
        if (!geo || !img) return;
        const v = loupeView({ card: geo, zoom: zoom(), at, size: loupe.offsetWidth });
        // The URL goes into a CSS url("…"): escape the characters that could end or break it.
        const src = (img.currentSrc || img.src).replace(/["\\\n]/g, encodeURIComponent);
        loupe.style.backgroundImage = `url("${src}")`;
        loupe.style.backgroundSize = `${v.bgWidth}px ${v.bgHeight}px`;
        loupe.style.backgroundPosition = `${v.bgX}px ${v.bgY}px`;
        loupe.style.transform = `translate(${v.left}px, ${v.top}px)`;
    };

    let describe = () => {};

    const setLoupe = (on, speak = false) => {
        loupeOn = on;
        loupeButton?.setAttribute('aria-pressed', String(on));
        plate.classList.toggle('is-loupe', on);
        loupe.classList.toggle('is-on', on);
        if (on) {
            plate.tabIndex = 0;
            plate.setAttribute('role', 'group');
            plate.setAttribute('aria-label', 'Loupe: arrow keys move it');
            untilt();
            moveLoupe();
            plate.focus({ preventScroll: true });
        } else {
            plate.removeAttribute('tabindex');
            plate.removeAttribute('role');
            plate.removeAttribute('aria-label');
        }
        describe();
        if (speak) announce(on ? 'Loupe on. Arrow keys move it, Escape puts it away.' : 'Loupe off.');
    };

    // The note is replaced only when its words change, so a resize or a slider drag never rewrites it.
    describe = () => {
        if (!note) return;
        const z = zoom();
        const available = z >= LOUPE_MIN_ZOOM;
        if (loupeButton) loupeButton.disabled = !available;
        if (!available && loupeOn) {
            setLoupe(false);
            return;
        }
        // Until the scan has loaded its size is unknown (naturalWidth is 0, which would read "0 × 0"): say nothing.
        if (!img || !geo || !img.complete || img.naturalWidth === 0) {
            note.replaceChildren();
            shown = '';
            return;
        }
        const kind = plateNoteKind({ loupeOn, loupeAvailable: available, mode, view: view() });
        // For a photo the helper returns no parts: its sentence is the page's (`[data-crd-note]`).
        const parts = noteParts(kind, {
            zoom: z, scale: geo.scale, naturalWidth: img.naturalWidth, naturalHeight: img.naturalHeight,
        });
        const text = JSON.stringify(parts);
        if (text === shown) return;
        shown = text;
        note.replaceChildren(...parts.map((part) => {
            if (typeof part === 'string') return document.createTextNode(part);
            if (part.b !== undefined) return node('b', part.b);
            if (part.mono !== undefined) return node('span', part.mono, 'crd-mono');
            const open = node('button', part.calibrate, 'crd-inline');
            open.type = 'button';
            open.dataset.crdCalOpen = '';
            open.setAttribute('aria-controls', 'crd-calib');
            open.setAttribute('aria-expanded', String(Boolean(calib && !calib.hidden)));
            return open;
        }));
    };

    const layout = (animate) => {
        plate.classList.add('is-live');
        const photo = view() === 'photo';
        const g = plateLayout({
            plateWidth: plate.clientWidth, viewportWidth: window.innerWidth, viewportHeight: window.innerHeight,
            mode: photo ? 'fit' : mode, cal,
        });
        geo = g;
        plate.style.height = `${g.height}px`;
        pos.classList.toggle('is-moving', Boolean(animate) && !still());
        pos.style.transform = `translate(${g.x}px, ${g.y}px) scale(${g.scale})`;
        const place = () => {
            marks.style.transform = `translate(${g.x}px, ${g.y}px)`;
            marks.style.width = `${g.w}px`;
            marks.style.height = `${g.h}px`;
            plate.style.setProperty('--crd-grid-x', `${g.x}px`);
            plate.style.setProperty('--crd-grid-y', `${g.y}px`);
            marks.classList.remove('is-moving');
        };
        if (animate && !still()) {
            marks.classList.add('is-moving');
            setTimeout(place, MOVE_MS);
        } else {
            place();
        }
        // A photo is not printed at 63 mm: no Actual size (the marks hide through data-view in CSS).
        if (sizeGroup) sizeGroup.hidden = photo;
        describe();
        if (loupeOn) moveLoupe();
    };

    const applyCal = (value) => {
        cal = value;
        plate.style.setProperty('--crd-cal', String(cal));
        if (slider) slider.value = String(cal);
        if (calValue) calValue.textContent = `${Math.round(cal * 100)}%`;
    };
    applyCal(cal);
    slider?.setAttribute('min', String(CAL_MIN));
    slider?.setAttribute('max', String(CAL_MAX));

    slider?.addEventListener('input', () => {
        applyCal(parseCalibration(slider.value));
        try { localStorage.setItem(CAL_KEY, String(cal)); } catch { /* private mode: the factor lasts for this page only */ }
        layout(false);
    });
    calReset?.addEventListener('click', () => {
        applyCal(1);
        try { localStorage.removeItem(CAL_KEY); } catch { /* nothing stored */ }
        layout(false);
        announce('Calibration reset.');
    });
    note?.addEventListener('click', (event) => {
        const open = event.target.closest('[data-crd-cal-open]');
        if (!open || !calib) return;
        calib.hidden = !calib.hidden;
        open.setAttribute('aria-expanded', String(!calib.hidden));
        if (!calib.hidden) slider?.focus();
    });

    sizeGroup?.querySelectorAll('[data-crd-size]').forEach((button) => button.addEventListener('click', () => {
        if (button.dataset.crdSize === mode) return;
        mode = button.dataset.crdSize;
        sizeGroup.querySelectorAll('[data-crd-size]').forEach((b) => b.setAttribute('aria-pressed', String(b === button)));
        if (mode === 'fit' && calib) calib.hidden = true;
        layout(true);
        announce(mode === 'actual' ? 'Actual size.' : 'Fitted.');
    }));

    loupeButton?.addEventListener('click', () => setLoupe(!loupeOn, true));

    // card.js switched between the photo and the official art.
    plate.addEventListener('crd:view', () => {
        img = plate.querySelector('[data-crd-img]');
        if (view() === 'photo' && calib) calib.hidden = true;
        // The new picture's size is unknown until it loads.
        shown = '';
        layout(false);
    });

    plate.addEventListener('keydown', (event) => {
        if (!loupeOn) return;
        if (event.key === 'Escape') {
            setLoupe(false, true);
            loupeButton?.focus();
            return;
        }
        const next = nudgeLoupe(at, event.key, event.shiftKey);
        if (!next) return;
        event.preventDefault();
        at = next;
        moveLoupe();
    });

    const pointer = (event) => pointOnCard({ clientX: event.clientX, clientY: event.clientY, rect: plate.getBoundingClientRect(), card: geo });

    plate.addEventListener('pointermove', (event) => {
        if (!geo) return;
        const p = pointer(event);
        if (loupeOn) {
            at = { x: clamp01(p.x), y: clamp01(p.y) };
            moveLoupe();
            if (event.pointerType !== 'mouse') event.preventDefault();
            return;
        }
        if (still() || !finePointer || event.pointerType !== 'mouse' || !tilt) return;
        const t = tiltFor(p);
        if (!t) {
            untilt();
            return;
        }
        tilt.classList.add('is-live');
        tilt.style.setProperty('--crd-rx', `${t.rx}deg`);
        tilt.style.setProperty('--crd-ry', `${t.ry}deg`);
        tilt.style.setProperty('--crd-gx', `${t.gx}%`);
        tilt.style.setProperty('--crd-gy', `${t.gy}%`);
    });
    plate.addEventListener('pointerleave', untilt);
    plate.addEventListener('pointerdown', (event) => {
        if (!loupeOn || !geo) return;
        const p = pointer(event);
        at = { x: clamp01(p.x), y: clamp01(p.y) };
        moveLoupe();
    });

    // The picture itself is handled by card.js (a failed one becomes the hatched face); here only its size matters.
    if (img) img.addEventListener('load', () => { shown = ''; layout(false); });

    let resizeTimer;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => layout(false), 120);
    });

    layout(false);
}

const plate = document.querySelector('[data-crd-plate]');
if (plate) initPlate(plate);
