import test from 'node:test';
import assert from 'node:assert/strict';
import { nearestIndex, readingStep, readingText, stopsReadingOnLeave } from './activity-helpers.js';

test('the point nearest the pointer is read; a tie keeps the earlier point', () => {
    assert.equal(nearestIndex([0, 50, 100], 20), 0);
    assert.equal(nearestIndex([0, 50, 100], 30), 1);
    assert.equal(nearestIndex([0, 50, 100], 25), 0);
    assert.equal(nearestIndex([0, 50, 100], 140), 2);
});

test('no points, no reading', () => {
    assert.equal(nearestIndex([], 10), -1);
});

test('arrows step one point and stop at the ends; with no reading yet they step from the newest point', () => {
    assert.equal(readingStep('ArrowLeft', -1, 5), 3);
    assert.equal(readingStep('ArrowRight', -1, 5), 4);
    assert.equal(readingStep('ArrowLeft', 4, 5), 3);
    assert.equal(readingStep('ArrowRight', 4, 5), 4);
    assert.equal(readingStep('ArrowLeft', 0, 5), 0);
    assert.equal(readingStep('ArrowRight', 1, 5), 2);
});

test('Home and End jump to the oldest and the newest point', () => {
    assert.equal(readingStep('Home', 3, 5), 0);
    assert.equal(readingStep('End', 0, 5), 4);
});

test('Up steps like Right and Down like Left, as a slider does', () => {
    assert.equal(readingStep('ArrowUp', 2, 5), 3);
    assert.equal(readingStep('ArrowDown', 2, 5), 1);
    assert.equal(readingStep('ArrowUp', 4, 5), 4);
    assert.equal(readingStep('ArrowDown', 0, 5), 0);
    assert.equal(readingStep('ArrowUp', -1, 5), 4);
    assert.equal(readingStep('ArrowDown', -1, 5), 3);
});

test('other keys and an empty chart do nothing', () => {
    assert.equal(readingStep('Enter', 2, 5), null);
    assert.equal(readingStep('ArrowLeft', -1, 0), null);
    assert.equal(readingStep('ArrowUp', -1, 0), null);
});

test('the readout is the day and the value', () => {
    assert.equal(readingText({ d: '4 Nov', v: '$10,814.05' }), '4 Nov · $10,814.05');
});

test('a pointer leaving a chart that keeps keyboard focus does not end the reading; losing focus always does', () => {
    assert.equal(stopsReadingOnLeave('pointerout', false), true);
    assert.equal(stopsReadingOnLeave('pointerout', true), false);
    assert.equal(stopsReadingOnLeave('focusout', false), true);
    assert.equal(stopsReadingOnLeave('focusout', true), true);
});
