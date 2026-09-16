<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\Set;

function cardTileCard(string $rarity): Card
{
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);

    return Card::create([
        'tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116',
        'name' => 'Mega Darkrai ex', 'rarity' => $rarity,
    ]);
}

test('a standard-tier rarity gets no accent class at all', function () {
    $card = cardTileCard('Common');

    $view = $this->blade('<x-card-tile :card="$card" :href="\'#\'" />', ['card' => $card]);

    $view->assertDontSee('rarity-silver', false);
    $view->assertDontSee('rarity-chase', false);
});

test('a silver-tier rarity gets the silver accent class on the rarity chip', function () {
    $card = cardTileCard('Double Rare');

    $view = $this->blade('<x-card-tile :card="$card" :href="\'#\'" />', ['card' => $card]);

    $view->assertSee('rarity-silver', false);
    $view->assertDontSee('rarity-chase', false);
});

test('a chase-tier rarity gets the holo accent on the chip AND the tile itself', function () {
    $card = cardTileCard('Special Illustration Rare');

    $view = $this->blade('<x-card-tile :card="$card" :href="\'#\'" />', ['card' => $card]);

    // The chip carries the accent; the tile itself carries the matching
    // class too — the CSS ring around the whole card lives there, not
    // on the chip, so both need the class for the holo treatment to
    // actually show up as designed. Two occurrences: the outer <a
    // class="nw-tile ..."> and the chip's <span class="rar ...">.
    expect(substr_count((string) $view, 'rarity-chase'))->toBe(2);
});

test('a graded slab replaces the rarity chip entirely, so no rarity tier accent applies', function () {
    $card = cardTileCard('Secret Rare'); // chase-tier rarity, but graded takes over
    $card->collectionItems()->create([
        'collection_id' => \App\Modules\Collection\Models\Collection::factory()->create()->id,
        'card_tcgdex_id' => $card->tcgdex_id, 'condition' => 'NM', 'quantity' => 1,
        'grade_company' => 'PSA', 'grade_value' => '10',
    ]);

    $view = $this->blade('<x-card-tile :card="$card" :items="$card->collectionItems" :href="\'#\'" />', ['card' => $card->fresh()]);

    $view->assertSee('PSA 10');
    $view->assertDontSee('rarity-chase', false);
});
