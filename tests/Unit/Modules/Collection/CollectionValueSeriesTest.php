<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Services\CardPriceResolver;
use App\Modules\Catalog\Support\ChartRange;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use App\Modules\Collection\Services\CollectionValueSeries;
use App\Modules\Collection\Services\PublicCollection;
use App\Modules\Collection\Services\Valuation;

beforeEach(function () {
    $this->user = User::factory()->create(['username' => 'carlos']);
    $this->collection = Collection::factory()->for($this->user)->create(['is_public' => true, 'slug' => 'main']);
    $this->set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $this->own = function (string $localId, string $variant = 'normal', int $quantity = 1): Card {
        $card = Card::firstOrCreate(['tcgdex_id' => "me05-{$localId}"], ['set_id' => $this->set->id, 'local_id' => $localId, 'name' => "Card {$localId}"]);
        CollectionItem::create(['collection_id' => $this->collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => $card->tcgdex_id, 'variant' => $variant, 'condition' => 'NM', 'quantity' => $quantity]);

        return $card;
    };
    $this->price = fn (Card $card, int $daysAgo, ?int $minor, string $variant = 'normal', string $source = 'tcgplayer', string $currency = 'USD') => CardPriceSnapshot::create(['card_id' => $card->id, 'source' => $source, 'variant' => $variant, 'captured_on' => today()->subDays($daysAgo), 'currency' => $currency, 'market_minor' => $minor]);
    $this->series = function (ChartRange $range) {
        $cards = PublicCollection::for($this->user)->cardsQuery()->get();

        return (new CollectionValueSeries(new Valuation(new CardPriceResolver)))->for($cards, 'USD', $range);
    };
    $this->headline = fn () => (new Valuation(new CardPriceResolver))->totalsByCurrency(PublicCollection::for($this->user)->cardsQuery()->get())['USD'] ?? 0;
});

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

    $series = ($this->series)($range);

    expect($series->last()['date']->toDateString())->toBe(today()->toDateString())
        ->and($series->last()['minor'])->toBe(($this->headline)());
})->with(fn () => ChartRange::cases());

test('long ranges sample every 3rd or 7th day back from today', function () {
    $a = ($this->own)('001');
    ($this->price)($a, 400, 100);

    $dates = fn (ChartRange $r) => ($this->series)($r)->pluck('date')->map->toDateString()->all();

    expect($dates(ChartRange::ThreeMonths))->toBe(array_map(fn ($d) => $d->toDateString(), ChartRange::ThreeMonths->sampleDays(today(), today()->subDays(400))))
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
    $a = ($this->own)('001', 'normal', 2);
    $b = ($this->own)('002', 'reverse-holofoil');
    $c = ($this->own)('003');
    $d = ($this->own)('004');
    $e = ($this->own)('005');

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
