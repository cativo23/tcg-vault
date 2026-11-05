<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Services\CardPriceResolver;
use App\Modules\Catalog\Services\PriceDataVersion;
use App\Modules\Catalog\Services\WeekBaselines;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

function baselineCard(string $id = 'me05-116'): Card
{
    $set = Set::firstOrCreate(['tcgdex_id' => 'me05'], ['name' => 'Pitch Black']);

    return Card::create(['tcgdex_id' => $id, 'set_id' => $set->id, 'local_id' => substr($id, 5), 'name' => "Card {$id}"]);
}

function baselinePrice(Card $card, int $daysAgo, ?int $minor, string $variant = 'normal', string $currency = 'USD', string $source = 'tcgplayer', string $origin = 'tcgdex'): CardPriceSnapshot
{
    return CardPriceSnapshot::create(['card_id' => $card->id, 'source' => $source, 'origin' => $origin, 'variant' => $variant, 'captured_on' => today()->subDays($daysAgo), 'currency' => $currency, 'market_minor' => $minor]);
}

/** The stored baselines as "source|variant|currency => anchor days ago, baseline days ago, origin, minor". */
function storedBaselines(Card $card): array
{
    return DB::table('card_price_week_baselines')->where('card_id', $card->id)->orderBy('source')->orderBy('variant')->orderBy('currency')->get()
        ->mapWithKeys(fn (object $r) => ["{$r->source}|{$r->variant}|{$r->currency}" => [
            (int) today()->diffInDays($r->anchor_on, true), (int) today()->diffInDays($r->baseline_on, true), $r->baseline_origin, (int) $r->baseline_minor,
        ]])->all();
}

test('a print\'s baseline is its newest priced row 7 to 14 days before its newest priced day', function () {
    $card = baselineCard();
    foreach ([0 => 1240, 1 => 1230, 6 => 1150, 7 => null, 8 => 1000, 9 => 900, 14 => 800, 15 => 700] as $days => $minor) {
        baselinePrice($card, $days, $minor);
    }

    app(WeekBaselines::class)->refreshCards([$card->id]);

    // Day 7 is unpriced, so day 8 is the newest priced row in [anchor - 14, anchor - 7].
    expect(storedBaselines($card))->toBe(['tcgplayer|normal|USD' => [0, 8, 'tcgdex', 1000]]);
});

test('the anchor is the newest priced day, so an unpriced newest row moves nothing', function () {
    $card = baselineCard();
    baselinePrice($card, 0, null);
    baselinePrice($card, 2, 500);
    baselinePrice($card, 9, 450);
    baselinePrice($card, 16, 400);

    app(WeekBaselines::class)->refreshCards([$card->id]);

    expect(storedBaselines($card))->toBe(['tcgplayer|normal|USD' => [2, 9, 'tcgdex', 450]]);
});

test('source, variant and currency are separate prints, a tcgcsv row counts, and a manual price gets none', function () {
    $card = baselineCard();
    baselinePrice($card, 0, 1000);
    baselinePrice($card, 7, 900, origin: 'tcgcsv');
    baselinePrice($card, 1, 95, currency: 'EUR');
    baselinePrice($card, 9, 90, currency: 'EUR');
    baselinePrice($card, 0, 3000, variant: 'holofoil');
    baselinePrice($card, 0, 80, variant: 'default', currency: 'EUR', source: 'cardmarket');
    baselinePrice($card, 10, 70, variant: 'default', currency: 'EUR', source: 'cardmarket');
    baselinePrice($card, 0, 500, source: 'manual', origin: 'hand', variant: 'reverse-holofoil');
    baselinePrice($card, 8, 400, source: 'manual', origin: 'hand', variant: 'reverse-holofoil');

    app(WeekBaselines::class)->refreshCards([$card->id]);

    // Same-day rows on one (card, source, variant) are impossible (unique index), hence days 0 and 1 for the two currencies.
    expect(storedBaselines($card))->toBe([
        'cardmarket|default|EUR' => [0, 10, 'tcgdex', 70],
        'tcgplayer|normal|EUR' => [1, 9, 'tcgdex', 90],
        'tcgplayer|normal|USD' => [0, 7, 'tcgcsv', 900],
    ]);
});

test('a print with no priced row in reach, or wholly before the window, has no baseline', function () {
    $short = baselineCard('me05-001');
    baselinePrice($short, 0, 500);
    baselinePrice($short, 3, 480);
    baselinePrice($short, 15, 300);
    $old = baselineCard('me05-002');
    baselinePrice($old, CardPriceSnapshot::RECENT_DAYS + 1, 500);
    baselinePrice($old, CardPriceSnapshot::RECENT_DAYS + 8, 450);

    app(WeekBaselines::class)->refreshAll();

    expect(storedBaselines($short))->toBe([])
        ->and(storedBaselines($old))->toBe([]);
});

test('a refresh drops a baseline the print no longer has and leaves other cards alone', function () {
    $card = baselineCard('me05-001');
    $other = baselineCard('me05-002');
    baselinePrice($card, 1, 500);
    baselinePrice($card, 8, 450);
    baselinePrice($other, 0, 900);
    baselinePrice($other, 7, 800);
    app(WeekBaselines::class)->refreshAll();

    // Today's price moves the reach to days 7-14; deleting day 8 leaves nothing priced there.
    baselinePrice($card, 0, 520);
    CardPriceSnapshot::where('card_id', $card->id)->whereDate('captured_on', today()->subDays(8))->delete();
    DB::table('card_price_week_baselines')->where('card_id', $other->id)->update(['baseline_minor' => 1]);

    app(WeekBaselines::class)->refreshCards([$card->id]);

    expect(storedBaselines($card))->toBe([])
        ->and(storedBaselines($other))->toBe(['tcgplayer|normal|USD' => [0, 7, 'tcgdex', 1]]);
});

test('a full refresh is idempotent and returns how many prints have a baseline', function () {
    $card = baselineCard();
    baselinePrice($card, 0, 1000);
    baselinePrice($card, 7, 900);
    baselinePrice($card, 0, 3000, variant: 'holofoil');
    baselinePrice($card, 12, 2800, variant: 'holofoil');

    $first = app(WeekBaselines::class)->refreshAll();
    $rows = storedBaselines($card);
    $second = app(WeekBaselines::class)->refreshAll();

    expect([$first, $second])->toBe([2, 2])
        ->and(storedBaselines($card))->toBe($rows);
});

// The writer does not bump the version itself: its insert reads card_price_snapshots, and BumpPriceDataVersion counts any
// non-read statement naming that table (even one inserting no rows), so every refresh moves the version after commit.
test('a refresh moves the price-data version, so cached price rows are read again', function () {
    $card = baselineCard();
    baselinePrice($card, 0, 1000);
    baselinePrice($card, 7, 900);
    $before = app(PriceDataVersion::class)->current();

    app(WeekBaselines::class)->refreshCards([$card->id]);

    expect(app(PriceDataVersion::class)->current())->not->toBe($before);
});

test('the oldest usable baseline is exactly WEEK_DAYS + WEEK_SLACK_DAYS back, the newest exactly WEEK_DAYS back', function () {
    $far = baselineCard('me05-001');
    baselinePrice($far, 0, 500);
    baselinePrice($far, CardPriceResolver::WEEK_DAYS + CardPriceResolver::WEEK_SLACK_DAYS, 400);
    $tooFar = baselineCard('me05-002');
    baselinePrice($tooFar, 0, 500);
    baselinePrice($tooFar, CardPriceResolver::WEEK_DAYS + CardPriceResolver::WEEK_SLACK_DAYS + 1, 400);
    $near = baselineCard('me05-003');
    baselinePrice($near, 0, 500);
    baselinePrice($near, CardPriceResolver::WEEK_DAYS, 450);
    $tooNear = baselineCard('me05-004');
    baselinePrice($tooNear, 0, 500);
    baselinePrice($tooNear, CardPriceResolver::WEEK_DAYS - 1, 450);

    app(WeekBaselines::class)->refreshAll();

    expect(storedBaselines($far))->toBe(['tcgplayer|normal|USD' => [0, 14, 'tcgdex', 400]])
        ->and(storedBaselines($tooFar))->toBe([])
        ->and(storedBaselines($near))->toBe(['tcgplayer|normal|USD' => [0, 7, 'tcgdex', 450]])
        ->and(storedBaselines($tooNear))->toBe([]);
});

test('a refresh of several cards at once rebuilds each of them', function () {
    $a = baselineCard('me05-001');
    $b = baselineCard('me05-002');
    foreach ([$a, $b] as $card) {
        baselinePrice($card, 0, 500);
        baselinePrice($card, 8, 450);
    }

    $written = app(WeekBaselines::class)->refreshCards([$b->id, $a->id, $b->id]);

    expect($written)->toBe(2)
        ->and(storedBaselines($a))->toBe(['tcgplayer|normal|USD' => [0, 8, 'tcgdex', 450]])
        ->and(storedBaselines($b))->toBe(['tcgplayer|normal|USD' => [0, 8, 'tcgdex', 450]]);
});

test('no card ids writes nothing and runs no query', function () {
    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    expect(app(WeekBaselines::class)->refreshCards([]))->toBe(0)
        ->and($queries)->toBe(0);
});

test('a refresh of more cards than one chunk runs one transaction per chunk and rebuilds every card', function () {
    $set = Set::firstOrCreate(['tcgdex_id' => 'me05'], ['name' => 'Pitch Black']);
    $now = now();
    DB::table('cards')->insert(collect(range(1, 450))->map(fn (int $i) => [
        'tcgdex_id' => "me05-$i", 'set_id' => $set->id, 'local_id' => (string) $i, 'name' => "Card $i", 'created_at' => $now, 'updated_at' => $now,
    ])->all());
    $ids = Card::pluck('id')->all();
    DB::table('card_price_snapshots')->insert(collect($ids)->flatMap(fn (int $id) => [
        ['card_id' => $id, 'source' => 'tcgplayer', 'origin' => 'tcgdex', 'variant' => 'normal', 'captured_on' => today()->toDateString(), 'currency' => 'USD', 'market_minor' => 500, 'created_at' => $now, 'updated_at' => $now],
        ['card_id' => $id, 'source' => 'tcgplayer', 'origin' => 'tcgdex', 'variant' => 'normal', 'captured_on' => today()->subDays(8)->toDateString(), 'currency' => 'USD', 'market_minor' => 450, 'created_at' => $now, 'updated_at' => $now],
    ])->all());

    $transactions = 0;
    Event::listen(TransactionBeginning::class, function () use (&$transactions) {
        $transactions++;
    });

    $written = app(WeekBaselines::class)->refreshCards($ids);

    // 450 cards is three chunks of 200 (200, 200, 50): each takes its own locks and releases them on commit.
    expect($written)->toBe(450)
        ->and(DB::table('card_price_week_baselines')->count())->toBe(450)
        ->and($transactions)->toBe(3);
});
