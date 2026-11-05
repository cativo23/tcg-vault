<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Support\MarketplaceSync;
use Illuminate\Support\Collection;

/**
 * A card with one price row per [days ago => [source, market minor]] pair,
 * loaded back with its price rows the way the gallery pages hold it.
 *
 * @param  array<int, array{0: string, 1: int|null}>  $rows
 */
function marketplaceSyncCard(string $tcgdexId, array $rows): Card
{
    $set = Set::query()->firstOrCreate(['tcgdex_id' => 'me05'], ['name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => $tcgdexId, 'set_id' => $set->id, 'local_id' => substr($tcgdexId, -3), 'name' => 'Card '.$tcgdexId]);

    foreach ($rows as $daysAgo => [$source, $minor]) {
        CardPriceSnapshot::create(['card_id' => $card->id, 'source' => $source, 'variant' => 'normal', 'captured_on' => today()->subDays($daysAgo), 'currency' => 'USD', 'market_minor' => $minor]);
    }

    return $card->load('priceSnapshots');
}

test('a manual price is not a sync row, even when it is the newest', function () {
    $card = marketplaceSyncCard('me05-101', [3 => ['tcgplayer', 1000], 0 => ['manual', 5000]]);

    $rows = MarketplaceSync::rows(new Collection([$card]));

    expect($rows->pluck('source')->all())->toBe(['tcgplayer'])
        ->and(MarketplaceSync::latestDay($rows)?->toDateString())->toBe(today()->subDays(3)->toDateString());
});

test('a price row without a market price is not a sync row', function () {
    $card = marketplaceSyncCard('me05-102', [4 => ['tcgplayer', 1000], 1 => ['tcgplayer', null]]);

    $rows = MarketplaceSync::rows(new Collection([$card]));

    expect($rows->pluck('market_minor')->all())->toBe([1000])
        ->and(MarketplaceSync::latestDay($rows)?->toDateString())->toBe(today()->subDays(4)->toDateString());
});

test('no sync rows means no sync day', function () {
    $manualOnly = marketplaceSyncCard('me05-103', [0 => ['manual', 5000]]);

    expect(MarketplaceSync::rows(new Collection)->all())->toBe([])
        ->and(MarketplaceSync::rows(new Collection([$manualOnly]))->all())->toBe([])
        ->and(MarketplaceSync::latestDay(new Collection))->toBeNull();
});

test('the sync day is the newest one across all the cards', function () {
    $cards = new Collection([
        marketplaceSyncCard('me05-104', [5 => ['tcgplayer', 1000], 2 => ['tcgplayer', 1100]]),
        marketplaceSyncCard('me05-105', [3 => ['cardmarket', 900]]),
        marketplaceSyncCard('me05-106', [6 => ['tcgplayer', 800]]),
    ]);

    $rows = MarketplaceSync::rows($cards);

    expect($rows)->toHaveCount(4)
        ->and(MarketplaceSync::latestDay($rows)?->toDateString())->toBe(today()->subDays(2)->toDateString());
});
