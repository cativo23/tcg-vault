<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;

function snapshotCapturedOn(string $day): CardPriceSnapshot
{
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);

    return CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => now()->parse($day), 'currency' => 'USD', 'market_minor' => 100]);
}

test('the capture day key is the Y-m-d day of a snapshot just created in memory', function () {
    expect(snapshotCapturedOn('2026-09-14')->capturedOnKey())->toBe('2026-09-14');
});

test('the capture day key is the Y-m-d day of a snapshot read back from the database', function () {
    $snapshot = snapshotCapturedOn('2026-09-14');

    expect(CardPriceSnapshot::find($snapshot->id)->capturedOnKey())->toBe('2026-09-14');
});

test('sourceLabel names where the price came from', function (string $source, string $label) {
    expect((new CardPriceSnapshot(['source' => $source]))->sourceLabel())->toBe($label);
})->with([
    ['tcgplayer', 'TCGplayer'],
    ['cardmarket', 'Cardmarket'],
    ['manual', 'Manual'],
]);
