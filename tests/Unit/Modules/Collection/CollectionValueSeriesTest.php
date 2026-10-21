<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Services\CardPriceResolver;
use App\Modules\Catalog\Services\DecidingPriceRows;
use App\Modules\Catalog\Support\ChartRange;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use App\Modules\Collection\Services\CollectionValueSeries;
use App\Modules\Collection\Services\PublicCollection;
use App\Modules\Collection\Services\Valuation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->user = User::factory()->create(['username' => 'carlos']);
    $this->collection = Collection::factory()->for($this->user)->create(['is_public' => true, 'slug' => 'main']);
    $this->set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $this->own = function (string $localId, ?string $variant = 'normal', int $quantity = 1): Card {
        $card = Card::firstOrCreate(['tcgdex_id' => "me05-{$localId}"], ['set_id' => $this->set->id, 'local_id' => $localId, 'name' => "Card {$localId}"]);
        CollectionItem::create(['collection_id' => $this->collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => $card->tcgdex_id, 'variant' => $variant, 'condition' => 'NM', 'quantity' => $quantity]);

        return $card;
    };
    $this->price = fn (Card $card, int $daysAgo, ?int $minor, string $variant = 'normal', string $source = 'tcgplayer', string $currency = 'USD') => CardPriceSnapshot::create(['card_id' => $card->id, 'source' => $source, 'variant' => $variant, 'captured_on' => today()->subDays($daysAgo), 'currency' => $currency, 'market_minor' => $minor]);
    $this->series = function (ChartRange $range) {
        $cards = PublicCollection::for($this->user)->cardsQuery()->get();

        return (new CollectionValueSeries(new Valuation(new CardPriceResolver)))->for($cards, 'USD', $range);
    };
    $this->headline = fn () => (new Valuation(new CardPriceResolver))->totalsByCurrency((new DecidingPriceRows)->loadOnto(PublicCollection::for($this->user)->cardsQuery()->get()))['USD'] ?? 0;
    $this->seedMixed = function (): void {
        $a = ($this->own)('101', 'normal', 2);
        $b = ($this->own)('102', 'reverse-holofoil');
        $c = ($this->own)('103');
        $d = ($this->own)('104');
        $e = ($this->own)('105');

        for ($ago = 100; $ago >= 0; $ago--) {
            // priced to 5 days ago, then an unpriced newest row over older priced ones
            ($this->price)($a, $ago, $ago <= 4 ? null : 100 + $ago);
            // a second marketplace on the very same days as the first
            ($this->price)($a, $ago, 50 + ($ago % 7), 'normal', 'cardmarket');
            if ($ago % 2 === 0) {
                ($this->price)($b, $ago, 300 + $ago, 'reverse-holofoil');
                ($this->price)($b, $ago, $ago % 4 === 0 ? null : 20 + $ago);
            }
            // a card whose rows stop
            if ($ago >= 60) {
                ($this->price)($c, $ago, 900 - $ago);
            }
        }
        // A sparse tcgplayer print beside a marketplace that alternates priced and unpriced days: on an
        // unpriced day the newest cardmarket row says nothing, so the staleness reference is the priced
        // row a day older, which makes a 4+ day old tcgplayer row stale and leaves the manual price.
        for ($ago = 100; $ago >= 0; $ago--) {
            ($this->price)($e, $ago, $ago % 2 === 1 ? 40 + $ago : null, 'normal', 'cardmarket');
            if ($ago % 10 === 0) {
                ($this->price)($e, $ago, 2000 + $ago);
            }
        }
        ($this->price)($e, 90, 777, 'normal', 'manual');

        // a manual price, and a sparse marketplace beside it
        ($this->price)($d, 80, 4200, 'normal', 'manual');
        ($this->price)($d, 33, 600);
        ($this->price)($d, 12, null);

        // a copy with no variant prices from the card-level chain, manual rows included
        $f = ($this->own)('106', null);
        // a normal copy whose own tcgplayer row freezes while cardmarket's card-wide 'default' keeps moving
        $g = ($this->own)('107');
        // a normal copy whose own cardmarket row turns unpriced: it must not borrow the 'default' price
        $h = ($this->own)('108');
        // a card only priced in EUR, which the USD series ignores
        $i = ($this->own)('109');

        for ($ago = 100; $ago >= 0; $ago--) {
            if ($ago % 3 === 0) {
                ($this->price)($f, $ago, 150 + $ago);
            }
            ($this->price)($f, $ago, 80 + $ago, 'default', 'cardmarket');
            ($this->price)($g, $ago, 300 + $ago, 'default', 'cardmarket');
            if ($ago >= 50) {
                ($this->price)($g, $ago, 700 + $ago);
            }
            ($this->price)($h, $ago, 400 + $ago, 'default', 'cardmarket');
            ($this->price)($h, $ago, $ago >= 40 ? 500 + $ago : null, 'normal', 'cardmarket');
            ($this->price)($i, $ago, 10 + $ago, 'normal', 'cardmarket', 'EUR');
            if ($ago % 5 === 0) {
                ($this->price)($g, $ago, 90 + $ago, 'normal', 'cardmarket', 'EUR');
            }
        }
        ($this->price)($f, 70, 55, 'normal', 'manual');

    };
});

// Also the parity guard between the series' own pricing (rowsOn()) and the page's headline
// (DecidingPriceRows): the stored series' today must equal the headline on mixed fixtures.
test('the last point is today and equals the headline total, on every range', function (ChartRange $range) {
    $a = ($this->own)('001', 'normal', 2);
    $b = ($this->own)('002', 'reverse-holofoil');
    foreach ([200, 60, 10, 1] as $ago) {
        ($this->price)($a, $ago, 100 + $ago);
        ($this->price)($b, $ago, 30 + $ago, 'reverse-holofoil');
    }
    // a frozen source: only an old priced row, still inside today's window
    $c = ($this->own)('003');
    ($this->price)($c, 25, 500);

    ($this->seedMixed)();

    $series = ($this->series)($range);

    expect($series->last()['date']->toDateString())->toBe(today()->toDateString())
        ->and($series->last()['minor'])->toBe(($this->headline)());
})->with(fn () => ChartRange::cases());

test('long ranges sample every 3rd or 7th day back from today', function () {
    $a = ($this->own)('001');
    ($this->price)($a, 400, 100);

    $dates = fn (ChartRange $r) => ($this->series)($r)->pluck('date')->map->toDateString()->all();
    $three = $dates(ChartRange::ThreeMonths);

    expect($three[0])->toBe(today()->subDays(90)->toDateString())
        ->and(end($three))->toBe(today()->toDateString())
        ->and(count($three))->toBe(31)
        ->and(collect($three)->skip(1)->values()->map(fn ($d, $i) => (int) abs(CarbonImmutable::parse($d)->diffInDays(CarbonImmutable::parse($three[$i]))))->unique()->all())->toBe([3])
        ->and(count($dates(ChartRange::OneYear)))->toBe(53)
        ->and(count($dates(ChartRange::OneMonth)))->toBe(31);
});

test('a card with no row near a sampled day keeps its stale price, as the headline does', function () {
    $a = ($this->own)('001');
    ($this->price)($a, 20, 700); // nothing since: stale, but inside every later day's 30-day window

    $series = ($this->series)(ChartRange::OneMonth);

    expect($series->firstWhere(fn ($p) => $p['date']->isSameDay(today()->subDays(5)))['minor'])->toBe(700)
        ->and($series->last()['minor'])->toBe(700);
});

test('a row older than a day\'s own 30-day window does not price that day', function () {
    $a = ($this->own)('001');
    ($this->price)($a, 100, 900);
    ($this->price)($a, 0, 100);

    $series = ($this->series)(ChartRange::SixMonths);
    $at = fn (int $ago) => $series->first(fn ($p) => $p['date']->isSameDay(today()->subDays($ago)));

    // 100 days ago itself is not a sample day for 6M (stride 7 from today): pick the sample days 98 and 63 days ago
    expect($at(98)['minor'])->toBe(900)  // 2 days after the row: inside its window
        ->and($at(63)['minor'])->toBe(0)  // 37 days after: outside, and nothing newer yet
        ->and($at(0)['minor'])->toBe(100);
});

test('manual prices count at any age, from their own day on', function () {
    $a = ($this->own)('001');
    ($this->price)($a, 150, 4200, 'normal', 'manual');
    // An EUR row moves the collection's earliest day before the manual price without adding to the USD series.
    ($this->price)($a, 200, 1, 'normal', 'cardmarket', 'EUR');

    $series = ($this->series)(ChartRange::OneYear);
    $before = $series->filter(fn ($p) => $p['date']->lt(today()->subDays(150)));
    $after = $series->filter(fn ($p) => $p['date']->gte(today()->subDays(150)));

    expect($before->pluck('minor')->unique()->all())->toBe([0])
        ->and($after->pluck('minor')->unique()->values()->all())->toBe([4200]);
});

test('rows after a sampled day never price it', function () {
    $a = ($this->own)('001');
    ($this->price)($a, 10, 100);
    ($this->price)($a, 0, 999);

    $series = ($this->series)(ChartRange::OneMonth);

    expect($series->first(fn ($p) => $p['date']->isSameDay(today()->subDays(1)))['minor'])->toBe(100);
});

test('the series never starts before the earliest price', function () {
    $a = ($this->own)('001');
    ($this->price)($a, 12, 100);

    $series = ($this->series)(ChartRange::OneYear);

    expect($series->first()['date']->gte(today()->subDays(12)))->toBeTrue();
});

test('fewer than two sample days is no series', function () {
    $a = ($this->own)('001');
    ($this->price)($a, 0, 100);

    expect(($this->series)(ChartRange::OneMonth))->toBeEmpty();
});

test('the earliest day is the first snapshot of any owned card', function () {
    $a = ($this->own)('001');
    ($this->price)($a, 12, 100);
    ($this->price)($a, 3, 100, 'holofoil');

    $cards = PublicCollection::for($this->user)->cardsQuery()->get();

    expect((new CollectionValueSeries(new Valuation(new CardPriceResolver)))->earliestDay($cards)?->toDateString())->toBe(today()->subDays(12)->toDateString());
});

test('a row exactly 30 days before a sampled day prices it and one 31 days before does not', function () {
    $a = ($this->own)('001');
    $b = ($this->own)('002');
    ($this->price)($a, 40, 100);
    ($this->price)($b, 41, 7777);

    $series = ($this->series)(ChartRange::OneMonth);
    $at = fn (int $ago) => $series->first(fn ($p) => $p['date']->isSameDay(today()->subDays($ago)))['minor'];

    // Day -11: a's row is 29 days old and b's 30: both inside. Day -10: b's is 31 days old, out.
    expect($at(11))->toBe(7877)
        ->and($at(10))->toBe(100)
        ->and($at(9))->toBe(0);
});

test('every point of a mixed collection matches pricing the full window the slow way', function () {
    ($this->seedMixed)();

    $cards = PublicCollection::for($this->user)->cardsQuery()->get();
    $all = CardPriceSnapshot::query()->orderBy('id')->get()->groupBy('card_id');
    $series = ($this->series)(ChartRange::ThreeMonths);
    $valuation = new Valuation(new CardPriceResolver);

    expect($series->count())->toBeGreaterThan(30);

    foreach ($series as $point) {
        $day = $point['date'];
        $floor = $day->subDays(CardPriceSnapshot::RECENT_DAYS)->toDateString();
        $dayCards = $cards->map(fn ($card) => (clone $card)->setRelation('priceSnapshots', $all->get($card->id, collect())
            ->filter(fn ($r) => $r->captured_on->toDateString() <= $day->toDateString()
                && ($r->captured_on->toDateString() >= $floor || $r->source === 'manual'))
            ->values()));

        expect($point['minor'])->toBe($valuation->totalsByCurrencyOver($dayCards, [$day])[$day->toDateString()]['USD'] ?? 0, $day->toDateString());
    }
});

test('no query binds a value per card or per row, so a large collection stays under the parameter limit', function () {
    foreach (range(1, 12) as $n) {
        $card = ($this->own)(sprintf('%03d', $n));
        foreach (range(0, 40) as $ago) {
            ($this->price)($card, $ago, 100 + $ago);
        }
    }

    $cards = PublicCollection::for($this->user)->cardsQuery()->get();
    $max = 0;
    DB::listen(function ($query) use (&$max) {
        $max = max($max, count($query->bindings));
    });

    $series = (new CollectionValueSeries(new Valuation(new CardPriceResolver)))->for($cards, 'USD', ChartRange::OneMonth);

    expect($series)->toHaveCount(31)
        ->and($max)->toBeLessThanOrEqual(8);
});

test('two days of history chart on the longest range, from the earliest day', function () {
    $a = ($this->own)('001');
    ($this->price)($a, 2, 100);
    ($this->price)($a, 0, 150);

    $series = ($this->series)(ChartRange::OneYear);

    expect($series->pluck('date')->map->toDateString()->all())->toBe([today()->subDays(2)->toDateString(), today()->toDateString()])
        ->and($series->pluck('minor')->all())->toBe([100, 150]);
});

test('unpriced rows do not move the earliest day', function () {
    $a = ($this->own)('001');
    ($this->price)($a, 20, null);
    ($this->price)($a, 12, 100);

    $cards = PublicCollection::for($this->user)->cardsQuery()->get();

    expect((new CollectionValueSeries(new Valuation(new CardPriceResolver)))->earliestDay($cards)?->toDateString())->toBe(today()->subDays(12)->toDateString());
});

test('a second identical call is served from the cache without rebuilding the series', function () {
    $a = ($this->own)('001');
    foreach ([40, 10, 0] as $ago) {
        ($this->price)($a, $ago, 100 + $ago);
    }

    $cards = PublicCollection::for($this->user)->cardsQuery()->get();
    $service = new CollectionValueSeries(new Valuation(new CardPriceResolver));
    $first = $service->for($cards, 'USD', ChartRange::ThreeMonths);

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });
    $second = $service->for($cards, 'USD', ChartRange::ThreeMonths);

    expect($second->map(fn ($p) => [$p['date']->toDateString(), $p['minor']])->all())->toBe($first->map(fn ($p) => [$p['date']->toDateString(), $p['minor']])->all())
        ->and($second->first()['date'])->toBeInstanceOf(CarbonImmutable::class)
        // The key is the owned prints the caller loaded plus the epoch: no query at all.
        ->and($queries)->toBe([]);
});

test('an edited copy, a deleted copy and another range each give a fresh series', function () {
    $a = ($this->own)('001');
    $b = ($this->own)('002');
    foreach ([40, 10, 0] as $ago) {
        ($this->price)($a, $ago, 100);
        ($this->price)($b, $ago, 10);
    }
    $last = fn (ChartRange $r = ChartRange::OneMonth) => ($this->series)($r)->last()['minor'];

    expect($last())->toBe(110);

    ($this->price)($a, 0, 500, 'holofoil'); // an unowned print: nothing changes
    expect($last())->toBe(110);

    $item = CollectionItem::where('card_id', $a->id)->first();
    $item->update(['quantity' => 3]);
    expect($last())->toBe(310);

    CollectionItem::where('card_id', $b->id)->delete();
    expect($last())->toBe(300)
        ->and(($this->series)(ChartRange::OneMonth))->toHaveCount(31)
        ->and(($this->series)(ChartRange::ThreeMonths)->count())->toBeLessThan(31);
});

/** Runs $work and says how many LATERAL queries (the series' per-day price reads) it issued. */
function lateralQueriesIn(Closure $work): int
{
    $lateral = 0;
    DB::listen(function ($query) use (&$lateral) {
        $lateral += str_contains(strtolower($query->sql), 'lateral') ? 1 : 0;
    });
    $work();

    return $lateral;
}

test('a price write dated today does not rebuild the cached series', function () {
    $a = ($this->own)('001');
    foreach ([200, 40, 10, 0] as $ago) {
        ($this->price)($a, $ago, 100 + $ago);
    }
    ($this->series)(ChartRange::OneMonth);

    ($this->price)($a, 0, 999, 'holofoil');
    CardPriceSnapshot::query()->where('card_id', $a->id)->where('captured_on', today())->where('variant', 'normal')->update(['market_minor' => 555]);

    expect(lateralQueriesIn(fn () => ($this->series)(ChartRange::OneMonth)))->toBe(0);
});

test('a backdated price fix is picked up after prices:bump-version, not before', function () {
    $a = ($this->own)('001');
    foreach ([200, 40, 10, 0] as $ago) {
        ($this->price)($a, $ago, 100 + $ago);
    }
    ($this->series)(ChartRange::OneMonth);

    CardPriceSnapshot::query()->where('card_id', $a->id)->where('captured_on', today()->subDays(10))->update(['market_minor' => 1]);
    expect(lateralQueriesIn(fn () => ($this->series)(ChartRange::OneMonth)))->toBe(0);

    $this->artisan('prices:bump-version')->assertSuccessful();

    expect(lateralQueriesIn(fn () => ($this->series)(ChartRange::OneMonth)))->toBeGreaterThan(0);
});

test('a manual price corrected by hand is picked up after prices:bump-version, not before', function () {
    $a = ($this->own)('001');
    foreach ([40, 0] as $ago) {
        ($this->price)($a, $ago, 100);
    }
    ($this->price)($a, 300, 555, 'normal', 'manual');
    ($this->series)(ChartRange::OneMonth);

    CardPriceSnapshot::query()->where('card_id', $a->id)->where('source', 'manual')->update(['market_minor' => 600, 'updated_at' => now()->addMinute()]);
    expect(lateralQueriesIn(fn () => ($this->series)(ChartRange::OneMonth)))->toBe(0);

    $this->artisan('prices:bump-version')->assertSuccessful();

    expect(lateralQueriesIn(fn () => ($this->series)(ChartRange::OneMonth)))->toBeGreaterThan(0);
});

test('a series with no priced day yet is not cached, so the next render builds it', function () {
    $a = ($this->own)('001');

    expect(($this->series)(ChartRange::OneMonth))->toBeEmpty();

    foreach ([40, 0] as $ago) {
        ($this->price)($a, $ago, 100);
    }

    expect(($this->series)(ChartRange::OneMonth))->toHaveCount(31);
});

test('the fingerprint ignores everything the series does not read', function (Closure $edit) {
    $a = ($this->own)('001', 'normal', 2);
    foreach ([40, 0] as $ago) {
        ($this->price)($a, $ago, 100);
    }
    ($this->series)(ChartRange::OneMonth);

    $edit->call($this, $a, CollectionItem::where('card_id', $a->id)->first());

    expect(lateralQueriesIn(fn () => ($this->series)(ChartRange::OneMonth)))->toBe(0);
})->with([
    'notes' => [fn (Card $a, CollectionItem $i) => $i->update(['notes' => 'sleeved'])],
    'photo' => [fn (Card $a, CollectionItem $i) => $i->update(['photo_path' => 'x/y.jpg'])],
    'condition' => [fn (Card $a, CollectionItem $i) => $i->update(['condition' => 'LP'])],
    'grade' => [fn (Card $a, CollectionItem $i) => $i->update(['grade_company' => 'PSA', 'grade_value' => '10'])],
    'updated_at' => [fn (Card $a, CollectionItem $i) => $i->forceFill(['updated_at' => now()->addHour()])->save()],
    'a new copy id for the same print' => [function (Card $a, CollectionItem $i) {
        $attrs = $i->only(['collection_id', 'card_id', 'card_tcgdex_id', 'variant', 'condition', 'quantity']);
        $i->delete();
        CollectionItem::create($attrs);
    }],
    'one copy of 2 split into two copies of 1' => [function (Card $a, CollectionItem $i) {
        $attrs = $i->only(['collection_id', 'card_id', 'card_tcgdex_id', 'variant']);
        $i->delete();
        CollectionItem::create($attrs + ['condition' => 'NM', 'quantity' => 1]);
        CollectionItem::create($attrs + ['condition' => 'LP', 'quantity' => 1]);
    }],
]);

test('a copy with quantity 0 counts as one, as the valuation does', function () {
    $a = ($this->own)('001', 'normal', 0);
    foreach ([40, 0] as $ago) {
        ($this->price)($a, $ago, 100);
    }
    ($this->series)(ChartRange::OneMonth);

    CollectionItem::where('card_id', $a->id)->update(['quantity' => 1]);

    expect(lateralQueriesIn(fn () => ($this->series)(ChartRange::OneMonth)))->toBe(0);
});

test('the fingerprint changes with what the valuation reads', function (Closure $edit) {
    $a = ($this->own)('001', 'normal', 2);
    $b = ($this->own)('002', 'normal');
    foreach ([$a, $b] as $card) {
        foreach ([40, 0] as $ago) {
            ($this->price)($card, $ago, 100);
        }
    }
    ($this->series)(ChartRange::OneMonth);

    $edit->call($this, $a, $b);

    expect(lateralQueriesIn(fn () => ($this->series)(ChartRange::OneMonth)))->toBeGreaterThan(0);
})->with([
    'variant' => [fn (Card $a, Card $b) => CollectionItem::where('card_id', $a->id)->update(['variant' => 'holofoil'])],
    'quantity' => [fn (Card $a, Card $b) => CollectionItem::where('card_id', $a->id)->update(['quantity' => 3])],
    'a removed print' => [fn (Card $a, Card $b) => CollectionItem::where('card_id', $b->id)->delete()],
    'an added print' => [fn (Card $a, Card $b) => ($this->own)('003')],
    'a second copy of a print in another variant' => [fn (Card $a, Card $b) => CollectionItem::create(['collection_id' => $this->collection->id, 'card_id' => $a->id, 'card_tcgdex_id' => $a->tcgdex_id, 'variant' => 'holofoil', 'condition' => 'NM', 'quantity' => 1])],
    'a second copy of the same variant' => [fn (Card $a, Card $b) => CollectionItem::create(['collection_id' => $this->collection->id, 'card_id' => $a->id, 'card_tcgdex_id' => $a->tcgdex_id, 'variant' => 'normal', 'condition' => 'LP', 'quantity' => 1])],
]);

test('a NULL variant is kept apart from an empty one and from normal', function (?string $from, ?string $to) {
    $a = ($this->own)('001', $from);
    foreach ([40, 0] as $ago) {
        ($this->price)($a, $ago, 100);
    }
    ($this->series)(ChartRange::OneMonth);

    CollectionItem::where('card_id', $a->id)->update(['variant' => $to]);

    expect(lateralQueriesIn(fn () => ($this->series)(ChartRange::OneMonth)))->toBeGreaterThan(0);
})->with([
    'null to normal' => [null, 'normal'],
    'null to empty' => [null, ''],
    'empty to normal' => ['', 'normal'],
    'normal to null' => ['normal', null],
]);

test('the public entry point returns what an anonymous visitor sees: cards, totals, primary currency, series and earliest day', function () {
    $a = ($this->own)('001', 'normal', 2);
    foreach ([40, 10, 0] as $ago) {
        ($this->price)($a, $ago, 100 + $ago);
    }
    $service = new CollectionValueSeries(new Valuation(new CardPriceResolver));

    $result = $service->forPublicCollection(PublicCollection::for($this->user), ChartRange::ThreeMonths);

    expect($result['cards']->pluck('id')->all())->toBe([$a->id])
        ->and($result['totals'])->toBe(['USD' => 200])
        ->and($result['currency'])->toBe('USD')
        ->and($result['series']->last()['minor'])->toBe(200)
        ->and($result['earliest']?->toDateString())->toBe(today()->subDays(40)->toDateString())
        ->and($result['cached'])->toBeFalse();

    expect($service->forPublicCollection(PublicCollection::for($this->user), ChartRange::ThreeMonths)['cached'])->toBeTrue();
});

test('today\'s point follows the headline after a price write today, without rebuilding past points', function () {
    $a = ($this->own)('001', 'normal', 2);
    foreach ([40, 10, 0] as $ago) {
        ($this->price)($a, $ago, 100 + $ago);
    }
    $service = new CollectionValueSeries(new Valuation(new CardPriceResolver));
    $first = $service->forPublicCollection(PublicCollection::for($this->user), ChartRange::OneMonth);

    CardPriceSnapshot::query()->where('card_id', $a->id)->where('captured_on', today())->update(['market_minor' => 555]);

    $lateral = 0;
    DB::listen(function ($query) use (&$lateral) {
        $lateral += str_contains(strtolower($query->sql), 'lateral') ? 1 : 0;
    });
    $second = $service->forPublicCollection(PublicCollection::for($this->user), ChartRange::OneMonth);

    expect($lateral)->toBe(0)
        ->and($second['cached'])->toBeTrue()
        ->and($first['series']->last()['minor'])->toBe(200)
        ->and($second['totals'])->toBe(['USD' => 1110])
        ->and($second['series']->last()['minor'])->toBe(1110)
        ->and($second['series']->last()['date']->toDateString())->toBe(today()->toDateString())
        ->and($second['series']->slice(0, -1)->values()->all())->toEqual($first['series']->slice(0, -1)->values()->all())
        // The stored series is untouched.
        ->and(($this->series)(ChartRange::OneMonth)->last()['minor'])->toBe(200);
});

test('a public series too short to chart stays empty when today follows the headline', function () {
    $a = ($this->own)('001');
    ($this->price)($a, 0, 100);

    $result = (new CollectionValueSeries(new Valuation(new CardPriceResolver)))->forPublicCollection(PublicCollection::for($this->user), ChartRange::OneMonth);

    expect($result['series'])->toBeEmpty();
});

test('a page view that has to build the series logs a warning with the user, range and duration, and a hit logs nothing', function () {
    $a = ($this->own)('001');
    foreach ([40, 10, 0] as $ago) {
        ($this->price)($a, $ago, 100);
    }
    $service = new CollectionValueSeries(new Valuation(new CardPriceResolver));
    Log::spy();

    $service->forPublicCollection(PublicCollection::for($this->user), ChartRange::OneMonth);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => $message === 'activity chart built on a page view'
        && $context['user_id'] === $this->user->id
        && $context['range'] === ChartRange::OneMonth->value
        && $context['owner'] === false
        && is_int($context['ms']) && $context['ms'] >= 0)->once();

    // The hit adds no second warning to the one the build logged.
    $service->forPublicCollection(PublicCollection::for($this->user), ChartRange::OneMonth);

    Log::shouldHaveReceived('warning')->once();

    // The owner looking at their own page is told apart from a visitor.
    $this->actingAs($this->user);
    $service->forPublicCollection(PublicCollection::for($this->user), ChartRange::ThreeMonths);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => ($context['range'] ?? null) === ChartRange::ThreeMonths->value
        && $context['owner'] === true)->once();
});

test('the public entry point sees every public collection of the user and never a private one', function () {
    $a = ($this->own)('001');
    ($this->price)($a, 0, 100);
    ($this->price)($a, 10, 100);

    $second = Collection::factory()->for($this->user)->create(['is_public' => true, 'slug' => 'second']);
    $b = Card::create(['tcgdex_id' => 'me05-002', 'set_id' => $this->set->id, 'local_id' => '002', 'name' => 'Card 002']);
    CollectionItem::create(['collection_id' => $second->id, 'card_id' => $b->id, 'card_tcgdex_id' => $b->tcgdex_id, 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 1]);
    ($this->price)($b, 0, 50);
    ($this->price)($b, 10, 50);

    $hidden = Collection::factory()->for($this->user)->create(['is_public' => false, 'slug' => 'hidden']);
    $c = Card::create(['tcgdex_id' => 'me05-003', 'set_id' => $this->set->id, 'local_id' => '003', 'name' => 'Card 003']);
    CollectionItem::create(['collection_id' => $hidden->id, 'card_id' => $c->id, 'card_tcgdex_id' => $c->tcgdex_id, 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 1]);
    ($this->price)($c, 0, 9999);

    $result = (new CollectionValueSeries(new Valuation(new CardPriceResolver)))->forPublicCollection(PublicCollection::for($this->user), ChartRange::OneMonth);

    expect($result['totals'])->toBe(['USD' => 150])
        ->and($result['series']->last()['minor'])->toBe(150);
});

test('the public entry point has no currency and no series for a user with nothing public', function () {
    Collection::query()->update(['is_public' => false]);

    $result = (new CollectionValueSeries(new Valuation(new CardPriceResolver)))->forPublicCollection(PublicCollection::for($this->user), ChartRange::OneMonth);

    expect($result['cards'])->toBeEmpty()
        ->and($result['currency'])->toBeNull()
        ->and($result['series'])->toBeEmpty()
        ->and($result['earliest'])->toBeNull();
});

test('warming sums the totals over card chunks, so its primary currency and keys are the page\'s', function () {
    $service = new CollectionValueSeries(new Valuation(new CardPriceResolver));
    $price = $this->price;
    // Valuation::order() puts USD first and otherwise keeps the order currencies first appear in, which
    // follows the cards' load order; the page and the warm-up must agree on it across chunks of cards.
    $first = ($this->own)('000');
    foreach ([40, 0] as $ago) {
        $price($first, $ago, 100, 'normal', 'cardmarket', 'EUR');
    }
    for ($i = 1; $i < CollectionValueSeries::WARM_CHUNK + 5; $i++) {
        $card = ($this->own)(sprintf('%03d', $i));
        foreach ([40, 0] as $ago) {
            $price($card, $ago, 50000, 'normal', 'cardmarket', 'GBP');
        }
    }

    $result = $service->warm(PublicCollection::for($this->user), ChartRange::cases());
    $page = $service->forPublicCollection(PublicCollection::for($this->user), ChartRange::OneYear);

    expect($result)->toBe(['computed' => 4, 'warm' => 0])
        ->and($page['cached'])->toBeTrue()
        ->and($service->warm(PublicCollection::for($this->user), ChartRange::cases()))->toBe(['computed' => 0, 'warm' => 4]);
});
