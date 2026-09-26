<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Data\CardDetailData;
use App\Modules\Catalog\Data\PriceEntryData;
use App\Modules\Catalog\Data\SetSummaryData;
use App\Modules\Catalog\Exceptions\CardNotFoundException;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\Set;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use App\Modules\Collection\Scopes\TenantScope;
use Mockery\MockInterface;
use Spatie\LaravelData\DataCollection;

beforeEach(function () {
    config([
        'tcgvault.demo.username' => 'demo',
        'tcgvault.demo.email' => 'demo@tcg-vault.invalid',
        'tcgvault.demo.cards' => ['me05-116', 'me05-120'],
    ]);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    Card::create(['tcgdex_id' => 'me05-120', 'set_id' => $set->id, 'local_id' => '120', 'name' => 'Mega Darkrai ex']);
});

function demoItems(): Illuminate\Support\Collection
{
    $demo = User::where('username', 'demo')->firstOrFail();
    $collection = Collection::withoutGlobalScope(TenantScope::class)->where('user_id', $demo->id)->firstOrFail();

    return CollectionItem::where('collection_id', $collection->id)->orderBy('card_tcgdex_id')->get();
}

function fakeProvider(): MockInterface
{
    $provider = Mockery::mock(CardCatalogProvider::class);
    app()->instance(CardCatalogProvider::class, $provider);

    return $provider;
}

test('it creates a public demo collection with one near-mint copy of each configured card', function () {
    fakeProvider()->shouldNotReceive('findCard');

    $this->artisan('demo:seed-gallery')->assertSuccessful();

    $demo = User::where('username', 'demo')->firstOrFail();
    $collection = Collection::withoutGlobalScope(TenantScope::class)->where('user_id', $demo->id)->firstOrFail();

    expect($demo->email)->toBe('demo@tcg-vault.invalid')
        ->and($demo->getRoleNames())->toBeEmpty()
        ->and($collection->is_public)->toBeTrue()
        ->and(demoItems()->map->only(['card_tcgdex_id', 'variant', 'condition', 'grade_company', 'quantity'])->all())->toBe([
            ['card_tcgdex_id' => 'me05-116', 'variant' => 'holofoil', 'condition' => 'NM', 'grade_company' => null, 'quantity' => 1],
            ['card_tcgdex_id' => 'me05-120', 'variant' => 'holofoil', 'condition' => 'NM', 'grade_company' => null, 'quantity' => 1],
        ]);
});

test('it pulls a card into the catalog when it is not there yet', function () {
    config(['tcgvault.demo.cards' => ['me05-116', 'sv08-238']]);

    $provider = fakeProvider();
    $provider->shouldReceive('findCard')->once()->with('sv08-238')->andReturn(new CardDetailData(
        tcgdexId: 'sv08-238', setTcgdexId: 'sv08', localId: '238', name: 'Pikachu ex',
        rarity: 'Special illustration rare', variants: [], officialImageUrl: null,
        prices: new DataCollection(PriceEntryData::class, []), raw: [],
    ));
    $provider->shouldReceive('findSet')->with('sv08')->andReturn(new SetSummaryData(
        tcgdexId: 'sv08', name: 'Surging Sparks', series: null, releasedOn: null, cardCount: null, logoUrl: null,
    ));

    $this->artisan('demo:seed-gallery')->assertSuccessful();

    expect(Card::where('tcgdex_id', 'sv08-238')->exists())->toBeTrue()
        ->and(demoItems()->pluck('card_tcgdex_id')->all())->toBe(['me05-116', 'sv08-238']);
});

test('running it again changes nothing', function () {
    fakeProvider()->shouldNotReceive('findCard');

    $this->artisan('demo:seed-gallery')->assertSuccessful();
    $this->artisan('demo:seed-gallery')->assertSuccessful();

    expect(User::where('username', 'demo')->count())->toBe(1)
        ->and(demoItems())->toHaveCount(2);
});

test('a card tcgdex cannot find is reported and skipped, and the rest are still seeded', function () {
    config(['tcgvault.demo.cards' => ['me05-116', 'xx-999']]);
    fakeProvider()->shouldReceive('findCard')->with('xx-999')->andThrow(CardNotFoundException::forTcgdexId('xx-999'));

    $this->artisan('demo:seed-gallery')
        ->expectsOutputToContain('xx-999')
        ->assertFailed();

    expect(demoItems()->pluck('card_tcgdex_id')->all())->toBe(['me05-116']);
});

test('it refuses to take over a real account that already has the demo username', function () {
    $real = User::factory()->create(['username' => 'demo', 'email' => 'someone@example.test']);
    fakeProvider()->shouldNotReceive('findCard');

    $this->artisan('demo:seed-gallery')->assertFailed();

    expect(Collection::withoutGlobalScope(TenantScope::class)->where('user_id', $real->id)->exists())->toBeFalse();
});
