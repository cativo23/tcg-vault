<?php

declare(strict_types=1);

use App\Livewire\Admin\Import;
use App\Models\User;
use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Data\CardDetailData;
use App\Modules\Catalog\Data\SetSummaryData;
use App\Modules\Catalog\Exceptions\CardNotFoundException;
use App\Modules\Collection\Models\Collection;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Queue::fake();
});

function fakeCatalogProviderForImport(): CardCatalogProvider
{
    $provider = Mockery::mock(CardCatalogProvider::class);

    $provider->shouldReceive('findCard')->andReturnUsing(function (string $tcgdexId) {
        [$setId, $localId] = explode('-', $tcgdexId, 2);

        return CardDetailData::from([
            'tcgdexId' => $tcgdexId,
            'setTcgdexId' => $setId,
            'localId' => $localId,
            'name' => "Card {$tcgdexId}",
            'rarity' => 'Common',
            'variants' => [],
            'officialImageUrl' => null,
            'prices' => [],
            'raw' => [],
        ]);
    });

    $provider->shouldReceive('findSet')->andReturnUsing(
        fn (string $setTcgdexId) => SetSummaryData::from([
            'tcgdexId' => $setTcgdexId,
            'name' => "Set {$setTcgdexId}",
            'series' => null,
            'releasedOn' => null,
            'cardCount' => null,
            'logoUrl' => null,
        ])
    );

    return $provider;
}

test('previewing and confirming the real TCGplayer export adds the expected cards and quantities', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $this->app->instance(CardCatalogProvider::class, fakeCatalogProviderForImport());

    $export = file_get_contents(base_path('tests/Fixtures/tcgplayer-export.txt'));

    Livewire::test(Import::class)
        ->set('text', $export)
        ->call('preview')
        ->assertSet('matched', fn (array $matched) => count($matched) === 52)
        ->assertSet('unmatched', fn (array $unmatched) => count($unmatched) === 0)
        ->call('confirm')
        ->assertSet('summary', '52 cartas agregadas, 66 copias totales.')
        ->assertSet('matched', [])
        ->assertSet('text', '');

    $collection = Collection::where('user_id', $user->id)->firstOrFail();
    expect($collection->items()->count())->toBe(52);
    expect($collection->items()->sum('quantity'))->toBe(66);
});

test('unmatched lines are reported but do not block confirming the matched ones', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $this->app->instance(CardCatalogProvider::class, fakeCatalogProviderForImport());

    Livewire::test(Import::class)
        ->set('text', "1 Toucannon - 068/084 [PBL] 068/084\n1 Something Weird [ZZZ] 001/100")
        ->call('preview')
        ->assertSet('matched', fn (array $matched) => count($matched) === 1)
        ->assertSet('unmatched', fn (array $unmatched) => count($unmatched) === 1 && $unmatched[0]->reason === 'unknown_set')
        ->call('confirm')
        ->assertSet('summary', '1 cartas agregadas, 1 copias totales.');

    $collection = Collection::where('user_id', $user->id)->firstOrFail();
    expect($collection->items()->count())->toBe(1);
});

test('a card the catalog rejects during confirmation does not abort the rest of the batch', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $this->app->instance(CardCatalogProvider::class, fakeCatalogProviderForImport());

    // Both cards must clear preview() and land in $matched — the parser's
    // own catch-and-continue around findCard() must NOT be what routes the
    // second card to `unmatched`, or confirm()'s try/catch is never
    // actually exercised.
    $component = Livewire::test(Import::class)
        ->set('text', "1 Toucannon - 068/084 [PBL] 068/084\n1 Malamar [PBL] 052/084")
        ->call('preview')
        ->assertSet('matched', fn (array $matched) => count($matched) === 2)
        ->assertSet('unmatched', fn (array $unmatched) => count($unmatched) === 0);

    // Re-bind the provider so the second card fails only when
    // CatalogSyncService::syncCard() calls findCard() again during
    // confirm() — proving confirm()'s own try/catch around
    // CollectionService::addItem() is what's under test, not the parser's.
    // CollectionService (and its CatalogSyncService/CardCatalogProvider
    // dependencies) are method-injected fresh per Livewire call, so
    // rebinding between preview() and confirm() on the SAME component
    // instance takes effect.
    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me05-068')->andReturnUsing(fakeCatalogProviderForImport()->findCard(...));
    $provider->shouldReceive('findCard')->with('me05-052')->andThrow(CardNotFoundException::forTcgdexId('me05-052'));
    $provider->shouldReceive('findSet')->andReturnUsing(
        fn (string $setTcgdexId) => SetSummaryData::from([
            'tcgdexId' => $setTcgdexId, 'name' => "Set {$setTcgdexId}", 'series' => null,
            'releasedOn' => null, 'cardCount' => null, 'logoUrl' => null,
        ])
    );
    $this->app->instance(CardCatalogProvider::class, $provider);

    $component
        ->call('confirm')
        ->assertSet('summary', '1 cartas agregadas, 1 copias totales.');

    $collection = Collection::where('user_id', $user->id)->firstOrFail();
    expect($collection->items()->count())->toBe(1);
});
