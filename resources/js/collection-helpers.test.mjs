import test from 'node:test';
import assert from 'node:assert/strict';
import { nextTabIndex, revealScrollLeft, overflowsRight, copyText, fallbackSrc } from './collection-helpers.js';

test('arrow keys move to the neighbouring tab and wrap around the ends', () => {
    assert.equal(nextTabIndex('ArrowRight', 0, 4), 1);
    assert.equal(nextTabIndex('ArrowRight', 3, 4), 0);
    assert.equal(nextTabIndex('ArrowLeft', 0, 4), 3);
    assert.equal(nextTabIndex('ArrowLeft', 2, 4), 1);
});

test('Home and End jump to the first and last tab', () => {
    assert.equal(nextTabIndex('Home', 2, 4), 0);
    assert.equal(nextTabIndex('End', 0, 4), 3);
});

test('other keys, no tabs, or focus outside the strip do nothing', () => {
    assert.equal(nextTabIndex('Enter', 1, 4), null);
    assert.equal(nextTabIndex('ArrowUp', 1, 4), null);
    assert.equal(nextTabIndex('ArrowRight', 0, 0), null);
    assert.equal(nextTabIndex('ArrowRight', -1, 4), null);
});

test('a tab already in view is left alone; one off either edge scrolls just enough, with padding', () => {
    const view = { scrollLeft: 100, viewWidth: 300, pad: 24 };
    assert.equal(revealScrollLeft({ tabLeft: 200, tabWidth: 80, ...view }), null);
    assert.equal(revealScrollLeft({ tabLeft: 90, tabWidth: 80, ...view }), 66);      // left edge: 90 - 24
    assert.equal(revealScrollLeft({ tabLeft: 360, tabWidth: 80, ...view }), 164);    // right edge: 360 + 80 + 24 - 300
    assert.equal(revealScrollLeft({ tabLeft: 10, tabWidth: 80, ...view }), 0);       // never negative
});

test('the edge fade shows only while the strip really runs past its right side', () => {
    assert.equal(overflowsRight(0, 300, 301), false);   // within the 2px slop
    assert.equal(overflowsRight(0, 300, 600), true);
    assert.equal(overflowsRight(300, 300, 600), false);
});

test('a failed low-resolution tcgdex image is retried once at high resolution, anything else is not', () => {
    assert.equal(fallbackSrc('https://assets.tcgdex.net/en/me/me05/116/low.webp'), 'https://assets.tcgdex.net/en/me/me05/116/high.webp');
    assert.equal(fallbackSrc('https://assets.tcgdex.net/en/me/me05/116/high.webp'), null);
    assert.equal(fallbackSrc('https://product-images.tcgplayer.com/fit-in/437x437/123.jpg'), null);
    assert.equal(fallbackSrc('https://assets.tcgdex.net.evil.test/en/me/me05/116/low.webp'), null);
    assert.equal(fallbackSrc(null), null);
    assert.equal(fallbackSrc(''), null);
});

test('copy uses the clipboard API when it is available and the page is secure', async () => {
    const calls = [];
    const ok = await copyText('https://x/y', {
        clipboard: { writeText: async (t) => { calls.push(t); } }, secure: true, legacy: () => { throw new Error('not reached'); },
    });
    assert.equal(ok, true);
    assert.deepEqual(calls, ['https://x/y']);
});

test('copy falls back to the legacy path when the clipboard API is missing, insecure or rejects', async () => {
    const legacyCalls = [];
    const legacy = (t) => { legacyCalls.push(t); return true; };
    assert.equal(await copyText('a', { clipboard: undefined, secure: true, legacy }), true);
    assert.equal(await copyText('b', { clipboard: { writeText: async () => {} }, secure: false, legacy }), true);
    assert.equal(await copyText('c', { clipboard: { writeText: async () => { throw new Error('denied'); } }, secure: true, legacy }), true);
    assert.deepEqual(legacyCalls, ['a', 'b', 'c']);
});

test('copy reports failure when the legacy path fails or throws', async () => {
    assert.equal(await copyText('a', { clipboard: undefined, secure: false, legacy: () => false }), false);
    assert.equal(await copyText('a', { clipboard: undefined, secure: false, legacy: () => { throw new Error('no'); } }), false);
});
