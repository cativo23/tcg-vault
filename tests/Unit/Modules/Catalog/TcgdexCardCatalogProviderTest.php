<?php

declare(strict_types=1);

use App\Modules\Catalog\Exceptions\CardNotFoundException;
use App\Modules\Catalog\Exceptions\InvalidTcgdexIdException;
use App\Modules\Catalog\Exceptions\MalformedCatalogResponseException;
use App\Modules\Catalog\Exceptions\SetNotFoundException;
use App\Modules\Catalog\Providers\TcgdexCardCatalogProvider;
use Illuminate\Support\Facades\Http;

function fakeTcgdexCardPayload(): array
{
    // Trimmed but structurally real response for me05-116 (Mega Darkrai ex),
    // captured against the live API during design — see design spec §3.
    return [
        'id' => 'me05-116',
        'localId' => '116',
        'name' => 'Mega Darkrai ex',
        'rarity' => 'Special Illustration Rare',
        'image' => 'https://assets.tcgdex.net/en/me/me05/116',
        'set' => ['id' => 'me05', 'name' => 'Pitch Black'],
        'variants' => ['holo' => true, 'normal' => false, 'reverse' => false],
        'pricing' => [
            'cardmarket' => [
                'updated' => '2026-09-14T17:09:10.051Z',
                'unit' => 'EUR',
                'avg' => 178.40,
                'low' => 134.99,
                'trend' => 182.10,
            ],
            'tcgplayer' => [
                'unit' => 'USD',
                'updated' => '2026-09-14T17:11:20.936Z',
                'holofoil' => [
                    'lowPrice' => 190.16,
                    'midPrice' => 218.50,
                    'highPrice' => 999.99,
                    'marketPrice' => 195.24,
                ],
            ],
        ],
    ];
}

test('findCard maps a tcgdex payload into a CardDetailData with normalized prices', function () {
    Http::fake([
        'api.tcgdex.net/v2/en/cards/me05-116' => Http::response(fakeTcgdexCardPayload(), 200),
    ]);

    $provider = new TcgdexCardCatalogProvider(config('tcgdex.base_url'));
    $card = $provider->findCard('me05-116');

    expect($card->tcgdexId)->toBe('me05-116');
    expect($card->setTcgdexId)->toBe('me05');
    expect($card->localId)->toBe('116'); // zero-padded string preserved, not cast to int
    expect($card->officialImageUrl)->toBe('https://assets.tcgdex.net/en/me/me05/116/high.webp');

    expect($card->prices)->toHaveCount(2);

    $cardmarket = $card->prices->first(fn ($p) => $p->source === 'cardmarket');
    expect($cardmarket->variant)->toBe('default');
    expect($cardmarket->currency)->toBe('EUR');
    expect($cardmarket->marketMinor)->toBe(17840); // 178.40 EUR -> minor units

    $tcgplayer = $card->prices->first(fn ($p) => $p->source === 'tcgplayer');
    expect($tcgplayer->variant)->toBe('holofoil');
    expect($tcgplayer->currency)->toBe('USD');
    expect($tcgplayer->marketMinor)->toBe(19524); // 195.24 USD -> minor units
    expect($tcgplayer->lowMinor)->toBe(19016);
    expect($tcgplayer->trendMinor)->toBeNull(); // tcgplayer payload has no trend field
});

test('findCard throws CardNotFoundException on a 404', function () {
    Http::fake([
        'api.tcgdex.net/v2/en/cards/does-not-exist' => Http::response(null, 404),
    ]);

    $provider = new TcgdexCardCatalogProvider(config('tcgdex.base_url'));

    expect(fn () => $provider->findCard('does-not-exist'))
        ->toThrow(CardNotFoundException::class);
});

test('findSet maps a tcgdex set payload into SetSummaryData', function () {
    Http::fake([
        'api.tcgdex.net/v2/en/sets/me05' => Http::response([
            'id' => 'me05',
            'name' => 'Pitch Black',
            'serie' => ['name' => 'Mega Evolution'],
            'cardCount' => ['total' => 120, 'official' => 84],
            'logo' => 'https://assets.tcgdex.net/en/me/me05/logo',
        ], 200),
    ]);

    $provider = new TcgdexCardCatalogProvider(config('tcgdex.base_url'));
    $set = $provider->findSet('me05');

    expect($set->tcgdexId)->toBe('me05');
    expect($set->name)->toBe('Pitch Black');
    expect($set->series)->toBe('Mega Evolution');
    expect($set->cardCount)->toBe(84);
    expect($set->logoUrl)->toBe('https://assets.tcgdex.net/en/me/me05/logo.png');
});

test('findSet throws SetNotFoundException on a 404', function () {
    Http::fake([
        'api.tcgdex.net/v2/en/sets/does-not-exist' => Http::response(null, 404),
    ]);

    $provider = new TcgdexCardCatalogProvider(config('tcgdex.base_url'));

    expect(fn () => $provider->findSet('does-not-exist'))
        ->toThrow(SetNotFoundException::class);
});

test('findCard rejects an ID shaped like an absolute URL without making any HTTP call', function () {
    Http::fake();

    $provider = new TcgdexCardCatalogProvider(config('tcgdex.base_url'));

    expect(fn () => $provider->findCard('https://evil.example/x'))
        ->toThrow(InvalidTcgdexIdException::class);

    Http::assertNothingSent();
});

test('findSet rejects an ID shaped like an absolute URL without making any HTTP call', function () {
    Http::fake();

    $provider = new TcgdexCardCatalogProvider(config('tcgdex.base_url'));

    expect(fn () => $provider->findSet('https://evil.example/x'))
        ->toThrow(InvalidTcgdexIdException::class);

    Http::assertNothingSent();
});

test('listSetCardIds rejects an ID shaped like an absolute URL without making any HTTP call', function () {
    Http::fake();

    $provider = new TcgdexCardCatalogProvider(config('tcgdex.base_url'));

    expect(fn () => $provider->listSetCardIds('https://evil.example/x'))
        ->toThrow(InvalidTcgdexIdException::class);

    Http::assertNothingSent();
});

test('listSetCardIds returns the zero-padded local card IDs prefixed with the set ID', function () {
    Http::fake([
        'api.tcgdex.net/v2/en/sets/me05' => Http::response([
            'id' => 'me05',
            'name' => 'Pitch Black',
            'cards' => [
                ['id' => 'me05-006', 'localId' => '006', 'name' => 'Sinistcha'],
                ['id' => 'me05-007', 'localId' => '007', 'name' => 'Heatran'],
            ],
        ], 200),
    ]);

    $provider = new TcgdexCardCatalogProvider(config('tcgdex.base_url'));
    $ids = $provider->listSetCardIds('me05');

    expect($ids)->toBe(['me05-006', 'me05-007']);
});

test('findCard throws MalformedCatalogResponseException when a required field is missing', function () {
    Http::fake([
        'api.tcgdex.net/v2/en/cards/me05-116' => Http::response([
            // 'id' is missing entirely
            'localId' => '116',
            'name' => 'Mega Darkrai ex',
            'set' => ['id' => 'me05'],
        ], 200),
    ]);

    $provider = new TcgdexCardCatalogProvider(config('tcgdex.base_url'));

    expect(fn () => $provider->findCard('me05-116'))
        ->toThrow(MalformedCatalogResponseException::class);
});

test('findCard throws MalformedCatalogResponseException when a currency code is not 3 characters', function () {
    Http::fake([
        'api.tcgdex.net/v2/en/cards/me05-116' => Http::response([
            'id' => 'me05-116',
            'localId' => '116',
            'name' => 'Mega Darkrai ex',
            'set' => ['id' => 'me05'],
            'pricing' => [
                'cardmarket' => ['unit' => 'EURO', 'avg' => 10.0],
            ],
        ], 200),
    ]);

    $provider = new TcgdexCardCatalogProvider(config('tcgdex.base_url'));

    expect(fn () => $provider->findCard('me05-116'))
        ->toThrow(MalformedCatalogResponseException::class);
});

test('findSet throws MalformedCatalogResponseException when a required field is missing', function () {
    Http::fake([
        'api.tcgdex.net/v2/en/sets/me05' => Http::response([
            // 'name' is missing entirely
            'id' => 'me05',
        ], 200),
    ]);

    $provider = new TcgdexCardCatalogProvider(config('tcgdex.base_url'));

    expect(fn () => $provider->findSet('me05'))
        ->toThrow(MalformedCatalogResponseException::class);
});
