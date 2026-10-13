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
    ($this->price)($a, 200, 1, 'normal', 'cardmarket', 'EUR'); // gives the collection an earlier first day

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
