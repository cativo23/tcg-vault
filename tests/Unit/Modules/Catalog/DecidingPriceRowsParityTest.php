<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Services\CardPriceResolver;
use App\Modules\Catalog\Services\DecidingPriceRows;
use App\Modules\Catalog\Services\PriceDelta;
use App\Modules\Collection\Models\CollectionItem;
use App\Modules\Collection\Services\Valuation;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\Support\FullRecentPriceRows;

/*
 * DecidingPriceRows keeps only the rows of the recent window that can decide
 * a current price. Every current-price question must get the same answer
 * from those rows as from the whole window: the resolver's picks and deltas,
 * Valuation's totals and headline, the newest market day and the price
 * origins a gallery header lists.
 */

const DECIDING_COSMOS = 'holofoil:cosmos+player-rewards-program';
const DECIDING_STAMP = 'normal+player-rewards-program';

/**
 * Rows per hand-written card: [source, variant, days ago, currency, market_minor, origin?].
 *
 * @return array<string, list<array{0: string, 1: string, 2: int, 3: string, 4: ?int, 5?: string}>>
 */
function decidingScenarios(): array
{
    $stale = CardPriceResolver::STALE_AFTER_DAYS;
    $window = CardPriceSnapshot::RECENT_DAYS;
    $daily = fn (string $source, string $variant, string $currency, int $from, int $to, int $base) => array_map(
        fn (int $d) => [$source, $variant, $d, $currency, $base + $d * 7],
        range($from, $to),
    );

    return [
        'long history on every base print' => array_merge(
            $daily('tcgplayer', 'normal', 'USD', 0, 45, 100),
            $daily('tcgplayer', 'holofoil', 'USD', 0, 45, 900),
            $daily('tcgplayer', 'reverse-holofoil', 'USD', 0, 40, 300),
            $daily('cardmarket', 'normal', 'EUR', 0, 45, 90),
            $daily('cardmarket', 'default', 'EUR', 1, 38, 85),
        ),
        'frozen tcgplayer behind a current cardmarket default' => array_merge(
            $daily('tcgplayer', 'normal', 'USD', $stale + 6, 40, 400),
            $daily('tcgplayer', 'holofoil', 'USD', $stale + 6, 40, 700),
            $daily('cardmarket', 'default', 'EUR', 0, 40, 350),
        ),
        'special prints next to the card-wide row' => array_merge(
            $daily('tcgplayer', DECIDING_COSMOS, 'USD', 0, 20, 5000),
            $daily('tcgplayer', DECIDING_STAMP, 'USD', 2, 25, 2500),
            $daily('cardmarket', 'default', 'EUR', 0, 33, 120),
        ),
        'old and new manual rows over a frozen reverse' => array_merge(
            [['manual', 'reverse-holofoil', 90, 'USD', 700], ['manual', 'reverse-holofoil', 0, 'USD', 750], ['manual', 'holofoil', 60, 'USD', 1200]],
            $daily('tcgplayer', 'reverse-holofoil', 'USD', $stale + 4, 35, 600),
            $daily('tcgplayer', 'normal', 'USD', 0, 30, 80),
        ),
        'only manual rows, some past the window' => [
            ['manual', 'normal', 120, 'USD', 300], ['manual', 'normal', 70, 'USD', 280], ['manual', 'holofoil', 5, 'EUR', 900], ['manual', 'holofoil', 6, 'EUR', null],
        ],
        'unpriced newest rows over priced older ones' => [
            ['tcgplayer', 'normal', 0, 'USD', null], ['tcgplayer', 'normal', 1, 'USD', null], ['tcgplayer', 'normal', 2, 'USD', 500],
            ['tcgplayer', 'normal', 3, 'USD', 480], ['tcgplayer', 'normal', 4, 'USD', 470], ['tcgplayer', 'normal', 9, 'USD', 460],
            ['cardmarket', 'normal', 0, 'EUR', null], ['cardmarket', 'normal', 3, 'EUR', 450], ['cardmarket', 'normal', 5, 'EUR', null], ['cardmarket', 'normal', 6, 'EUR', 440],
            ['cardmarket', 'default', 0, 'EUR', null], ['cardmarket', 'default', 2, 'EUR', 430], ['cardmarket', 'default', 4, 'EUR', 420],
        ],
        'same-day ties across sources and variants' => [
            ['ebay', 'normal', 0, 'USD', 200], ['cardmarket', 'normal', 0, 'USD', 100], ['tcgplayer', 'holofoil', 0, 'USD', 300],
            ['cardmarket', 'holofoil', 0, 'EUR', 280], ['ebay', 'reverse-holofoil', 0, 'USD', 150], ['cardmarket', 'reverse-holofoil', 0, 'EUR', 140],
            ['ebay', 'normal', 1, 'USD', 190], ['cardmarket', 'normal', 1, 'USD', 110], ['tcgplayer', 'holofoil', 1, 'USD', 290],
            ['cardmarket', 'holofoil', 2, 'EUR', 270], ['ebay', 'reverse-holofoil', 2, 'USD', 160], ['cardmarket', 'reverse-holofoil', 1, 'EUR', 130],
        ],
        // The newest USD row's previous comparable is two days back, behind
        // a EUR row of the same print: only a per-currency rank keeps it.
        'currencies alternating on one print' => [
            ['tcgplayer', 'normal', 0, 'USD', 100], ['tcgplayer', 'normal', 1, 'EUR', 95], ['tcgplayer', 'normal', 2, 'USD', 98],
            ['tcgplayer', 'normal', 3, 'EUR', 93], ['tcgplayer', 'normal', 4, 'USD', 97], ['cardmarket', 'normal', 1, 'EUR', 90],
            ['manual', 'normal', 3, 'GBP', 85], ['manual', 'normal', 8, 'USD', 84],
        ],
        'at and past the window edge' => [
            ['tcgplayer', 'normal', $window, 'USD', 100], ['tcgplayer', 'normal', $window + 1, 'USD', 101], ['tcgplayer', 'normal', $window + 2, 'USD', 102],
            ['tcgplayer', 'holofoil', $window - 1, 'USD', 900], ['tcgplayer', 'holofoil', $window, 'USD', 905], ['tcgplayer', 'holofoil', $window + 5, 'USD', 910],
            ['cardmarket', 'default', $window + 1, 'EUR', 80],
        ],
        'a card wholly past the window' => [
            ['tcgplayer', 'normal', $window + 1, 'USD', 300], ['tcgplayer', 'normal', $window + 9, 'USD', 310], ['cardmarket', 'default', $window + 3, 'EUR', 250],
        ],
        'exactly at and one past the staleness edge' => array_merge(
            $daily('tcgplayer', 'normal', 'USD', $stale, 20, 100),
            $daily('tcgplayer', 'holofoil', 'USD', $stale + 1, 20, 900),
            $daily('cardmarket', 'default', 'EUR', 0, 20, 80),
            $daily('cardmarket', 'reverse-holofoil', 'EUR', $stale + 1, 20, 70),
        ),
        'a holo-only card borrowing the card-wide row' => array_merge(
            $daily('tcgplayer', 'holofoil', 'USD', $stale + 2, 25, 1500),
            $daily('cardmarket', 'holofoil', 'EUR', 0, 25, 1300),
            $daily('cardmarket', 'default', 'EUR', 0, 25, 1250),
        ),
        // tcgdex priced the group on the two newest days and before the
        // gap; tcgcsv filled the day tcgdex missed. Neither the newest row
        // nor the two newest priced rows carry tcgcsv, but the header does.
        'tcgcsv gap-fill behind two newer tcgdex days' => [
            ['tcgplayer', 'normal', 0, 'USD', 500], ['tcgplayer', 'normal', 1, 'USD', 495],
            ['tcgplayer', 'normal', 2, 'USD', 490, 'tcgcsv'], ['tcgplayer', 'normal', 3, 'USD', 485], ['tcgplayer', 'normal', 4, 'USD', 480],
            ['tcgplayer', 'holofoil', 6, 'USD', 900, 'tcgcsv'], ['tcgplayer', 'holofoil', 0, 'USD', 920], ['tcgplayer', 'holofoil', 1, 'USD', 910],
        ],
        'no price rows at all' => [],
    ];
}

/**
 * A seeded random history over about two windows, with gaps, unpriced days,
 * a currency switch now and then, and tcgcsv days among tcgdex ones.
 *
 * @return list<list<array{0: string, 1: string, 2: int, 3: string, 4: ?int, 5: string}>>
 */
function decidingRandomCards(int $count): array
{
    mt_srand(20261019);
    $sources = ['tcgplayer', 'tcgplayer', 'cardmarket', 'cardmarket', 'manual', 'ebay'];
    $variants = ['normal', 'holofoil', 'reverse-holofoil', 'default', DECIDING_COSMOS, DECIDING_STAMP, 'reverse-holofoil:pokeball'];
    $cards = [];

    for ($c = 0; $c < $count; $c++) {
        $rows = [];
        $series = mt_rand(1, 7);

        for ($s = 0; $s < $series; $s++) {
            $source = $sources[mt_rand(0, count($sources) - 1)];
            $variant = $variants[mt_rand(0, count($variants) - 1)];
            $currency = $source === 'cardmarket' ? 'EUR' : 'USD';
            $newest = mt_rand(0, $source === 'manual' ? 90 : 36);
            $oldest = $newest + mt_rand(0, $source === 'manual' ? 3 : 30);

            for ($day = $newest; $day <= $oldest; $day++) {
                if (mt_rand(0, 3) === 0 && $day !== $newest) {
                    continue;
                }
                $dayCurrency = mt_rand(0, 9) === 0 ? ($currency === 'USD' ? 'EUR' : 'USD') : $currency;
                $origin = $source === 'manual' ? 'hand' : (mt_rand(0, 7) === 0 ? 'tcgcsv' : 'tcgdex');
                $rows["{$source}|{$variant}|{$day}"] = [$source, $variant, $day, $dayCurrency, mt_rand(0, 4) === 0 ? null : mt_rand(1, 20000), $origin];
            }
        }

        $cards[] = array_values($rows);
    }

    return $cards;
}

/**
 * @param  list<array{0: string, 1: string, 2: int, 3: string, 4: ?int, 5?: string}>  $rows
 */
function decidingCard(Set $set, int $n, array $rows): Card
{
    $card = Card::create(['tcgdex_id' => "dec-{$n}", 'set_id' => $set->id, 'local_id' => (string) $n, 'name' => "Deciding {$n}"]);
    $now = now();

    if ($rows !== []) {
        CardPriceSnapshot::insert(array_map(fn (array $r) => [
            'card_id' => $card->id,
            'source' => $r[0],
            'origin' => $r[5] ?? ($r[0] === 'manual' ? 'hand' : 'tcgdex'),
            'variant' => $r[1],
            'captured_on' => today()->subDays($r[2])->toDateString(),
            'currency' => $r[3],
            'market_minor' => $r[4],
            'created_at' => $now,
            'updated_at' => $now,
        ], $rows));
    }

    return $card;
}

/** A comparable fingerprint of whatever a resolver or Valuation method returned. */
function decidingKey(mixed $value): mixed
{
    return match (true) {
        $value === null, is_bool($value) => $value,
        $value instanceof CardPriceSnapshot => [$value->id, $value->market_minor, $value->currency, $value->capturedOnKey()],
        $value instanceof PriceDelta => [decidingKey($value->latest), decidingKey($value->previous), $value->deltaMinor],
        is_array($value) => array_map(fn ($v) => decidingKey($v), $value),
        default => $value,
    };
}

/**
 * The owned copies each card gets, the same on both loads: a few variants a
 * screen can ask about, unassigned ones included.
 *
 * @return list<list<array{0: ?string, 1: int}>>
 */
function decidingHoldings(int $cards): array
{
    mt_srand(4242);
    $variants = [null, 'normal', 'holofoil', 'reverse-holofoil', 'default', DECIDING_COSMOS, DECIDING_STAMP, 'unknown-print'];
    $holdings = [];

    for ($c = 0; $c < $cards; $c++) {
        $copies = [];
        for ($k = mt_rand(0, 3); $k > 0; $k--) {
            $copies[] = [$variants[mt_rand(0, count($variants) - 1)], mt_rand(1, 3)];
        }
        $holdings[] = $c % 9 === 0 ? [] : $copies;
    }

    return $holdings;
}

/**
 * Every current-price answer for the cards, from one loader.
 *
 * @param  list<int>  $ids
 * @param  list<list<array{0: ?string, 1: int}>>  $holdings
 * @return array<string, mixed>
 */
function decidingAnswers(DecidingPriceRows $loader, array $ids, array $holdings): array
{
    $cards = $loader->loadOnto(Card::query()->whereIn('id', $ids)->orderBy('id')->get());
    $resolver = new CardPriceResolver;
    $valuation = new Valuation($resolver);
    $answers = [];

    foreach ($cards->values() as $i => $card) {
        $card->setRelation('collectionItems', new EloquentCollection(array_map(
            fn (array $h) => new CollectionItem(['variant' => $h[0], 'quantity' => $h[1]]),
            $holdings[$i],
        )));
        $tag = $card->tcgdex_id;
        $variants = array_values(array_unique(array_merge(
            [null, 'normal', 'holofoil', 'reverse-holofoil', 'default', 'unknown-print', DECIDING_COSMOS, DECIDING_STAMP],
            CardPriceSnapshot::where('card_id', $card->id)->distinct()->pluck('variant')->all(),
        )));

        // Twice where a pick is memoized: the second call is served from the memo.
        foreach ([1, 2] as $pass) {
            $answers["{$tag} resolve #{$pass}"] = decidingKey($resolver->resolve($card));
        }
        $answers["{$tag} resolveDelta"] = decidingKey($resolver->resolveDelta($card));
        $answers["{$tag} resolveCardWide"] = decidingKey($resolver->resolveCardWide($card));

        foreach ($variants as $variant) {
            $v = $variant ?? 'null';
            foreach ([1, 2] as $pass) {
                $answers["{$tag} resolveForVariant {$v} #{$pass}"] = decidingKey($resolver->resolveForVariant($card, $variant));
            }
            $answers["{$tag} deltaFor pick {$v}"] = decidingKey($resolver->deltaFor($card, $resolver->resolveForVariant($card, $variant)));

            if ($variant !== null) {
                $answers["{$tag} hasCurrentMarketPrice {$v}"] = $resolver->hasCurrentMarketPrice($card, $variant);
            }
        }

        $headline = $valuation->headlineSnapshot($card);
        $answers["{$tag} headlineSnapshot"] = decidingKey($headline);
        $answers["{$tag} headlineItem"] = $card->collectionItems->search($valuation->headlineItem($card), true);
        $answers["{$tag} deltaFor headline"] = decidingKey($resolver->deltaFor($card, $headline));
        $answers["{$tag} cardTotal"] = $valuation->cardTotal($card);
        $answers["{$tag} origins"] = decidingOrigins(new EloquentCollection([$card]));
        $answers["{$tag} newest market day"] = decidingNewestMarketDay(new EloquentCollection([$card]));
    }

    $answers['totalsByCurrency'] = $valuation->totalsByCurrency($cards);
    $answers['newest market day'] = decidingNewestMarketDay($cards);
    $answers['origins'] = decidingOrigins($cards);

    return $answers;
}

/**
 * The priced marketplace rows a gallery header reads, as Gallery\Index filters them.
 *
 * @param  EloquentCollection<int, Card>  $cards
 * @return Collection<int, CardPriceSnapshot>
 */
function decidingSynced(EloquentCollection $cards): Collection
{
    return $cards->flatMap(fn (Card $c) => $c->priceSnapshots)
        ->filter(fn (CardPriceSnapshot $s) => $s->source !== 'manual' && $s->market_minor !== null);
}

/** @param EloquentCollection<int, Card> $cards */
function decidingNewestMarketDay(EloquentCollection $cards): ?string
{
    return decidingSynced($cards)->max(fn (CardPriceSnapshot $s) => $s->capturedOnKey());
}

/**
 * @param  EloquentCollection<int, Card>  $cards
 * @return list<string>
 */
function decidingOrigins(EloquentCollection $cards): array
{
    return decidingSynced($cards)->pluck('origin')->unique()->sort()->values()->all();
}

test('the deciding rows answer every current-price question as the whole recent window does', function () {
    $set = Set::create(['tcgdex_id' => 'dec', 'name' => 'Deciding']);
    $histories = array_merge(array_values(decidingScenarios()), decidingRandomCards(40));
    $ids = [];
    foreach ($histories as $n => $rows) {
        $ids[] = decidingCard($set, $n, $rows)->id;
    }
    $holdings = decidingHoldings(count($ids));

    $expected = decidingAnswers(new FullRecentPriceRows, $ids, $holdings);
    $actual = decidingAnswers(new DecidingPriceRows, $ids, $holdings);

    $mismatches = [];
    foreach ($expected as $what => $value) {
        $got = array_key_exists($what, $actual) ? $actual[$what] : 'missing';
        if ($got !== $value) {
            $mismatches[] = "{$what}: expected ".json_encode($value).', got '.json_encode($got);
        }
    }

    expect($mismatches)->toBe([])
        ->and(array_keys($actual))->toBe(array_keys($expected))
        ->and(count($expected))->toBeGreaterThan(1500);
});

test('the deciding rows are a strict subset of the window, so the trim is doing something', function () {
    $set = Set::create(['tcgdex_id' => 'dec', 'name' => 'Deciding']);
    foreach (array_values(decidingScenarios()) as $n => $rows) {
        decidingCard($set, $n, $rows);
    }

    $full = (new FullRecentPriceRows)->loadOnto(Card::all())->flatMap(fn (Card $c) => $c->priceSnapshots)->pluck('id');
    $kept = (new DecidingPriceRows)->loadOnto(Card::all())->flatMap(fn (Card $c) => $c->priceSnapshots)->pluck('id');

    expect($kept->diff($full))->toBeEmpty()
        ->and($kept->count())->toBeLessThan(intdiv($full->count(), 2));
});

test('the header still lists tcgcsv when tcgdex priced its group on two later days', function () {
    $set = Set::create(['tcgdex_id' => 'dec', 'name' => 'Deciding']);
    decidingCard($set, 1, decidingScenarios()['tcgcsv gap-fill behind two newer tcgdex days']);

    $cards = (new DecidingPriceRows)->loadOnto(Card::all());

    expect(decidingOrigins($cards))->toBe(['tcgcsv', 'tcgdex']);
});

test('the deciding rows are loaded in one query, with the card ids bound as one array and without the raw payload', function () {
    $set = Set::create(['tcgdex_id' => 'dec', 'name' => 'Deciding']);
    foreach (array_values(decidingScenarios()) as $n => $rows) {
        decidingCard($set, $n, $rows);
    }
    $cards = Card::all();
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query;
    });

    (new DecidingPriceRows)->loadOnto($cards);

    expect($queries)->toHaveCount(1)
        ->and($queries[0]->bindings)->toHaveCount(2)
        ->and($cards->every(fn (Card $c) => $c->relationLoaded('priceSnapshots')))->toBeTrue()
        ->and($cards->flatMap(fn (Card $c) => $c->priceSnapshots)->every(fn (CardPriceSnapshot $s) => ! array_key_exists('raw', $s->getAttributes())))->toBeTrue();
});

test('no cards means no query', function () {
    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    expect((new DecidingPriceRows)->loadOnto(new EloquentCollection)->all())->toBe([])
        ->and($queries)->toBe(0);
});

/**
 * Runs $callback and returns how many queries read card_price_snapshots.
 */
function decidingPriceReads(callable $callback): int
{
    $reads = 0;
    DB::listen(function ($query) use (&$reads) {
        if (str_contains($query->sql, 'card_price_snapshots')) {
            $reads++;
        }
    });
    $callback();

    return $reads;
}

/**
 * Each card's loaded rows as plain attribute arrays, in relation order.
 *
 * @param  EloquentCollection<int, Card>  $cards
 * @return array<int, list<array<string, mixed>>>
 */
function decidingLoaded(EloquentCollection $cards): array
{
    return $cards->mapWithKeys(fn (Card $c) => [$c->id => $c->priceSnapshots->map(fn (CardPriceSnapshot $s) => [
        get_class($s), $s->exists, $s->getAttributes(), $s->getRawOriginal(),
    ])->all()])->all();
}

test('loading the same cards again reads no price rows and sets exactly the same relations', function () {
    $set = Set::create(['tcgdex_id' => 'dec', 'name' => 'Deciding']);
    foreach (array_merge(array_values(decidingScenarios()), decidingRandomCards(10)) as $n => $rows) {
        decidingCard($set, $n, $rows);
    }

    $missReads = decidingPriceReads(function () use (&$miss) {
        $miss = (new DecidingPriceRows)->loadOnto(Card::orderBy('id')->get());
    });
    // In the other order: the cache key is the set of cards, not their order.
    $hitReads = decidingPriceReads(function () use (&$hit) {
        $hit = (new DecidingPriceRows)->loadOnto(Card::orderByDesc('id')->get());
    });

    $expected = decidingLoaded($miss);
    $actual = decidingLoaded($hit);
    $differing = array_keys(array_filter($expected, fn (array $rows, int $id) => ($actual[$id] ?? null) !== $rows, ARRAY_FILTER_USE_BOTH));

    expect($missReads)->toBe(1)
        ->and($hitReads)->toBe(0)
        ->and($differing)->toBe([])
        ->and(array_keys($actual))->toEqualCanonicalizing(array_keys($expected))
        ->and(collect($expected)->flatten(1)->count())->toBeGreaterThan(100);
});

test('different card sets never share cached rows', function () {
    $set = Set::create(['tcgdex_id' => 'dec', 'name' => 'Deciding']);
    $cards = [];
    foreach (array_slice(array_values(decidingScenarios()), 0, 6) as $n => $rows) {
        $cards[] = decidingCard($set, $n, $rows);
    }
    $all = collect($cards)->pluck('id')->all();
    $some = array_slice($all, 0, 3);
    $loader = new DecidingPriceRows;

    $loader->loadOnto(Card::whereIn('id', $all)->get());
    $reads = decidingPriceReads(function () use ($loader, $some, &$loaded) {
        $loaded = $loader->loadOnto(Card::whereIn('id', $some)->get());
    });

    expect($reads)->toBe(1)
        ->and($loaded->flatMap(fn (Card $c) => $c->priceSnapshots)->pluck('card_id')->unique()->sort()->values()->all())
        ->toBe(CardPriceSnapshot::whereIn('card_id', $some)->distinct()->orderBy('card_id')->pluck('card_id')->all())
        ->and(DecidingPriceRows::cacheKey($some))->not->toBe(DecidingPriceRows::cacheKey($all));
});

test('a price written after the rows were cached is the one the next load returns', function () {
    $set = Set::create(['tcgdex_id' => 'dec', 'name' => 'Deciding']);
    $card = decidingCard($set, 1, [['tcgplayer', 'normal', 0, 'USD', 500], ['tcgplayer', 'normal', 1, 'USD', 490]]);
    $loader = new DecidingPriceRows;
    $loader->loadOnto(Card::whereKey($card->id)->get());

    CardPriceSnapshot::where('card_id', $card->id)->whereDate('captured_on', today())->sole()->update(['market_minor' => 777]);
    $reads = decidingPriceReads(function () use ($loader, $card, &$after) {
        $after = $loader->loadOnto(Card::whereKey($card->id)->get())->sole();
    });

    expect($reads)->toBe(1)
        ->and((new CardPriceResolver)->resolve($after)?->market_minor)->toBe(777);
});

test('the next day reads the rows again, since the recent window moved', function () {
    $set = Set::create(['tcgdex_id' => 'dec', 'name' => 'Deciding']);
    $card = decidingCard($set, 1, [['tcgplayer', 'normal', CardPriceSnapshot::RECENT_DAYS, 'USD', 500], ['tcgplayer', 'normal', 1, 'USD', 490]]);
    $loader = new DecidingPriceRows;
    $before = $loader->loadOnto(Card::whereKey($card->id)->get())->sole()->priceSnapshots->count();

    $this->travel(1)->days();
    $reads = decidingPriceReads(function () use ($loader, $card, &$after) {
        $after = $loader->loadOnto(Card::whereKey($card->id)->get())->sole()->priceSnapshots->count();
    });

    expect($reads)->toBe(1)
        ->and([$before, $after])->toBe([2, 1]);
});
