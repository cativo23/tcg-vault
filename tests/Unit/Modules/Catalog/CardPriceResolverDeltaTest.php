<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Services\CardPriceResolver;

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

test('history returns the resolved source/variant series oldest first, one point per day', function () {
    $card = deltaCard();
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDays(2), 'currency' => 'USD', 'market_minor' => 800]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDay(), 'currency' => 'USD', 'market_minor' => 1000]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 1240]);
    // a different variant must not pollute the series
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'reverse-holofoil', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 5]);

    $history = (new CardPriceResolver)->history($card->fresh(['priceSnapshots']));

    expect($history->pluck('market_minor')->all())->toBe([800, 1000, 1240]);
});

test('delta never self-compares when the newest day only has a lower-priority source', function () {
    $card = deltaCard();
    // yesterday: tcgplayer (preferred); today: cardmarket only → resolve() still lands on yesterday's row
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDay(), 'currency' => 'USD', 'market_minor' => 1000]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 900]);

    expect((new CardPriceResolver)->resolveDelta($card->fresh(['priceSnapshots'])))->toBeNull();
});
