<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function originCard(): Card
{
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);

    return Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
}

test('a snapshot records where its price came from, tcgdex unless told otherwise', function () {
    $card = originCard();

    $synced = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'holofoil', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 16027]);
    $hand = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'manual', 'variant' => 'holofoil:cosmos', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 500, 'origin' => 'hand']);

    expect($synced->fresh()->origin)->toBe('tcgdex');
    expect($hand->fresh()->origin)->toBe('hand');
});

test('origin is not part of the one-row-per-marketplace-print-day rule', function () {
    $card = originCard();
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'holofoil', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 16027]);

    expect(fn () => CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'holofoil', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 16063, 'origin' => 'tcgcsv']))
        ->toThrow(QueryException::class);
});

test('the origin migration marks existing manual prices as entered by hand', function () {
    $card = originCard();
    $manual = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'manual', 'variant' => 'holofoil:cosmos', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 500]);
    $synced = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'holofoil', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 17840]);
    DB::table('card_price_snapshots')->update(['origin' => 'tcgdex']);

    $migration = require database_path('migrations/2026_10_06_000001_add_origin_to_card_price_snapshots_table.php');
    $migration->backfillHandOrigin();

    expect($manual->fresh()->origin)->toBe('hand');
    expect($synced->fresh()->origin)->toBe('tcgdex');
});

test('originLabel names where a price came from', function (string $origin, string $label) {
    expect((new CardPriceSnapshot(['origin' => $origin]))->originLabel())->toBe($label);
})->with([
    ['tcgdex', 'tcgdex'],
    ['tcgcsv', 'tcgcsv'],
    ['hand', 'entered by hand'],
]);

test('a print links to at most one TCGplayer product, and the link goes with its card', function () {
    $card = originCard();
    DB::table('card_tcgplayer_links')->insert(['card_id' => $card->id, 'variant' => 'holofoil', 'product_id' => 704873, 'sub_type' => 'Holofoil', 'method' => 'tcgdex-price', 'created_at' => now(), 'updated_at' => now()]);

    // A savepoint, so the rejected insert doesn't abort the test's transaction.
    expect(fn () => DB::transaction(fn () => DB::table('card_tcgplayer_links')->insert(['card_id' => $card->id, 'variant' => 'holofoil', 'product_id' => 1, 'sub_type' => 'Holofoil', 'method' => 'admin', 'created_at' => now(), 'updated_at' => now()])))
        ->toThrow(QueryException::class);

    $card->delete();
    expect(DB::table('card_tcgplayer_links')->count())->toBe(0);
});

test('a set can pull several TCGplayer groups, each once', function () {
    $set = Set::create(['tcgdex_id' => '30th', 'name' => '30th Celebration']);
    DB::table('set_tcgplayer_groups')->insert([['set_id' => $set->id, 'group_id' => 24722], ['set_id' => $set->id, 'group_id' => 24837]]);

    expect(fn () => DB::table('set_tcgplayer_groups')->insert(['set_id' => $set->id, 'group_id' => 24722]))
        ->toThrow(QueryException::class);
});
