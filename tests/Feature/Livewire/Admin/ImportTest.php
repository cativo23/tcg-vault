<?php

declare(strict_types=1);

use App\Livewire\Admin\Import;
use App\Models\User;
use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Data\CardDetailData;
use App\Modules\Catalog\Data\SetSummaryData;
use App\Modules\Catalog\Exceptions\CardNotFoundException;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
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

    $component = Livewire::test(Import::class)
        ->set('text', $export)
        ->call('preview')
        ->assertSet('matched', fn (array $matched) => count($matched) === 52)
        ->assertSet('unmatched', fn (array $unmatched) => count($unmatched) === 0);

    // confirm() only drains one chunk per click, so the 52-card export takes
    // six of them. The intermediate summary has to report progress, not
    // completion — an admin who reads "52 cards added" and clicks again
    // would double the import.
    $component->call('confirm')
        ->assertSet('matched', fn (array $matched) => count($matched) === 42)
        ->assertSet('summary', '10 cards added so far, 42 pending.');

    $clicks = 1;
    while ($component->get('matched') !== [] && $clicks < 10) {
        $component->call('confirm');
        $clicks++;
    }

    expect($clicks)->toBe(6);

    $component
        ->assertSet('summary', '52 cards added, 66 copies total.')
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
        ->assertSet('summary', '1 cards added, 1 copies total.');

    $collection = Collection::where('user_id', $user->id)->firstOrFail();
    expect($collection->items()->count())->toBe(1);
});

test('an unrecognized set code shows an admin-facing message, not a hint to edit a config file', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $this->app->instance(CardCatalogProvider::class, fakeCatalogProviderForImport());

    Livewire::test(Import::class)
        ->set('text', '1 Something Weird [ZZZ] 001/100')
        ->call('preview')
        ->assertSee('unrecognized set — not yet supported for import', escape: false)
        ->assertDontSee('tcgvault.php', escape: false)
        ->assertDontSee('tcgplayer_set_map', escape: false);
});

test('a preview that recognizes nothing says so instead of rendering an empty page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $this->app->instance(CardCatalogProvider::class, fakeCatalogProviderForImport());

    Livewire::test(Import::class)
        ->set('text', "   \n\n")
        ->call('preview')
        ->assertSet('matched', [])
        ->assertSet('unmatched', [])
        ->assertSet('hasPreviewed', true)
        ->assertSee('0 lines recognized', escape: false);
});

test('a variant-ambiguous matched line sets needs_variant_review on the created item', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $this->app->instance(CardCatalogProvider::class, fakeCatalogProviderForImport());

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    Card::create(['tcgdex_id' => 'me05-037', 'set_id' => $set->id, 'local_id' => '037', 'name' => 'Lampent']);
    CardPriceSnapshot::create(['card_id' => Card::where('tcgdex_id', 'me05-037')->value('id'), 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 100]);
    CardPriceSnapshot::create(['card_id' => Card::where('tcgdex_id', 'me05-037')->value('id'), 'source' => 'tcgplayer', 'variant' => 'holofoil', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 300]);

    Livewire::test(Import::class)
        ->set('text', '3 Lampent [PBL] 037/084')
        ->call('preview')
        ->call('confirm');

    $item = CollectionItem::where('card_tcgdex_id', 'me05-037')->first();
    expect($item->needs_variant_review)->toBeTrue();
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
        ->assertSet('summary', '1 cards added, 1 copies total.');

    $collection = Collection::where('user_id', $user->id)->firstOrFail();
    expect($collection->items()->count())->toBe(1);
});
