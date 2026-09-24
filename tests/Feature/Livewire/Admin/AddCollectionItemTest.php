<?php

declare(strict_types=1);

use App\Livewire\Admin\AddCollectionItem;
use App\Models\User;
use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Data\CardDetailData;
use App\Modules\Catalog\Data\CardSummaryData;
use App\Modules\Catalog\Data\PriceEntryData;
use App\Modules\Catalog\Data\SetSummaryData;
use App\Modules\Catalog\Exceptions\CardNotFoundException;
use App\Modules\Catalog\Exceptions\MalformedCatalogResponseException;
use App\Modules\Catalog\Models\Set;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\LaravelData\DataCollection;

// The test queue connection is 'sync', so a successful save() would
// otherwise dispatch ImportSetJob inline against these tests' provider
// mocks (which never stub listSetCardIds()) — not any of these tests'
// concern; CollectionServiceTest and ImportSetJobTest cover that job.
beforeEach(fn () => Queue::fake());

test('a logged-in admin can search tcgdex and see results', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('searchCardsByName')->with('Darkrai', null)->andReturn([
        new CardSummaryData(tcgdexId: 'me05-116', setTcgdexId: 'me05', localId: '116', name: 'Mega Darkrai ex', imageUrl: 'https://assets.tcgdex.net/en/me/me05/116/high.webp'),
    ]);
    $this->app->instance(CardCatalogProvider::class, $provider);

    Livewire::test(AddCollectionItem::class)
        ->set('search', 'Darkrai')
        ->call('runSearch')
        ->assertSet('results.0.name', 'Mega Darkrai ex');
});

test('the search box shows a loading indicator while a debounced search is in flight', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $html = Livewire::test(AddCollectionItem::class)->html();

    expect($html)->toContain('wire:loading')
        ->toContain('wire:target="search,runSearch"');
});

test('previous results dim instead of sitting unchanged while a new search is in flight', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('searchCardsByName')->with('Darkrai', null)->andReturn([
        new CardSummaryData(tcgdexId: 'me05-116', setTcgdexId: 'me05', localId: '116', name: 'Mega Darkrai ex', imageUrl: null),
    ]);
    $this->app->instance(CardCatalogProvider::class, $provider);

    $html = Livewire::test(AddCollectionItem::class)
        ->set('search', 'Darkrai')
        ->call('runSearch')
        ->html();

    // Without this, retyping/pasting a new query leaves the previous
    // query's tiles looking identical to a fresh, current answer for as
    // long as the request takes — a visible spinner elsewhere on the
    // page isn't enough to connect the two.
    expect($html)->toContain('wire:loading.class="opacity-40"');
    expect($html)->toContain('Mega Darkrai ex');
});

test('a result whose set is already synced locally shows the real set name, not the raw tcgdex code', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    Set::create(['tcgdex_id' => 'sv02', 'name' => 'Paldea Evolved']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('searchCardsByName')->with('Pikachu', null)->andReturn([
        new CardSummaryData(tcgdexId: 'sv02-062', setTcgdexId: 'sv02', localId: '062', name: 'Pikachu', imageUrl: null),
    ]);
    $this->app->instance(CardCatalogProvider::class, $provider);

    Livewire::test(AddCollectionItem::class)
        ->set('search', 'Pikachu')
        ->call('runSearch')
        ->assertSee('Paldea Evolved');
});

test('a result whose set is not synced locally falls back to the raw tcgdex set code', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('searchCardsByName')->with('Pikachu', null)->andReturn([
        new CardSummaryData(tcgdexId: 'swsh4-043', setTcgdexId: 'swsh4', localId: '043', name: 'Pikachu', imageUrl: null),
    ]);
    $this->app->instance(CardCatalogProvider::class, $provider);

    Livewire::test(AddCollectionItem::class)
        ->set('search', 'Pikachu')
        ->call('runSearch')
        ->assertSee('swsh4');
});

test('a logged-in admin can select a result and save it to the collection', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('searchCardsByName')->andReturn([
        new CardSummaryData(tcgdexId: 'me05-116', setTcgdexId: 'me05', localId: '116', name: 'Mega Darkrai ex', imageUrl: null),
    ]);
    $provider->shouldReceive('findCard')->with('me05-116')->andReturn(new CardDetailData(
        tcgdexId: 'me05-116', setTcgdexId: 'me05', localId: '116', name: 'Mega Darkrai ex',
        rarity: 'SIR', variants: [], officialImageUrl: null,
        prices: new DataCollection(PriceEntryData::class, []), raw: [],
    ));
    $provider->shouldReceive('findSet')->with('me05')->andReturn(new SetSummaryData(
        tcgdexId: 'me05', name: 'Pitch Black', series: null, releasedOn: null, cardCount: null, logoUrl: null,
    ));
    $this->app->instance(CardCatalogProvider::class, $provider);

    Livewire::test(AddCollectionItem::class, ['collectionId' => $collection->id])
        ->set('search', 'Darkrai')
        ->call('runSearch')
        ->call('selectCard', 'me05-116')
        ->set('rows.0.condition', 'NM')
        ->set('rows.0.quantity', 1)
        ->call('save')
        ->assertRedirect();

    expect(CollectionItem::where('card_tcgdex_id', 'me05-116')->exists())->toBeTrue();
});

test('an uploaded photo is stored and its path saved on the item', function () {
    Storage::fake('collection-photos');
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->andReturn(new CardDetailData(
        tcgdexId: 'me05-116', setTcgdexId: 'me05', localId: '116', name: 'Mega Darkrai ex',
        rarity: 'SIR', variants: [], officialImageUrl: null,
        prices: new DataCollection(PriceEntryData::class, []), raw: [],
    ));
    $provider->shouldReceive('findSet')->andReturn(new SetSummaryData(
        tcgdexId: 'me05', name: 'Pitch Black', series: null, releasedOn: null, cardCount: null, logoUrl: null,
    ));
    $this->app->instance(CardCatalogProvider::class, $provider);

    $component = Livewire::test(AddCollectionItem::class, ['collectionId' => $collection->id])
        ->call('selectCard', 'me05-116')
        ->call('toggleRowDetails', 0);

    // A real file input, not just a bound property with no way to reach
    // it from the UI — this is what makes the assertions below a genuine
    // regression test rather than one exercising dead wiring.
    expect($component->html())->toContain('wire:model="rows.0.photo"')
        ->toContain('type="file"');

    $component
        ->set('rows.0.condition', 'NM')
        ->set('rows.0.photo', UploadedFile::fake()->image('card.jpg'))
        ->call('save');

    $item = CollectionItem::where('card_tcgdex_id', 'me05-116')->firstOrFail();
    expect($item->photo_path)->not->toBeNull();
    Storage::disk('collection-photos')->assertExists(basename($item->photo_path));
});

test('selecting a card narrows the variant dropdown to what that card actually has priced', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me05-116')->andReturn(new CardDetailData(
        tcgdexId: 'me05-116', setTcgdexId: 'me05', localId: '116', name: 'Mega Darkrai ex',
        rarity: 'SIR', variants: [], officialImageUrl: null,
        prices: new DataCollection(PriceEntryData::class, [
            new PriceEntryData(source: 'tcgplayer', variant: 'holofoil', currency: 'USD', marketMinor: 1000, lowMinor: 800, trendMinor: 900, sourceUpdatedAt: null, raw: []),
            new PriceEntryData(source: 'cardmarket', variant: 'holofoil', currency: 'EUR', marketMinor: 900, lowMinor: 700, trendMinor: 800, sourceUpdatedAt: null, raw: []),
        ]),
        raw: [],
    ));
    $this->app->instance(CardCatalogProvider::class, $provider);

    Livewire::test(AddCollectionItem::class)
        ->call('selectCard', 'me05-116')
        ->assertSet('availableVariants', ['holofoil'])
        ->assertSet('rows.0.variant', 'holofoil');
});

test('selecting a card uses its own print flags, not just synced pricing coverage', function () {
    // A card that is normal + reverse-holofoil with no straight holo
    // print, but whose only synced price is cardmarket's 'holofoil' row
    // (mislabeled reverse-holo), must still offer the correct variants.
    $user = User::factory()->create();
    $this->actingAs($user);
    Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me03-068')->andReturn(new CardDetailData(
        tcgdexId: 'me03-068', setTcgdexId: 'me03', localId: '068', name: 'Antique Jaw Fossil',
        rarity: 'Common',
        variants: ['holo' => false, 'normal' => true, 'wPromo' => false, 'reverse' => true, 'firstEdition' => false],
        officialImageUrl: null,
        prices: new DataCollection(PriceEntryData::class, [
            new PriceEntryData(source: 'cardmarket', variant: 'default', currency: 'EUR', marketMinor: 4, lowMinor: 2, trendMinor: 3, sourceUpdatedAt: null, raw: []),
            new PriceEntryData(source: 'cardmarket', variant: 'holofoil', currency: 'EUR', marketMinor: 9, lowMinor: 2, trendMinor: 13, sourceUpdatedAt: null, raw: []),
        ]),
        raw: [],
    ));
    $this->app->instance(CardCatalogProvider::class, $provider);

    Livewire::test(AddCollectionItem::class)
        ->call('selectCard', 'me03-068')
        ->assertSet('availableVariants', ['normal', 'reverse-holofoil']);
});

test('selecting a card falls back to the full known variant list when the catalog lookup fails', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me05-116')->andThrow(
        CardNotFoundException::forTcgdexId('me05-116'),
    );
    $this->app->instance(CardCatalogProvider::class, $provider);

    Livewire::test(AddCollectionItem::class)
        ->call('selectCard', 'me05-116')
        ->assertSet('availableVariants', ['normal', 'holofoil', 'reverse-holofoil'])
        ->assertSet('rows.0.variant', null);
});

test('a malformed catalog search response shows a friendly error instead of crashing', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('searchCardsByName')->with('Darkrai', null)->andThrow(
        new MalformedCatalogResponseException('Malformed tcgdex search response for query [Darkrai]: response body is not a JSON array.'),
    );
    $this->app->instance(CardCatalogProvider::class, $provider);

    Livewire::test(AddCollectionItem::class)
        ->set('search', 'Darkrai')
        ->call('runSearch')
        ->assertHasErrors('search')
        ->assertSet('results', []);
});

test('a full page of results signals there might be more, without fetching them yet', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $firstPage = array_map(
        fn (int $i) => new CardSummaryData(tcgdexId: "sv02-{$i}", setTcgdexId: 'sv02', localId: (string) $i, name: 'Pikachu', imageUrl: null),
        range(1, 24),
    );

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('searchCardsByName')->with('Pikachu', null)->once()->andReturn($firstPage);
    $this->app->instance(CardCatalogProvider::class, $provider);

    Livewire::test(AddCollectionItem::class)
        ->set('search', 'Pikachu')
        ->call('runSearch')
        ->assertCount('results', 24)
        ->assertSet('hasMoreResults', true);
});

test('a partial page of results means there is nothing more to load', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('searchCardsByName')->with('Darkrai', null)->once()->andReturn([
        new CardSummaryData(tcgdexId: 'me05-116', setTcgdexId: 'me05', localId: '116', name: 'Mega Darkrai ex', imageUrl: null),
    ]);
    $this->app->instance(CardCatalogProvider::class, $provider);

    Livewire::test(AddCollectionItem::class)
        ->set('search', 'Darkrai')
        ->call('runSearch')
        ->assertSet('hasMoreResults', false);
});

test('loading more appends the next page instead of replacing what is already shown', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $firstPage = array_map(
        fn (int $i) => new CardSummaryData(tcgdexId: "sv02-{$i}", setTcgdexId: 'sv02', localId: (string) $i, name: 'Pikachu', imageUrl: null),
        range(1, 24),
    );
    $secondPage = [
        new CardSummaryData(tcgdexId: 'swsh4-043', setTcgdexId: 'swsh4', localId: '043', name: 'Pikachu', imageUrl: null),
    ];

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('searchCardsByName')->with('Pikachu', null)->once()->andReturn($firstPage);
    $provider->shouldReceive('searchCardsByName')->with('Pikachu', null, 2)->once()->andReturn($secondPage);
    $this->app->instance(CardCatalogProvider::class, $provider);

    Livewire::test(AddCollectionItem::class)
        ->set('search', 'Pikachu')
        ->call('runSearch')
        ->assertCount('results', 24)
        ->call('loadMoreResults')
        ->assertCount('results', 25)
        ->assertSet('results.24.tcgdexId', 'swsh4-043')
        ->assertSet('hasMoreResults', false);
});

test('a card only visible after loading more can still be selected', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $firstPage = array_map(
        fn (int $i) => new CardSummaryData(tcgdexId: "sv02-{$i}", setTcgdexId: 'sv02', localId: (string) $i, name: 'Pikachu', imageUrl: null),
        range(1, 24),
    );
    $secondPage = [
        new CardSummaryData(tcgdexId: 'swsh4-043', setTcgdexId: 'swsh4', localId: '043', name: 'Pikachu', imageUrl: null),
    ];

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('searchCardsByName')->with('Pikachu', null)->once()->andReturn($firstPage);
    $provider->shouldReceive('searchCardsByName')->with('Pikachu', null, 2)->once()->andReturn($secondPage);
    $provider->shouldReceive('findCard')->with('swsh4-043')->andThrow(
        CardNotFoundException::forTcgdexId('swsh4-043'),
    );
    $this->app->instance(CardCatalogProvider::class, $provider);

    Livewire::test(AddCollectionItem::class)
        ->set('search', 'Pikachu')
        ->call('runSearch')
        ->call('loadMoreResults')
        ->call('selectCard', 'swsh4-043')
        ->assertSet('selectedTcgdexId', 'swsh4-043')
        ->assertSet('selectedName', 'Pikachu');
});

test('starting a new search resets back to the first page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $firstPage = array_map(
        fn (int $i) => new CardSummaryData(tcgdexId: "sv02-{$i}", setTcgdexId: 'sv02', localId: (string) $i, name: 'Pikachu', imageUrl: null),
        range(1, 24),
    );

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('searchCardsByName')->with('Pikachu', null)->once()->andReturn($firstPage);
    $provider->shouldReceive('searchCardsByName')->with('Pikachu', null, 2)->once()->andReturn([
        new CardSummaryData(tcgdexId: 'swsh4-043', setTcgdexId: 'swsh4', localId: '043', name: 'Pikachu', imageUrl: null),
    ]);
    // A brand-new search for a different name must re-query page 1, not
    // resume from whatever page the previous search's "load more" reached.
    $provider->shouldReceive('searchCardsByName')->with('Raichu', null)->once()->andReturn([
        new CardSummaryData(tcgdexId: 'sv02-100', setTcgdexId: 'sv02', localId: '100', name: 'Raichu', imageUrl: null),
    ]);
    $this->app->instance(CardCatalogProvider::class, $provider);

    Livewire::test(AddCollectionItem::class)
        ->set('search', 'Pikachu')
        ->call('runSearch')
        ->call('loadMoreResults')
        ->assertSet('searchPage', 2)
        ->set('search', 'Raichu')
        ->call('runSearch')
        ->assertSet('searchPage', 1)
        ->assertCount('results', 1);
});

test('loading more does nothing when there is no active search, instead of calling tcgdex with an empty query', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldNotReceive('searchCardsByName');
    $this->app->instance(CardCatalogProvider::class, $provider);

    Livewire::test(AddCollectionItem::class)
        ->call('loadMoreResults')
        ->assertSet('results', []);
});

test('loading more does nothing once hasMoreResults is false, even if called directly', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('searchCardsByName')->with('Darkrai', null)->once()->andReturn([
        new CardSummaryData(tcgdexId: 'me05-116', setTcgdexId: 'me05', localId: '116', name: 'Mega Darkrai ex', imageUrl: null),
    ]);
    // Never a second call — the component's own action, not just the UI's
    // "Load more" button being hidden, must be what stops further fetching.
    $provider->shouldNotReceive('searchCardsByName')->with('Darkrai', null, Mockery::any());
    $this->app->instance(CardCatalogProvider::class, $provider);

    Livewire::test(AddCollectionItem::class)
        ->set('search', 'Darkrai')
        ->call('runSearch')
        ->assertSet('hasMoreResults', false)
        ->call('loadMoreResults')
        ->assertCount('results', 1);
});

test('loading more stops at a hard ceiling even if a client calls it directly, over and over', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $fullPage = fn () => array_map(
        fn (int $i) => new CardSummaryData(tcgdexId: "sv02-{$i}", setTcgdexId: 'sv02', localId: (string) $i, name: 'Pikachu', imageUrl: null),
        range(1, 24),
    );

    $provider = Mockery::mock(CardCatalogProvider::class);
    // First page (runSearch) + enough further pages to reach the ceiling,
    // never beyond it — bounds real outbound calls to tcgdex regardless
    // of how many times a client invokes the action.
    $provider->shouldReceive('searchCardsByName')->with('Pikachu', null)->once()->andReturn($fullPage());
    $provider->shouldReceive('searchCardsByName')->with('Pikachu', null, Mockery::any())->andReturn($fullPage());
    $this->app->instance(CardCatalogProvider::class, $provider);

    $component = Livewire::test(AddCollectionItem::class)
        ->set('search', 'Pikachu')
        ->call('runSearch');

    // Calling far past any plausible legitimate "keep scrolling" count.
    for ($i = 0; $i < 30; $i++) {
        $component->call('loadMoreResults');
    }

    expect(count($component->get('results')))->toBeLessThanOrEqual(240);
    $component->assertSet('hasMoreResults', false);
});

test('a stale search error clears once a later search succeeds', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('searchCardsByName')->with('Darkrai', null)->once()->andThrow(
        new MalformedCatalogResponseException('Malformed tcgdex search response for query [Darkrai]: response body is not a JSON array.'),
    );
    $provider->shouldReceive('searchCardsByName')->with('Pikachu', null)->once()->andReturn([
        new CardSummaryData(tcgdexId: 'me05-116', setTcgdexId: 'me05', localId: '116', name: 'Mega Darkrai ex', imageUrl: null),
    ]);
    $this->app->instance(CardCatalogProvider::class, $provider);

    $component = Livewire::test(AddCollectionItem::class)
        ->set('search', 'Darkrai')
        ->call('runSearch')
        ->assertHasErrors('search');

    $component->set('search', 'Pikachu')
        ->call('runSearch')
        ->assertHasNoErrors('search');
});

test('the set dropdown lists only locally synced sets, sorted by name', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    Set::create(['tcgdex_id' => 'sv02', 'name' => 'Paldea Evolved']);
    Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);

    Livewire::test(AddCollectionItem::class)
        ->assertSetStrict('availableSets', ['sv02' => 'Paldea Evolved', 'me05' => 'Pitch Black']);
});

test('picking a set narrows the search to that set', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    Set::create(['tcgdex_id' => 'sv02', 'name' => 'Paldea Evolved']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('searchCardsByName')->with('Pikachu', 'sv02')->once()->andReturn([
        new CardSummaryData(tcgdexId: 'sv02-062', setTcgdexId: 'sv02', localId: '062', name: 'Pikachu', imageUrl: null),
    ]);
    $this->app->instance(CardCatalogProvider::class, $provider);

    Livewire::test(AddCollectionItem::class)
        ->set('search', 'Pikachu')
        ->set('setFilter', 'sv02')
        ->assertSet('results.0.tcgdexId', 'sv02-062');
});

test('changing the set filter re-runs the current search immediately', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    Set::create(['tcgdex_id' => 'sv02', 'name' => 'Paldea Evolved']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('searchCardsByName')->with('Pikachu', null)->once()->andReturn([
        new CardSummaryData(tcgdexId: 'me05-999', setTcgdexId: 'me05', localId: '999', name: 'Pikachu', imageUrl: null),
    ]);
    $provider->shouldReceive('searchCardsByName')->with('Pikachu', 'sv02')->once()->andReturn([
        new CardSummaryData(tcgdexId: 'sv02-062', setTcgdexId: 'sv02', localId: '062', name: 'Pikachu', imageUrl: null),
    ]);
    $this->app->instance(CardCatalogProvider::class, $provider);

    Livewire::test(AddCollectionItem::class)
        ->set('search', 'Pikachu')
        ->call('runSearch')
        ->assertSet('results.0.tcgdexId', 'me05-999')
        ->set('setFilter', 'sv02')
        ->assertSet('results.0.tcgdexId', 'sv02-062');
});

test('leaving the set filter on "All sets" behaves exactly like today\'s unfiltered search', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('searchCardsByName')->with('Pikachu', null)->once()->andReturn([
        new CardSummaryData(tcgdexId: 'me05-999', setTcgdexId: 'me05', localId: '999', name: 'Pikachu', imageUrl: null),
    ]);
    $this->app->instance(CardCatalogProvider::class, $provider);

    Livewire::test(AddCollectionItem::class)
        ->assertSet('setFilter', null)
        ->set('search', 'Pikachu')
        ->call('runSearch')
        ->assertSet('results.0.tcgdexId', 'me05-999');
});

test('picking "All sets" after a real set sends an empty string over the wire, which is normalized back to null', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    Set::create(['tcgdex_id' => 'sv02', 'name' => 'Paldea Evolved']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('searchCardsByName')->with('Pikachu', 'sv02')->once()->andReturn([
        new CardSummaryData(tcgdexId: 'sv02-062', setTcgdexId: 'sv02', localId: '062', name: 'Pikachu', imageUrl: null),
    ]);
    $provider->shouldReceive('searchCardsByName')->with('Pikachu', null)->once()->andReturn([
        new CardSummaryData(tcgdexId: 'me05-999', setTcgdexId: 'me05', localId: '999', name: 'Pikachu', imageUrl: null),
    ]);
    $this->app->instance(CardCatalogProvider::class, $provider);

    Livewire::test(AddCollectionItem::class)
        ->set('search', 'Pikachu')
        ->set('setFilter', 'sv02')
        ->assertSet('results.0.tcgdexId', 'sv02-062')
        // A real <select>'s "All sets" option (value="") round-trips as ''
        // over the wire, not null — this is the exact value Livewire sends,
        // not the null a test could set directly and accidentally pass.
        ->set('setFilter', '')
        ->assertSet('setFilter', null)
        ->assertSet('results.0.tcgdexId', 'me05-999');
});

test('a brand-new user with no Collection row can save a card, which creates one on demand', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    // Deliberately no Collection::factory() call here — this is the case
    // that used to hit Collection::firstOrFail() and 404 on first save.

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->andReturn(new CardDetailData(
        tcgdexId: 'me05-116', setTcgdexId: 'me05', localId: '116', name: 'Mega Darkrai ex',
        rarity: 'SIR', variants: [], officialImageUrl: null,
        prices: new DataCollection(PriceEntryData::class, []), raw: [],
    ));
    $provider->shouldReceive('findSet')->andReturn(new SetSummaryData(
        tcgdexId: 'me05', name: 'Pitch Black', series: null, releasedOn: null, cardCount: null, logoUrl: null,
    ));
    $this->app->instance(CardCatalogProvider::class, $provider);

    Livewire::test(AddCollectionItem::class)
        ->call('selectCard', 'me05-116')
        ->set('rows.0.condition', 'NM')
        ->set('rows.0.quantity', 1)
        ->call('save')
        ->assertRedirect();

    $collection = Collection::where('user_id', $user->id)->where('slug', 'my-collection')->first();
    expect($collection)->not->toBeNull();
    expect($collection->name)->toBe('My Collection');
    expect(CollectionItem::where('card_tcgdex_id', 'me05-116')->where('collection_id', $collection->id)->exists())->toBeTrue();
});

test('a user cannot save a card into another users collection by passing its ID (IDOR)', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $this->actingAs($user);
    $otherUsersCollection = Collection::factory()->for($otherUser)->create(['name' => 'Not mine', 'slug' => 'not-mine']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->andReturn(new CardDetailData(
        tcgdexId: 'me05-116', setTcgdexId: 'me05', localId: '116', name: 'Mega Darkrai ex',
        rarity: 'SIR', variants: [], officialImageUrl: null,
        prices: new DataCollection(PriceEntryData::class, []), raw: [],
    ));
    $provider->shouldReceive('findSet')->andReturn(new SetSummaryData(
        tcgdexId: 'me05', name: 'Pitch Black', series: null, releasedOn: null, cardCount: null, logoUrl: null,
    ));
    $this->app->instance(CardCatalogProvider::class, $provider);

    Livewire::test(AddCollectionItem::class, ['collectionId' => $otherUsersCollection->id])
        ->call('selectCard', 'me05-116')
        ->set('rows.0.condition', 'NM')
        ->set('rows.0.quantity', 1)
        ->call('save')
        ->assertHasErrors('selectedTcgdexId');

    expect(CollectionItem::where('card_tcgdex_id', 'me05-116')->exists())->toBeFalse();
});

test('submitting multiple rows for one card syncs the catalog card only once, not once per row', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    // ->twice(), not ->times(4) — one call from selectCard() (narrowing
    // the Variant dropdown) plus exactly ONE call from save()'s catalog
    // sync, no matter how many rows are in this submission. The whole
    // point of this fix: every row is the same card, synced once.
    $provider->shouldReceive('findCard')->with('sv05-050')->twice()->andReturn(new CardDetailData(
        tcgdexId: 'sv05-050', setTcgdexId: 'sv05', localId: '050', name: 'Iron Hands ex',
        rarity: 'SIR', variants: [], officialImageUrl: null,
        prices: new DataCollection(PriceEntryData::class, []), raw: [],
    ));
    $provider->shouldReceive('findSet')->with('sv05')->once()->andReturn(new SetSummaryData(
        tcgdexId: 'sv05', name: 'Temporal Forces', series: null, releasedOn: null, cardCount: null, logoUrl: null,
    ));
    $this->app->instance(CardCatalogProvider::class, $provider);

    Livewire::test(AddCollectionItem::class, ['collectionId' => $collection->id])
        ->call('selectCard', 'sv05-050')
        ->set('rows.0.variant', 'normal')
        ->call('addRow')
        ->set('rows.1.variant', 'holofoil')
        ->call('addRow')
        ->set('rows.2.variant', 'reverse-holofoil')
        ->call('save')
        ->assertRedirect();

    expect(CollectionItem::where('card_tcgdex_id', 'sv05-050')->count())->toBe(3);
});

test('an uploaded photo from an earlier row is cleaned up when a later row fails and the submission rolls back', function () {
    Storage::fake('collection-photos');
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->andReturn(new CardDetailData(
        tcgdexId: 'sv05-050', setTcgdexId: 'sv05', localId: '050', name: 'Iron Hands ex',
        rarity: 'SIR', variants: [], officialImageUrl: null,
        prices: new DataCollection(PriceEntryData::class, []), raw: [],
    ));
    $provider->shouldReceive('findSet')->andReturn(new SetSummaryData(
        tcgdexId: 'sv05', name: 'Temporal Forces', series: null, releasedOn: null, cardCount: null, logoUrl: null,
    ));
    $this->app->instance(CardCatalogProvider::class, $provider);

    Livewire::test(AddCollectionItem::class, ['collectionId' => $collection->id])
        ->call('selectCard', 'sv05-050')
        ->set('rows.0.variant', 'normal')
        ->set('rows.0.photo', UploadedFile::fake()->image('a.jpg'))
        ->call('addRow')
        ->set('rows.1.variant', 'holofoil')
        // A quantity this large overflows the DB column at INSERT time —
        // valid per the 'integer' validation rule, but a genuine failure
        // deep inside the per-row transaction, after row 0's photo has
        // already been stored to disk (Storage isn't transactional).
        ->set('rows.1.quantity', 5000000000)
        ->call('save')
        ->assertHasErrors('selectedTcgdexId');

    expect(CollectionItem::where('card_tcgdex_id', 'sv05-050')->count())->toBe(0);
    expect(Storage::disk('collection-photos')->allFiles())->toBeEmpty();
});

test('a submission cannot carry more rows than the server-side cap allows', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->andReturn(new CardDetailData(
        tcgdexId: 'sv05-050', setTcgdexId: 'sv05', localId: '050', name: 'Iron Hands ex',
        rarity: 'SIR', variants: [], officialImageUrl: null,
        prices: new DataCollection(PriceEntryData::class, []), raw: [],
    ));
    $this->app->instance(CardCatalogProvider::class, $provider);

    $component = Livewire::test(AddCollectionItem::class, ['collectionId' => $collection->id])
        ->call('selectCard', 'sv05-050');

    // addRow() itself refuses to grow past the cap — not just the final
    // rules() validation — since it's a public action a client could call
    // directly and repeatedly regardless of what's rendered.
    for ($i = 0; $i < 30; $i++) {
        $component->call('addRow');
    }

    expect($component->get('rows'))->toHaveCount(25);
});

test('submitting 3 rows for one new card creates 3 items in one request', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->andReturn(new CardDetailData(
        tcgdexId: 'sv05-050', setTcgdexId: 'sv05', localId: '050', name: 'Iron Hands ex',
        rarity: 'SIR', variants: [], officialImageUrl: null,
        prices: new DataCollection(PriceEntryData::class, []), raw: [],
    ));
    $provider->shouldReceive('findSet')->andReturn(new SetSummaryData(
        tcgdexId: 'sv05', name: 'Temporal Forces', series: null, releasedOn: null, cardCount: null, logoUrl: null,
    ));
    $this->app->instance(CardCatalogProvider::class, $provider);

    Livewire::test(AddCollectionItem::class, ['collectionId' => $collection->id])
        ->call('selectCard', 'sv05-050')
        ->set('rows.0.variant', 'normal')
        ->set('rows.0.quantity', 2)
        ->call('addRow')
        ->set('rows.1.variant', 'holofoil')
        ->set('rows.1.quantity', 1)
        ->call('save')
        ->assertRedirect();

    expect(CollectionItem::where('card_tcgdex_id', 'sv05-050')->count())->toBe(2);
    expect(CollectionItem::where('card_tcgdex_id', 'sv05-050')->where('variant', 'normal')->first()->quantity)->toBe(2);
});

test('two rows with the identical variant/condition in one submission fail validation and save nothing', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->andReturn(new CardDetailData(
        tcgdexId: 'sv05-050', setTcgdexId: 'sv05', localId: '050', name: 'Iron Hands ex',
        rarity: 'SIR', variants: [], officialImageUrl: null,
        prices: new DataCollection(PriceEntryData::class, []), raw: [],
    ));
    $provider->shouldReceive('findSet')->andReturn(new SetSummaryData(
        tcgdexId: 'sv05', name: 'Temporal Forces', series: null, releasedOn: null, cardCount: null, logoUrl: null,
    ));
    $this->app->instance(CardCatalogProvider::class, $provider);

    Livewire::test(AddCollectionItem::class, ['collectionId' => $collection->id])
        ->call('selectCard', 'sv05-050')
        ->set('rows.0.variant', 'normal')
        ->call('addRow')
        ->set('rows.1.variant', 'normal')
        ->call('save')
        ->assertHasErrors(['rows.1.variant']);

    expect(CollectionItem::where('card_tcgdex_id', 'sv05-050')->count())->toBe(0);
});

test('a catalog sync failure during save shows a friendly error and does not create an item', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me05-116')->andThrow(
        CardNotFoundException::forTcgdexId('me05-116'),
    );
    $this->app->instance(CardCatalogProvider::class, $provider);

    Livewire::test(AddCollectionItem::class, ['collectionId' => $collection->id])
        ->call('selectCard', 'me05-116')
        ->set('rows.0.condition', 'NM')
        ->set('rows.0.quantity', 1)
        ->call('save')
        ->assertHasErrors('selectedTcgdexId');

    expect(CollectionItem::where('card_tcgdex_id', 'me05-116')->exists())->toBeFalse();
});
