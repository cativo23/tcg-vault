<?php

declare(strict_types=1);

use App\Modules\Catalog\Tcgcsv\TcgcsvFallbackPrices;
use App\Modules\Catalog\Tcgcsv\TcgcsvPriceRow;
use Illuminate\Support\Carbon;

const FALLBACK_BUILD = '2026-10-21T20:05:22+00:00';

beforeEach(function () {
    config(['tcgcsv.mode' => 'fill']);
    $this->travelTo(Carbon::parse('2026-10-22 00:10', 'UTC'));
});

function fallbackRow(?int $market = 14207, int $product = 704873): TcgcsvPriceRow
{
    return new TcgcsvPriceRow(groupId: 24688, productId: $product, subType: 'Holofoil', marketMinor: $market, lowMinor: 13649);
}

test('a stored price is returned for its print and for no other variant', function () {
    $store = new TcgcsvFallbackPrices;

    $store->putMany([[23, 'holofoil', fallbackRow()]], FALLBACK_BUILD);

    $found = $store->forCard(23, ['holofoil', 'normal']);
    expect(array_keys($found))->toBe(['holofoil']);
    expect($found['holofoil'])->toBe([
        'market' => 14207,
        'low' => 13649,
        'productId' => 704873,
        'groupId' => 24688,
        'subType' => 'Holofoil',
        'build' => FALLBACK_BUILD,
    ]);
});

test('a price is not returned for another card', function () {
    $store = new TcgcsvFallbackPrices;

    $store->putMany([[23, 'holofoil', fallbackRow()]], FALLBACK_BUILD);

    expect($store->forCard(130, ['holofoil']))->toBe([]);
});

test('a stored price is dropped once its build is 36 hours old', function () {
    $store = new TcgcsvFallbackPrices;
    $store->putMany([[23, 'holofoil', fallbackRow()]], FALLBACK_BUILD);

    $this->travelTo(Carbon::parse(FALLBACK_BUILD)->addHours(35));
    expect($store->forCard(23, ['holofoil']))->toHaveCount(1);

    $this->travelTo(Carbon::parse(FALLBACK_BUILD)->addHours(36)->addMinute());
    expect($store->forCard(23, ['holofoil']))->toBe([]);
});

test('a build already over 36 hours old stores nothing', function () {
    $store = new TcgcsvFallbackPrices;

    $stored = $store->putMany([[23, 'holofoil', fallbackRow()]], '2026-10-20T10:00:00+00:00');

    expect($stored)->toBe(0);
    expect($store->forCard(23, ['holofoil']))->toBe([]);
});

test('a print with no market price, or a zero one, is not stored', function () {
    $store = new TcgcsvFallbackPrices;

    $stored = $store->putMany([[23, 'holofoil', fallbackRow(null)], [23, 'normal', fallbackRow(0)]], FALLBACK_BUILD);

    expect($stored)->toBe(0);
    expect($store->forCard(23, ['holofoil', 'normal']))->toBe([]);
});

test('nothing is stored or returned outside fill mode', function () {
    $store = new TcgcsvFallbackPrices;

    config(['tcgcsv.mode' => 'shadow']);
    expect($store->putMany([[23, 'holofoil', fallbackRow()]], FALLBACK_BUILD))->toBe(0);

    config(['tcgcsv.mode' => 'fill']);
    $store->putMany([[23, 'holofoil', fallbackRow()]], FALLBACK_BUILD);
    config(['tcgcsv.mode' => 'shadow']);

    expect($store->forCard(23, ['holofoil']))->toBe([]);
});

test('a stored price becomes the same snapshot row the evening fill writes', function () {
    $store = new TcgcsvFallbackPrices;
    $entry = $store->entryFor(fallbackRow(), FALLBACK_BUILD);

    $attributes = $store->snapshotAttributes($entry);

    expect($attributes)->toMatchArray([
        'origin' => 'tcgcsv',
        'currency' => 'USD',
        'market_minor' => 14207,
        'low_minor' => 13649,
        'trend_minor' => null,
        'raw' => ['productId' => 704873, 'groupId' => 24688, 'subType' => 'Holofoil', 'build' => FALLBACK_BUILD],
    ]);
    expect($attributes['source_updated_at']->toIso8601String())->toBe(FALLBACK_BUILD);
});
