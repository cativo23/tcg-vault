<?php

declare(strict_types=1);

use App\Livewire\Gallery\Show;
use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Services\WeekBaselines;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\RevampHtml;

test('shows only owned cards by default, computes stats from the whole set', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 2]);
    $owned = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $notOwned = Card::create(['tcgdex_id' => 'me05-003', 'set_id' => $set->id, 'local_id' => '003', 'name' => 'Fomantis']);

    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $owned->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    CardPriceSnapshot::create(['card_id' => $owned->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 5000]);
    CardPriceSnapshot::create(['card_id' => $notOwned->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 1000]);

    $response = $this->get('/carlos/me05');

    $response->assertOk();
    $response->assertSee('Mega Darkrai ex'); // owned, shown
    $response->assertDontSee('Fomantis'); // not owned — hidden by default (missing cards are opt-in)
    expect(RevampHtml::summary($response->getContent()))->toBe('ME05 · 1 of 2 cards · $50.00'); // the whole set, unfiltered
    // Swapping the result count with no announcement told assistive tech
    // nothing happened when the search box actually changed the grid.
    $response->assertSee('role="status"', false);
});

test('owning even one card out of a huge set never rounds the progress bar down to 0%', function () {
    // Same rounding bug as Sets.php, on the set-detail page's own progress
    // bar/percentage text.
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me02.5', 'name' => 'Ascended Heroes', 'card_count' => 217]);
    $card = Card::create(['tcgdex_id' => 'me02.5-123', 'set_id' => $set->id, 'local_id' => '123', 'name' => 'Gastly']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me02.5-123', 'condition' => 'NM', 'quantity' => 1]);

    $response = $this->get('/carlos/me02.5');

    $response->assertOk();
    $html = $response->getContent();
    expect(RevampHtml::summary($html))->toBe('ME02.5 · 1 of 217 cards')
        ->and($html)->toContain('<span class="set-strip-pct" aria-hidden="true">1%</span>')
        ->and($html)->toContain('aria-label="1% of the set collected"')
        ->and(RevampHtml::stripSlots($html))->toHaveCount(217);
});

test('toggling "show missing" reveals cards the collector does not own', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 2]);
    $owned = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    Card::create(['tcgdex_id' => 'me05-003', 'set_id' => $set->id, 'local_id' => '003', 'name' => 'Fomantis']);

    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $owned->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(Show::class, ['username' => 'carlos', 'setTcgdexId' => 'me05'])
        ->assertDontSee('Fomantis')
        ->call('toggleMissing')
        ->assertSee('Fomantis')
        ->call('toggleMissing')
        ->assertDontSee('Fomantis');
});

test('a private (non-public) collection contributes nothing to the set-detail screen', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => false, 'slug' => 'private']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 1]);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1, 'photo_path' => 'secret.jpg']);

    $response = $this->get('/carlos/me05');

    // The set only exists in this user's gallery via a public collection
    // — with none, the same existence-gate that hides an untouched set
    // must also hide a set touched only through a private one, and the
    // private item's photo must never leak into the response either way.
    $response->assertNotFound();
    $response->assertDontSee('secret.jpg', false);
});

test('the set-detail route requires no authentication', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 1]);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    // Explicitly NOT calling $this->actingAs(...) — a guest must be able to load this.
    $response = $this->get('/carlos/me05');

    $response->assertOk();
});

test('a set that exists but the user has never touched 404s', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    Set::create(['tcgdex_id' => 'untouched', 'name' => 'Never Added']);

    $response = $this->get('/carlos/untouched');

    $response->assertNotFound();
});

test('uses the users own photo over official art when owned and photographed', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 1]);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex', 'official_image_url' => 'https://official.example/card.webp']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1, 'photo_path' => 'my-photo.jpg']);

    $response = $this->get('/carlos/me05');

    $response->assertSee(Storage::disk('collection-photos')->url('my-photo.jpg'), false);
    $response->assertDontSee('https://official.example/card.webp', false);
});

test('search filters the card grid by name', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 2]);
    $card1 = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $card2 = Card::create(['tcgdex_id' => 'me05-003', 'set_id' => $set->id, 'local_id' => '003', 'name' => 'Fomantis']);

    // mount()'s existence-gate (borrowed from Task 4) only shows a set the
    // user has touched — without this, the set 404s before search is ever
    // exercised.
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card1->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(Show::class, ['username' => 'carlos', 'setTcgdexId' => 'me05'])
        ->set('search', 'Darkrai')
        ->assertSee('Mega Darkrai ex')
        ->assertDontSee('Fomantis');
});

test('search is case-insensitive', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 1]);
    $card = Card::create(['tcgdex_id' => 'me05-003', 'set_id' => $set->id, 'local_id' => '003', 'name' => 'Fomantis']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-003', 'condition' => 'NM', 'quantity' => 1]);

    // Postgres' LIKE is case-sensitive by default — a lowercase search
    // must still find a card whose stored name is capitalized.
    Livewire::test(Show::class, ['username' => 'carlos', 'setTcgdexId' => 'me05'])
        ->set('search', 'fomantis')
        ->assertSee('Fomantis');
});

test('rarity filter only shows cards of the selected rarity', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 2]);
    $card1 = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex', 'rarity' => 'SIR']);
    Card::create(['tcgdex_id' => 'me05-003', 'set_id' => $set->id, 'local_id' => '003', 'name' => 'Fomantis', 'rarity' => 'Common']);

    // Same existence-gate note as the search test above.
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card1->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(Show::class, ['username' => 'carlos', 'setTcgdexId' => 'me05'])
        ->set('rarityFilter', 'SIR')
        ->assertSee('Mega Darkrai ex')
        ->assertDontSee('Fomantis');
});

test('tcgdex\'s literal "None" rarity never becomes a selectable filter option', function () {
    // Regression: whereNotNull('rarity') only excludes a real NULL —
    // tcgdex's literal string "None" (promos/unrated prints) survived
    // as a selectable-but-blank-labeled option (Rarity::label('None')
    // is already ''), and picking it matched only cards literally rated
    // "None", hiding every properly-rated card in the set.
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 3]);
    $darkrai = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex', 'rarity' => 'Special illustration rare']);
    Card::create(['tcgdex_id' => 'me05-003', 'set_id' => $set->id, 'local_id' => '003', 'name' => 'Fomantis', 'rarity' => 'Common']);
    Card::create(['tcgdex_id' => 'me05-200', 'set_id' => $set->id, 'local_id' => '200', 'name' => 'Pikachu on the Ball', 'rarity' => 'None']);

    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $darkrai->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    $response = $this->get('/carlos/me05');

    $response->assertOk();
    $response->assertSee('value="Special illustration rare"', false);
    $response->assertSee('value="Common"', false);
    $response->assertDontSee('value="None"', false);
    $response->assertDontSee('value="none"', false);
});

test('the set page falls back to the local card count when tcgdex reports zero', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 0]);
    $owned = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    Card::create(['tcgdex_id' => 'me05-003', 'set_id' => $set->id, 'local_id' => '003', 'name' => 'Fomantis']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $owned->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    $html = $this->get('/carlos/me05')->assertOk()->getContent();

    // 'of 0 cards' is what a tcgdex total of 0 would print: it must never reach the copy.
    expect(RevampHtml::summary($html))->toBe('ME05 · 1 of 2 cards')
        ->and(RevampHtml::summary($html))->not->toContain('of 0 cards')
        ->and($html)->toContain('<span class="set-strip-pct" aria-hidden="true">50%</span>');
});

test('the owner viewing their own public set page gets a link into the set grid', function () {
    $this->seed(PermissionSeeder::class);
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 2]);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    $this->actingAs($user)->get('/carlos/me05')
        ->assertOk()
        ->assertSee('Edit in my collection')
        ->assertSee(route('admin.sets.show', ['setTcgdexId' => 'me05']), false);
});

test('a visitor, signed in or not, does not get the edit link', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 2]);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    $this->get('/carlos/me05')->assertOk()->assertDontSee('Edit in my collection');

    $this->actingAs(User::factory()->create(['username' => 'ash']))
        ->get('/carlos/me05')->assertOk()->assertDontSee('Edit in my collection');

    // Neither visitor gets the owner's bar link either (the layout is tested alone; this is the page).
    $this->get('/carlos/me05')->assertOk()->assertDontSee('Manage collection');
    $this->actingAs(User::factory()->create(['username' => 'bea']))->get('/carlos/me05')->assertOk()->assertDontSee('Manage collection');
});

test('an owner without the use-collection permission gets no link into the set grid', function () {
    $this->seed(PermissionSeeder::class);
    $user = User::factory()->create(['username' => 'carlos']);
    $user->syncRoles([]);
    $user->syncPermissions([]);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 2]);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    $this->actingAs($user)->get('/carlos/me05')->assertOk()->assertDontSee('Edit in my collection');
});

/**
 * The demo's 151 in miniature: five cards, three of them owned (001, 003,
 * 005), USD prices on 003 ($24.99), 005 ($1.50) and the missing 002 ($0.75).
 *
 * @param  array<string, mixed>  $setAttributes
 * @return array{user: User, collection: Collection, set: Set, cards: array<string, Card>}
 */
function seedSetPage(array $setAttributes = []): array
{
    $user = User::factory()->create(['username' => 'demo', 'name' => 'Demo']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create([
        'tcgdex_id' => 'sv03.5', 'name' => '151', 'series' => 'Scarlet & Violet', 'abbreviation' => 'MEW',
        'card_count' => 5, 'released_on' => '2023-09-22', 'logo_url' => 'https://assets.tcgdex.net/en/sv/sv03.5/logo.png',
        ...$setAttributes,
    ]);

    $cards = [];
    foreach ([['001', 'Bulbasaur', 'Common'], ['002', 'Ivysaur', 'Uncommon'], ['003', 'Venusaur ex', 'Double rare'], ['004', 'Charmander', 'Common'], ['005', 'Charmeleon', 'Uncommon']] as [$number, $name, $rarity]) {
        $cards[$number] = Card::create([
            'tcgdex_id' => "sv03.5-$number", 'set_id' => $set->id, 'local_id' => $number, 'name' => $name, 'rarity' => $rarity,
            'official_image_url' => "https://assets.tcgdex.net/en/sv/sv03.5/$number/high.webp",
        ]);
    }

    foreach (['001', '003', '005'] as $number) {
        CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $cards[$number]->id, 'card_tcgdex_id' => "sv03.5-$number", 'condition' => 'NM', 'quantity' => 1]);
    }

    foreach (['003' => 2499, '005' => 150, '002' => 75] as $number => $minor) {
        CardPriceSnapshot::create(['card_id' => $cards[$number]->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => $minor]);
    }

    return compact('user', 'collection', 'set', 'cards');
}

test('the strip, the counts and the missing number come from the whole set, whatever the grid shows', function () {
    seedSetPage();

    $page = Livewire::withQueryParams(['search' => 'Bulba'])->test(Show::class, ['username' => 'demo', 'setTcgdexId' => 'sv03.5']);

    expect($page->viewData('entries'))->toHaveCount(1)
        ->and($page->viewData('ownedCount'))->toBe(3)
        ->and($page->viewData('totalCount'))->toBe(5)
        ->and($page->viewData('missingCount'))->toBe(2)
        ->and($page->viewData('percent'))->toBe(60)
        ->and(array_column($page->viewData('strip'), 'state'))->toBe(['owned', 'missing', 'owned', 'missing', 'owned'])
        ->and(array_column($page->viewData('strip'), 'number'))->toBe(['001', '002', '003', '004', '005'])
        ->and($page->viewData('ticks'))->toBe([['label' => 1, 'left' => '0.00'], ['label' => 5, 'left' => '100.00']])
        ->and($page->viewData('ownedTotals'))->toBe(['USD' => 2649])
        ->and($page->viewData('isFiltered'))->toBeTrue();
});

test('a set the catalogue holds only in part counts its missing cards from what is imported, and the rest is absent from the strip', function () {
    // 5 of 7 cards imported, 3 owned: the toggle can reveal 2 pockets (imported - owned, not 7 - 3), the other 2 are grey slots.
    seedSetPage(['card_count' => 7]);

    $page = Livewire::test(Show::class, ['username' => 'demo', 'setTcgdexId' => 'sv03.5']);

    expect($page->viewData('totalCount'))->toBe(7)
        ->and($page->viewData('missingCount'))->toBe(2)
        ->and(array_column($page->viewData('strip'), 'state'))->toBe(['owned', 'missing', 'owned', 'missing', 'owned', 'absent', 'absent'])
        ->and(array_column($page->viewData('strip'), 'number'))->toBe(['001', '002', '003', '004', '005', null, null]);
});

test('one imported card, owned, out of a huge set leaves nothing for the toggle to reveal', function () {
    $user = User::factory()->create(['username' => 'demo', 'name' => 'Demo']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'swsh11', 'name' => 'Lost Origin', 'card_count' => 217]);
    $card = Card::create(['tcgdex_id' => 'swsh11-123', 'set_id' => $set->id, 'local_id' => '123', 'name' => 'Gastly']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'swsh11-123', 'condition' => 'NM', 'quantity' => 1]);

    $page = Livewire::test(Show::class, ['username' => 'demo', 'setTcgdexId' => 'swsh11']);
    $states = array_count_values(array_column($page->viewData('strip'), 'state'));

    expect($page->viewData('ownedCount'))->toBe(1)
        ->and($page->viewData('totalCount'))->toBe(217)
        ->and($page->viewData('missingCount'))->toBe(0)
        ->and($states)->toBe(['absent' => 216, 'owned' => 1]);
});

test('the set code is the abbreviation, else the uppercase tcgdex id', function () {
    $seed = seedSetPage();

    expect(Livewire::test(Show::class, ['username' => 'demo', 'setTcgdexId' => 'sv03.5'])->viewData('setCode'))->toBe('MEW');

    $seed['set']->update(['abbreviation' => null]);

    expect(Livewire::test(Show::class, ['username' => 'demo', 'setTcgdexId' => 'sv03.5'])->viewData('setCode'))->toBe('SV03.5');
});

test('each owned card carries its 7-day move; a missing card carries none', function () {
    $this->freezeTime();
    $seed = seedSetPage();
    CardPriceSnapshot::create(['card_id' => $seed['cards']['003']->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDays(7), 'currency' => 'USD', 'market_minor' => 2399]);
    CardPriceSnapshot::create(['card_id' => $seed['cards']['002']->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDays(7), 'currency' => 'USD', 'market_minor' => 50]);
    app(WeekBaselines::class)->refreshAll();

    $entries = Livewire::withQueryParams(['missing' => true])->test(Show::class, ['username' => 'demo', 'setTcgdexId' => 'sv03.5'])
        ->viewData('entries')->keyBy(fn (array $e) => $e['card']->local_id);

    expect($entries['003']['weekDelta']?->deltaMinor)->toBe(100)
        ->and($entries['002']['weekDelta'])->toBeNull()
        ->and($entries['001']['weekDelta'])->toBeNull();
});

test('the two priciest owned cards take double pockets only in Value order, without a search, with more than two owned cards showing, whatever the missing toggle says', function (array $query, int $leads, ?Closure $prepare) {
    $seed = seedSetPage();
    if ($prepare !== null) {
        $prepare($seed);
    }

    expect(Livewire::withQueryParams($query)->test(Show::class, ['username' => 'demo', 'setTcgdexId' => 'sv03.5'])->viewData('leadCount'))->toBe($leads);
})->with([
    'value' => [['sort' => 'value'], 2, null],
    'value, missing shown, three owned' => [['sort' => 'value', 'missing' => true], 2, null],
    'value, searched' => [['sort' => 'value', 'search' => 'a'], 0, null],
    'number' => [[], 0, null],
    'value, one card left' => [['sort' => 'value', 'rarity' => 'Common'], 0, null],
    // Pockets do not count: two owned tiles stay single-size when the toggle adds three pockets (5 entries > 2 would have made them double).
    'value, two owned, missing shown' => [['sort' => 'value', 'missing' => true], 0, fn (array $seed) => CollectionItem::where('card_id', $seed['cards']['005']->id)->delete()],
    'value, one owned, missing shown' => [['sort' => 'value', 'missing' => true], 0, fn (array $seed) => CollectionItem::whereIn('card_id', [$seed['cards']['003']->id, $seed['cards']['005']->id])->delete()],
]);

test('Clear drops the search and the rarity, and keeps the order and the missing toggle', function () {
    seedSetPage();

    Livewire::withQueryParams(['search' => 'saur', 'rarity' => 'Common', 'sort' => 'name', 'missing' => true])
        ->test(Show::class, ['username' => 'demo', 'setTcgdexId' => 'sv03.5'])
        ->call('clearFilters')
        ->assertSet('search', '')
        ->assertSet('rarityFilter', '')
        ->assertSet('sort', 'name')
        ->assertSet('showMissing', true);
});

test('the rarity filter lists the set\'s rarities from commonest to rarest', function () {
    seedSetPage();

    $page = Livewire::test(Show::class, ['username' => 'demo', 'setTcgdexId' => 'sv03.5']);
    $html = $page->html();

    expect($page->viewData('rarities')->all())->toBe(['Common', 'Uncommon', 'Double rare'])
        ->and(strpos($html, '<option value="Common">'))->toBeLessThan(strpos($html, '<option value="Uncommon">'))
        ->and(strpos($html, '<option value="Uncommon">'))->toBeLessThan(strpos($html, '<option value="Double rare">'));
});

test('the set page costs the same number of queries for three cards as for thirty, searched or not, with or without pockets', function (array $query, int $fewShown, int $manyShown) {
    $user = User::factory()->create(['username' => 'demo']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'sv03.5', 'name' => '151', 'card_count' => 30]);
    $add = function (int $from, int $to) use ($collection, $set) {
        foreach (range($from, $to) as $i) {
            $number = sprintf('%03d', $i);
            $card = Card::create(['tcgdex_id' => "sv03.5-$number", 'set_id' => $set->id, 'local_id' => $number, 'name' => "Card $number"]);
            // Every fifth card stays unowned, so the pocket path runs when the missing cards are shown.
            if ($i % 5 !== 0) {
                CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => $card->tcgdex_id, 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 1]);
            }
            // Ten daily rows rising one cent a day: every card's 7-day move is +7.
            foreach (range(0, 9) as $d) {
                CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDays($d), 'currency' => 'USD', 'market_minor' => 1000 + $i - $d]);
            }
        }
    };
    // Measured cold both times, so a cache hit on one side cannot hide a per-card read on the other.
    $queriesFor = function () use ($query): array {
        Cache::flush();
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });
        $entries = Livewire::withQueryParams($query)->test(Show::class, ['username' => 'demo', 'setTcgdexId' => 'sv03.5'])->viewData('entries');

        return [$count, $entries];
    };

    $add(1, 3);
    app(WeekBaselines::class)->refreshAll();
    [$few, $fewEntries] = $queriesFor();
    $add(4, 30);
    app(WeekBaselines::class)->refreshAll();
    [$many, $manyEntries] = $queriesFor();

    expect($fewEntries)->toHaveCount($fewShown)
        ->and($manyEntries)->toHaveCount($manyShown)
        ->and($manyEntries->filter(fn (array $e) => $e['owned'])->every(fn (array $e) => $e['weekDelta']?->deltaMinor === 7))->toBeTrue()
        ->and($many)->toBe($few);
})->with([
    'unfiltered' => [[], 3, 24],
    'searched' => [['search' => 'Card'], 3, 24],
    'missing shown' => [['missing' => true], 3, 30],
]);

test('the set page sits on the revamp shell: Sets is current, its own stylesheets only, indexable', function () {
    seedSetPage();

    $html = $this->get('/demo/sv03.5')->assertOk()->getContent();
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);

    expect(RevampHtml::currentNav($html))->toBe('Sets')
        ->and($html)->toContain('<nav class="bar-nav" aria-label="Gallery">')
        ->and($html)->toContain('class="col-page set-page"')
        ->and($html)->toContain($manifest['resources/css/revamp.css']['file'])
        ->and($html)->toContain($manifest['resources/css/collection.css']['file'])
        ->and($html)->toContain($manifest['resources/css/set.css']['file'])
        ->and($html)->not->toContain($manifest['resources/css/app.css']['file'])
        ->and($html)->not->toContain('noindex');
});

test('the breadcrumb leads back to the sets, names the series, and marks the set as the current page', function () {
    $seed = seedSetPage();

    $html = $this->get('/demo/sv03.5')->getContent();
    expect($html)->toContain('<nav class="set-crumbs" aria-label="Breadcrumb">')
        ->and($html)->toContain('<li><a href="'.route('gallery.sets', ['username' => 'demo']).'">Sets</a></li>')
        ->and($html)->toContain('<li>Scarlet &amp; Violet</li>')
        ->and($html)->toContain('<li aria-current="page">151</li>');

    $seed['set']->update(['series' => null]);
    expect($this->get('/demo/sv03.5')->getContent())->not->toContain('<li>Scarlet');
});

test('the head is the set\'s name and logo over one summary line that starts with its code, with no stat boxes', function () {
    seedSetPage();

    $html = $this->get('/demo/sv03.5')->getContent();

    expect($html)->toContain('<h1 id="set-name" class="set-name">151</h1>')
        ->and($html)->toContain('<img class="set-logo" src="https://assets.tcgdex.net/en/sv/sv03.5/logo.png" alt="" decoding="async" data-optional>')
        ->and($html)->toContain('<p class="col-summary"><span class="set-code">MEW</span>')
        ->and(RevampHtml::summary($html))->toBe('MEW · 3 of 5 cards · $26.49 · released Sep 2023')
        ->and(RevampHtml::note($html))->toBe('')
        ->and($html)->not->toContain('Most valuable owned')
        ->and($html)->not->toContain('nw-stats');
});

test('the code in the summary is the abbreviation, else the uppercase tcgdex id', function () {
    $seed = seedSetPage();
    $seed['set']->update(['abbreviation' => null]);

    expect(RevampHtml::summary($this->get('/demo/sv03.5')->getContent()))->toStartWith('SV03.5 · 3 of 5 cards');
});

test('the summary drops the value without a price and the release without a date', function (Closure $change, string $summary) {
    $seed = seedSetPage();
    $change($seed);

    expect(RevampHtml::summary($this->get('/demo/sv03.5')->getContent()))->toBe($summary);
})->with([
    'no price' => [fn (array $seed) => CardPriceSnapshot::query()->delete(), 'MEW · 3 of 5 cards · released Sep 2023'],
    'no release date' => [fn (array $seed) => $seed['set']->update(['released_on' => null]), 'MEW · 3 of 5 cards · $26.49'],
    'neither' => [function (array $seed) {
        CardPriceSnapshot::query()->delete();
        $seed['set']->update(['released_on' => null]);
    }, 'MEW · 3 of 5 cards'],
]);

test('another currency is never added in: it moves to a note under the summary', function () {
    $seed = seedSetPage();
    CardPriceSnapshot::create(['card_id' => $seed['cards']['001']->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 1000]);

    $html = $this->get('/demo/sv03.5')->getContent();

    expect(RevampHtml::summary($html))->toBe('MEW · 3 of 5 cards · $26.49 · released Sep 2023')
        ->and(RevampHtml::note($html))->toBe('Also €10.00 priced in EUR, not added to the total.');
});

test('the completion bar is a printed strip: one slot per card in number order, the missing ones hollow', function () {
    seedSetPage();

    $html = $this->get('/demo/sv03.5')->getContent();

    expect(RevampHtml::stripSlots($html))->toBe(['owned', 'missing', 'owned', 'missing', 'owned'])
        ->and($html)->toContain('<div class="set-strip-bar" role="progressbar" aria-valuemin="0" aria-valuemax="5" aria-valuenow="3" aria-label="60% of the set collected">')
        ->and($html)->toContain('<span class="set-strip-miss" title="#002 missing"></span>')
        ->and($html)->toContain('<span class="set-strip-pct" aria-hidden="true">60%</span>')
        ->and($html)->toContain('<b style="left: 0.00%">1</b>')
        ->and($html)->toContain('<b style="left: 100.00%">5</b>');
});

test('a set the catalogue holds only in part still spans the whole set: grey, unnumbered slots for the cards it has not imported', function () {
    seedSetPage(['card_count' => 7]);

    $html = $this->get('/demo/sv03.5')->getContent();

    expect(RevampHtml::stripSlots($html))->toBe(['owned', 'missing', 'owned', 'missing', 'owned', 'absent', 'absent'])
        ->and($html)->toContain('<span class="set-strip-off" title="not in the catalogue yet"></span>')
        ->and($html)->toContain('<span class="set-strip-miss" title="#004 missing"></span>')
        ->and(RevampHtml::summary($html))->toStartWith('MEW · 3 of 7 cards');
});

test('the owner gets Edit in my collection as a plain link into the set grid, and Manage collection in the bar', function () {
    $this->seed(PermissionSeeder::class);
    $seed = seedSetPage();

    $html = $this->actingAs($seed['user'])->get('/demo/sv03.5')->assertOk()->getContent();

    expect($html)->toContain('<a class="set-edit" href="'.route('admin.sets.show', ['setTcgdexId' => 'sv03.5']).'">Edit in my collection</a>')
        ->and($html)->toContain('Manage collection');
});

test('title, description and share image stay as they were', function () {
    seedSetPage();

    $this->get('/demo/sv03.5')
        ->assertSee("151 · Demo's collection · tcg-vault")
        ->assertSee('content="3 of 5 cards from 151 (Scarlet &amp; Violet) in Demo&#039;s Pokémon TCG collection."', false)
        ->assertSee('content="https://assets.tcgdex.net/en/sv/sv03.5/003/high.webp"', false);
});
