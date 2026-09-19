<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use Illuminate\Database\QueryException;

test('a set can have many cards, and a card belongs to a set', function () {
    $set = Set::create([
        'tcgdex_id' => 'me05',
        'name' => 'Pitch Black',
        'series' => 'Mega Evolution',
        'card_count' => 84,
    ]);

    $card = Card::create([
        'tcgdex_id' => 'me05-116',
        'set_id' => $set->id,
        'local_id' => '116',
        'name' => 'Mega Darkrai ex',
        'rarity' => 'Special Illustration Rare',
    ]);

    expect($set->cards)->toHaveCount(1);
    expect($card->set->tcgdex_id)->toBe('me05');
});

test('a card can have many price snapshots, unique per source+variant+day', function () {
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create([
        'tcgdex_id' => 'me05-116',
        'set_id' => $set->id,
        'local_id' => '116',
        'name' => 'Mega Darkrai ex',
    ]);

    CardPriceSnapshot::create([
        'card_id' => $card->id,
        'source' => 'tcgplayer',
        'variant' => 'holofoil',
        'captured_on' => '2026-09-14',
        'currency' => 'USD',
        'market_minor' => 19524,
    ]);

    expect($card->priceSnapshots)->toHaveCount(1);
    expect($card->priceSnapshots->first()->market_minor)->toBe(19524);
});

test('duplicate snapshot for the same card+source+variant+day is rejected', function () {
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create([
        'tcgdex_id' => 'me05-116',
        'set_id' => $set->id,
        'local_id' => '116',
        'name' => 'Mega Darkrai ex',
    ]);

    CardPriceSnapshot::create([
        'card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'holofoil',
        'captured_on' => '2026-09-14', 'currency' => 'USD', 'market_minor' => 19524,
    ]);

    expect(fn () => CardPriceSnapshot::create([
        'card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'holofoil',
        'captured_on' => '2026-09-14', 'currency' => 'USD', 'market_minor' => 20000,
    ]))->toThrow(QueryException::class);
});
