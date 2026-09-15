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
