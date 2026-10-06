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

    $resolved = (new CardPriceResolver)->resolve($card);

    expect($resolved->id)->toBe($tcgplayer->id);
});

test('falls back to cardmarket default when no tcgplayer normal or holofoil exists', function () {
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);

    $cardmarket = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 500]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'reverse-holofoil', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 1500]);

    $resolved = (new CardPriceResolver)->resolve($card);

    expect($resolved->id)->toBe($cardmarket->id);
});

test('falls back to cardmarket normal (not just default) when no tcgplayer normal or holofoil exists', function () {
    // The importer labels cardmarket's base price 'normal' (not
    // 'default') for any card that genuinely has a normal print (the
    // common case) — the card-level fallback chain must recognize both
    // labels as "cardmarket's primary listing", or it silently falls
    // through to whichever row happens to sort first instead of the
    // right one.
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);

    // Created BEFORE 'normal' on purpose: this must be picked by an
    // explicit "cardmarket's base listing wins" rule, not by accidentally
    // matching insertion/collection order.
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'reverse-holofoil', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 900]);
    $cardmarket = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 500]);

    $resolved = (new CardPriceResolver)->resolve($card);

    expect($resolved->id)->toBe($cardmarket->id);
});

test('falls back to any remaining snapshot, most recent first', function () {
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);

    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'reverse-holofoil', 'captured_on' => today()->subDay(), 'currency' => 'USD', 'market_minor' => 1000]);
    $newest = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'reverse-holofoil', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 1200]);

    $resolved = (new CardPriceResolver)->resolve($card);

    expect($resolved->id)->toBe($newest->id);
});

test('returns null when the card has no snapshot at all', function () {
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);

    expect((new CardPriceResolver)->resolve($card))->toBeNull();
});

test('resolveAsOf ignores snapshots captured after the given date', function () {
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);

    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDays(2), 'currency' => 'USD', 'market_minor' => 1000]);
    $newer = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 1200]);

    $resolved = (new CardPriceResolver)->resolveAsOf($card, today()->subDays(2));

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

    $resolver = new CardPriceResolver;
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

    $resolver = new CardPriceResolver;
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

    $resolver = new CardPriceResolver;
    expect($resolver->previousComparable($card, $latestRow))->toBeNull();
});

test('resolveForVariant picks the snapshot matching that exact variant, even when a different variant would outrank it in the default chain', function () {
    // Reverse-holofoil isn't in resolve()'s preferred set (['normal',
    // 'holofoil']), so the card-level default would skip right past it —
    // but it's real money the collector's copy is actually worth.
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-051', 'set_id' => $set->id, 'local_id' => '051', 'name' => 'Inkay']);

    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 11]);
    $reverseHolo = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'reverse-holofoil', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 45]);

    $resolved = (new CardPriceResolver)->resolveForVariant($card, 'reverse-holofoil');

    expect($resolved->id)->toBe($reverseHolo->id);
});

test('resolveForVariant prefers tcgplayer over cardmarket when both cover the requested variant', function () {
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-051', 'set_id' => $set->id, 'local_id' => '051', 'name' => 'Inkay']);

    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'holofoil', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 200]);
    $tcgplayer = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'holofoil', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 250]);

    $resolved = (new CardPriceResolver)->resolveForVariant($card, 'holofoil');

    expect($resolved->id)->toBe($tcgplayer->id);
});

test('resolveForVariant falls back to the default priority chain when the item has no assigned variant', function () {
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);

    $tcgplayer = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 1000]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 500]);

    $resolved = (new CardPriceResolver)->resolveForVariant($card, null);

    expect($resolved->id)->toBe($tcgplayer->id);
});

test('resolveForVariant returns null when the card has no snapshot for that variant', function () {
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-051', 'set_id' => $set->id, 'local_id' => '051', 'name' => 'Inkay']);

    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 11]);

    $resolved = (new CardPriceResolver)->resolveForVariant($card, 'reverse-holofoil');

    expect($resolved)->toBeNull();
});

test('historyFor returns the daily series for a SPECIFIC snapshot, not whatever resolve() would pick', function () {
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-051', 'set_id' => $set->id, 'local_id' => '051', 'name' => 'Inkay']);

    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 11]);
    $holoYesterday = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'reverse-holofoil', 'captured_on' => today()->subDay(), 'currency' => 'USD', 'market_minor' => 18]);
    $holoToday = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'reverse-holofoil', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 20]);

    $card->load('priceSnapshots');
    $resolver = new CardPriceResolver;

    $history = $resolver->historyFor($card, $holoToday);

    expect($history->pluck('id')->all())->toBe([$holoYesterday->id, $holoToday->id]);
});

test('historyFor returns an empty collection for a null snapshot', function () {
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);

    expect((new CardPriceResolver)->historyFor($card, null))->toBeEmpty();
});

test('history is historyFor applied to resolve()\'s own pick, unchanged', function () {
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDay(), 'currency' => 'USD', 'market_minor' => 1000]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 1200]);
    $card->load('priceSnapshots');
    $resolver = new CardPriceResolver;

    expect($resolver->history($card)->pluck('id')->all())
        ->toBe($resolver->historyFor($card, $resolver->resolve($card))->pluck('id')->all());
});

test('distinctSnapshotDates returns one entry per day, most recent first, regardless of how many source rows exist per day', function () {
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);

    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDay(), 'currency' => 'USD', 'market_minor' => 1000]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today()->subDay(), 'currency' => 'EUR', 'market_minor' => 900]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 1100]);

    $dates = (new CardPriceResolver)->distinctSnapshotDates($card);

    expect($dates)->toHaveCount(2);
    expect($dates->first()->toDateString())->toBe(today()->toDateString());
});

test('the card-level fallback never lands on a special print\'s price', function () {
    // svp-223 Professor's Research: cardmarket normal €1.15 beside a
    // professor-program stamped print at €4.77, captured the same day.
    $set = Set::create(['tcgdex_id' => 'svp', 'name' => 'SV Promos']);
    $card = Card::create(['tcgdex_id' => 'svp-223', 'set_id' => $set->id, 'local_id' => '223', 'name' => 'Professor\'s Research']);

    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'normal+professor-program', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 477]);
    $base = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 115]);

    expect((new CardPriceResolver)->resolve($card)->id)->toBe($base->id);
    expect((new CardPriceResolver)->resolveForVariant($card, null)->id)->toBe($base->id);
});

test('a card priced only through special prints has no card-level price', function () {
    $set = Set::create(['tcgdex_id' => 'svp', 'name' => 'SV Promos']);
    $card = Card::create(['tcgdex_id' => 'svp-224', 'set_id' => $set->id, 'local_id' => '224', 'name' => 'Some Worlds Promo']);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'normal+worlds-2025', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 900]);

    expect((new CardPriceResolver)->resolve($card))->toBeNull();
    expect((new CardPriceResolver)->resolveForVariantOnDays($card->fresh(), null, collect([today()->toDateString()]))[today()->toDateString()])->toBeNull();
});

test('a special print is still priced when asked for by its own key', function () {
    $set = Set::create(['tcgdex_id' => 'svp', 'name' => 'SV Promos']);
    $card = Card::create(['tcgdex_id' => 'svp-224', 'set_id' => $set->id, 'local_id' => '224', 'name' => 'Some Worlds Promo']);
    $stamped = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'normal+worlds-2025', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 900]);

    expect((new CardPriceResolver)->resolveForVariant($card, 'normal+worlds-2025')->id)->toBe($stamped->id);
});

function staleTcgplayerCard(): Card
{
    // 30th-130 Moltres: tcgdex stopped sending TCGplayer prices for the set,
    // so its last TCGplayer row is 12 days old while cardmarket is current.
    $set = Set::create(['tcgdex_id' => '30th', 'name' => '30th Celebration']);

    return Card::create(['tcgdex_id' => '30th-130', 'set_id' => $set->id, 'local_id' => '130', 'name' => 'Moltres']);
}

test('a stale TCGplayer price no longer outranks a current cardmarket one', function () {
    $card = staleTcgplayerCard();
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDays(12), 'currency' => 'USD', 'market_minor' => 1231]);
    $fresh = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 1522]);

    expect((new CardPriceResolver)->resolve($card)->id)->toBe($fresh->id);
});

test('a TCGplayer price a day or two behind still wins, so one missed sync does not flip the currency', function () {
    $card = staleTcgplayerCard();
    $tcgplayer = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDays(2), 'currency' => 'USD', 'market_minor' => 1231]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 1522]);

    expect((new CardPriceResolver)->resolve($card)->id)->toBe($tcgplayer->id);
});

test('a variant with no row of its own, or a special print, is never priced from the card-wide row', function () {
    $card = staleTcgplayerCard();
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 1522]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'holofoil:cosmos', 'captured_on' => today()->subDays(12), 'currency' => 'USD', 'market_minor' => 900]);

    expect((new CardPriceResolver)->resolveForVariant($card, 'reverse-holofoil'))->toBeNull();
    expect((new CardPriceResolver)->resolveForVariant($card, 'holofoil:cosmos')->market_minor)->toBe(900);
});

test('a manual price stays the pick at any age, ahead of a frozen TCGplayer row', function () {
    $card = staleTcgplayerCard();
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDays(20), 'currency' => 'USD', 'market_minor' => 1231]);
    $manual = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'manual', 'variant' => 'normal', 'captured_on' => today()->subDays(10), 'currency' => 'USD', 'market_minor' => 571]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 1522]);

    expect((new CardPriceResolver)->resolveForVariant($card, 'normal')->id)->toBe($manual->id);
});

test('a current market price for the same print beats a manual one', function () {
    $card = staleTcgplayerCard();
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'manual', 'variant' => 'normal+player-rewards-program', 'captured_on' => today()->subDays(10), 'currency' => 'USD', 'market_minor' => 25]);
    $cardmarket = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'normal+player-rewards-program', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 52]);

    expect((new CardPriceResolver)->resolveForVariant($card, 'normal+player-rewards-program')->id)->toBe($cardmarket->id);
});

test('a copy whose own price froze falls back to the current card-wide price', function () {
    // Production's 30th cards with no manual price: the copy is 'normal',
    // its only 'normal' row is frozen TCGplayer, cardmarket is 'default'.
    $card = staleTcgplayerCard();
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDays(12), 'currency' => 'USD', 'market_minor' => 1231]);
    $fresh = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 1522]);

    expect((new CardPriceResolver)->resolveForVariant($card, 'normal')->id)->toBe($fresh->id);
    $today = today()->toDateString();
    expect((new CardPriceResolver)->resolveForVariantOnDays($card->fresh(), 'normal', collect([$today]))[$today]->id)->toBe($fresh->id);
});

test('a newer row with no price does not push out an older priced one', function () {
    $card = staleTcgplayerCard();
    $priced = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDays(5), 'currency' => 'USD', 'market_minor' => 1231]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => null]);

    expect((new CardPriceResolver)->resolve($card)->id)->toBe($priced->id);
});

test('exactly three days behind is still current; four is stale', function () {
    $card = staleTcgplayerCard();
    $tcgplayer = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDays(3), 'currency' => 'USD', 'market_minor' => 1231]);
    $cardmarket = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 1522]);

    expect((new CardPriceResolver)->resolveForVariant($card, 'normal')->id)->toBe($tcgplayer->id);

    $tcgplayer->update(['captured_on' => today()->subDays(4)]);
    expect((new CardPriceResolver)->resolveForVariant($card->fresh(), 'normal')->id)->toBe($cardmarket->id);
});

test('a stale TCGplayer price for a variant yields to that variant\'s current cardmarket price', function () {
    $card = staleTcgplayerCard();
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'reverse-holofoil', 'captured_on' => today()->subDays(12), 'currency' => 'USD', 'market_minor' => 300]);
    $fresh = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'reverse-holofoil', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 250]);

    expect((new CardPriceResolver)->resolveForVariant($card, 'reverse-holofoil')->id)->toBe($fresh->id);
});

test('the per-day series applies the same staleness rule as the single-day pick', function () {
    $card = staleTcgplayerCard();
    $old = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDays(12), 'currency' => 'USD', 'market_minor' => 1231]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'normal', 'captured_on' => today()->subDays(12), 'currency' => 'EUR', 'market_minor' => 1200]);
    $fresh = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 1522]);

    $days = collect([today()->subDays(12)->toDateString(), today()->toDateString()]);
    $series = (new CardPriceResolver)->resolveForVariantOnDays($card->fresh(), 'normal', $days);

    expect($series[today()->subDays(12)->toDateString()]->id)->toBe($old->id);
    expect($series[today()->toDateString()]->id)->toBe($fresh->id);
    expect((new CardPriceResolver)->resolveForVariant($card->fresh(), 'normal')->id)->toBe($fresh->id);
});

test('a current TCGplayer price still beats a manual one', function () {
    $card = staleTcgplayerCard();
    $tcgplayer = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 600]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'manual', 'variant' => 'normal', 'captured_on' => today()->subDays(2), 'currency' => 'USD', 'market_minor' => 571]);

    expect((new CardPriceResolver)->resolveForVariant($card, 'normal')->id)->toBe($tcgplayer->id);
});

test('staleness is measured against the card\'s latest sync, not just the variant\'s own rows', function () {
    // Production's 30th-130: cardmarket rows are labelled 'default', the
    // copy is 'normal', and only a frozen TCGplayer row and a manual price
    // carry 'normal'. The frozen row must not count as current just
    // because nothing else of its variant is dated.
    $card = staleTcgplayerCard();
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDays(12), 'currency' => 'USD', 'market_minor' => 1231]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 1522]);
    $manual = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'manual', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 571]);

    $resolver = new CardPriceResolver;
    expect($resolver->resolveForVariant($card, 'normal')->id)->toBe($manual->id);

    $today = today()->toDateString();
    expect($resolver->resolveForVariantOnDays($card->fresh(), 'normal', collect([$today]))[$today]->id)->toBe($manual->id);
});

test('a frozen reverse holo never borrows the card-wide price of the primary print', function () {
    $card = staleTcgplayerCard();
    $own = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'reverse-holofoil', 'captured_on' => today()->subDays(12), 'currency' => 'USD', 'market_minor' => 300]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 1522]);

    expect((new CardPriceResolver)->resolveForVariant($card, 'reverse-holofoil')->id)->toBe($own->id);
});

test('hasCurrentMarketPrice is true only for a marketplace price that is still current', function () {
    $card = staleTcgplayerCard();
    $resolver = new CardPriceResolver;

    expect($resolver->hasCurrentMarketPrice($card, 'normal'))->toBeFalse();

    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'manual', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 571]);
    expect($resolver->hasCurrentMarketPrice($card->fresh(), 'normal'))->toBeFalse();

    // Frozen: 12 days behind the card's latest sync.
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDays(12), 'currency' => 'USD', 'market_minor' => 1231]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 1522]);
    expect($resolver->hasCurrentMarketPrice($card->fresh(), 'normal'))->toBeFalse();

    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'normal', 'captured_on' => today()->subDay(), 'currency' => 'EUR', 'market_minor' => 1500]);
    expect($resolver->hasCurrentMarketPrice($card->fresh(), 'normal'))->toBeTrue();
});

test('hasCurrentMarketPrice follows the resolver: an unpriced newest row is no current price', function () {
    $card = staleTcgplayerCard();
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDays(2), 'currency' => 'USD', 'market_minor' => 1231]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => null]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 1522]);

    expect((new CardPriceResolver)->hasCurrentMarketPrice($card, 'normal'))->toBeFalse();
});

test('hasCurrentMarketPrice treats exactly three days behind as current', function () {
    $card = staleTcgplayerCard();
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDays(3), 'currency' => 'USD', 'market_minor' => 1231]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 1522]);

    expect((new CardPriceResolver)->hasCurrentMarketPrice($card, 'normal'))->toBeTrue();
});
