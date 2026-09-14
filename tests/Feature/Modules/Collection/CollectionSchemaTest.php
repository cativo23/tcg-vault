<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\Set;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;

test('a collection belongs to a user and the tenant scope filters by the authenticated user', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    Collection::factory()->for($owner)->create(['name' => "Owner's", 'slug' => 'owners']);
    Collection::factory()->for($other)->create(['name' => "Other's", 'slug' => 'others']);

    $this->actingAs($owner);
    expect(Collection::count())->toBe(1);
    expect(Collection::first()->name)->toBe("Owner's");
});

test('an unauthenticated context sees zero rows — the scope fails closed, never open', function () {
    $owner = User::factory()->create();
    Collection::factory()->for($owner)->create(['name' => 'Any', 'slug' => 'any']);

    expect(Collection::count())->toBe(0);
});

test('console/seeder code can still reach every tenant by explicitly opting out of the scope', function () {
    $owner = User::factory()->create();
    Collection::factory()->for($owner)->create(['name' => 'Any', 'slug' => 'any']);

    expect(Collection::withoutGlobalScope(\App\Modules\Collection\Scopes\TenantScope::class)->count())->toBe(1);
});

test('a collection has many items, and an item belongs to a card', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create([
        'tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex',
    ]);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $item = CollectionItem::create([
        'collection_id' => $collection->id,
        'card_id' => $card->id,
        'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM',
        'quantity' => 1,
    ]);

    expect($collection->items)->toHaveCount(1);
    expect($item->card->name)->toBe('Mega Darkrai ex');
});
