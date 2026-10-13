<?php

declare(strict_types=1);

use App\Modules\Catalog\Exceptions\CatalogUnavailableException;
use App\Modules\Catalog\Exceptions\MalformedCatalogResponseException;
use App\Modules\Catalog\Providers\TcgdexCardCatalogProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

// One Http::fake whose answer can change mid-test: stubs accumulate and the
// first match wins, so a second fake() could never replace the first.
function fakeSetListingUpstream(mixed $response): ArrayObject
{
    $upstream = new ArrayObject(['response' => $response]);

    Http::fake([SET_LIST_URL => fn () => $upstream['response']]);

    return $upstream;
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

test('listSets drops logo urls that are not on the tcgdex assets host', function () {
    Http::fake([SET_LIST_URL => Http::response([
        ['id' => 'a', 'name' => 'A', 'logo' => 'https://assets.tcgdex.net/en/a/logo', 'cardCount' => ['total' => 1]],
        ['id' => 'b', 'name' => 'B', 'logo' => 'https://evil.example/logo', 'cardCount' => ['total' => 1]],
        ['id' => 'c', 'name' => 'C', 'logo' => 'http://assets.tcgdex.net/en/c/logo', 'cardCount' => ['total' => 1]],
        ['id' => 'd', 'name' => 'D', 'logo' => 'https://assets.tcgdex.net.evil.example/logo', 'cardCount' => ['total' => 1]],
        ['id' => 'e', 'name' => 'E', 'logo' => 'javascript:alert(1)', 'cardCount' => ['total' => 1]],
    ], 200)]);

    $sets = setListingProvider()->listSets();

    expect($sets[0]->logoUrl)->toBe('https://assets.tcgdex.net/en/a/logo.png')
        ->and($sets[1]->logoUrl)->toBeNull()
        ->and($sets[2]->logoUrl)->toBeNull()
        ->and($sets[3]->logoUrl)->toBeNull()
        ->and($sets[4]->logoUrl)->toBeNull();
});

test('listSets serves the cached list for 24 hours without another request', function () {
    Http::fake([SET_LIST_URL => Http::response(fakeSetListing(), 200)]);

    $provider = setListingProvider();
    $provider->listSets();
    $this->travel(23)->hours();
    $provider->listSets();

    Http::assertSentCount(1);

    $this->travel(2)->hours();
    $provider->listSets();

    Http::assertSentCount(2);
});

test('listSets serves the stale copy when tcgdex fails after the fresh copy expired', function () {
    $upstream = fakeSetListingUpstream(Http::response(fakeSetListing(), 200));
    $provider = setListingProvider();
    $provider->listSets();

    Cache::forget(TcgdexCardCatalogProvider::SET_LIST_FRESH_KEY);
    $upstream['response'] = Http::response('boom', 500);
    Log::spy();

    $sets = $provider->listSets();

    Log::shouldHaveReceived('warning')->once();
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
    $upstream = fakeSetListingUpstream(Http::response(fakeSetListing(), 200));
    $provider = setListingProvider();
    $provider->listSets();
    Cache::forget(TcgdexCardCatalogProvider::SET_LIST_FRESH_KEY);

    $upstream['response'] = Http::response(['not' => 'a list'], 200);

    expect($provider->listSets())->toHaveCount(3); // stale copy still served

    Cache::flush();

    expect(fn () => $provider->listSets())->toThrow(CatalogUnavailableException::class);
});

test('listSets treats an empty listing as malformed and reports it as unavailable', function () {
    Http::fake([SET_LIST_URL => Http::response([], 200)]);

    try {
        setListingProvider()->listSets();
        $this->fail('Expected CatalogUnavailableException.');
    } catch (CatalogUnavailableException $e) {
        expect($e->getPrevious())->toBeInstanceOf(MalformedCatalogResponseException::class);
    }
});

test('listSets throws the same exception type on the repeat call inside the failure window, malformed body or not', function () {
    fakeSetListingUpstream(Http::response(['not' => 'a list'], 200));
    $provider = setListingProvider();

    expect(fn () => $provider->listSets())->toThrow(CatalogUnavailableException::class);
    expect(fn () => $provider->listSets())->toThrow(CatalogUnavailableException::class);

    Http::assertSentCount(1);
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

test('listSets backs off after an outage with a stale copy instead of retrying on every call', function () {
    $upstream = fakeSetListingUpstream(Http::response(fakeSetListing(), 200));
    $provider = setListingProvider();
    $provider->listSets();
    Cache::forget(TcgdexCardCatalogProvider::SET_LIST_FRESH_KEY);
    $upstream['response'] = Http::response('boom', 500);

    $provider->listSets();
    $provider->listSets();

    Http::assertSentCount(2); // the first fetch plus ONE failed retry

    $this->travel(6)->minutes();
    $provider->listSets();

    Http::assertSentCount(3); // the backoff has passed, so it retries
});

test('listSets remembers a failure with no stale copy so the next call throws without a request', function () {
    fakeSetListingUpstream(Http::response('boom', 500));
    $provider = setListingProvider();

    expect(fn () => $provider->listSets())->toThrow(CatalogUnavailableException::class);
    expect(fn () => $provider->listSets())->toThrow(CatalogUnavailableException::class);

    Http::assertSentCount(1);

    $this->travel(61)->seconds();

    expect(fn () => $provider->listSets())->toThrow(CatalogUnavailableException::class);

    Http::assertSentCount(2);
});

test('listSets treats a cached value of the wrong shape as a miss', function () {
    Http::fake([SET_LIST_URL => Http::response(fakeSetListing(), 200)]);
    Cache::put(TcgdexCardCatalogProvider::SET_LIST_FRESH_KEY, [['id' => 'base1']], 3600);

    $sets = setListingProvider()->listSets();

    expect($sets)->toHaveCount(3);
    Http::assertSentCount(1);
});

test('listSets treats a stale copy of the wrong shape as a miss instead of serving it', function () {
    fakeSetListingUpstream(Http::response('boom', 500));
    Cache::put(TcgdexCardCatalogProvider::SET_LIST_STALE_KEY, [['id' => 'base1']], 3600);

    expect(fn () => setListingProvider()->listSets())->toThrow(CatalogUnavailableException::class);
});
