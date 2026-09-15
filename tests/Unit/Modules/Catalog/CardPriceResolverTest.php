<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Services\CardPriceResolver;

test('prefers tcgplayer normal over everything else', function () {
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);

    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 500]);
    $tcgplayer = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 1000]);

    $resolved = (new CardPriceResolver())->resolve($card);

    expect($resolved->id)->toBe($tcgplayer->id);
});

test('falls back to cardmarket default when no tcgplayer normal or holofoil exists', function () {
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);

    $cardmarket = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 500]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'reverse-holofoil', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 1500]);

    $resolved = (new CardPriceResolver())->resolve($card);

    expect($resolved->id)->toBe($cardmarket->id);
});

test('falls back to any remaining snapshot, most recent first', function () {
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);

    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'reverse-holofoil', 'captured_on' => today()->subDay(), 'currency' => 'USD', 'market_minor' => 1000]);
    $newest = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'reverse-holofoil', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 1200]);

    $resolved = (new CardPriceResolver())->resolve($card);

    expect($resolved->id)->toBe($newest->id);
});

test('returns null when the card has no snapshot at all', function () {
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);

    expect((new CardPriceResolver())->resolve($card))->toBeNull();
});

test('resolveAsOf ignores snapshots captured after the given date', function () {
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);

    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDays(2), 'currency' => 'USD', 'market_minor' => 1000]);
    $newer = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 1200]);

    $resolved = (new CardPriceResolver())->resolveAsOf($card, today()->subDays(2));

    expect($resolved->market_minor)->toBe(1000);
    expect($resolved->id)->not->toBe($newer->id);
});

test('previousComparable returns null rather than the same row when there is no earlier same-source snapshot', function () {
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);

    // Day 1: only tcgplayer. Day 2 (today): only cardmarket — so
    // resolve() on "today" falls back to yesterday's tcgplayer row (the
    // priority chain always prefers tcgplayer when any exists). Without
    // requiring captured_on strictly-before $latest, asking "what's the
    // previous comparable price" would return that SAME tcgplayer row,
    // producing a fabricated zero delta instead of "no comparable point".
    $day1Tcgplayer = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDay(), 'currency' => 'USD', 'market_minor' => 1000]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 900]);

    $resolver = new CardPriceResolver();
    $latest = $resolver->resolve($card);

    expect($latest->id)->toBe($day1Tcgplayer->id);
    expect($resolver->previousComparable($card, $latest))->toBeNull();
});

test('previousComparable finds a real same-source predecessor across a 3-day history with a mixed-source middle day', function () {
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);

    $day1 = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDays(2), 'currency' => 'USD', 'market_minor' => 1000]);
    $day2 = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDay(), 'currency' => 'USD', 'market_minor' => 1200]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 850]);

    $resolver = new CardPriceResolver();
    // resolve() on "today" still falls back to day2's tcgplayer row
    // (today only has cardmarket) — previousComparable must then find
    // day1's tcgplayer row (not day2 itself, not null), the real
    // +2.00 move this phase's spec asks for.
    $latest = $resolver->resolve($card);
    expect($latest->id)->toBe($day2->id);

    $previous = $resolver->previousComparable($card, $latest);
    expect($previous->id)->toBe($day1->id);
});

test('previousComparable ignores a same-day/source predecessor with a null market_minor', function () {
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);

    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDay(), 'currency' => 'USD', 'market_minor' => null]);
    $latestRow = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 1200]);

    $resolver = new CardPriceResolver();
    expect($resolver->previousComparable($card, $latestRow))->toBeNull();
});

test('distinctSnapshotDates returns one entry per day, most recent first, regardless of how many source rows exist per day', function () {
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);

    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDay(), 'currency' => 'USD', 'market_minor' => 1000]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today()->subDay(), 'currency' => 'EUR', 'market_minor' => 900]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 1100]);

    $dates = (new CardPriceResolver())->distinctSnapshotDates($card);

    expect($dates)->toHaveCount(2);
    expect($dates->first()->toDateString())->toBe(today()->toDateString());
});
