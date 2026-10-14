<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Services\CardPriceResolver;
use App\Modules\Catalog\Services\PriceDelta;
use Illuminate\Support\Collection;
use Tests\Support\LegacyCardPriceResolver;

/*
 * Every public method of CardPriceResolver must pick exactly the row the
 * frozen LegacyCardPriceResolver picks, for every card and every variant a
 * screen can ask about. The dataset mixes hand-written edge cases with a
 * seeded random history, and every relation is loaded in shuffled order.
 */

const PARITY_SPECIAL_COSMOS = 'holofoil:cosmos+player-rewards-program';
const PARITY_SPECIAL_STAMP = 'normal+player-rewards-program';

/**
 * Rows per hand-written card: [source, variant, days ago, currency, market_minor].
 *
 * @return array<string, list<array{0: string, 1: string, 2: int, 3: string, 4: ?int}>>
 */
function parityScenarios(): array
{
    $stale = CardPriceResolver::STALE_AFTER_DAYS;

    return [
        'every base print on both marketplaces' => [
            ['tcgplayer', 'normal', 0, 'USD', 100], ['tcgplayer', 'holofoil', 0, 'USD', 900], ['tcgplayer', 'reverse-holofoil', 0, 'USD', 300],
            ['cardmarket', 'normal', 0, 'EUR', 90], ['cardmarket', 'holofoil', 0, 'EUR', 800], ['cardmarket', 'reverse-holofoil', 0, 'EUR', 250],
            ['tcgplayer', 'normal', 1, 'USD', 110], ['cardmarket', 'normal', 1, 'EUR', 95],
        ],
        'frozen tcgplayer behind a current cardmarket default' => [
            ['tcgplayer', 'normal', $stale + 5, 'USD', 400], ['tcgplayer', 'holofoil', $stale + 5, 'USD', 700],
            ['cardmarket', 'default', 0, 'EUR', 350], ['cardmarket', 'default', 1, 'EUR', 340],
        ],
        'special prints next to the card-wide row' => [
            ['tcgplayer', PARITY_SPECIAL_COSMOS, 0, 'USD', 5000], ['tcgplayer', PARITY_SPECIAL_STAMP, 0, 'USD', 2500],
            ['cardmarket', 'default', 0, 'EUR', 120], ['tcgplayer', PARITY_SPECIAL_COSMOS, 2, 'USD', 4900],
        ],
        'only special prints' => [
            ['tcgplayer', PARITY_SPECIAL_COSMOS, 0, 'USD', 5000], ['cardmarket', PARITY_SPECIAL_STAMP, 1, 'EUR', 2100],
        ],
        'old and new manual rows over a frozen reverse' => [
            ['manual', 'reverse-holofoil', 90, 'USD', 700], ['manual', 'reverse-holofoil', 0, 'USD', 750],
            ['tcgplayer', 'reverse-holofoil', $stale + 4, 'USD', 600], ['tcgplayer', 'normal', 0, 'USD', 80],
            ['manual', 'holofoil', 60, 'USD', 1200],
        ],
        'only manual rows' => [
            ['manual', 'normal', 120, 'USD', 300], ['manual', 'holofoil', 5, 'EUR', 900],
        ],
        'unpriced newest rows over priced older ones' => [
            ['tcgplayer', 'normal', 0, 'USD', null], ['tcgplayer', 'normal', 1, 'USD', 500], ['tcgplayer', 'normal', 2, 'USD', 480],
            ['cardmarket', 'normal', 0, 'EUR', null], ['cardmarket', 'normal', 3, 'EUR', 450],
            ['cardmarket', 'default', 0, 'EUR', null], ['cardmarket', 'default', 2, 'EUR', 430],
        ],
        'same-day ties across sources and variants' => [
            ['ebay', 'normal', 0, 'USD', 200], ['cardmarket', 'normal', 0, 'USD', 100], ['tcgplayer', 'holofoil', 0, 'USD', 300],
            ['cardmarket', 'holofoil', 0, 'EUR', 280], ['ebay', 'reverse-holofoil', 0, 'USD', 150], ['cardmarket', 'reverse-holofoil', 0, 'EUR', 140],
        ],
        // No tcgplayer normal/holofoil and no cardmarket default, so the card-level pick falls to the
        // any-base-print step, where only the source then variant tie order separates same-day rows:
        // cardmarket|normal wins, and flipping either comparison picks another row.
        'card-level tie order alone decides' => [
            ['ebay', 'normal', 0, 'USD', 200], ['cardmarket', 'reverse-holofoil', 0, 'EUR', 140],
            ['cardmarket', 'normal', 0, 'EUR', 100], ['ebay', 'holofoil', 0, 'USD', 300],
        ],
        'mixed currencies on one print' => [
            ['tcgplayer', 'normal', 0, 'USD', 100], ['tcgplayer', 'normal', 1, 'EUR', 95], ['cardmarket', 'normal', 1, 'EUR', 90],
            ['manual', 'normal', 3, 'GBP', 85], ['tcgplayer', 'normal', 2, 'USD', 98],
        ],
        'exactly at and one past the staleness edge' => [
            ['tcgplayer', 'normal', $stale, 'USD', 100], ['tcgplayer', 'holofoil', $stale + 1, 'USD', 900],
            ['cardmarket', 'default', 0, 'EUR', 80], ['cardmarket', 'reverse-holofoil', $stale + 1, 'EUR', 70],
        ],
        'card-wide row with no price over a frozen normal' => [
            ['cardmarket', 'default', 0, 'EUR', null], ['tcgplayer', 'normal', $stale + 10, 'USD', 300], ['tcgplayer', 'holofoil', 0, 'USD', null],
        ],
        'a holo-only card borrowing the card-wide row' => [
            ['tcgplayer', 'holofoil', $stale + 2, 'USD', 1500], ['cardmarket', 'holofoil', 0, 'EUR', 1300], ['cardmarket', 'default', 0, 'EUR', 1250],
        ],
        'no price rows at all' => [],
    ];
}

/**
 * A seeded random history: a few (source, variant) series per card, each
 * running over its own span of days with gaps and unpriced days.
 *
 * @return list<list<array{0: string, 1: string, 2: int, 3: string, 4: ?int}>>
 */
function parityRandomCards(int $count): array
{
    mt_srand(20261014);
    $sources = ['tcgplayer', 'tcgplayer', 'cardmarket', 'cardmarket', 'manual', 'ebay'];
    $variants = ['normal', 'holofoil', 'reverse-holofoil', 'default', PARITY_SPECIAL_COSMOS, PARITY_SPECIAL_STAMP, 'reverse-holofoil:pokeball'];
    $currencies = ['USD', 'EUR'];
    $cards = [];

    for ($c = 0; $c < $count; $c++) {
        $rows = [];
        $series = mt_rand(1, 7);

        for ($s = 0; $s < $series; $s++) {
            $source = $sources[mt_rand(0, count($sources) - 1)];
            $variant = $variants[mt_rand(0, count($variants) - 1)];
            $currency = match ($source) {
                'tcgplayer' => mt_rand(0, 9) === 0 ? 'EUR' : 'USD',
                'cardmarket' => 'EUR',
                default => $currencies[mt_rand(0, 1)],
            };
            $newest = mt_rand(0, 12);
            $oldest = $newest + mt_rand(0, $source === 'manual' ? 3 : 20);

            for ($day = $newest; $day <= $oldest; $day++) {
                if (mt_rand(0, 3) === 0 && $day !== $newest) {
                    continue;
                }
                $rows["{$source}|{$variant}|{$day}"] = [$source, $variant, $day, $currency, mt_rand(0, 4) === 0 ? null : mt_rand(1, 20000)];
            }
        }

        $cards[] = array_values($rows);
    }

    return $cards;
}

/**
 * @param  list<array{0: string, 1: string, 2: int, 3: string, 4: ?int}>  $rows
 */
function parityCard(Set $set, int $n, array $rows): Card
{
    $card = Card::create(['tcgdex_id' => "par-{$n}", 'set_id' => $set->id, 'local_id' => (string) $n, 'name' => "Parity {$n}"]);
    $now = now();

    if ($rows !== []) {
        CardPriceSnapshot::insert(array_map(fn (array $r) => [
            'card_id' => $card->id,
            'source' => $r[0],
            'origin' => $r[0] === 'manual' ? 'hand' : 'tcgdex',
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

/**
 * The rows in a seeded random order (mt_srand), so a failing run repeats.
 *
 * @param  Collection<int, CardPriceSnapshot>  $rows
 * @return Collection<int, CardPriceSnapshot>
 */
function parityShuffled(Collection $rows): Collection
{
    $all = $rows->values()->all();
    for ($i = count($all) - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        [$all[$i], $all[$j]] = [$all[$j], $all[$i]];
    }

    return new Illuminate\Database\Eloquent\Collection($all);
}

/** A comparable fingerprint of whatever a resolver method returned. */
function parityKey(mixed $value): mixed
{
    return match (true) {
        $value === null, is_bool($value) => $value,
        $value instanceof CardPriceSnapshot => $value->id,
        $value instanceof PriceDelta => [$value->latest->id, $value->previous->id, $value->deltaMinor],
        $value instanceof Collection => $value->map(fn ($v) => parityKey($v))->values()->all(),
        $value instanceof DateTimeInterface => $value->format('Y-m-d'),
        is_array($value) => array_map(fn ($v) => parityKey($v), $value),
        default => $value,
    };
}

test('every public method picks the same row as the legacy resolver', function () {
    $set = Set::create(['tcgdex_id' => 'par', 'name' => 'Parity']);
    $histories = array_merge(array_values(parityScenarios()), parityRandomCards(30));
    $ids = [];
    foreach ($histories as $n => $rows) {
        $ids[] = parityCard($set, $n, $rows)->id;
    }

    $legacy = new LegacyCardPriceResolver;
    $resolver = new CardPriceResolver;
    $asOfDays = [today(), today()->subDays(2), today()->subDays(CardPriceResolver::STALE_AFTER_DAYS + 1), today()->subDays(15)];
    $dayKeys = collect(range(0, 20))->map(fn (int $d) => today()->subDays($d)->toDateString());
    $checked = 0;
    $mismatches = [];

    $compare = function (string $what, Closure $call, int $passes = 1) use ($legacy, $resolver, &$checked, &$mismatches): void {
        $expected = parityKey($call($legacy));
        foreach (range(1, $passes) as $pass) {
            $actual = parityKey($call($resolver));
            $checked++;
            if ($actual !== $expected) {
                $mismatches[] = "{$what} (pass {$pass}): expected ".json_encode($expected).', got '.json_encode($actual);
            }
        }
    };

    mt_srand(7);
    // Both the full history and the recent() window the screens load.
    $loads = [
        'all' => fn () => Card::with('priceSnapshots')->whereIn('id', $ids)->get(),
        'recent' => fn () => Card::with(['priceSnapshots' => fn ($q) => $q->recent()])->whereIn('id', $ids)->get(),
    ];

    foreach ($loads as $load => $query) {
        foreach ($query() as $card) {
            $card->setRelation('priceSnapshots', parityShuffled($card->priceSnapshots));
            $tag = "{$load} card {$card->tcgdex_id}";
            $variants = array_values(array_unique(array_merge(
                [null, 'normal', 'holofoil', 'reverse-holofoil', 'default', 'unknown-print', PARITY_SPECIAL_COSMOS, PARITY_SPECIAL_STAMP],
                $card->priceSnapshots->pluck('variant')->all(),
            )));

            // Twice where a pick is memoized: the second call is served from the memo.
            $compare("{$tag} resolve", fn ($r) => $r->resolve($card), 2);
            $compare("{$tag} resolveDelta", fn ($r) => $r->resolveDelta($card));
            $compare("{$tag} resolveCardWide", fn ($r) => $r->resolveCardWide($card));
            $compare("{$tag} distinctSnapshotDates", fn ($r) => $r->distinctSnapshotDates($card));

            foreach ($asOfDays as $asOf) {
                $compare("{$tag} resolveAsOf {$asOf->toDateString()}", fn ($r) => $r->resolveAsOf($card, $asOf));
            }

            foreach ($variants as $variant) {
                $v = $variant ?? 'null';
                $compare("{$tag} resolveForVariant {$v}", fn ($r) => $r->resolveForVariant($card, $variant), 2);
                $compare("{$tag} resolveForVariantOnDays {$v}", fn ($r) => $r->resolveForVariantOnDays($card, $variant, $dayKeys));

                foreach ($asOfDays as $asOf) {
                    $compare("{$tag} resolveForVariantAsOf {$v} {$asOf->toDateString()}", fn ($r) => $r->resolveForVariantAsOf($card, $variant, $asOf));
                }

                if ($variant !== null) {
                    $compare("{$tag} hasCurrentMarketPrice {$v}", fn ($r) => $r->hasCurrentMarketPrice($card, $variant));
                }

                $picked = $legacy->resolveForVariant($card, $variant);
                $compare("{$tag} deltaFor pick {$v}", fn ($r) => $r->deltaFor($card, $picked));
                // Read from the database, so the relation's window doesn't matter.
                if ($load === 'all') {
                    $compare("{$tag} historyIn pick {$v}", fn ($r) => $r->historyIn($picked, today()->subDays(10)));
                    $compare("{$tag} historySpan pick {$v}", fn ($r) => $r->historySpan($picked));
                }
            }

            foreach ($card->priceSnapshots as $row) {
                $compare("{$tag} previousComparable row {$row->id}", fn ($r) => $r->previousComparable($card, $row));
                $compare("{$tag} deltaFor row {$row->id}", fn ($r) => $r->deltaFor($card, $row));
            }
        }
    }

    expect($mismatches)->toBe([])
        ->and($checked)->toBeGreaterThan(5000);
});

test('a new load of the relation is resolved afresh, never from an earlier load\'s memo', function () {
    $set = Set::create(['tcgdex_id' => 'par', 'name' => 'Parity']);
    $card = parityCard($set, 1, [['tcgplayer', 'normal', 0, 'USD', 100]]);
    $resolver = new CardPriceResolver;
    $card->load('priceSnapshots');
    $first = $resolver->resolve($card);

    $newer = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->addDay(), 'currency' => 'USD', 'market_minor' => 150]);
    $card->load('priceSnapshots');

    expect($first->id)->not->toBe($newer->id)
        ->and($resolver->resolve($card)->id)->toBe($newer->id)
        ->and($resolver->resolveForVariant($card, 'normal')->id)->toBe($newer->id);

    $card->setRelation('priceSnapshots', collect([$first]));

    expect($resolver->resolve($card)->id)->toBe($first->id);
});
