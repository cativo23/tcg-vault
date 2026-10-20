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
use App\Modules\Catalog\Services\CardPriceResolver;
use App\Modules\Catalog\Services\DecidingPriceRows;
use App\Modules\Catalog\Support\ChartRange;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use App\Modules\Collection\Scopes\TenantScope;
use App\Modules\Collection\Services\CollectionValueSeries;
use App\Modules\Collection\Services\PublicCollection;
use App\Modules\Collection\Services\Valuation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\FullRecentPriceRows;
use Tests\Support\PriceRowReads;
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
function seedPagesCollection(int $seed = 1910): void
{
    $user = User::factory()->create(['username' => 'carlos', 'name' => 'Carlos']);
    $public = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $private = Collection::factory()->for($user)->create(['is_public' => false, 'slug' => 'vault']);
    // Seeded after the factories, whose fakers draw from the same generator.
    mt_srand($seed);
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
            // Each copy added at its own moment, so "newest" orders have no ties
            // for the database to break differently from one query to the next.
            Carbon::setTestNow($now->copy()->subMinutes($n * 3 + $copy));
            CollectionItem::create([
                'collection_id' => $n % 7 === 0 ? $private->id : $public->id, 'card_id' => $card->id,
                'card_tcgdex_id' => $card->tcgdex_id, 'variant' => [null, 'normal', 'holofoil', 'reverse-holofoil'][mt_rand(0, 3)],
                'condition' => ['NM', 'LP', 'MP'][$copy - 1], 'quantity' => mt_rand(1, 3),
            ]);
        }
    }

    Carbon::setTestNow($now);

    foreach (array_chunk(array_values($rows), 500) as $chunk) {
        CardPriceSnapshot::insert($chunk);
    }
}

/**
 * The page's view data and HTML, rendered with $loader bound, from an
 * empty cache unless $flush is false.
 *
 * @param  class-string  $component
 * @param  array<string, mixed>  $params
 * @param  array<string, mixed>  $query
 * @param  list<string>  $keys
 * @return array{data: array<string, mixed>, html: string}
 */
function renderPageWith(DecidingPriceRows $loader, string $component, array $params, array $query, array $keys, bool $flush = true): array
{
    app()->instance(DecidingPriceRows::class, $loader);

    if ($flush) {
        Cache::flush();
    }

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
    // Again over the cache the render above filled.
    $cached = renderPageWith(new DecidingPriceRows, $component, $params, $query, $keys, flush: false);

    expect($deciding['data'])->toBe($full['data'])
        ->and($deciding['html'])->toBe($full['html'])
        ->and($cached['data'])->toBe($full['data'])
        ->and($cached['html'])->toBe($full['html']);
})->with('gallery pages');

/*
 * The guard on PublicCollection::cardsQuery()'s contract: its cards come
 * without price rows, and a page that forgets DecidingPriceRows would read
 * them some other way, eagerly over the window or lazily, one card's whole
 * history at a time. Every read of card_price_snapshots is counted; only the
 * deciding query and the value series' own reads are allowed. The first
 * render runs one deciding query per set of cards it prices; the second
 * finds them cached and reads no price rows at all.
 */
test('a gallery page reads price rows only through the deciding query, and not at all when they are cached', function (string $component, array $params, array $query, int $decidingLoads) {
    seedPagesCollection();
    $render = fn () => Livewire::withQueryParams($query)->test($component, ['username' => 'carlos', ...$params]);

    $miss = PriceRowReads::during($render);
    $hit = PriceRowReads::during($render);

    expect($miss->stray)->toBe([])
        ->and($miss->deciding)->toHaveCount($decidingLoads)
        ->and($hit->stray)->toBe([])
        ->and($hit->deciding)->toBe([]);
})->with([
    'index' => [Index::class, [], [], 1],
    'index, searched' => [Index::class, [], ['search' => 'Card 1'], 1],
    'sets' => [Sets::class, [], [], 1],
    'set page' => [Show::class, ['setTcgdexId' => 'me05'], [], 1],
    // A search narrows the grid, so the set's owned cards load again for the stats.
    'set page, searched' => [Show::class, ['setTcgdexId' => 'me05'], ['search' => 'Card'], 2],
    'activity' => [Activity::class, [], [], 1],
]);

test('the value series\' public entry point reads price rows only through the deciding query', function () {
    seedPagesCollection();

    $reads = PriceRowReads::during(fn () => (new CollectionValueSeries(new Valuation(new CardPriceResolver)))
        ->forPublicCollection(PublicCollection::for(User::where('username', 'carlos')->sole()), ChartRange::ThreeMonths));

    expect($reads->stray)->toBe([])
        ->and($reads->deciding)->toHaveCount(1)
        ->and($reads->series)->not->toBeEmpty();
});

test('a public page caches the rows of its public cards only', function () {
    seedPagesCollection();
    $user = User::where('username', 'carlos')->sole();
    $publicIds = PublicCollection::for($user)->cardsQuery()->orderBy('id')->pluck('id')->all();
    $privateOnly = CollectionItem::withoutGlobalScope(TenantScope::class)
        ->whereIn('collection_id', Collection::withoutGlobalScope(TenantScope::class)->where('is_public', false)->select('id'))
        ->whereNotIn('card_id', $publicIds)
        ->pluck('card_id')->unique()->values()->all();

    Livewire::test(Index::class, ['username' => 'carlos']);
    $entry = Cache::get(DecidingPriceRows::cacheKey($publicIds));
    $rows = unserialize(gzuncompress($entry['rows']));
    $cachedCards = array_values(array_unique(array_column($rows, array_search('card_id', CardPriceSnapshot::CHART_COLUMNS, true))));

    expect(CardPriceSnapshot::whereIn('card_id', $privateOnly)->exists())->toBeTrue()
        ->and($entry['ids'])->toBe(implode(',', $publicIds))
        ->and(array_intersect($cachedCards, $privateOnly))->toBe([])
        ->and(array_diff($cachedCards, $publicIds))->toBe([]);
});

test('after a price write the next render shows the new price, not the cached one', function (Closure $write) {
    $this->freezeTime();
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true]);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-1', 'set_id' => $set->id, 'local_id' => '1', 'name' => 'Card 1']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => $card->tcgdex_id, 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 1]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDay(), 'currency' => 'USD', 'market_minor' => 500]);
    $render = fn () => Livewire::test(Index::class, ['username' => 'carlos'])->viewData('totals');

    $before = $render();
    $cached = PriceRowReads::during($render);
    $write($card);

    expect($before)->toBe(['USD' => 500])
        ->and($cached->deciding)->toBe([])
        ->and($render())->toBe(['USD' => 650]);
})->with([
    'Eloquent update' => [fn (Card $card) => CardPriceSnapshot::where('card_id', $card->id)->sole()->update(['market_minor' => 650])],
    'query-builder update' => [fn (Card $card) => DB::table('card_price_snapshots')->where('card_id', $card->id)->update(['market_minor' => 650])],
    'raw SQL update' => [fn (Card $card) => DB::update('UPDATE card_price_snapshots SET market_minor = 650 WHERE card_id = ?', [$card->id])],
    'insert of a newer row' => [fn (Card $card) => CardPriceSnapshot::insert([[
        'card_id' => $card->id, 'source' => 'tcgplayer', 'origin' => 'tcgdex', 'variant' => 'normal', 'captured_on' => today()->toDateString(),
        'currency' => 'USD', 'market_minor' => 650, 'created_at' => now(), 'updated_at' => now(),
    ]])],
    'delete of the newest row' => [function (Card $card) {
        CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDays(2), 'currency' => 'USD', 'market_minor' => 650]);
        // The insert above bumped the version too; render so the cache holds the 500 again.
        Livewire::test(Index::class, ['username' => 'carlos']);
        CardPriceSnapshot::where('card_id', $card->id)->whereDate('captured_on', today()->subDay())->delete();
    }],
]);
