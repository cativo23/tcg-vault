import test from 'node:test';
import assert from 'node:assert/strict';
import { themeAfterNavigation, themeFromSearch, validTheme } from './theme-helpers.js';

test('only light and dark count as a theme', () => {
    assert.equal(validTheme('light'), 'light');
    assert.equal(validTheme('dark'), 'dark');
    assert.equal(validTheme('sepia'), null);
    assert.equal(validTheme(''), null);
    assert.equal(validTheme(null), null);
});

test('the theme is read from the query string when valid', () => {
    assert.equal(themeFromSearch('?theme=light'), 'light');
    assert.equal(themeFromSearch('?range=3m&theme=dark'), 'dark');
    assert.equal(themeFromSearch('?theme=neon'), null);
    assert.equal(themeFromSearch(''), null);
    assert.equal(themeFromSearch(undefined), null);
});

test('an explicit query theme beats the stored one after navigation', () => {
    assert.equal(themeAfterNavigation({ override: 'light', stored: 'dark' }), 'light');
});

test('the stored theme applies when there is no valid override', () => {
    assert.equal(themeAfterNavigation({ override: null, stored: 'dark' }), 'dark');
    assert.equal(themeAfterNavigation({ override: 'bad', stored: 'light' }), 'light');
    assert.equal(themeAfterNavigation({ override: null, stored: null }), null);
});
