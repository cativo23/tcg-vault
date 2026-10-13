<?php

declare(strict_types=1);

use App\Livewire\Admin\Sets;
use App\Models\User;
use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Data\SetSummaryData;
use App\Modules\Catalog\Exceptions\CatalogUnavailableException;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\Set;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use Database\Seeders\PermissionSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

function mySetsOwn(User $user, Set $set, string $localId, string $name, string $slug = 'my-collection'): Card
{
    $card = Card::create([
        'tcgdex_id' => $set->tcgdex_id.'-'.$localId, 'set_id' => $set->id,
        'local_id' => $localId, 'name' => $name,
    ]);
    $collection = Collection::withoutGlobalScopes()->firstOrCreate(
        ['user_id' => $user->id, 'slug' => $slug],
        ['name' => 'My Collection', 'is_public' => false],
    );
    CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id,
        'card_tcgdex_id' => $card->tcgdex_id, 'condition' => 'NM', 'quantity' => 1,
    ]);

    return $card;
}

test('guests are sent to log in', function () {
    $this->get('/admin/sets')->assertRedirect(route('login'));
});

test('lists the sets the owner holds a card from with owned and total counts', function () {
    $user = User::factory()->create();
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'series' => 'Mega Evolution', 'card_count' => 120]);
    mySetsOwn($user, $set, '116', 'Mega Darkrai ex');
    mySetsOwn($user, $set, '003', 'Fomantis');
    // A second copy of an owned card still counts the card once.
    CollectionItem::create([
        'collection_id' => Collection::withoutGlobalScopes()->where('user_id', $user->id)->value('id'),
        'card_id' => Card::where('tcgdex_id', 'me05-116')->value('id'),
        'card_tcgdex_id' => 'me05-116', 'condition' => 'LP', 'quantity' => 1,
    ]);

    $this->actingAs($user)->get('/admin/sets')
        ->assertOk()
        ->assertSee('Pitch Black')
        ->assertSee('2 / 120')
        ->assertSee('/admin/sets/me05', false);
});

test('works for a private collection', function () {
    $user = User::factory()->create();
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 120]);
    mySetsOwn($user, $set, '116', 'Mega Darkrai ex');
    expect(Collection::withoutGlobalScopes()->where('user_id', $user->id)->value('is_public'))->toBeFalse();

    $this->actingAs($user)->get('/admin/sets')->assertOk()->assertSee('Pitch Black');
});

test('lists only the signed-in owners sets', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $mine = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 120]);
    $theirs = Set::create(['tcgdex_id' => 'sv08', 'name' => 'Surging Sparks', 'card_count' => 191]);
    Set::create(['tcgdex_id' => 'untouched', 'name' => 'Never Added', 'card_count' => 10]);
    mySetsOwn($user, $mine, '116', 'Mega Darkrai ex');
    mySetsOwn($other, $theirs, '001', 'Exeggcute');

    $this->actingAs($user)->get('/admin/sets')
        ->assertSee('Pitch Black')
        ->assertDontSee('Surging Sparks')
        ->assertDontSee('Never Added');
});

test('falls back to the real card count when tcgdex reported none', function () {
    $user = User::factory()->create();
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    mySetsOwn($user, $set, '116', 'Mega Darkrai ex');
    Card::create(['tcgdex_id' => 'me05-003', 'set_id' => $set->id, 'local_id' => '003', 'name' => 'Fomantis']);

    $this->actingAs($user)->get('/admin/sets')->assertSee('1 / 2');
});

test('an owner with no cards sees an empty state that links to adding one', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/admin/sets')
        ->assertOk()
        ->assertSee('No sets yet')
        ->assertSee(route('admin.collection.add'), false);
});

test('shows the newest set first and puts sets with no release date last', function () {
    $user = User::factory()->create();
    $old = Set::create(['tcgdex_id' => 'base1', 'name' => 'Base Set', 'card_count' => 102, 'released_on' => '1999-01-09']);
    $new = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 120, 'released_on' => '2026-09-01']);
    $undated = Set::create(['tcgdex_id' => 'miscp', 'name' => 'Miscellaneous Promos', 'card_count' => 1]);
    mySetsOwn($user, $old, '001', 'Alakazam');
    mySetsOwn($user, $new, '116', 'Mega Darkrai ex');
    mySetsOwn($user, $undated, '001', 'Pikachu');

    $this->actingAs($user)->get('/admin/sets')
        ->assertSeeInOrder(['Pitch Black', 'Base Set', 'Miscellaneous Promos']);
});

test('falls back to the local card count when tcgdex reports zero', function () {
    $user = User::factory()->create();
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 0]);
    mySetsOwn($user, $set, '116', 'Mega Darkrai ex');
    Card::create(['tcgdex_id' => 'me05-003', 'set_id' => $set->id, 'local_id' => '003', 'name' => 'Fomantis']);

    $this->actingAs($user)->get('/admin/sets')
        ->assertOk()
        ->assertSee('1 / 2')
        ->assertDontSee('1 / 0');
});

test('a signed-in user without use-collection gets a 403 on the set pages', function () {
    $this->seed(PermissionSeeder::class);
    $user = User::factory()->create();
    $user->syncRoles([]);
    $this->actingAs($user);

    $this->get('/admin/sets')->assertForbidden();
    $this->get('/admin/sets/me05')->assertForbidden();
});

function pickerSet(string $id, string $name, ?int $count = 100, ?string $logo = null): SetSummaryData
{
    return new SetSummaryData(tcgdexId: $id, name: $name, series: null, releasedOn: null, cardCount: $count, logoUrl: $logo);
}

/** @param list<SetSummaryData> $sets */
function pickerCatalog(array $sets): void
{
    test()->mock(CardCatalogProvider::class, fn ($mock) => $mock->shouldReceive('listSets')->andReturn($sets));
}

function pickerUser(): User
{
    $user = User::factory()->create();
    test()->actingAs($user);

    return $user;
}

test('the picker is closed on load and does not ask tcgdex for the list', function () {
    pickerUser();
    test()->mock(CardCatalogProvider::class, fn ($mock) => $mock->shouldNotReceive('listSets'));

    Livewire::test(Sets::class)
        ->assertSet('pickerOpen', false)
        ->assertSeeHtml('id="add-set-trigger"')
        ->assertDontSeeHtml('role="dialog"');
});

test('opening the picker lists the sets with their card counts', function () {
    pickerUser();
    pickerCatalog([
        pickerSet('me05', 'Pitch Black', 120, 'https://assets.tcgdex.net/en/me/me05/logo.png'),
        pickerSet('miscp', 'Miscellaneous Promos', 1),
    ]);

    Livewire::test(Sets::class)
        ->call('openPicker')
        ->assertSet('pickerOpen', true)
        ->assertSee('Pitch Black')
        ->assertSee('Miscellaneous Promos')
        ->assertSeeHtml('<span class="mono">120</span>')
        ->assertSeeHtml('src="https://assets.tcgdex.net/en/me/me05/logo.png"');
});

test('the picker is an accessible dialog', function () {
    pickerUser();
    pickerCatalog([pickerSet('me05', 'Pitch Black')]);

    Livewire::test(Sets::class)
        ->call('openPicker')
        ->assertSeeHtml('role="dialog"')
        ->assertSeeHtml('aria-modal="true"')
        ->assertSeeHtml('aria-labelledby="set-picker-title"')
        ->assertSeeHtml('id="set-picker-title"')
        ->assertSeeHtml('for="set-picker-search"')
        ->assertSeeHtml('aria-label="Close"');
});

test('the picker lists the newest sets first', function () {
    pickerUser();
    pickerCatalog([pickerSet('base1', 'Base Set'), pickerSet('me05', 'Pitch Black')]);

    Livewire::test(Sets::class)->call('openPicker')->assertSeeInOrder(['Pitch Black', 'Base Set']);
});

test('the filter matches a set by name or by id, ignoring case', function () {
    pickerUser();
    pickerCatalog([pickerSet('me05', 'Pitch Black'), pickerSet('base1', 'Base Set'), pickerSet('sv08', 'Surging Sparks')]);

    Livewire::test(Sets::class)
        ->call('openPicker')
        ->set('pickerSearch', 'PITCH')
        ->assertSee('Pitch Black')
        ->assertDontSee('Base Set')
        ->set('pickerSearch', 'sv08')
        ->assertSee('Surging Sparks')
        ->assertDontSee('Pitch Black');
});

test('a filter with no match says so', function () {
    pickerUser();
    pickerCatalog([pickerSet('me05', 'Pitch Black')]);

    Livewire::test(Sets::class)
        ->call('openPicker')
        ->set('pickerSearch', 'zzz')
        ->assertSee('No sets match')
        ->assertDontSee('Pitch Black');
});

test('sets the owner already has are tagged but still selectable', function () {
    $user = pickerUser();
    $mine = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 120]);
    mySetsOwn($user, $mine, '116', 'Mega Darkrai ex');
    pickerCatalog([pickerSet('me05', 'Pitch Black'), pickerSet('base1', 'Base Set')]);

    $html = Livewire::test(Sets::class)->call('openPicker')->html();

    expect(substr_count($html, 'In My sets'))->toBe(1)
        ->and(substr_count($html, 'wire:click="chooseSet'))->toBe(2);
});

test('a failed set list shows an error with a retry, and retry recovers', function () {
    pickerUser();
    $calls = 0;
    test()->mock(CardCatalogProvider::class, fn ($mock) => $mock->shouldReceive('listSets')->andReturnUsing(
        function () use (&$calls) {
            if (++$calls === 1) {
                throw CatalogUnavailableException::forSetListing(new RuntimeException('down'));
            }

            return [pickerSet('me05', 'Pitch Black')];
        },
    ));

    Livewire::test(Sets::class)
        ->call('openPicker')
        ->assertSee("Couldn't load the set list", false)
        ->assertSeeHtml('wire:click="$refresh"')
        ->assertDontSee('Pitch Black')
        ->refresh()
        ->assertDontSee("Couldn't load the set list", false)
        ->assertSee('Pitch Black');
});

test('closing the picker clears the filter and tells the page to return focus', function () {
    pickerUser();
    pickerCatalog([pickerSet('me05', 'Pitch Black')]);

    Livewire::test(Sets::class)
        ->call('openPicker')
        ->set('pickerSearch', 'pitch')
        ->call('closePicker')
        ->assertSet('pickerOpen', false)
        ->assertSet('pickerSearch', '')
        ->assertDispatched('set-picker-closed')
        ->assertDontSeeHtml('role="dialog"');
});

test('the empty state offers the picker as well as adding a single card', function () {
    pickerUser();

    Livewire::test(Sets::class)->assertSee('No sets yet')->assertSeeHtml('wire:click="openPicker"');
});
