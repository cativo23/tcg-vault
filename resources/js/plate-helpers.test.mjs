import test from 'node:test';
import assert from 'node:assert/strict';
import {
    CAL_MAX, CAL_MIN, LOUPE_MIN_ZOOM, clamp01, loupeView, loupeZoom, noteParts, nudgeLoupe,
    parseCalibration, plateLayout, plateNoteKind, pointOnCard, tiltFor,
} from './plate-helpers.js';

const near = (actual, expected, label) => assert.ok(Math.abs(actual - expected) < 1e-3, `${label ?? ''} ${actual} ≈ ${expected}`);

test('a stored calibration is used only when it is a number inside the slider range', () => {
    assert.equal(parseCalibration('1.08'), 1.08);
    assert.equal(parseCalibration(String(CAL_MIN)), CAL_MIN);
    assert.equal(parseCalibration(String(CAL_MAX)), CAL_MAX);
    assert.equal(parseCalibration(null), 1);
    assert.equal(parseCalibration(''), 1);
    assert.equal(parseCalibration('abc'), 1);
    assert.equal(parseCalibration('0.2'), 1);
    assert.equal(parseCalibration('9'), 1);
});

test('fitted on a desktop plate the card fills the height the screen leaves and sits centred', () => {
    const g = plateLayout({ plateWidth: 600, viewportWidth: 1440, viewportHeight: 900, mode: 'fit', cal: 1 });
    near(g.w, 395.1818, 'w');
    near(g.h, 552, 'h');
    assert.equal(g.height, 672);
    assert.equal(g.x, 102);
    assert.equal(g.y, 60);
});

test('actual size draws the card at 63 × 88 mm, and the calibration scales it', () => {
    const g = plateLayout({ plateWidth: 600, viewportWidth: 1440, viewportHeight: 900, mode: 'actual', cal: 1 });
    assert.equal(g.scale, 1);
    near(g.w, 238.1102, 'w');
    near(g.h, 332.5984, 'h');
    assert.deepEqual([g.x, g.y, g.height], [181, 170, 672]);

    const c = plateLayout({ plateWidth: 600, viewportWidth: 1440, viewportHeight: 900, mode: 'actual', cal: 1.2 });
    near(c.w, 285.7323, 'calibrated w');
    assert.deepEqual([c.x, c.y], [157, 136]);
});

test('on a phone the plate keeps 40px margins and the card takes the plate width', () => {
    const g = plateLayout({ plateWidth: 390, viewportWidth: 390, viewportHeight: 844, mode: 'fit', cal: 1 });
    near(g.w, 310, 'w');
    assert.deepEqual([g.x, g.y, g.height], [40, 40, 513]);
});

test('the loupe stops at one image pixel per device pixel, so a 600px scan has none fitted on a 2x screen', () => {
    near(loupeZoom({ naturalWidth: 600, devicePixelRatio: 1, shownWidth: 395.1818 }), 1.51829, '1x fitted');
    assert.ok(loupeZoom({ naturalWidth: 600, devicePixelRatio: 2, shownWidth: 395.1818 }) < LOUPE_MIN_ZOOM);
    assert.ok(loupeZoom({ naturalWidth: 600, devicePixelRatio: 2, shownWidth: 238.1102 }) >= LOUPE_MIN_ZOOM);
    assert.equal(loupeZoom({ naturalWidth: 0, devicePixelRatio: 1, shownWidth: 300 }), 0);
    assert.equal(loupeZoom({ naturalWidth: 600, devicePixelRatio: 1, shownWidth: 0 }), 0);
});

test('the loupe centres on its point and shows the image magnified around it', () => {
    const v = loupeView({ card: { x: 100, y: 60, w: 400, h: 552 }, zoom: 1.5, at: { x: 0.5, y: 0.25 }, size: 176 });
    assert.deepEqual(v, { bgWidth: 600, bgHeight: 828, bgX: -212, bgY: -119, left: 212, top: 110 });
});

test('arrow keys move the loupe a little, Shift a lot, and never off the card; other keys do nothing', () => {
    const right = nudgeLoupe({ x: 0.5, y: 0.5 }, 'ArrowRight', false);
    near(right.x, 0.53);
    assert.equal(right.y, 0.5);
    assert.deepEqual(nudgeLoupe({ x: 0.05, y: 0.5 }, 'ArrowLeft', true), { x: 0, y: 0.5 });
    assert.deepEqual(nudgeLoupe({ x: 0.5, y: 0.95 }, 'ArrowDown', true), { x: 0.5, y: 1 });
    assert.equal(nudgeLoupe({ x: 0.5, y: 0.5 }, 'Enter', false), null);
    assert.equal(clamp01(-2), 0);
    assert.equal(clamp01(3), 1);
});

test('a pointer position is measured from the card, not the plate', () => {
    assert.deepEqual(
        pointOnCard({ clientX: 330, clientY: 210, rect: { left: 30, top: 10 }, card: { x: 100, y: 60, w: 400, h: 560 } }),
        { x: 0.5, y: 0.25 },
    );
});

test('the tilt follows the pointer a few degrees and lets go once it leaves the card', () => {
    assert.deepEqual(tiltFor({ x: 0.5, y: 0.5 }), { rx: 0, ry: 0, gx: 0, gy: 0 });
    assert.deepEqual(tiltFor({ x: 0, y: 0 }), { rx: 5.5, ry: -7, gx: -35, gy: -20 });
    assert.deepEqual(tiltFor({ x: -0.1, y: 0.5 }), { rx: 0, ry: -7, gx: -35, gy: 0 });
    assert.equal(tiltFor({ x: 1.2, y: 0.5 }), null);
    assert.equal(tiltFor({ x: 0.5, y: -0.2 }), null);
});

test('the note under the plate: loupe first, then a photo, then actual size, then fitted or no loupe', () => {
    assert.equal(plateNoteKind({ loupeOn: true, loupeAvailable: true, mode: 'fit', view: 'official' }), 'loupe');
    assert.equal(plateNoteKind({ loupeOn: false, loupeAvailable: true, mode: 'actual', view: 'photo' }), 'photo');
    assert.equal(plateNoteKind({ loupeOn: false, loupeAvailable: false, mode: 'actual', view: 'official' }), 'actual');
    assert.equal(plateNoteKind({ loupeOn: false, loupeAvailable: true, mode: 'fit', view: 'official' }), 'fit');
    assert.equal(plateNoteKind({ loupeOn: false, loupeAvailable: false, mode: 'fit', view: 'official' }), 'no-loupe');
});

test('each note kind is built from the facts it names (zoom, shown percentage, scan size), and a photo has no note here: its sentence is the page\'s', () => {
    assert.deepEqual(noteParts('loupe', { zoom: 1.5 }), [{ b: 'Loupe ' }, { mono: '×1.50' }, ': one pixel of the scan per pixel of your screen, which is all the detail the scan has. Arrow keys move it, Esc puts it away.']);
    assert.deepEqual(noteParts('photo', {}), []);
    assert.deepEqual(noteParts('actual', {}), ['Printed size, ', { mono: '63 × 88' }, ' mm, as near as this screen allows. ', { calibrate: 'Match a real card' }]);
    assert.deepEqual(noteParts('fit', { scale: 0.7 }), ['Shown at ', { mono: '70%' }, ' of its printed size. The marks give the real size, ', { mono: '63 × 88' }, ' mm.']);
    assert.deepEqual(noteParts('no-loupe', { naturalWidth: 600, naturalHeight: 825 }), ['No loupe at this size: the screen already shows every pixel of the ', { mono: '600 × 825' }, ' scan. Switch to ', { b: 'Actual size' }, ' to use it.']);
});
