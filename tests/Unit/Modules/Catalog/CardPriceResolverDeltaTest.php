<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Services\CardPriceResolver;
use Illuminate\Support\Facades\DB;

function deltaCard(): Card
{
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);

    return Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
}

test('delta is the change between the two most recent snapshot days of the same source and variant', function () {
    $card = deltaCard();
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDay(), 'currency' => 'USD', 'market_minor' => 1000]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 1240]);

    $delta = (new CardPriceResolver)->resolveDelta($card->fresh(['priceSnapshots']));

    expect($delta)->not->toBeNull()
        ->and($delta->deltaMinor)->toBe(240)
        ->and($delta->latest->market_minor)->toBe(1240)
        ->and($delta->isUp())->toBeTrue();
});

test('deltaFor computes the move for a SPECIFIC snapshot (e.g. the headline variant), not whatever resolve() would pick', function () {
    $card = deltaCard();
    // The card-level chain would resolve to 'normal' (tcgplayer's
    // preferred variant) — but the collector's copy is reverse-holofoil,
    // and that's the trend that should show next to its own price.
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 11]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'reverse-holofoil', 'captured_on' => today()->subDay(), 'currency' => 'USD', 'market_minor' => 4000]);
    $reverseHoloToday = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'reverse-holofoil', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 4500]);

    $card->load('priceSnapshots');
    $resolver = new CardPriceResolver;

    $delta = $resolver->deltaFor($card, $reverseHoloToday);

    expect($delta)->not->toBeNull()
        ->and($delta->deltaMinor)->toBe(500)
        ->and($delta->isUp())->toBeTrue();
});

test('deltaFor returns null for a null snapshot (nothing to compare)', function () {
    $card = deltaCard();

    expect((new CardPriceResolver)->deltaFor($card, null))->toBeNull();
});

test('resolveDelta is deltaFor applied to resolve()\'s own pick, unchanged', function () {
    $card = deltaCard();
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDay(), 'currency' => 'USD', 'market_minor' => 1000]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 1240]);
    $card->load('priceSnapshots');
    $resolver = new CardPriceResolver;

    expect($resolver->resolveDelta($card)->deltaMinor)->toBe($resolver->deltaFor($card, $resolver->resolve($card))->deltaMinor);
});

test('delta is null with a single snapshot day', function () {
    $card = deltaCard();
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 1240]);

    expect((new CardPriceResolver)->resolveDelta($card->fresh(['priceSnapshots'])))->toBeNull();
});

test('delta refuses to compare across a source, variant, or currency mismatch', function () {
    $card = deltaCard();
    // yesterday only cardmarket/EUR existed; today tcgplayer/USD wins the priority chain
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today()->subDay(), 'currency' => 'EUR', 'market_minor' => 900]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 1240]);

    expect((new CardPriceResolver)->resolveDelta($card->fresh(['priceSnapshots'])))->toBeNull();
});

test('historyIn returns the resolved source/variant series oldest first, one point per day', function () {
    $card = deltaCard();
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDays(2), 'currency' => 'USD', 'market_minor' => 800]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDay(), 'currency' => 'USD', 'market_minor' => 1000]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 1240]);
    // a different variant must not pollute the series
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'reverse-holofoil', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 5]);

    $resolver = new CardPriceResolver;
    $history = $resolver->historyIn($resolver->resolve($card->fresh(['priceSnapshots'])), today()->subDays(30));

    expect($history->pluck('market_minor')->all())->toBe([800, 1000, 1240]);
});

test('delta never self-compares when the newest day only has a lower-priority source', function () {
    $card = deltaCard();
    // yesterday: tcgplayer (preferred); today: cardmarket only → resolve() still lands on yesterday's row
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDay(), 'currency' => 'USD', 'market_minor' => 1000]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 900]);

    expect((new CardPriceResolver)->resolveDelta($card->fresh(['priceSnapshots'])))->toBeNull();
});

function deltaCard2(): Card
{
    $set = Set::firstOrCreate(['tcgdex_id' => 'me05'], ['name' => 'Pitch Black']);

    return Card::create(['tcgdex_id' => 'me05-117', 'set_id' => $set->id, 'local_id' => '117', 'name' => 'Mega Darkrai ex 2']);
}

function weekPrice(Card $card, int $daysAgo, ?int $minor, string $variant = 'normal', string $currency = 'USD', string $source = 'tcgplayer'): CardPriceSnapshot
{
    return CardPriceSnapshot::create(['card_id' => $card->id, 'source' => $source, 'variant' => $variant, 'captured_on' => today()->subDays($daysAgo), 'currency' => $currency, 'market_minor' => $minor]);
}

test('the week move compares the latest price with the one seven days earlier on the same print', function () {
    $card = deltaCard();
    foreach ([0 => 1240, 1 => 1230, 3 => 1100, 7 => 1000, 9 => 900] as $days => $minor) {
        weekPrice($card, $days, $minor);
    }
    $card->load('priceSnapshots');
    $resolver = new CardPriceResolver;

    $week = $resolver->weekDeltaFor($card, $resolver->resolve($card));

    expect($week)->not->toBeNull()
        ->and($week->deltaMinor)->toBe(240)
        ->and($week->previous->market_minor)->toBe(1000)
        ->and($week->isUp())->toBeTrue()
        ->and($resolver->resolveDelta($card)->deltaMinor)->toBe(10); // the previous-day delta is untouched
});

test('a gap on the exact day falls back to the newest earlier price, up to a further week', function () {
    $card = deltaCard();
    weekPrice($card, 0, 500);
    weekPrice($card, 8, 400);
    weekPrice($card, 14, 350);
    $card->load('priceSnapshots');
    $resolver = new CardPriceResolver;

    expect($resolver->weekDeltaFor($card, $resolver->resolve($card))->previous->market_minor)->toBe(400);

    $onlyOld = deltaCard2();
    weekPrice($onlyOld, 0, 500);
    weekPrice($onlyOld, 15, 300); // 15 days: older than 7 + 7
    $onlyOld->load('priceSnapshots');

    expect($resolver->weekDeltaFor($onlyOld, $resolver->resolve($onlyOld)))->toBeNull();
});

test('a card priced for less than a week has no week move', function () {
    $card = deltaCard();
    weekPrice($card, 0, 500);
    weekPrice($card, 3, 480);
    $card->load('priceSnapshots');
    $resolver = new CardPriceResolver;

    expect($resolver->weekDeltaFor($card, $resolver->resolve($card)))->toBeNull();
});

test('the week move never compares across currency or variant, and skips an unpriced day', function () {
    $card = deltaCard();
    weekPrice($card, 0, 1000);
    weekPrice($card, 7, 900, currency: 'EUR');          // same print, other currency
    weekPrice($card, 7, 800, variant: 'holofoil');      // other variant
    weekPrice($card, 8, null);                          // same basis but unpriced
    weekPrice($card, 9, 700);                           // the newest priced same-basis row at or before day 7
    $card->load('priceSnapshots');
    $resolver = new CardPriceResolver;

    $week = $resolver->weekDeltaFor($card, $resolver->resolve($card));

    // (The unique index allows one row per card, source, variant and day, hence the different days.)
    expect($week)->not->toBeNull()
        ->and($week->previous->market_minor)->toBe(700)
        ->and($week->deltaMinor)->toBe(300);
});

test('a manual price has no week move, however old its history', function () {
    $card = deltaCard();
    weekPrice($card, 0, 500, source: 'manual');
    weekPrice($card, 7, 400, source: 'manual', variant: 'normal', currency: 'USD');
    $card->load('priceSnapshots');
    $resolver = new CardPriceResolver;

    expect($resolver->weekDeltaFor($card, $resolver->resolve($card)))->toBeNull();
});

test('a price its marketplace stopped updating has no week move', function () {
    $card = deltaCard();
    $frozen = weekPrice($card, 10, 1000);
    weekPrice($card, 17, 900);
    weekPrice($card, 0, 400, variant: 'default', currency: 'EUR', source: 'cardmarket'); // the card's newest marketplace sync
    $card->load('priceSnapshots');
    $resolver = new CardPriceResolver;

    expect($resolver->weekDeltaFor($card, $card->priceSnapshots->firstWhere('id', $frozen->id)))->toBeNull();
});

test('no snapshot or an unpriced one means no week move, and the lookup reads no database rows', function () {
    $card = deltaCard();
    weekPrice($card, 0, null);
    weekPrice($card, 8, 500);
    $card->load('priceSnapshots');
    $resolver = new CardPriceResolver;
    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    expect($resolver->weekDeltaFor($card, null))->toBeNull()
        ->and($resolver->weekDeltaFor($card, $resolver->resolve($card)))->toBeNull()
        ->and($queries)->toBe(0);
});
