<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\Set;
use Illuminate\Support\Facades\Http;

test('catalog:import-set imports every card in a set with its current price', function () {
    Http::fake([
        'api.tcgdex.net/v2/en/sets/me05' => Http::response([
            'id' => 'me05',
            'name' => 'Pitch Black',
            'serie' => ['name' => 'Mega Evolution'],
            'cardCount' => ['official' => 2],
            'logo' => 'https://assets.tcgdex.net/en/me/me05/logo',
            'cards' => [
                ['id' => 'me05-006', 'localId' => '006', 'name' => 'Sinistcha'],
                ['id' => 'me05-007', 'localId' => '007', 'name' => 'Heatran'],
            ],
        ], 200),
        'api.tcgdex.net/v2/en/cards/me05-006' => Http::response([
            'id' => 'me05-006', 'localId' => '006', 'name' => 'Sinistcha', 'rarity' => 'Common',
            'image' => 'https://assets.tcgdex.net/en/me/me05/006',
            'set' => ['id' => 'me05'], 'variants' => [],
            'pricing' => ['tcgplayer' => ['unit' => 'USD', 'normal' => ['marketPrice' => 0.90]]],
        ], 200),
        'api.tcgdex.net/v2/en/cards/me05-007' => Http::response([
            'id' => 'me05-007', 'localId' => '007', 'name' => 'Heatran', 'rarity' => 'Rare Holo',
            'image' => 'https://assets.tcgdex.net/en/me/me05/007',
            'set' => ['id' => 'me05'], 'variants' => [],
            'pricing' => ['tcgplayer' => ['unit' => 'USD', 'holofoil' => ['marketPrice' => 1.20]]],
        ], 200),
    ]);

    $this->artisan('catalog:import-set', ['setId' => 'me05'])
        ->expectsOutputToContain('Imported 2 / 2 cards for set [me05].')
        ->assertExitCode(0);

    expect(Set::where('tcgdex_id', 'me05')->exists())->toBeTrue();
    expect(Card::count())->toBe(2);
    expect(Card::where('tcgdex_id', 'me05-007')->first()->priceSnapshots)->toHaveCount(1);
});

test('catalog:import-set reports a failed card without aborting the whole run', function () {
    Http::fake([
        'api.tcgdex.net/v2/en/sets/me05' => Http::response([
            'id' => 'me05', 'name' => 'Pitch Black',
            'cards' => [
                ['id' => 'me05-006', 'localId' => '006', 'name' => 'Sinistcha'],
                ['id' => 'me05-999', 'localId' => '999', 'name' => 'Broken'],
            ],
        ], 200),
        'api.tcgdex.net/v2/en/cards/me05-006' => Http::response([
            'id' => 'me05-006', 'localId' => '006', 'name' => 'Sinistcha',
            'image' => 'https://assets.tcgdex.net/en/me/me05/006',
            'set' => ['id' => 'me05'], 'variants' => [], 'pricing' => [],
        ], 200),
        'api.tcgdex.net/v2/en/cards/me05-999' => Http::response(null, 404),
    ]);

    $this->artisan('catalog:import-set', ['setId' => 'me05'])
        ->expectsOutputToContain('Failed: me05-999')
        ->expectsOutputToContain('Imported 1 / 2 cards for set [me05].')
        ->assertExitCode(0);

    expect(Card::count())->toBe(1); // the broken card is skipped, not fatal
});

test('catalog:import-set reports a transient HTTP failure without aborting the whole run', function () {
    Http::fake([
        'api.tcgdex.net/v2/en/sets/me05' => Http::response([
            'id' => 'me05', 'name' => 'Pitch Black',
            'cards' => [
                ['id' => 'me05-006', 'localId' => '006', 'name' => 'Sinistcha'],
                ['id' => 'me05-500', 'localId' => '500', 'name' => 'Server Error'],
            ],
        ], 200),
        'api.tcgdex.net/v2/en/cards/me05-006' => Http::response([
            'id' => 'me05-006', 'localId' => '006', 'name' => 'Sinistcha',
            'image' => 'https://assets.tcgdex.net/en/me/me05/006',
            'set' => ['id' => 'me05'], 'variants' => [], 'pricing' => [],
        ], 200),
        'api.tcgdex.net/v2/en/cards/me05-500' => Http::response(null, 500),
    ]);

    $this->artisan('catalog:import-set', ['setId' => 'me05'])
        ->expectsOutputToContain('Failed: me05-500')
        ->expectsOutputToContain('Imported 1 / 2 cards for set [me05].')
        ->assertExitCode(0);

    expect(Card::count())->toBe(1); // the transient failure is skipped, not fatal
});
