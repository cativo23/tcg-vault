<?php

declare(strict_types=1);

use App\Modules\Catalog\Exceptions\CatalogUnavailableException;
use App\Modules\Catalog\Exceptions\MalformedCatalogResponseException;
use App\Modules\Catalog\Providers\TcgdexCardCatalogProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

const SET_LIST_URL = 'api.tcgdex.net/v2/en/sets';

function fakeSetListing(): array
{
    // The listing shape tcgdex returns: no series, no release date, a
    // logo without an extension, and some sets with no logo at all.
    return [
        ['id' => 'base1', 'name' => 'Base Set', 'logo' => 'https://assets.tcgdex.net/en/base/base1/logo', 'symbol' => 'https://assets.tcgdex.net/univ/base/base1/symbol', 'cardCount' => ['total' => 102, 'official' => 102]],
        ['id' => 'miscp', 'name' => 'Miscellaneous Promos', 'cardCount' => ['total' => 1, 'official' => 1]],
        ['id' => '30th-c', 'name' => '30th Classic Collection', 'cardCount' => ['official' => 0]],
    ];
}

// Http::fake() stubs accumulate and the first match wins, so a test that
// changes the upstream answer mid-way starts from a fresh factory.
function replaceSetListingFake(mixed $response): void
{
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([SET_LIST_URL => $response]);
}

function setListingProvider(): TcgdexCardCatalogProvider
{
    return new TcgdexCardCatalogProvider(config('tcgdex.base_url'));
}

test('listSets maps the listing into SetSummaryData with png logos and no series', function () {
    Http::fake([SET_LIST_URL => Http::response(fakeSetListing(), 200)]);

    $sets = setListingProvider()->listSets();

    expect($sets)->toHaveCount(3)
        ->and($sets[0]->tcgdexId)->toBe('base1')
        ->and($sets[0]->name)->toBe('Base Set')
        ->and($sets[0]->logoUrl)->toBe('https://assets.tcgdex.net/en/base/base1/logo.png')
        ->and($sets[0]->cardCount)->toBe(102)
        ->and($sets[0]->series)->toBeNull()
        ->and($sets[0]->releasedOn)->toBeNull()
        ->and($sets[1]->logoUrl)->toBeNull()
        ->and($sets[2]->cardCount)->toBe(0); // falls back to "official" when "total" is absent
});

test('listSets serves the cached list for 24 hours without another request', function () {
    Http::fake([SET_LIST_URL => Http::response(fakeSetListing(), 200)]);

    $provider = setListingProvider();
    $provider->listSets();
    $provider->listSets();

    Http::assertSentCount(1);
});

test('listSets serves the stale copy when tcgdex fails after the fresh copy expired', function () {
    Http::fake([SET_LIST_URL => Http::response(fakeSetListing(), 200)]);
    $provider = setListingProvider();
    $provider->listSets();

    Cache::forget(TcgdexCardCatalogProvider::SET_LIST_FRESH_KEY);
    replaceSetListingFake(Http::response('boom', 500));

    $sets = $provider->listSets();

    expect($sets)->toHaveCount(3)->and($sets[0]->tcgdexId)->toBe('base1');
});

test('listSets throws CatalogUnavailableException when tcgdex is down and nothing is cached', function (Closure $fake) {
    Http::fake([SET_LIST_URL => $fake()]);

    expect(fn () => setListingProvider()->listSets())->toThrow(CatalogUnavailableException::class);
})->with([
    'server error' => [fn () => fn () => Http::response('boom', 500)],
    'connection failure' => [fn () => fn () => throw new ConnectionException('could not resolve host')],
]);

test('listSets rejects a malformed body and never overwrites a good cached list with it', function () {
    Http::fake([SET_LIST_URL => Http::response(fakeSetListing(), 200)]);
    $provider = setListingProvider();
    $provider->listSets();
    Cache::forget(TcgdexCardCatalogProvider::SET_LIST_FRESH_KEY);

    replaceSetListingFake(Http::response(['not' => 'a list'], 200));

    expect($provider->listSets())->toHaveCount(3); // stale copy still served

    Cache::flush();

    expect(fn () => $provider->listSets())->toThrow(MalformedCatalogResponseException::class);
});

test('listSets treats an empty listing as malformed', function () {
    Http::fake([SET_LIST_URL => Http::response([], 200)]);

    expect(fn () => setListingProvider()->listSets())->toThrow(MalformedCatalogResponseException::class);
});

test('listSets skips entries without a string id or name', function () {
    Http::fake([SET_LIST_URL => Http::response([
        ['id' => 'base1', 'name' => 'Base Set', 'cardCount' => ['total' => 102]],
        ['id' => 7, 'name' => 'Numeric id'],
        ['name' => 'No id'],
        'not an object',
    ], 200)]);

    $sets = setListingProvider()->listSets();

    expect($sets)->toHaveCount(1)->and($sets[0]->tcgdexId)->toBe('base1');
});
