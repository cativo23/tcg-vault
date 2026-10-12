<?php

declare(strict_types=1);

use App\Livewire\Admin\CollectionItems;
use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Services\CardPriceResolver;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

test('the collection index lists the authenticated users items', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1,
    ]);

    Livewire::test(CollectionItems::class)
        ->assertSee('Mega Darkrai ex')
        ->assertSee('NM')
        // Swapping the result count with no announcement told assistive
        // tech nothing happened when search/filters actually changed it.
        ->assertSeeHtml('role="status"');
});

test('the search box shows a loading indicator while a debounced search is in flight', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $html = Livewire::test(CollectionItems::class)->html();

    expect($html)->toContain('wire:loading')
        ->toContain('wire:target="search"');
});

test('a user only sees their own items, never another users', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $otherCollection = Collection::factory()->for($otherUser)->create(['name' => 'Not mine', 'slug' => 'not-mine']);
    CollectionItem::create([
        'collection_id' => $otherCollection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1,
    ]);

    Livewire::test(CollectionItems::class)
        ->assertDontSee('Mega Darkrai ex');
});

test('search matches by card name', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $darkrai = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $toucannon = Card::create(['tcgdex_id' => 'me05-068', 'set_id' => $set->id, 'local_id' => '068', 'name' => 'Toucannon']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $darkrai->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $toucannon->id, 'card_tcgdex_id' => 'me05-068', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(CollectionItems::class)
        ->set('search', 'darkrai')
        ->assertSee('Mega Darkrai ex')
        ->assertDontSee('Toucannon');
});

test('search matches by set name', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $pitchBlack = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $surging = Set::create(['tcgdex_id' => 'sv08', 'name' => 'Surging Sparks']);
    $darkrai = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $pitchBlack->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $other = Card::create(['tcgdex_id' => 'sv08-1', 'set_id' => $surging->id, 'local_id' => '1', 'name' => 'Something Else']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $darkrai->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $other->id, 'card_tcgdex_id' => 'sv08-1', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(CollectionItems::class)
        ->set('search', 'pitch black')
        ->assertSee('Mega Darkrai ex')
        ->assertDontSee('Something Else');
});

test('search matches by notes', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $darkrai = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $other = Card::create(['tcgdex_id' => 'me05-068', 'set_id' => $set->id, 'local_id' => '068', 'name' => 'Toucannon']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $darkrai->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1, 'notes' => 'bought at a con']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $other->id, 'card_tcgdex_id' => 'me05-068', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(CollectionItems::class)
        ->set('search', 'con')
        ->assertSee('Mega Darkrai ex')
        ->assertDontSee('Toucannon');
});

test('condition filter narrows the list', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $darkrai = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $other = Card::create(['tcgdex_id' => 'me05-068', 'set_id' => $set->id, 'local_id' => '068', 'name' => 'Toucannon']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $darkrai->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $other->id, 'card_tcgdex_id' => 'me05-068', 'condition' => 'LP', 'quantity' => 1]);

    Livewire::test(CollectionItems::class)
        ->set('conditionFilter', 'LP')
        ->assertSee('Toucannon')
        ->assertDontSee('Mega Darkrai ex');
});

test('variant filter narrows the list', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $darkrai = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $other = Card::create(['tcgdex_id' => 'me05-068', 'set_id' => $set->id, 'local_id' => '068', 'name' => 'Toucannon']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $darkrai->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1, 'variant' => 'holofoil']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $other->id, 'card_tcgdex_id' => 'me05-068', 'condition' => 'NM', 'quantity' => 1, 'variant' => 'normal']);

    Livewire::test(CollectionItems::class)
        ->set('variantFilter', 'holofoil')
        ->assertSee('Mega Darkrai ex')
        ->assertDontSee('Toucannon');
});

test('needs-review filter shows only flagged items', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $darkrai = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $other = Card::create(['tcgdex_id' => 'me05-068', 'set_id' => $set->id, 'local_id' => '068', 'name' => 'Toucannon']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $darkrai->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1, 'needs_variant_review' => true]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $other->id, 'card_tcgdex_id' => 'me05-068', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(CollectionItems::class)
        ->set('needsReviewOnly', true)
        ->assertSee('Mega Darkrai ex')
        ->assertDontSee('Toucannon');
});

test('sorting by name orders alphabetically', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $zebra = Card::create(['tcgdex_id' => 'me05-1', 'set_id' => $set->id, 'local_id' => '1', 'name' => 'Zebstrika']);
    $abra = Card::create(['tcgdex_id' => 'me05-2', 'set_id' => $set->id, 'local_id' => '2', 'name' => 'Abra']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $zebra->id, 'card_tcgdex_id' => 'me05-1', 'condition' => 'NM', 'quantity' => 1]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $abra->id, 'card_tcgdex_id' => 'me05-2', 'condition' => 'NM', 'quantity' => 1]);

    $component = Livewire::test(CollectionItems::class)->call('sortBy', 'name');

    $names = $component->viewData('cardGroups')->pluck('card.name')->all();
    expect($names)->toBe(['Abra', 'Zebstrika']);
});

test('default sort is value descending', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $cheap = Card::create(['tcgdex_id' => 'me05-1', 'set_id' => $set->id, 'local_id' => '1', 'name' => 'Cheap Card']);
    $pricey = Card::create(['tcgdex_id' => 'me05-2', 'set_id' => $set->id, 'local_id' => '2', 'name' => 'Pricey Card']);
    CardPriceSnapshot::create(['card_id' => $cheap->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 100]);
    CardPriceSnapshot::create(['card_id' => $pricey->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 10000]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $cheap->id, 'card_tcgdex_id' => 'me05-1', 'condition' => 'NM', 'quantity' => 1]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $pricey->id, 'card_tcgdex_id' => 'me05-2', 'condition' => 'NM', 'quantity' => 1]);

    $component = Livewire::test(CollectionItems::class);

    $names = $component->viewData('cardGroups')->pluck('card.name')->all();
    expect($names)->toBe(['Pricey Card', 'Cheap Card']);
});

test('an item with no priced snapshot shows an em dash for value', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    $html = Livewire::test(CollectionItems::class)
        ->assertSee('—')
        ->html();

    // A plain assertDontSee('$') would false-positive on the toolbar's
    // unrelated `wire:click="$set('needsReviewOnly', ...)"` markup — match
    // the actual currency shape (e.g. "$40.00") instead, so this test would
    // really fail if the Value column stopped rendering an em dash.
    expect($html)->not->toMatch('/\$\d/');
    // A bare "—" gives no reason it's blank — a newly-added card with no
    // price data yet reads identically to something actually broken.
    expect($html)->toContain('title="No price data synced for this card/variant yet"');
});

test('a flagged item shows the needs-review badge', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1, 'needs_variant_review' => true]);

    Livewire::test(CollectionItems::class)
        ->assertSee('Review');
});

test('the list paginates at 24 items per page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);

    for ($i = 1; $i <= 26; $i++) {
        $card = Card::create(['tcgdex_id' => "me05-{$i}", 'set_id' => $set->id, 'local_id' => (string) $i, 'name' => "Card {$i}"]);
        CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => "me05-{$i}", 'condition' => 'NM', 'quantity' => 1]);
    }

    $component = Livewire::test(CollectionItems::class);

    expect($component->viewData('cardGroups'))->toHaveCount(24);
    expect($component->viewData('cardGroups')->total())->toBe(26);
});

test('hitting the row-fetch ceiling shows an honest truncation notice instead of silently dropping items', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);

    $now = now();
    $rowLimit = 1000;
    $cardRows = [];
    $itemRows = [];
    for ($i = 1; $i <= $rowLimit; $i++) {
        $cardRows[] = ['tcgdex_id' => "me05-{$i}", 'set_id' => $set->id, 'local_id' => (string) $i, 'name' => "Card {$i}", 'created_at' => $now, 'updated_at' => $now];
    }
    Card::insert($cardRows);
    $cardIds = Card::where('set_id', $set->id)->pluck('id', 'tcgdex_id');
    foreach ($cardIds as $tcgdexId => $cardId) {
        $itemRows[] = ['collection_id' => $collection->id, 'card_id' => $cardId, 'card_tcgdex_id' => $tcgdexId, 'condition' => 'NM', 'quantity' => 1, 'created_at' => $now, 'updated_at' => $now];
    }
    CollectionItem::insert($itemRows);

    $html = Livewire::test(CollectionItems::class)->html();

    expect($html)->toContain('Showing the first 1,000 items');
});

test('a collection under the row-fetch ceiling shows no truncation notice', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-1', 'set_id' => $set->id, 'local_id' => '1', 'name' => 'Card 1']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-1', 'condition' => 'NM', 'quantity' => 1]);

    $html = Livewire::test(CollectionItems::class)->html();

    expect($html)->not->toContain('Showing the first');
});

test('page 2 is reachable and shows the right items after a Livewire interaction', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);

    for ($i = 1; $i <= 26; $i++) {
        $card = Card::create(['tcgdex_id' => "me05-{$i}", 'set_id' => $set->id, 'local_id' => (string) $i, 'name' => "Card {$i}"]);
        CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => "me05-{$i}", 'condition' => 'NM', 'quantity' => 1]);
    }

    $component = Livewire::test(CollectionItems::class)
        ->set('conditionFilter', 'NM') // an interaction that triggers a component update, same class of bug as search/sort/edit
        ->call('sortBy', 'name')
        ->call('gotoPage', 2);

    expect($component->viewData('cardGroups'))->toHaveCount(2); // 26 items, 24 on page 1, 2 remain on page 2
});

test('pagination links point back at /admin, not the site root', function () {
    // Bug: the default "value" sort can't be expressed as a SQL ORDER
    // BY (CardPriceResolver's priority chain lives in PHP), so this
    // branch builds a LengthAwarePaginator by hand with no explicit
    // `path` option. Left to its default resolver, its links rendered
    // as "/?page=2" instead of "/admin?page=2" — clicking "Next"
    // took you to the marketing home page, not page 2 of your own
    // collection.
    Role::findOrCreate('user')->givePermissionTo(
        Permission::findOrCreate('use-collection'),
    );
    $user = User::factory()->create();
    $user->assignRole('user');
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    foreach (range(1, 25) as $i) {
        $card = Card::create(['tcgdex_id' => "me05-{$i}", 'set_id' => $set->id, 'local_id' => (string) $i, 'name' => "Card {$i}"]);
        CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => "me05-{$i}", 'condition' => 'NM', 'quantity' => 1]);
    }

    $response = $this->get('/admin');

    $response->assertOk();
    $response->assertSee('/admin?page=2', false);
    $response->assertDontSee('href="/?page=2"', false);
});

test('the empty state links straight into adding a card, not just inert text', function () {
    // Was plain text ("No cards yet — add your first one.") with no
    // link anywhere on the row — the "+ Add card" button above the
    // table is easy to miss on first load, and this was the only other
    // thing on the page telling a new user what to do.
    $user = User::factory()->create();
    $this->actingAs($user);

    $html = Livewire::test(CollectionItems::class)->html();

    // Before the fix, the add-card route only appears once (the "+ Add
    // card" button in the header) — the empty-state row is plain text
    // with no link of its own. After the fix it appears a second time,
    // in the row itself.
    expect(substr_count($html, route('admin.collection.add')))->toBe(2);
});

test('a new collection defaults to private, matching every other creation path', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(CollectionItems::class)
        ->assertSet('isPublic', false);

    expect(Collection::where('user_id', $user->id)->where('slug', 'my-collection')->first()->is_public)->toBeFalse();
});

test('making the collection public persists it', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(CollectionItems::class)
        ->call('makePublic')
        ->assertSet('isPublic', true);

    expect(Collection::where('user_id', $user->id)->where('slug', 'my-collection')->first()->is_public)->toBeTrue();
});

test('an existing collections current visibility is reflected, not silently reset', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Collection::create(['user_id' => $user->id, 'slug' => 'my-collection', 'name' => 'My Collection', 'is_public' => true]);

    Livewire::test(CollectionItems::class)
        ->assertSet('isPublic', true);
});

test('the visibility control has fixed Private and Public options, with the current one pressed', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(CollectionItems::class)
        ->assertSeeHtml('<span class="lbl">Visibility</span>')
        ->assertSeeHtml('wire:click="makePrivate" aria-pressed="true">Private</button>')
        ->assertSeeHtml('wire:click="makePublic" aria-pressed="false">Public</button>')
        ->call('makePublic')
        ->assertSeeHtml('wire:click="makePrivate" aria-pressed="false">Private</button>')
        ->assertSeeHtml('wire:click="makePublic" aria-pressed="true">Public</button>');
});

test('making a public collection private persists it and says so', function () {
    $user = User::factory()->create(['username' => 'ash']);
    $this->actingAs($user);
    Collection::create(['user_id' => $user->id, 'slug' => 'my-collection', 'name' => 'My Collection', 'is_public' => true]);

    Livewire::test(CollectionItems::class)
        ->assertSet('isPublic', true)
        ->call('makePrivate')
        ->assertSet('isPublic', false)
        ->assertSee('Only you can see your collection.');

    expect(Collection::where('user_id', $user->id)->where('slug', 'my-collection')->first()->is_public)->toBeFalse();
});

test('choosing the option that is already selected leaves visibility unchanged', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(CollectionItems::class)
        ->call('makePublic')
        ->call('makePublic')
        ->assertSet('isPublic', true);

    expect(Collection::where('user_id', $user->id)->where('slug', 'my-collection')->first()->is_public)->toBeTrue();
});

test('a private collection says only the owner can see it', function () {
    $this->actingAs(User::factory()->create(['username' => 'ash']));

    Livewire::test(CollectionItems::class)
        ->assertSee('Only you can see your collection.')
        ->assertDontSee('Anyone can see it at');
});

test('a public collection says who can see it and links to the page', function () {
    $this->actingAs(User::factory()->create(['username' => 'ash']));

    Livewire::test(CollectionItems::class)
        ->call('makePublic')
        ->assertSee('Anyone can see it at')
        ->assertSeeHtml('href="'.route('gallery.index', ['username' => 'ash']).'"')
        ->assertDontSee('Only you can see your collection.');
});

test('a public collection without a username points to the profile, since there is no page yet', function () {
    $this->actingAs(User::factory()->create(['username' => null]));

    Livewire::test(CollectionItems::class)
        ->call('makePublic')
        ->assertSee('Set a username')
        ->assertSeeHtml('href="'.route('profile').'"')
        ->assertDontSee('Anyone can see it at');
});

test('a card with 3 items across 2 conditions groups into one row with the right totals', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 100]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'holofoil', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 300]);

    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 2]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'holofoil', 'condition' => 'NM', 'quantity' => 3]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'holofoil', 'condition' => 'LP', 'quantity' => 1]);

    $test = Livewire::test(CollectionItems::class);

    // One row, not three: 2 + 3 + 1 copies, ($1.00*2)+($3.00*3)+($3.00*1) minor units.
    expect($test->viewData('cardGroups'))->toHaveCount(1);
    $group = $test->viewData('cardGroups')->first();
    expect($group->totalQuantity)->toBe(6);
    expect($group->totalValueMinor)->toBe(200 + 900 + 300);
    expect($test->viewData('totalCards'))->toBe(1);
    expect($test->viewData('totalCopies'))->toBe(6);
});

test('a cardmarket-only card group shows its total in EUR, not mislabeled as USD', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 500]);

    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    $html = Livewire::test(CollectionItems::class)->html();

    expect($html)->toContain('€5.00');
    expect($html)->not->toMatch('/\$5\.00/');
});

test('a card group with items priced in different currencies shows no single summed total', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 100]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'holofoil', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 900]);

    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 1]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'holofoil', 'condition' => 'NM', 'quantity' => 1]);

    $test = Livewire::test(CollectionItems::class);
    $group = $test->viewData('cardGroups')->first();

    expect($group->totalValueMinor)->toBeNull();
    expect($group->hasMixedCurrencyPricing)->toBeTrue();
    expect($test->html())->toContain('Mixed currencies');
});

test('the default value sort never compares raw amounts across different currencies', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    // USD group's raw minor units (1000) are larger than the EUR group's
    // (500) — a naive cross-currency comparison would rank USD first.
    // There's no live exchange rate to convert fairly, so groups are
    // ordered by currency code first instead, EUR ('E') before USD ('U').
    $usdCard = Card::create(['tcgdex_id' => 'me05-001', 'set_id' => $set->id, 'local_id' => '001', 'name' => 'Tropius']);
    CardPriceSnapshot::create(['card_id' => $usdCard->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 1000]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $usdCard->id, 'card_tcgdex_id' => 'me05-001', 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 1]);

    $eurCard = Card::create(['tcgdex_id' => 'me05-002', 'set_id' => $set->id, 'local_id' => '002', 'name' => 'Grubbin']);
    CardPriceSnapshot::create(['card_id' => $eurCard->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 500]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $eurCard->id, 'card_tcgdex_id' => 'me05-002', 'condition' => 'NM', 'quantity' => 1]);

    $test = Livewire::test(CollectionItems::class);
    $groups = $test->viewData('cardGroups');

    expect($groups)->toHaveCount(2);
    expect($groups[0]->card->id)->toBe($eurCard->id);
    expect($groups[0]->totalValueCurrency)->toBe('EUR');
    expect($groups[1]->card->id)->toBe($usdCard->id);
    expect($groups[1]->totalValueCurrency)->toBe('USD');
});

test('the grouped query orders deterministically before its row-count ceiling applies', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    Livewire::test(CollectionItems::class);

    $limitedQuery = collect($queries)->first(
        fn ($sql) => str_contains($sql, 'limit') && str_contains($sql, 'collection_items'),
    );

    expect($limitedQuery)->not->toBeNull();
    expect($limitedQuery)->toContain('order by');
    expect($limitedQuery)->toContain('"card_id" asc');
});

test('two different cards each with one item produce two separate groups', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $a = Card::create(['tcgdex_id' => 'me05-001', 'set_id' => $set->id, 'local_id' => '001', 'name' => 'Tropius']);
    $b = Card::create(['tcgdex_id' => 'me05-002', 'set_id' => $set->id, 'local_id' => '002', 'name' => 'Grubbin']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $a->id, 'card_tcgdex_id' => 'me05-001', 'condition' => 'NM', 'quantity' => 1]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $b->id, 'card_tcgdex_id' => 'me05-002', 'condition' => 'NM', 'quantity' => 1]);

    $test = Livewire::test(CollectionItems::class);

    expect($test->viewData('cardGroups'))->toHaveCount(2);
    expect($test->viewData('totalCards'))->toBe(2);
    expect($test->viewData('totalCopies'))->toBe(2);
});

test('the toolbar count reads distinct cards and total copies, not row count', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 2]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'holofoil', 'condition' => 'NM', 'quantity' => 3]);

    Livewire::test(CollectionItems::class)
        ->assertSee('Showing')
        ->assertSee('1', false)
        ->assertSee('card', false)
        ->assertSee('5', false)
        ->assertSee('copies', false);
});

test('a card row shows each owned variant as a chip with its quantity', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 2]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'holofoil', 'condition' => 'LP', 'quantity' => 3]);

    Livewire::test(CollectionItems::class)
        ->assertSee('Mega Darkrai ex')
        ->assertSee('Normal · NM')
        ->assertSee('×2', false)
        ->assertSee('Holofoil · LP')
        ->assertSee('×3', false);
});

test('a special-foil item is labelled and priced as its own print in the list', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $card = budewCard();
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'reverse-holofoil', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 32]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'reverse-holofoil:masterball', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 328]);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'sv08.5-004',
        'condition' => 'NM', 'quantity' => 1, 'variant' => 'reverse-holofoil:masterball',
    ]);

    Livewire::test(CollectionItems::class)
        ->assertSee('Reverse Holofoil · Master Ball')
        ->assertSee('3.28');
});

test('the variant filter offers the special prints the collection holds and narrows to one', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $card = budewCard();
    $other = Card::create(['tcgdex_id' => 'sv08.5-005', 'set_id' => $card->set_id, 'local_id' => '005', 'name' => 'Roselia']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'sv08.5-004', 'condition' => 'NM', 'quantity' => 1, 'variant' => 'reverse-holofoil:pokeball']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $other->id, 'card_tcgdex_id' => 'sv08.5-005', 'condition' => 'NM', 'quantity' => 1, 'variant' => 'reverse-holofoil']);

    Livewire::test(CollectionItems::class)
        ->assertSeeHtml('<option value="reverse-holofoil:pokeball">Reverse Holofoil · Poké Ball</option>')
        ->set('variantFilter', 'reverse-holofoil:pokeball')
        ->assertSee('Budew')
        ->assertDontSee('Roselia');
});

test('the variant filter never offers another users special prints', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $card = budewCard();
    $stranger = User::factory()->create();
    $theirs = Collection::factory()->for($stranger)->create(['name' => 'Theirs', 'slug' => 'theirs']);
    CollectionItem::create(['collection_id' => $theirs->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'sv08.5-004', 'condition' => 'NM', 'quantity' => 1, 'variant' => 'reverse-holofoil:masterball']);

    Livewire::test(CollectionItems::class)
        ->assertDontSeeHtml('value="reverse-holofoil:masterball"');
});

test('a manual price entered long ago still values the copy', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    [$card] = prizePackBudew($user);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'manual', 'variant' => 'holofoil:cosmos+player-rewards-program', 'captured_on' => today()->subDays(60), 'currency' => 'USD', 'market_minor' => 70]);

    Livewire::test(CollectionItems::class)->assertSee('$0.70');
});

if (! function_exists('budewCard')) {
    function budewCard(): Card
    {
        $set = Set::create(['tcgdex_id' => 'sv08.5', 'name' => 'Prismatic Evolutions']);

        return Card::create([
            'tcgdex_id' => 'sv08.5-004', 'set_id' => $set->id, 'local_id' => '004', 'name' => 'Budew',
            'variants' => ['holo' => false, 'normal' => true, 'reverse' => true],
            'raw' => ['variants_detailed' => [
                ['type' => 'normal', 'size' => 'standard'],
                ['type' => 'reverse', 'size' => 'standard'],
                ['type' => 'reverse', 'size' => 'standard', 'foil' => 'pokeball'],
                ['type' => 'reverse', 'size' => 'standard', 'foil' => 'masterball'],
            ]],
        ]);
    }
}
