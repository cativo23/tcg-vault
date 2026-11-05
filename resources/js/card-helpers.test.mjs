import test from 'node:test';
import assert from 'node:assert/strict';
import { nearestIndex, setValue, shareUrl, stepReading, stopsReadingOnLeave } from './card-helpers.js';

test('the shared link is the page with its variant, as the URL holds it, and nothing else', () => {
    assert.equal(shareUrl('https://x.test/demo/swsh7/215?range=3m&variant=holofoil&theme=dark#chart'), 'https://x.test/demo/swsh7/215?variant=holofoil');
    assert.equal(shareUrl('https://x.test/demo/swsh7/215?range=3m'), 'https://x.test/demo/swsh7/215');
    assert.equal(shareUrl('https://x.test/demo/swsh7/215?variant='), 'https://x.test/demo/swsh7/215');
    assert.equal(shareUrl('https://x.test/demo/sv08.5/059?variant=holofoil%3Acosmos%2Bplayer-rewards-program'), 'https://x.test/demo/sv08.5/059?variant=holofoil%3Acosmos%2Bplayer-rewards-program');
    assert.equal(shareUrl('https://x.test/demo/sv08.5/059?variant=holofoil:cosmos+player-rewards-program'), 'https://x.test/demo/sv08.5/059?variant=holofoil:cosmos+player-rewards-program');
});

test('the chart reads the nearest day, the first of two equally near ones, and nothing on an empty chart', () => {
    assert.equal(nearestIndex([0, 25, 50, 100], 30), 1);
    assert.equal(nearestIndex([0, 25, 50, 100], 99), 3);
    assert.equal(nearestIndex([0, 50], 25), 0);
    assert.equal(nearestIndex([], 10), -1);
});

test('arrow keys step the reading one day, stay inside the chart, and with no reading yet the first press steps from the newest day the chart rests on', () => {
    assert.equal(stepReading(2, 5, 'ArrowLeft'), 1);
    assert.equal(stepReading(2, 5, 'ArrowRight'), 3);
    assert.equal(stepReading(0, 5, 'ArrowLeft'), 0);
    assert.equal(stepReading(4, 5, 'ArrowRight'), 4);
    assert.equal(stepReading(null, 5, 'ArrowLeft'), 3);
    assert.equal(stepReading(null, 5, 'ArrowRight'), 4);
    assert.equal(stepReading(null, 1, 'ArrowLeft'), 0);
});

test('Up steps like Right and Down like Left, as a slider does', () => {
    assert.equal(stepReading(2, 5, 'ArrowUp'), 3);
    assert.equal(stepReading(2, 5, 'ArrowDown'), 1);
    assert.equal(stepReading(null, 5, 'ArrowDown'), 3);
    assert.equal(stepReading(4, 5, 'ArrowUp'), 4);
});

test('Home and End jump to the first and last day, and any other key or an empty chart reads nothing', () => {
    assert.equal(stepReading(2, 5, 'Home'), 0);
    assert.equal(stepReading(2, 5, 'End'), 4);
    assert.equal(stepReading(2, 5, 'Enter'), null);
    assert.equal(stepReading(null, 0, 'ArrowLeft'), null);
});

test('a pointer leaving a chart that still has keyboard focus keeps its reading, and losing focus always ends it', () => {
    assert.equal(stopsReadingOnLeave('pointerout', true), false);
    assert.equal(stopsReadingOnLeave('pointerout', false), true);
    assert.equal(stopsReadingOnLeave('focusout', true), true);
    assert.equal(stopsReadingOnLeave('focusout', false), true);
});

test('the slider value is the index of the day being read and its value text the reading', () => {
    const attributes = {};
    const slider = { setAttribute: (name, value) => { attributes[name] = value; } };

    setValue(slider, 3, '4 Nov · $10.00');

    assert.deepEqual(attributes, { 'aria-valuenow': '3', 'aria-valuetext': '4 Nov · $10.00' });
});
