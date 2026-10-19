<?php

declare(strict_types=1);

use App\Livewire\Gallery\Activity;
use App\Livewire\Gallery\Index;
use App\Livewire\Gallery\Sets;
use App\Livewire\Gallery\Show;
use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Services\DecidingPriceRows;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\FullRecentPriceRows;
use Tests\Support\ViewDataFingerprint;

/*
 * Every public gallery page shows exactly what it showed when it loaded the
 * whole recent window: each page is rendered once with the full window
 * (FullRecentPriceRows bound in DecidingPriceRows' place) and once with the
 * deciding rows, and their view data and HTML are compared.
 */

/**
 * A seeded collection over two sets, with long histories, unpriced and
 * frozen series, manual prices, mixed currencies, tcgcsv days, ghost cards
 * and copies of several variants.
 */
function seedPagesCollection(): void
{
    mt_srand(1910);
    $user = User::factory()->create(['username' => 'carlos', 'name' => 'Carlos']);
    $public = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $private = Collection::factory()->for($user)->create(['is_public' => false, 'slug' => 'vault']);
    $sets = [
        Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 30, 'released_on' => '2026-01-01']),
        Set::create(['tcgdex_id' => 'sv08', 'name' => 'Surging Sparks', 'card_count' => 20, 'released_on' => '2025-01-01']),
    ];
    $sources = ['tcgplayer', 'tcgplayer', 'cardmarket', 'cardmarket', 'manual'];
    $variants = ['normal', 'holofoil', 'reverse-holofoil', 'default', 'holofoil:cosmos+player-rewards-program'];
    $rarities = ['Common', 'Rare', 'Double rare', 'None'];
    $now = now();
    $rows = [];

    foreach (range(1, 36) as $n) {
        $set = $sets[$n % 2];
        $card = Card::create([
            'tcgdex_id' => "{$set->tcgdex_id}-{$n}", 'set_id' => $set->id, 'local_id' => (string) $n,
            'name' => "Card {$n}", 'rarity' => $rarities[$n % 4],
        ]);

        for ($series = mt_rand(0, 5); $series > 0; $series--) {
            $source = $sources[mt_rand(0, 4)];
            $variant = $variants[mt_rand(0, 4)];
            $currency = $source === 'cardmarket' ? 'EUR' : 'USD';
            $newest = mt_rand(0, $source === 'manual' ? 60 : 34);

            foreach (range($newest, $newest + mt_rand(0, $source === 'manual' ? 2 : 40)) as $day) {
                if (mt_rand(0, 3) === 0 && $day !== $newest) {
                    continue;
                }
                $rows["{$card->id}|{$source}|{$variant}|{$day}"] = [
                    'card_id' => $card->id, 'source' => $source, 'variant' => $variant,
                    'origin' => $source === 'manual' ? 'hand' : (mt_rand(0, 6) === 0 ? 'tcgcsv' : 'tcgdex'),
                    'captured_on' => today()->subDays($day)->toDateString(),
                    'currency' => mt_rand(0, 12) === 0 ? ($currency === 'USD' ? 'EUR' : 'USD') : $currency,
                    'market_minor' => mt_rand(0, 4) === 0 ? null : mt_rand(10, 30000),
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }

        // Every fifth card is a ghost (unowned); every seventh is owned only privately.
        if ($n % 5 === 0) {
            continue;
        }
        foreach (range(1, mt_rand(1, 3)) as $copy) {
            CollectionItem::create([
                'collection_id' => $n % 7 === 0 ? $private->id : $public->id, 'card_id' => $card->id,
                'card_tcgdex_id' => $card->tcgdex_id, 'variant' => [null, 'normal', 'holofoil', 'reverse-holofoil'][mt_rand(0, 3)],
                'condition' => ['NM', 'LP', 'MP'][$copy - 1], 'quantity' => mt_rand(1, 3),
            ]);
        }
    }

    foreach (array_chunk(array_values($rows), 500) as $chunk) {
        CardPriceSnapshot::insert($chunk);
    }
}

/**
 * The page's view data and HTML, rendered with $loader bound.
 *
 * @param  class-string  $component
 * @param  array<string, mixed>  $params
 * @param  array<string, mixed>  $query
 * @param  list<string>  $keys
 * @return array{data: array<string, mixed>, html: string}
 */
function renderPageWith(DecidingPriceRows $loader, string $component, array $params, array $query, array $keys): array
{
    app()->instance(DecidingPriceRows::class, $loader);
    Cache::flush();

    try {
        $page = Livewire::withQueryParams($query)->test($component, $params);
        $data = [];
        foreach ($keys as $key) {
            $data[$key] = ViewDataFingerprint::of($page->viewData($key));
        }

        // The component's id and snapshot differ per render; everything else must not.
        $html = preg_replace('/wire:(id|snapshot|effects)="[^"]*"/', '', $page->html());

        return ['data' => $data, 'html' => (string) $html];
    } finally {
        app()->forgetInstance(DecidingPriceRows::class);
    }
}

dataset('gallery pages', [
    'index by value' => [Index::class, [], ['sort' => 'value'], ['entries', 'totalEntries', 'hasMore', 'totals', 'copies', 'updatedAt', 'priceOrigins', 'topEntry', 'rarities']],
    'index by newest' => [Index::class, [], ['sort' => 'newest'], ['entries', 'totals', 'updatedAt', 'priceOrigins', 'topEntry']],
    'index by number, filtered' => [Index::class, [], ['sort' => 'number', 'set' => 'me05', 'rarity' => 'Rare'], ['entries', 'totals', 'topEntry']],
    'sets' => [Sets::class, [], [], ['sets', 'setValues']],
    'set page' => [Show::class, ['setTcgdexId' => 'me05'], [], ['entries', 'ownedCount', 'ownedTotals', 'mostValuable']],
    'set page with missing cards, by value' => [Show::class, ['setTcgdexId' => 'sv08'], ['missing' => true, 'sort' => 'value'], ['entries', 'ownedCount', 'ownedTotals', 'mostValuable']],
    'set page, searched' => [Show::class, ['setTcgdexId' => 'sv08'], ['search' => 'Card 1'], ['entries', 'ownedCount', 'ownedTotals', 'mostValuable']],
    'activity' => [Activity::class, [], [], ['feed', 'feedCount', 'totals', 'series', 'primaryCurrency', 'historySince']],
    'activity over a year' => [Activity::class, [], ['range' => '1y'], ['feed', 'totals', 'series', 'historySince']],
]);

test('a gallery page shows the same values from the deciding rows as from the whole recent window', function (string $component, array $params, array $query, array $keys) {
    // The feed prints relative times ("2s ago"); both renders must see the same clock.
    $this->freezeTime();
    seedPagesCollection();
    $params = ['username' => 'carlos', ...$params];

    $full = renderPageWith(new FullRecentPriceRows, $component, $params, $query, $keys);
    $deciding = renderPageWith(new DecidingPriceRows, $component, $params, $query, $keys);

    expect($deciding['data'])->toBe($full['data'])
        ->and($deciding['html'])->toBe($full['html']);
})->with('gallery pages');

test('a gallery page loads its price rows through the deciding-rows query, never the whole window', function (string $component, array $params, array $query) {
    seedPagesCollection();
    $windowLoads = 0;
    $decidingLoads = 0;
    DB::listen(function ($query) use (&$windowLoads, &$decidingLoads) {
        // An eager load of the relation over the recent window, or the deciding-rows query.
        $windowLoads += preg_match('/^select \* from "card_price_snapshots" where "card_price_snapshots"."card_id" in/i', $query->sql);
        $decidingLoads += preg_match('/row_number\(\) over \(partition by card_id, source, variant order by captured_on desc\)/i', $query->sql);
    });

    Livewire::withQueryParams($query)->test($component, ['username' => 'carlos', ...$params]);

    expect($decidingLoads)->toBeGreaterThan(0)
        ->and($windowLoads)->toBe(0);
})->with([
    'index' => [Index::class, [], []],
    'sets' => [Sets::class, [], []],
    'set page' => [Show::class, ['setTcgdexId' => 'me05'], []],
    'set page, searched' => [Show::class, ['setTcgdexId' => 'me05'], ['search' => 'Card']],
    'activity' => [Activity::class, [], []],
]);
