<?php

declare(strict_types=1);

use App\Jobs\ImportSetJob;
use App\Livewire\Admin\Sets;
use App\Models\User;
use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Data\SetSummaryData;
use App\Modules\Catalog\Exceptions\CatalogUnavailableException;
use App\Modules\Catalog\Exceptions\InvalidTcgdexIdException;
use App\Modules\Catalog\Exceptions\SetNotFoundException;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\Set;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use Database\Seeders\PermissionSeeder;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
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

function chooseCatalog(?Closure $findSet = null): void
{
    test()->mock(CardCatalogProvider::class, function ($mock) use ($findSet) {
        $mock->shouldReceive('listSets')->andReturn([pickerSet('me05', 'Pitch Black', 84)]);

        if ($findSet === null) {
            $mock->shouldNotReceive('findSet');
        } else {
            $findSet($mock);
        }
    });
}

test('choosing a set tcgdex has but we never stored creates it, queues the import and opens the grid', function () {
    pickerUser();
    Queue::fake();
    chooseCatalog(fn ($mock) => $mock->shouldReceive('findSet')->once()->with('me05')->andReturn(
        new SetSummaryData(tcgdexId: 'me05', name: 'Pitch Black', series: 'Mega Evolution', releasedOn: null, cardCount: 84, logoUrl: null),
    ));

    Livewire::test(Sets::class)
        ->call('openPicker')
        ->call('chooseSet', 'me05')
        ->assertRedirect(route('admin.sets.show', ['setTcgdexId' => 'me05']));

    expect(Set::where('tcgdex_id', 'me05')->exists())->toBeTrue();
    Queue::assertPushed(ImportSetJob::class, fn (ImportSetJob $job) => $job->setTcgdexId === 'me05');
});

test('choosing a fully imported set opens the grid without queueing an import', function () {
    pickerUser();
    Queue::fake();
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 2]);
    Card::create(['tcgdex_id' => 'me05-001', 'set_id' => $set->id, 'local_id' => '001', 'name' => 'One']);
    Card::create(['tcgdex_id' => 'me05-002', 'set_id' => $set->id, 'local_id' => '002', 'name' => 'Two']);
    chooseCatalog();

    Livewire::test(Sets::class)
        ->call('chooseSet', 'me05')
        ->assertRedirect(route('admin.sets.show', ['setTcgdexId' => 'me05']));

    Queue::assertNothingPushed();
});

test('choosing a partly imported set queues the import again', function (?int $cardCount) {
    pickerUser();
    Queue::fake();
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => $cardCount]);
    Card::create(['tcgdex_id' => 'me05-001', 'set_id' => $set->id, 'local_id' => '001', 'name' => 'One']);
    chooseCatalog();

    Livewire::test(Sets::class)
        ->call('chooseSet', 'me05')
        ->assertRedirect(route('admin.sets.show', ['setTcgdexId' => 'me05']));

    Queue::assertPushed(ImportSetJob::class, 1);
})->with([
    'short of the announced total' => [5],
    'no announced total' => [null],
    'a total of zero' => [0],
]);

test('a set tcgdex rejects shows an error, opens nothing and queues nothing', function (Closure $make) {
    pickerUser();
    Queue::fake();
    $failure = $make();
    chooseCatalog(fn ($mock) => $mock->shouldReceive('findSet')->once()->andThrow($failure));

    Livewire::test(Sets::class)
        ->call('openPicker')
        ->call('chooseSet', 'me05')
        ->assertNoRedirect()
        ->assertSet('pickerOpen', true)
        ->assertSee("That set isn't available from tcgdex.");

    expect(Set::count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'not found' => [fn () => SetNotFoundException::forTcgdexId('me05')],
    'invalid id' => [fn () => InvalidTcgdexIdException::forId('me05')],
]);

test('an id that is not in the tcgdex set list never reaches tcgdex', function (string $id) {
    $user = pickerUser();
    Queue::fake();
    chooseCatalog();

    Livewire::test(Sets::class)
        ->call('openPicker')
        ->call('chooseSet', $id)
        ->assertNoRedirect()
        ->assertSee("That set isn't available from tcgdex.");

    expect(Set::count())->toBe(0)
        ->and(RateLimiter::attempts('add-set:'.$user->id))->toBe(1);
    Queue::assertNothingPushed();
})->with([
    'unknown id' => ['nope'],
    'malformed shape' => ['../../etc/passwd'],
    'empty' => [''],
    'too long' => [str_repeat('a', 200)],
]);

test('an unreachable set list while choosing shows a retryable error and never redirects', function () {
    pickerUser();
    Queue::fake();
    test()->mock(CardCatalogProvider::class, function ($mock) {
        $mock->shouldReceive('listSets')->andThrow(CatalogUnavailableException::forSetListingBackoff());
        $mock->shouldNotReceive('findSet');
    });

    Livewire::test(Sets::class)
        ->set('pickerOpen', true)
        ->call('chooseSet', 'me05')
        ->assertNoRedirect()
        ->assertSee("Couldn't reach the card catalog");

    Queue::assertNothingPushed();
});

test('a set list failure window while choosing is not reported again', function () {
    pickerUser();
    Exceptions::fake();
    test()->mock(CardCatalogProvider::class, function ($mock) {
        $mock->shouldReceive('listSets')->andThrow(CatalogUnavailableException::forSetListingBackoff());
        $mock->shouldNotReceive('findSet');
    });

    Livewire::test(Sets::class)
        ->set('pickerOpen', true)
        ->call('chooseSet', 'me05')
        ->assertSee("Couldn't reach the card catalog");

    Exceptions::assertReportedCount(0);
});

test('a catalog outage while choosing shows a retryable error and never redirects', function () {
    pickerUser();
    Queue::fake();
    chooseCatalog(fn ($mock) => $mock->shouldReceive('findSet')->once()->andThrow(new RuntimeException('tcgdex down')));

    Livewire::test(Sets::class)
        ->call('openPicker')
        ->call('chooseSet', 'me05')
        ->assertNoRedirect()
        ->assertSee("Couldn't reach the card catalog");

    expect(Set::count())->toBe(0);
    Queue::assertNothingPushed();
});

test('choosing is limited to ten sets a minute per user', function () {
    $user = pickerUser();
    Queue::fake();
    for ($i = 0; $i < 10; $i++) {
        RateLimiter::hit('add-set:'.$user->id, 60);
    }
    chooseCatalog();

    Livewire::test(Sets::class)
        ->call('openPicker')
        ->call('chooseSet', 'me05')
        ->assertNoRedirect()
        ->assertSee('Slow down');

    expect(Set::count())->toBe(0);
    Queue::assertNothingPushed();
});

test("another user's choices do not count against this user's limit", function () {
    $user = pickerUser();
    $other = User::factory()->create();
    Queue::fake();
    for ($i = 0; $i < 10; $i++) {
        RateLimiter::hit('add-set:'.$other->id, 60);
    }
    Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 0]);
    chooseCatalog();

    Livewire::test(Sets::class)->call('chooseSet', 'me05')->assertRedirect();

    expect(RateLimiter::attempts('add-set:'.$user->id))->toBe(1);
});

test('the twenty-first new set in a day is refused without asking tcgdex', function () {
    $user = pickerUser();
    Queue::fake();
    $key = 'add-set-new:'.$user->id.':'.now()->toDateString();
    for ($i = 0; $i < Sets::NEW_SETS_LIMIT_PER_DAY; $i++) {
        RateLimiter::hit($key, 86400);
    }
    chooseCatalog();

    Livewire::test(Sets::class)
        ->call('openPicker')
        ->call('chooseSet', 'me05')
        ->assertNoRedirect()
        ->assertSee("You've started 20 new sets today");

    expect(Set::count())->toBe(0);
    Queue::assertNothingPushed();
});

test('creating a set counts toward the daily cap and an existing set does not', function () {
    $user = pickerUser();
    Queue::fake();
    $key = 'add-set-new:'.$user->id.':'.now()->toDateString();
    Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 0]);
    chooseCatalog();

    Livewire::test(Sets::class)->call('chooseSet', 'me05')->assertRedirect();

    expect(RateLimiter::attempts($key))->toBe(0);

    Set::query()->delete();
    chooseCatalog(fn ($mock) => $mock->shouldReceive('findSet')->once()->andReturn(
        new SetSummaryData(tcgdexId: 'me05', name: 'Pitch Black', series: null, releasedOn: null, cardCount: 84, logoUrl: null),
    ));

    Livewire::test(Sets::class)->call('chooseSet', 'me05')->assertRedirect();

    expect(RateLimiter::attempts($key))->toBe(1);
});

test('an existing set can still be chosen once the daily cap is reached', function () {
    $user = pickerUser();
    Queue::fake();
    $key = 'add-set-new:'.$user->id.':'.now()->toDateString();
    for ($i = 0; $i < Sets::NEW_SETS_LIMIT_PER_DAY; $i++) {
        RateLimiter::hit($key, 86400);
    }
    Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 0]);
    chooseCatalog();

    Livewire::test(Sets::class)->call('chooseSet', 'me05')->assertRedirect();
});

test('the daily cap resets the next day', function () {
    $user = pickerUser();
    Queue::fake();
    $key = 'add-set-new:'.$user->id.':'.now()->toDateString();
    for ($i = 0; $i < Sets::NEW_SETS_LIMIT_PER_DAY; $i++) {
        RateLimiter::hit($key, 86400);
    }
    chooseCatalog(fn ($mock) => $mock->shouldReceive('findSet')->once()->andReturn(
        new SetSummaryData(tcgdexId: 'me05', name: 'Pitch Black', series: null, releasedOn: null, cardCount: 84, logoUrl: null),
    ));

    $this->travel(1)->days();

    Livewire::test(Sets::class)->call('chooseSet', 'me05')->assertRedirect();
});

test('a failed choice still counts against the limit', function () {
    $user = pickerUser();
    chooseCatalog(fn ($mock) => $mock->shouldReceive('findSet')->once()->andThrow(SetNotFoundException::forTcgdexId('me05')));

    Livewire::test(Sets::class)->call('chooseSet', 'me05');

    expect(RateLimiter::attempts('add-set:'.$user->id))->toBe(1);
});

test('rendering the picker during a failure backoff reports the outage once, not on every render', function () {
    pickerUser();
    Exceptions::fake();
    $calls = 0;
    test()->mock(CardCatalogProvider::class, fn ($mock) => $mock->shouldReceive('listSets')->andReturnUsing(
        function () use (&$calls) {
            throw ++$calls === 1
                ? CatalogUnavailableException::forSetListing(new RuntimeException('down'))
                : CatalogUnavailableException::forSetListingBackoff();
        },
    ));

    Livewire::test(Sets::class)
        ->call('openPicker')
        ->assertSee('tcgdex did not answer')
        ->refresh()
        ->refresh()
        ->assertSee('Try again in a minute');

    Exceptions::assertReportedCount(1);
});

test('the picker error copy stays plain when the outage is not a backoff', function () {
    pickerUser();
    test()->mock(CardCatalogProvider::class, fn ($mock) => $mock->shouldReceive('listSets')->andThrow(
        CatalogUnavailableException::forSetListing(new RuntimeException('down')),
    ));

    Livewire::test(Sets::class)->call('openPicker')->assertDontSee('Try again in a minute');
});

test('a failed import dispatch is reported, keeps the picker open and does not redirect', function () {
    pickerUser();
    Exceptions::fake();
    chooseCatalog(fn ($mock) => $mock->shouldReceive('findSet')->once()->andReturn(
        new SetSummaryData(tcgdexId: 'me05', name: 'Pitch Black', series: null, releasedOn: null, cardCount: 84, logoUrl: null),
    ));
    test()->mock(Dispatcher::class, fn ($mock) => $mock->shouldReceive('dispatch')->andThrow(new RuntimeException('queue down')));

    Livewire::test(Sets::class)
        ->call('openPicker')
        ->call('chooseSet', 'me05')
        ->assertNoRedirect()
        ->assertSet('pickerOpen', true)
        ->assertSet('pickerError', "Couldn't start the import — try again");

    Exceptions::assertReportedCount(1);
});

test('the picker status line says how many sets are shown of how many', function () {
    pickerUser();
    pickerCatalog([pickerSet('me05', 'Pitch Black'), pickerSet('base1', 'Base Set'), pickerSet('sv08', 'Surging Sparks')]);

    Livewire::test(Sets::class)
        ->call('openPicker')
        ->assertSee('3 of 3 sets')
        ->set('pickerSearch', 'base')
        ->assertSee('1 of 3 sets');
});

test('the picker keeps its adding live region mounted and never disables a row', function () {
    pickerUser();
    pickerCatalog([pickerSet('me05', 'Pitch Black')]);

    Livewire::test(Sets::class)
        ->call('openPicker')
        ->assertSeeHtml('id="set-picker-adding"')
        ->assertDontSeeHtml('wire:loading.attr="disabled"');
});

test('an over-long filter is capped so it cannot be used to bloat requests', function () {
    pickerUser();
    pickerCatalog([pickerSet('me05', 'Pitch Black')]);

    Livewire::test(Sets::class)
        ->call('openPicker')
        ->set('pickerSearch', str_repeat('a', 500))
        ->assertSet('pickerSearch', str_repeat('a', 100));
});
