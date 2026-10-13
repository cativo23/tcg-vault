<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\Set;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use App\Modules\Collection\Support\VariantChips;

function chipCard(array $variants): array
{
    $user = User::factory()->create();
    $collection = Collection::factory()->for($user)->create();
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-001', 'set_id' => $set->id, 'local_id' => '001', 'name' => 'Fomantis', 'variants' => $variants]);

    return [$collection, $card];
}

function chipItem(Collection $collection, Card $card, ?string $variant, int $quantity, string $condition = 'NM'): void
{
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => $card->tcgdex_id, 'variant' => $variant, 'condition' => $condition, 'quantity' => $quantity]);
}

test('one chip per offered print, counting every condition of that print', function () {
    [$collection, $card] = chipCard(['normal' => true, 'reverse' => true, 'holo' => false]);
    chipItem($collection, $card, 'normal', 2);
    chipItem($collection, $card, 'normal', 1, 'LP');

    expect(VariantChips::for($card, $card->collectionItems()->get()))->toBe([
        ['variant' => 'normal', 'label' => 'Normal', 'count' => 3],
        ['variant' => 'reverse-holofoil', 'label' => 'Reverse Holofoil', 'count' => 0],
    ]);
});

test('owned prints the card does not list come after the offered ones in key order, then unspecified copies', function () {
    [$collection, $card] = chipCard(['normal' => true]);
    chipItem($collection, $card, 'holofoil:cracked-ice', 4);
    chipItem($collection, $card, 'holofoil:cosmos', 1);
    chipItem($collection, $card, null, 2);

    expect(VariantChips::for($card, $card->collectionItems()->get()))->toBe([
        ['variant' => 'normal', 'label' => 'Normal', 'count' => 0],
        ['variant' => 'holofoil:cosmos', 'label' => 'Holofoil · Cosmos', 'count' => 1],
        ['variant' => 'holofoil:cracked-ice', 'label' => 'Holofoil · Cracked Ice', 'count' => 4],
        ['variant' => null, 'label' => 'Unspecified', 'count' => 2],
    ]);
});

test('a card with no known prints gets one unlabeled chip for unspecified copies', function () {
    [$collection, $card] = chipCard([]);
    chipItem($collection, $card, null, 2);

    expect(VariantChips::for($card, $card->collectionItems()->get()))->toBe([
        ['variant' => null, 'label' => '', 'count' => 2],
    ]);
});
