<?php

declare(strict_types=1);

use App\Livewire\Gallery\CardShow;
use App\Livewire\Gallery\Index;
use App\Livewire\Gallery\Show;
use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Services\WeekBaselines;
use App\Modules\Catalog\Support\ChartRange;
use App\Modules\Catalog\Support\PriceChart;
use App\Modules\Catalog\Support\Rarity;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use App\Modules\Collection\Services\Valuation;
use App\Support\DayLabel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\RevampHtml;

function seedCardPage(bool $public = true): array
{
    $user = User::factory()->create(['username' => 'carlos', 'name' => 'Carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => $public, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'series' => 'Mega Evolution', 'card_count' => 120]);
    $card = Card::create([
        'tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex',
        'rarity' => 'Special illustration rare', 'official_image_url' => 'https://official.example/116/high.webp',
        'raw' => ['illustrator' => 'AKIRA EGAWA', 'types' => ['Darkness'], 'hp' => 280, 'dexId' => [491], 'regulationMark' => 'J'],
    ]);
    $other = Card::create(['tcgdex_id' => 'me05-003', 'set_id' => $set->id, 'local_id' => '003', 'name' => 'Fomantis']);

    return compact('user', 'collection', 'set', 'card', 'other');
}

test('shows the card with its copies, market reads and catalog facts', function () {
    ['collection' => $collection, 'card' => $card] = seedCardPage();
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 2, 'notes' => 'Pulled from a booster bundle']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'grade_company' => 'PSA', 'grade_value' => '10', 'quantity' => 1]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'holofoil', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 19468]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 27333]);

    $response = $this->get('/carlos/me05/116');

    $response->assertOk();
    $response->assertSee('Mega Darkrai ex');
    $response->assertSee('In the collection');
    $response->assertSee('3 copies');
    $response->assertSee('PSA 10');
    $response->assertSee('Pulled from a booster bundle');
    $response->assertSee('$194.68');
    $response->assertSee('€273.33');
    $response->assertSee('$584.04'); // 3 copies at the resolved USD price
    $response->assertSee('AKIRA EGAWA');
    $response->assertSee('#0491');
    $response->assertSee('<title>Mega Darkrai ex #116 · Pitch Black', false);
});

test('the collectors own photo leads and the official art stays available as an alternate view', function () {
    ['collection' => $collection, 'card' => $card] = seedCardPage();
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1, 'photo_path' => 'my-photo.jpg']);

    $response = $this->get('/carlos/me05/116');

    $photoUrl = Storage::disk('collection-photos')->url('my-photo.jpg');
    $response->assertSeeInOrder([$photoUrl, 'https://official.example/116/high.webp'], false);
    $response->assertSee('property="og:image" content="'.$photoUrl.'"', false);
    $response->assertSee('Official art');
});

test('a card in a touched set that is not owned renders as context, not a 404', function () {
    ['collection' => $collection, 'card' => $card] = seedCardPage();
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    $response = $this->get('/carlos/me05/003');

    $response->assertOk();
    $response->assertSee('Fomantis');
    $response->assertSee('Not in the collection');
});

test('a card in a set the collector has never touched 404s', function () {
    seedCardPage();

    $this->get('/carlos/me05/116')->assertNotFound();
});

test('a card number that does not exist in the set 404s', function () {
    ['collection' => $collection, 'card' => $card] = seedCardPage();
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    $this->get('/carlos/me05/999')->assertNotFound();
});

test('a private collection never leaks copies, notes or photos through the card page', function () {
    ['collection' => $collection, 'card' => $card] = seedCardPage(public: false);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1, 'notes' => 'secret note', 'photo_path' => 'secret.jpg']);

    $response = $this->get('/carlos/me05/116');

    $response->assertNotFound();
    $response->assertDontSee('secret note');
    $response->assertDontSee('secret.jpg', false);
});

test('the card page requires no authentication', function () {
    ['collection' => $collection, 'card' => $card] = seedCardPage();
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    $this->get('/carlos/me05/116')->assertOk();
});

test('a manually entered market price is labelled as manual, never as a marketplace', function () {
    ['collection' => $collection, 'card' => $card] = seedCardPage();
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1, 'variant' => 'holofoil:cosmos+player-rewards-program']);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'manual', 'variant' => 'holofoil:cosmos+player-rewards-program', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 70]);

    $response = $this->get('/carlos/me05/116');

    $response->assertOk();
    $response->assertSee('Manual');
    $response->assertSee('Holofoil · Cosmos · Player Rewards Program');
    $response->assertDontSee('Cardmarket');
});

test('a market read older than the rest says how old it is, and is not the headline', function () {
    ['collection' => $collection, 'card' => $card] = seedCardPage();
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1, 'variant' => 'normal']);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDays(12), 'currency' => 'USD', 'market_minor' => 1231]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 1522]);

    $response = $this->get('/carlos/me05/116');

    $response->assertOk();
    $response->assertSee('as of '.DayLabel::of(today()->subDays(12)));
    preg_match('#class="crd-htag crd-htag--lead".*?<b>([^<]+)</b>#s', $response->getContent(), $lead);
    expect($lead[1] ?? null)->toBe('Cardmarket');
});

test('the headline read is never marked as old, and a manual price does not date tcgdex reads', function () {
    ['collection' => $collection, 'card' => $card] = seedCardPage();
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1, 'variant' => 'holofoil']);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'holofoil', 'captured_on' => today()->subDay(), 'currency' => 'USD', 'market_minor' => 19468]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'manual', 'variant' => 'holofoil:cosmos', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 500]);

    $response = $this->get('/carlos/me05/116');

    $response->assertOk();
    $response->assertDontSee('as of');
});

test('a headline price that is all there is but frozen says how old it is instead of showing a move', function () {
    ['collection' => $collection, 'card' => $card] = seedCardPage();
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1, 'variant' => 'holofoil:cosmos']);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'holofoil:cosmos', 'captured_on' => today()->subDays(13), 'currency' => 'USD', 'market_minor' => 1000]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'holofoil:cosmos', 'captured_on' => today()->subDays(12), 'currency' => 'USD', 'market_minor' => 900]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 50]);

    $response = $this->get('/carlos/me05/116');

    $response->assertOk();
    // The tile says how old the price is instead of a move; the price
    // history chart below still dates its own points.
    $response->assertSeeInOrder(['$9.00', 'as of '.DayLabel::of(today()->subDays(12)), 'Price history']);
});

test('the market header names the origins of the prices shown, and dates the latest sync, not a manual entry', function () {
    ['collection' => $collection, 'card' => $card] = seedCardPage();
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1, 'variant' => 'holofoil']);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'holofoil', 'captured_on' => today()->subDay(), 'currency' => 'USD', 'market_minor' => 16027]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'origin' => 'tcgcsv', 'variant' => 'normal', 'captured_on' => today()->subDay(), 'currency' => 'USD', 'market_minor' => 15000]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'manual', 'origin' => 'hand', 'variant' => 'holofoil:cosmos', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 500]);

    $response = $this->get('/carlos/me05/116');

    $response->assertOk();
    $response->assertSee(today()->subDay()->format('j M Y'));
    $response->assertSee('· via tcgcsv · tcgdex');
    // Only the date is set in the numeral face; the words stay in the body face.
    $response->assertSee('<span class="crd-mono">'.today()->subDay()->format('j M Y').'</span> · via', false);
    $response->assertDontSee(today()->format('j M Y').' · via');
});

test('a manual price says when it was entered, and every tile says where its price came from', function () {
    ['collection' => $collection, 'card' => $card] = seedCardPage();
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1, 'variant' => 'holofoil:cosmos']);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'holofoil', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 16027, 'low_minor' => 15000]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'manual', 'origin' => 'hand', 'variant' => 'holofoil:cosmos', 'captured_on' => today()->subDays(2), 'currency' => 'USD', 'market_minor' => 500, 'low_minor' => 400]);

    $response = $this->get('/carlos/me05/116');

    $response->assertOk();
    $response->assertSee('entered '.DayLabel::of(today()->subDays(2)));
    $response->assertSee('title="TCGplayer market price via tcgdex, '.today()->format('j M Y').'"', false);
    $response->assertSee('title="Manual price entered by hand, '.today()->subDays(2)->format('j M Y').'"', false);
});

test('a card priced only by hand shows no marketplace attribution', function () {
    ['collection' => $collection, 'card' => $card] = seedCardPage();
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1, 'variant' => 'holofoil:cosmos']);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'manual', 'origin' => 'hand', 'variant' => 'holofoil:cosmos', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 500]);

    $response = $this->get('/carlos/me05/116');

    $response->assertOk();
    $response->assertDontSee('· via');
});

test('the market header shows no date and no origin when the selected variant has only a manual price', function () {
    $seed = seedVariantCard(); // marketplace prices for other variants, priced today
    ownCopy($seed, 'holofoil:cosmos');
    CardPriceSnapshot::create(['card_id' => $seed['card']->id, 'source' => 'manual', 'origin' => 'hand', 'variant' => 'holofoil:cosmos', 'captured_on' => today()->subDays(2), 'currency' => 'USD', 'market_minor' => 500]);

    $response = $this->get('/carlos/me05/116?variant=holofoil:cosmos');

    $response->assertOk()
        ->assertSee('entered '.DayLabel::of(today()->subDays(2)))
        ->assertDontSee('<span class="crd-sec-r"><span class="crd-mono">', false)
        ->assertDontSee('· via');
});

test('a manual price copied from a feed says so, is never shown as a move, and dates older entries with the year', function () {
    ['collection' => $collection, 'card' => $card] = seedCardPage();
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1, 'variant' => 'holofoil:cosmos']);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'manual', 'origin' => 'hand', 'variant' => 'holofoil:cosmos', 'captured_on' => today()->subYear(), 'currency' => 'USD', 'market_minor' => 400]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'manual', 'origin' => 'hand', 'variant' => 'holofoil:cosmos', 'captured_on' => today()->subYear()->addDay(), 'currency' => 'USD', 'market_minor' => 500, 'raw' => ['origin' => 'tcgcsv']]);

    $response = $this->get('/carlos/me05/116');

    $response->assertOk();
    $response->assertSee('copied by hand from TCGplayer (tcgcsv)');
    $response->assertSee('entered '.today()->subYear()->addDay()->format('j M Y'));
    $response->assertDontSee('crd-move--up', false);
});

function seedVariantCard(): array
{
    $seed = seedCardPage();
    $seed['card']->update(['variants' => ['normal' => true, 'reverse' => true, 'holo' => false]]);
    $card = $seed['card'];
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 42]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'reverse-holofoil', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 110]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 37]);

    return $seed;
}

function ownCopy(array $seed, ?string $variant, int $quantity = 1, ?string $notes = null): void
{
    CollectionItem::create(['collection_id' => $seed['collection']->id, 'card_id' => $seed['card']->id, 'card_tcgdex_id' => 'me05-116', 'variant' => $variant, 'condition' => 'NM', 'quantity' => $quantity, 'notes' => $notes]);
}

test('the card page opens on the priciest variant the collector owns', function () {
    $seed = seedVariantCard();
    ownCopy($seed, 'normal', 2);

    Livewire::test(CardShow::class, ['username' => 'carlos', 'setTcgdexId' => 'me05', 'localId' => '116'])
        ->assertViewHas('selected', fn ($s) => $s['key'] === 'normal')
        ->assertSeeHtml('Market value <span class="crd-sec-v">· Normal</span>');
});

test('a card the collector does not own opens on its priciest variant', function () {
    $seed = seedVariantCard();
    ownCopy($seed, 'normal'); // an owned card makes the set reachable; the page under test is 003
    $other = $seed['other'];
    $other->update(['variants' => ['normal' => true, 'reverse' => true]]);
    CardPriceSnapshot::create(['card_id' => $other->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 5]);
    CardPriceSnapshot::create(['card_id' => $other->id, 'source' => 'tcgplayer', 'variant' => 'reverse-holofoil', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 90]);

    Livewire::test(CardShow::class, ['username' => 'carlos', 'setTcgdexId' => 'me05', 'localId' => '003'])
        ->assertViewHas('selected', fn ($s) => $s['key'] === 'reverse-holofoil');
});

test('the default price on the card page is the price on its set grid tile', function () {
    $seed = seedVariantCard();
    ownCopy($seed, 'normal');
    ownCopy($seed, 'reverse-holofoil');

    $card = $seed['card']->fresh()->load(['priceSnapshots', 'collectionItems']);
    $tile = (new Valuation)->headlineSnapshot($card);

    Livewire::test(CardShow::class, ['username' => 'carlos', 'setTcgdexId' => 'me05', 'localId' => '116'])
        ->assertViewHas('snapshot', fn ($s) => $s->is($tile));
});

test('a variant in the URL switches the price, the reads and the copies', function () {
    $seed = seedVariantCard();
    ownCopy($seed, 'normal', 2, 'binder copy');
    ownCopy($seed, 'reverse-holofoil', 1, 'pulled at league');

    Livewire::withQueryParams(['variant' => 'normal'])
        ->test(CardShow::class, ['username' => 'carlos', 'setTcgdexId' => 'me05', 'localId' => '116'])
        ->assertViewHas('selected', fn ($s) => $s['key'] === 'normal')
        ->assertViewHas('snapshot', fn ($s) => $s->variant === 'normal' && $s->market_minor === 42)
        ->assertSee('binder copy')
        ->assertDontSee('pulled at league')
        ->assertSee('3 copies'); // the header stays card-wide
});

test('selecting a pill switches the variant and updates the URL', function () {
    $seed = seedVariantCard();
    ownCopy($seed, 'normal');

    Livewire::test(CardShow::class, ['username' => 'carlos', 'setTcgdexId' => 'me05', 'localId' => '116'])
        ->call('selectVariant', 'reverse-holofoil')
        ->assertSet('variant', 'reverse-holofoil')
        ->assertViewHas('snapshot', fn ($s) => $s->variant === 'reverse-holofoil');
});

test('an unknown variant in the URL falls back to the default instead of failing', function () {
    $seed = seedVariantCard();
    ownCopy($seed, 'normal');

    $response = $this->get('/carlos/me05/116?variant=%3Cb%3Ejunk%3C%2Fb%3E')->assertOk()
        ->assertSee('aria-pressed="true"', false);

    // The raw value only ever travels inside the escaped component snapshot;
    // no part of the rendered page echoes it.
    expect(preg_replace('/wire:snapshot="[^"]*"/', '', $response->getContent()))->not->toContain('junk');

    Livewire::withQueryParams(['variant' => 'holofoil:nope'])
        ->test(CardShow::class, ['username' => 'carlos', 'setTcgdexId' => 'me05', 'localId' => '116'])
        ->assertViewHas('selected', fn ($s) => $s['key'] === 'normal');
});

test('a non-string variant in the URL does not break the page', function () {
    $seed = seedVariantCard();
    ownCopy($seed, 'normal');

    $this->get('/carlos/me05/116?variant[]=x')->assertOk();

    Livewire::withQueryParams(['variant' => ['x']])
        ->test(CardShow::class, ['username' => 'carlos', 'setTcgdexId' => 'me05', 'localId' => '116'])
        ->assertViewHas('selected', fn ($s) => $s['key'] === 'normal');
});

test('copies with no variant are reachable as the unspecified pill', function () {
    $seed = seedVariantCard();
    ownCopy($seed, 'normal');
    ownCopy($seed, null, 1, 'loose copy');

    Livewire::withQueryParams(['variant' => 'unspecified'])
        ->test(CardShow::class, ['username' => 'carlos', 'setTcgdexId' => 'me05', 'localId' => '116'])
        ->assertViewHas('selected', fn ($s) => $s['key'] === 'unspecified' && $s['variant'] === null)
        ->assertSee('loose copy');
});

test('the whole-card average shows under normal only', function () {
    $seed = seedVariantCard();
    ownCopy($seed, 'normal');

    $reads = fn (string $variant) => Livewire::withQueryParams(['variant' => $variant])
        ->test(CardShow::class, ['username' => 'carlos', 'setTcgdexId' => 'me05', 'localId' => '116'])
        ->viewData('marketReads')->pluck('variant')->all();

    expect($reads('normal'))->toContain('default')
        ->and($reads('reverse-holofoil'))->not->toContain('default')
        ->and($reads('reverse-holofoil'))->toBe(['reverse-holofoil']);
});

test('a card with a single variant shows no switcher', function () {
    $seed = seedCardPage();
    $seed['card']->update(['variants' => ['holo' => true]]);
    ownCopy($seed, 'holofoil');

    Livewire::test(CardShow::class, ['username' => 'carlos', 'setTcgdexId' => 'me05', 'localId' => '116'])
        ->assertViewHas('switcher', false)
        ->assertDontSeeHtml('crd-variants');
});

test('a variant with no price says so instead of borrowing another', function () {
    $seed = seedVariantCard();
    $seed['card']->update(['variants' => ['normal' => true, 'reverse' => true, 'holo' => true]]);
    ownCopy($seed, 'normal');

    Livewire::withQueryParams(['variant' => 'holofoil'])
        ->test(CardShow::class, ['username' => 'carlos', 'setTcgdexId' => 'me05', 'localId' => '116'])
        ->assertViewHas('snapshot', fn ($s) => $s === null)
        ->assertSee('No market price for Holofoil yet.');
});

test('the read a variant borrows its price from is listed among the market reads', function () {
    $seed = seedCardPage();
    $seed['card']->update(['variants' => ['normal' => true, 'holo' => true]]);
    ownCopy($seed, 'holofoil');
    CardPriceSnapshot::create(['card_id' => $seed['card']->id, 'source' => 'tcgplayer', 'variant' => 'holofoil', 'captured_on' => today()->subDays(12), 'currency' => 'USD', 'market_minor' => 300]);
    $default = CardPriceSnapshot::create(['card_id' => $seed['card']->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 250]);

    Livewire::withQueryParams(['variant' => 'holofoil'])
        ->test(CardShow::class, ['username' => 'carlos', 'setTcgdexId' => 'me05', 'localId' => '116'])
        ->assertViewHas('snapshot', fn ($s) => $s->is($default))
        ->assertViewHas('marketReads', fn ($reads) => $reads->contains(fn ($r) => $r->is($default)));
});

test('a card with no catalog variants and one hand-entered print shows no switcher and lists its copies', function () {
    $seed = seedCardPage();
    ownCopy($seed, 'holofoil', 1, 'only copy');

    Livewire::test(CardShow::class, ['username' => 'carlos', 'setTcgdexId' => 'me05', 'localId' => '116'])
        ->assertViewHas('switcher', false)
        ->assertViewHas('items', fn ($items) => $items->count() === 1)
        ->assertSee('only copy');
});

test('the pills are buttons, only the selected one is pressed, and owned ones show their count', function () {
    $seed = seedVariantCard();
    ownCopy($seed, 'normal', 2);

    $html = Livewire::withQueryParams(['variant' => 'normal'])
        ->test(CardShow::class, ['username' => 'carlos', 'setTcgdexId' => 'me05', 'localId' => '116'])
        ->assertSeeHtml('<div class="crd-variants" role="group" aria-label="Variants of Mega Darkrai ex">')
        ->assertSeeHtml('<span class="sr-only">2 owned</span>')
        ->assertSeeHtml('<span class="crd-vo" aria-hidden="true">×2 owned</span>')
        ->html();

    preg_match('/<div class="crd-variants".*?<\/div>/s', $html, $group);
    $pills = $group[0];

    expect(substr_count($pills, 'class="crd-vpill"'))->toBe(2)
        ->and(substr_count($pills, 'aria-pressed="true"'))->toBe(1)
        ->and(substr_count($pills, 'aria-pressed="false"'))->toBe(1)
        ->and(substr_count($pills, '<button type="button" wire:key="variant-'))->toBe(2);
});

test('a graded copy of another variant keeps its badge when a pill filters it out of the list', function () {
    $seed = seedVariantCard();
    ownCopy($seed, 'normal');
    CollectionItem::create(['collection_id' => $seed['collection']->id, 'card_id' => $seed['card']->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'reverse-holofoil', 'condition' => 'NM', 'grade_company' => 'PSA', 'grade_value' => '10', 'quantity' => 1]);

    Livewire::withQueryParams(['variant' => 'normal'])
        ->test(CardShow::class, ['username' => 'carlos', 'setTcgdexId' => 'me05', 'localId' => '116'])
        ->assertViewHas('items', fn ($items) => $items->count() === 1)
        ->assertSee('PSA 10');
});

test('the variant lands in the URL as a new history entry so the back button returns to the previous one', function () {
    $seed = seedVariantCard();
    ownCopy($seed, 'normal');

    Livewire::test(CardShow::class, ['username' => 'carlos', 'setTcgdexId' => 'me05', 'localId' => '116'])
        ->assertSeeHtml('&quot;use&quot;:&quot;push&quot;');
});

test('owned copies that are all unpriced open on the variant the set tile prices', function (?string $variant) {
    $seed = seedVariantCard();
    ownCopy($seed, $variant);

    $tile = Livewire::test(Show::class, ['username' => 'carlos', 'setTcgdexId' => 'me05'])
        ->viewData('entries')->first(fn ($e) => $e['card']->is($seed['card']))['snapshot'];

    Livewire::test(CardShow::class, ['username' => 'carlos', 'setTcgdexId' => 'me05', 'localId' => '116'])
        ->assertViewHas('snapshot', fn ($s) => $s->is($tile));
})->with([
    'a hand-entered print' => ['holofoil:masterball'],
    'a copy with no variant' => [''],
]);

test('a variant whose latest row has no market price says so instead of the stale-window message', function () {
    $seed = seedVariantCard();
    ownCopy($seed, 'normal');
    CardPriceSnapshot::create(['card_id' => $seed['card']->id, 'source' => 'tcgplayer', 'variant' => 'holofoil', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => null]);
    $seed['card']->update(['variants' => ['normal' => true, 'reverse' => true, 'holo' => true]]);

    Livewire::withQueryParams(['variant' => 'holofoil'])
        ->test(CardShow::class, ['username' => 'carlos', 'setTcgdexId' => 'me05', 'localId' => '116'])
        ->assertSee('No market price for Holofoil yet.')
        ->assertDontSee('No market price in the last');
});

test('a null or non-string variant never breaks the page', function () {
    $seed = seedVariantCard();
    ownCopy($seed, 'normal');

    Livewire::test(CardShow::class, ['username' => 'carlos', 'setTcgdexId' => 'me05', 'localId' => '116'])
        ->set('variant', null)
        ->assertOk()
        ->assertViewHas('selected', fn ($s) => $s['key'] === 'normal')
        ->call('selectVariant', ['x'])
        ->assertOk()
        ->assertViewHas('selected', fn ($s) => $s['key'] === 'normal');
});

/** A card page whose owned normal print is priced on each of $daysAgo; returns the Livewire test. */
function rangedCardPage(array $daysAgo, array $query = []): Testable
{
    ['collection' => $collection, 'card' => $card] = seedCardPage();
    $card->update(['variants' => ['normal' => true, 'reverse' => true]]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 1]);
    foreach ($daysAgo as $ago) {
        CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDays($ago), 'currency' => 'USD', 'market_minor' => 1000 + $ago]);
    }

    return Livewire::withQueryParams($query)->test(CardShow::class, ['username' => 'carlos', 'setTcgdexId' => 'me05', 'localId' => '116']);
}

test('the chart opens on one month', function () {
    $page = rangedCardPage([0, 20, 60, 120, 400]);

    expect($page->viewData('chartRange'))->toBe(ChartRange::OneMonth)
        ->and($page->viewData('history')->pluck('market_minor')->all())->toBe([1020, 1000]);
});

test('each range charts its own days', function (string $range, int $points) {
    expect(rangedCardPage([0, 20, 60, 120, 400], ['range' => $range])->viewData('history')->count())->toBe($points);
})->with([['1m', 2], ['3m', 3], ['6m', 4], ['1y', 4]]);

test('selectRange switches the chart and ignores anything that is not a string', function () {
    $page = rangedCardPage([0, 20, 60]);

    $page->call('selectRange', '6m');
    expect($page->viewData('chartRange'))->toBe(ChartRange::SixMonths);

    $page->call('selectRange', ['1y']);
    expect($page->viewData('chartRange'))->toBe(ChartRange::SixMonths);
});

test('an unknown or array range opens one month with a 200', function () {
    rangedCardPage([0, 20]);

    $this->get('/carlos/me05/116?range=7d')->assertOk();
    $this->get('/carlos/me05/116?range[]=1y')->assertOk();
    expect(Livewire::withQueryParams(['range' => ['1y']])->test(CardShow::class, ['username' => 'carlos', 'setTcgdexId' => 'me05', 'localId' => '116'])->viewData('chartRange'))
        ->toBe(ChartRange::OneMonth);
});

test('the range and the variant survive switching each other, and the chart follows the variant', function () {
    $page = rangedCardPage([0, 20]);
    foreach ([0, 10] as $ago) {
        CardPriceSnapshot::create(['card_id' => $page->instance()->card->id, 'source' => 'tcgplayer', 'variant' => 'reverse-holofoil', 'captured_on' => today()->subDays($ago), 'currency' => 'USD', 'market_minor' => 50 + $ago]);
    }

    $page->call('selectRange', '3m')->call('selectVariant', 'reverse-holofoil');
    expect($page->get('range'))->toBe('3m')
        ->and($page->viewData('history')->pluck('variant')->unique()->all())->toBe(['reverse-holofoil']);

    $page->call('selectRange', '1y');
    expect($page->get('variant'))->toBe('reverse-holofoil');
});

test('the range tabs are a tablist with the active one selected and the only one in the tab order', function () {
    rangedCardPage([0, 20]);

    $html = $this->get('/carlos/me05/116?range=3m')->assertOk()->getContent();

    expect($html)->toContain('<div class="crd-tabs" role="tablist" aria-label="Price history range" data-crd-tabs>')
        ->toMatch('/aria-selected="true"[^>]*tabindex="0"[^>]*selectRange\(\'3m\'\)/s')
        ->toMatch('/aria-selected="false"[^>]*tabindex="-1"[^>]*selectRange\(\'1m\'\)/s')
        ->toContain('id="crd-chart-panel" role="tabpanel" aria-labelledby="crd-range-3m"');
});

test('history since shows only when the price data starts after the range start', function () {
    rangedCardPage([0, 20, 45]);

    $this->get('/carlos/me05/116')->assertDontSee('History since');
    $this->get('/carlos/me05/116?range=3m')->assertSee('History since')->assertSee(DayLabel::of(today()->subDays(45)));
});

test('a range with no two points keeps its buttons and says so', function () {
    rangedCardPage([0, 45]);

    $this->get('/carlos/me05/116')
        ->assertSee('No price history in this range.')
        ->assertSee("selectRange('3m')", false);
});

test('a card with a single priced row has no price history panel and no range buttons', function () {
    rangedCardPage([0]);

    $this->get('/carlos/me05/116')
        ->assertOk()
        ->assertDontSee('Price history')
        ->assertDontSee("selectRange('3m')", false);
});

test('the range includes its first day and not the day before', function () {
    $page = rangedCardPage([0, 30, 31]);

    expect($page->viewData('history')->pluck('market_minor')->all())->toBe([1030, 1000]);
});

test('the range buttons are named by their visible text, with the group named once', function () {
    rangedCardPage([0, 20]);

    $this->get('/carlos/me05/116?range=3m')
        ->assertSee('aria-label="Price history range"', false)
        ->assertDontSee('aria-label="3 months"', false)
        ->assertDontSee('aria-label="1 month"', false)
        ->assertSee('over 3 months, from');
});

/**
 * An owned holofoil card whose tcgplayer price is 4000 minor units today, 3200
 * $previousDaysAgo days ago (yesterday by default) and 800 a week ago (low $35.00
 * today), with its week baseline stored: the headline moved +$8.00 since the
 * previous price and +$32.00 in a week.
 */
function seedMovedHeadline(int $today = 4000, int $yesterday = 3200, int $week = 800, int $previousDaysAgo = 1): array
{
    $seed = seedCardPage();
    ['collection' => $collection, 'card' => $card] = $seed;
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'holofoil', 'condition' => 'NM', 'quantity' => 1]);
    foreach ([7 => $week, $previousDaysAgo => $yesterday, 0 => $today] as $ago => $minor) {
        CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'holofoil', 'captured_on' => today()->subDays($ago), 'currency' => 'USD', 'market_minor' => $minor, 'low_minor' => $ago === 0 ? 3500 : null]);
    }
    app(WeekBaselines::class)->refreshAll();

    return $seed;
}

function cardPageOf(string $localId = '116'): Testable
{
    return Livewire::test(CardShow::class, ['username' => 'carlos', 'setTcgdexId' => 'me05', 'localId' => $localId]);
}

test('the headline price carries both its 1-day and its 7-day move', function () {
    seedMovedHeadline();

    $page = cardPageOf();

    expect($page->viewData('delta')?->deltaMinor)->toBe(800)
        ->and($page->viewData('weekDelta')?->deltaMinor)->toBe(3200)
        ->and($page->viewData('weekDelta')?->previous->capturedOnKey())->toBe(today()->subDays(7)->toDateString());
});

test('without a stored week baseline the headline has no 7-day move', function () {
    seedMovedHeadline();
    DB::table('card_price_week_baselines')->delete();

    expect(cardPageOf()->viewData('weekDelta'))->toBeNull();
});

test('a manual headline price never has a 7-day move', function () {
    ['collection' => $collection, 'card' => $card] = seedCardPage();
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);
    foreach ([7 => 400, 0 => 500] as $ago => $minor) {
        CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'manual', 'origin' => 'hand', 'variant' => 'normal', 'captured_on' => today()->subDays($ago), 'currency' => 'USD', 'market_minor' => $minor]);
    }
    app(WeekBaselines::class)->refreshAll();

    expect(cardPageOf()->viewData('weekDelta'))->toBeNull();
});

test('the 7-day move costs the page one read of the week-baseline table, and no more', function () {
    seedMovedHeadline();

    $reads = [];
    DB::listen(function ($query) use (&$reads) {
        if (str_contains($query->sql, 'card_price_week_baselines')) {
            $reads[] = $query->sql;
        }
    });
    cardPageOf();

    expect($reads)->toHaveCount(1);
});

test('the chart plots the range by date and breaks the line where a market day has no price', function () {
    $chart = rangedCardPage([0, 1, 3, 4])->viewData('chart');

    expect($chart)->toBeInstanceOf(PriceChart::class)
        ->and($chart->step)->toBeFalse()
        ->and($chart->lines)->toHaveCount(2)
        ->and($chart->gaps)->toHaveCount(1)
        ->and(array_column($chart->points, 'minor'))->toBe([1004, 1003, 1001, 1000]);
});

test('a manual price chart is one continuous step line, however far apart its entries are', function () {
    ['collection' => $collection, 'card' => $card] = seedCardPage();
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1, 'variant' => 'holofoil:cosmos']);
    foreach ([25 => 400, 10 => 500, 2 => 500] as $ago => $minor) {
        CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'manual', 'origin' => 'hand', 'variant' => 'holofoil:cosmos', 'captured_on' => today()->subDays($ago), 'currency' => 'USD', 'market_minor' => $minor]);
    }

    $chart = cardPageOf()->viewData('chart');

    expect($chart)->toBeInstanceOf(PriceChart::class)
        ->and($chart->step)->toBeTrue()
        ->and($chart->lines)->toHaveCount(1)
        ->and($chart->gaps)->toBe([])
        ->and($chart->dots)->toBe([]);
});

test('a range with fewer than two priced days has no chart to draw', function () {
    expect(rangedCardPage([0, 45])->viewData('chart'))->toBeNull();
});

test('hasMarket and marketEmpty are the one answer to whether a market price shows', function () {
    seedMovedHeadline();
    $priced = cardPageOf();
    expect($priced->viewData('hasMarket'))->toBeTrue()
        ->and($priced->viewData('marketEmpty'))->toBeNull();
});

test('a pill with no price, a card priced only before the window and a card never priced each say which in marketEmpty', function () {
    $seed = seedVariantCard();
    $seed['card']->update(['variants' => ['normal' => true, 'reverse' => true, 'holo' => true]]);
    ownCopy($seed, 'normal');

    $pill = Livewire::withQueryParams(['variant' => 'holofoil'])->test(CardShow::class, ['username' => 'carlos', 'setTcgdexId' => 'me05', 'localId' => '116']);
    expect($pill->viewData('hasMarket'))->toBeFalse()
        ->and($pill->viewData('marketEmpty'))->toBe('No market price for Holofoil yet.');

    // The never-priced card: 003 has no rows at all.
    expect(cardPageOf('003')->viewData('marketEmpty'))->toBe('No market price recorded for this card yet.');

    // Priced only before the window: 003 gets one row older than the window.
    CardPriceSnapshot::create(['card_id' => $seed['other']->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDays(CardPriceSnapshot::RECENT_DAYS + 5), 'currency' => 'USD', 'market_minor' => 300]);
    $old = cardPageOf('003');
    expect($old->viewData('hasMarket'))->toBeFalse()
        ->and($old->viewData('marketEmpty'))->toBe('No market price in the last '.CardPriceSnapshot::RECENT_DAYS.' days.');
});

/**
 * Carlos owns 116 (unpriced) and 003 as a reverse holo: $9.00 today, $10.00 a
 * week ago, while 003's normal print (which resolve()'s card-level chain picks)
 * is $50.00.
 */
function seedRelatedStrip(): array
{
    $seed = seedCardPage();
    ['collection' => $collection, 'card' => $card, 'other' => $other] = $seed;
    $other->update(['variants' => ['normal' => true, 'reverse' => true]]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $other->id, 'card_tcgdex_id' => 'me05-003', 'variant' => 'reverse-holofoil', 'condition' => 'NM', 'quantity' => 1]);
    CardPriceSnapshot::create(['card_id' => $other->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 5000]);
    CardPriceSnapshot::create(['card_id' => $other->id, 'source' => 'tcgplayer', 'variant' => 'reverse-holofoil', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 900]);
    CardPriceSnapshot::create(['card_id' => $other->id, 'source' => 'tcgplayer', 'variant' => 'reverse-holofoil', 'captured_on' => today()->subDays(7), 'currency' => 'USD', 'market_minor' => 1000]);
    app(WeekBaselines::class)->refreshAll();

    return $seed;
}

test('the related strip prices each card like its collection tile and carries the 7-day move', function () {
    ['other' => $other] = seedRelatedStrip();

    $related = cardPageOf()->viewData('related');
    $tile = Livewire::test(Index::class, ['username' => 'carlos'])->viewData('entries')->first(fn ($e) => $e['card']->is($other));

    expect($related)->toHaveCount(1)
        ->and($related->first()['snapshot']->is($tile['snapshot']))->toBeTrue()
        ->and($related->first()['snapshot']->market_minor)->toBe(900)
        ->and($related->first()['weekDelta']?->deltaMinor)->toBe(-100)
        ->and($tile['weekDelta']?->deltaMinor)->toBe(-100);
});

test('the related strip comes out in number order', function () {
    $seed = seedCardPage();
    foreach (['009', '005', '007'] as $number) {
        $card = Card::create(['tcgdex_id' => "me05-{$number}", 'set_id' => $seed['set']->id, 'local_id' => $number, 'name' => "Card {$number}"]);
        CollectionItem::create(['collection_id' => $seed['collection']->id, 'card_id' => $card->id, 'card_tcgdex_id' => "me05-{$number}", 'condition' => 'NM', 'quantity' => 1]);
    }

    expect(cardPageOf()->viewData('related')->map(fn (array $entry) => $entry['card']->local_id)->all())->toBe(['005', '007', '009']);
});

// The result above is already in number order on a small table, because the planner walks the (set_id, local_id)
// index; only the issued SQL shows that the order is asked for rather than left to the planner.
test('the related strip asks the database for number order', function () {
    $seed = seedCardPage();
    $card = Card::create(['tcgdex_id' => 'me05-005', 'set_id' => $seed['set']->id, 'local_id' => '005', 'name' => 'Card 005']);
    CollectionItem::create(['collection_id' => $seed['collection']->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-005', 'condition' => 'NM', 'quantity' => 1]);

    $strips = [];
    DB::listen(function ($query) use (&$strips) {
        if (str_contains($query->sql, 'limit 8') && str_contains($query->sql, 'from "cards"')) {
            $strips[] = $query->sql;
        }
    });
    cardPageOf();

    expect($strips)->toHaveCount(1)
        ->and($strips[0])->toContain('order by "local_id" asc');
});

/** An owned Mega Darkrai ex page (PSA 10 and a raw copy), as HTML. */
function ownedCardPageHtml(): string
{
    ['collection' => $collection, 'card' => $card] = seedCardPage();
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 2]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'grade_company' => 'PSA', 'grade_value' => '10', 'quantity' => 1]);

    return test()->get('/carlos/me05/116')->assertOk()->getContent();
}

test('the card page sits on the revamp shell with its own stylesheet, and keeps its title, description, canonical and image', function () {
    $html = ownedCardPageHtml();
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);

    expect(RevampHtml::currentNav($html))->toBe('Collection')
        ->and($html)->toContain('<nav class="bar-nav" aria-label="Gallery">')
        ->and($html)->toContain($manifest['resources/css/revamp.css']['file'])
        ->and($html)->toContain($manifest['resources/css/collection.css']['file'])
        ->and($html)->toContain($manifest['resources/css/card.css']['file'])
        ->and($html)->not->toContain($manifest['resources/css/app.css']['file'])
        ->and($html)->not->toContain('wire:navigate')
        ->and($html)->not->toContain('noindex')
        ->and($html)->toContain('<title>Mega Darkrai ex #116 · Pitch Black · tcg-vault</title>')
        ->and($html)->toContain('<meta name="description" content="Mega Darkrai ex #116 from Pitch Black, Special illustration rare — in Carlos&#039;s collection.">')
        ->and($html)->toContain('<link rel="canonical" href="'.url('/carlos/me05/116').'">')
        ->and($html)->toContain('<meta property="og:image" content="https://official.example/116/high.webp">');
});

test('the head of the page: breadcrumb, badges, the name and the set line', function () {
    $html = ownedCardPageHtml();

    expect($html)->toContain('<nav class="crd-crumbs" aria-label="Breadcrumb">')
        ->and($html)->toContain('<li><a href="'.route('gallery.index', ['username' => 'carlos']).'">Collection</a></li>')
        ->and($html)->toContain('<li><a href="'.route('gallery.show', ['username' => 'carlos', 'setTcgdexId' => 'me05']).'">Pitch Black</a></li>')
        ->and($html)->toContain('<li aria-current="page"><span class="crd-mono">#116</span></li>')
        ->and($html)->toContain('class="crd-badge crd-badge--own"')
        ->and($html)->toContain('<span class="crd-badge crd-badge--slab">PSA 10</span>')
        ->and($html)->toContain('<span class="crd-badge crd-badge--chase">'.e(Rarity::label('Special illustration rare')).'</span>')
        ->and($html)->toContain('<h1 id="crd-name" class="crd-name">Mega Darkrai ex</h1>')
        ->and($html)->toContain('<span class="crd-mono">#116/120</span>')
        ->and($html)->toContain('Mega Evolution</p>');
});

test('a set with no printed total or series still reads cleanly on the set line', function () {
    ['collection' => $collection, 'card' => $card, 'set' => $set] = seedCardPage();
    $set->update(['card_count' => null, 'series' => null]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    $html = $this->get('/carlos/me05/116')->assertOk()->getContent();

    expect($html)->toContain('<span class="crd-mono">#116</span></p>')
        ->and($html)->not->toContain('#116/');
});

test('a card not in the collection wears the dashed badge', function () {
    ['collection' => $collection, 'card' => $card] = seedCardPage();
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    expect($this->get('/carlos/me05/003')->assertOk()->getContent())
        ->toContain('<span class="crd-badge crd-badge--out">Not in the collection</span>')
        ->not->toContain('crd-badge--own');
});

test('Copy link is a button the page script owns, with a status line, and both survive Livewire morphs', function () {
    $html = ownedCardPageHtml();
    preg_match('#<button[^>]*data-crd-copy[^>]*>#', $html, $button);
    preg_match('#<span[^>]*data-crd-copy-status[^>]*>#', $html, $status);

    expect($button[0] ?? '')->toContain('wire:ignore')
        ->and($html)->toContain('<span class="crd-copy__label">Copy link</span>')
        ->and($status[0] ?? '')->toContain('role="status"')->toContain('wire:ignore');
});

test('the plate shows the official art on a static plate with crop marks, and Livewire leaves it alone', function () {
    $html = ownedCardPageHtml();
    preg_match('#<section class="crd-plate-col"[^>]*>#', $html, $col);

    expect($col[0] ?? '')->toContain('wire:ignore')
        ->and($html)->toContain('src="https://official.example/116/high.webp"')
        ->and($html)->toContain('alt="Mega Darkrai ex, Pitch Black #116"')
        ->and($html)->toContain('data-view="official"')
        ->and($html)->toContain('data-crd-kind="official"')
        ->and($html)->toContain('<span class="crd-ring" aria-hidden="true"></span>')
        ->and($html)->toContain('<span class="crd-dim crd-dim--h crd-mono">63 mm</span>')
        ->and($html)->toContain('<span class="crd-dim crd-dim--v crd-mono">88 mm</span>')
        ->and($html)->not->toContain('data-crd-note');
});

test('the plate offers Actual size, a loupe, tilt hooks and the calibration panel, and the tool note is not a live region', function () {
    $html = ownedCardPageHtml();

    expect($html)->toContain('<div class="crd-sizes" role="group" aria-label="Image size" data-crd-size-group>')
        ->and($html)->toContain('<button type="button" data-crd-size="actual" aria-pressed="false">Actual size</button>')
        ->and($html)->toContain('<button type="button" data-crd-size="fit" aria-pressed="true">Fitted</button>')
        ->and($html)->toContain('data-crd-loupe-toggle')
        ->and($html)->toContain('<div class="crd-loupe" data-crd-loupe aria-hidden="true"></div>')
        ->and($html)->toContain('<div class="crd-card" data-crd-tilt>')
        ->and($html)->toContain('<span class="crd-glare" aria-hidden="true"></span>')
        ->and($html)->toContain('<p class="crd-tool-note" data-crd-tool-note></p>')
        ->and($html)->toContain('<label for="crd-cal">Hold a real card against the screen and drag until the marks match its edges.</label>')
        ->and($html)->not->toContain('aria-live');
});

test('a card with no image offers no size or loupe tools', function () {
    ['collection' => $collection, 'card' => $card] = seedCardPage();
    $card->update(['official_image_url' => null]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    expect($this->get('/carlos/me05/116')->assertOk()->getContent())
        ->not->toContain('data-crd-loupe-toggle')
        ->not->toContain('data-crd-size=')
        ->not->toContain('data-crd-calib');
});

test('a collector photo leads, with its own alt text, the note that says the printed size is for the official art, and a thumb for each view', function () {
    ['collection' => $collection, 'card' => $card] = seedCardPage();
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1, 'photo_path' => 'my-photo.jpg']);
    $photoUrl = Storage::disk('collection-photos')->url('my-photo.jpg');

    $html = $this->get('/carlos/me05/116')->assertOk()->getContent();

    expect($html)->toContain('data-view="photo"')
        ->and($html)->toContain('alt="Your photo of Mega Darkrai ex, Pitch Black #116"')
        ->and($html)->toContain('<p class="crd-note" data-crd-note>Your photo of this copy. The printed size applies to the official art.</p>')
        ->and(substr_count($html, 'data-crd-view="'))->toBe(2)
        ->and($html)->toContain('data-crd-view="'.$photoUrl.'" data-crd-kind="photo" data-crd-alt="Your photo of Mega Darkrai ex, Pitch Black #116" aria-pressed="true"')
        ->and($html)->toContain('data-crd-view="https://official.example/116/high.webp" data-crd-kind="official" data-crd-alt="Mega Darkrai ex, Pitch Black #116" aria-pressed="false"');
});

test('a card with no image keeps its name on a hatched face and offers no views', function () {
    ['collection' => $collection, 'card' => $card] = seedCardPage();
    $card->update(['official_image_url' => null]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    expect($this->get('/carlos/me05/116')->assertOk()->getContent())
        ->toContain('<div class="crd-noimg"><b>Mega Darkrai ex</b><span>No image</span></div>')
        ->not->toContain('data-crd-view=');
});

test('a market day with no price breaks the chart line and is drawn as a dotted gap', function () {
    rangedCardPage([0, 1, 3, 4]);

    $html = $this->get('/carlos/me05/116')->getContent();

    expect(substr_count($html, '<path class="crd-line"'))->toBe(2)
        ->and(substr_count($html, '<line class="crd-gap"'))->toBe(1);
});

test('consecutive days draw one line and no gap', function () {
    rangedCardPage([0, 1, 2]);

    $html = $this->get('/carlos/me05/116')->getContent();

    expect(substr_count($html, '<path class="crd-line"'))->toBe(1)
        ->and($html)->not->toContain('<line class="crd-gap"');
});

test('a manual price is one continuous step line, with no gaps and no dots', function () {
    ['collection' => $collection, 'card' => $card] = seedCardPage();
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1, 'variant' => 'holofoil:cosmos']);
    foreach ([25 => 400, 10 => 500, 2 => 500] as $ago => $minor) {
        CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'manual', 'origin' => 'hand', 'variant' => 'holofoil:cosmos', 'captured_on' => today()->subDays($ago), 'currency' => 'USD', 'market_minor' => $minor]);
    }

    $html = $this->get('/carlos/me05/116?range=3m')->assertOk()->getContent();

    expect(substr_count($html, '<path class="crd-line"'))->toBe(1)
        ->and($html)->not->toContain('<line class="crd-gap"')
        ->and($html)->not->toContain('class="crd-dot"')
        ->and($html)->toContain('<path class="crd-line" d="M0.0 ');
});

test('the end of the line and the range move are green only when the range ended higher', function () {
    rangedCardPage([0, 1, 2]); // 1002 -> 1001 -> 1000: down

    $down = $this->get('/carlos/me05/116')->getContent();
    expect($down)->toContain('<span class="crd-end"')
        ->and($down)->not->toContain('crd-end--up')
        ->and($down)->toContain("\u{2212}\$0.02 since ".DayLabel::of(today()->subDays(2)));

    $card = Card::where('tcgdex_id', 'me05-116')->firstOrFail();
    CardPriceSnapshot::where('card_id', $card->id)->where('variant', 'normal')->whereDate('captured_on', today())->update(['market_minor' => 1100]);

    $up = $this->get('/carlos/me05/116')->getContent();
    expect($up)->toContain('<span class="crd-end crd-end--up"')
        ->and($up)->toContain('<small class="crd-up">+$0.98 since '.DayLabel::of(today()->subDays(2)).'</small>');
});

test('the chart hands its readings to the page script as data, and is a keyboard slider resting on the newest day', function () {
    rangedCardPage([0, 1]);

    $html = $this->get('/carlos/me05/116')->getContent();
    preg_match("#data-points='([^']*)'#", $html, $m);
    $points = json_decode(html_entity_decode($m[1] ?? '[]'), true);

    expect($points)->toHaveCount(2)
        ->and($points[1])->toBe(['x' => 100, 'y' => 90.3, 'read' => DayLabel::of(today()).' · $10.00'])
        ->and($html)->toContain('data-crd-chart tabindex="0" role="slider" aria-label="Price chart. Left and right arrow keys read each day." aria-valuemin="0" aria-valuemax="1" aria-valuenow="1" aria-valuetext="'.DayLabel::of(today()).' · $10.00" aria-describedby="crd-chart-sum"')
        ->and($html)->toContain('<p class="sr-only" id="crd-chart-sum">Market price of Mega Darkrai ex')
        ->and($html)->toContain(', from $10.01 to $10.00</p>')
        ->and($html)->toContain('<svg viewBox="0 0 600 200" preserveAspectRatio="none" aria-hidden="true">')
        ->and($html)->not->toContain('data-crd-chart-status')
        ->and($html)->toContain('<p class="crd-ch-read crd-mono" data-crd-chart-read aria-hidden="true"></p>');
});

test('a pill whose latest price is missing shows no price history, even with older prices', function () {
    $page = rangedCardPage([0, 20]);
    $id = $page->instance()->card->id;
    foreach ([10 => 300, 9 => 310, 0 => null] as $ago => $minor) {
        CardPriceSnapshot::create(['card_id' => $id, 'source' => 'tcgplayer', 'variant' => 'reverse-holofoil', 'captured_on' => today()->subDays($ago), 'currency' => 'USD', 'market_minor' => $minor]);
    }

    $this->get('/carlos/me05/116?variant=reverse-holofoil')->assertOk()
        ->assertSee('No market price for')
        ->assertDontSee('crd-tabs', false)
        ->assertDontSee("selectRange('3m')", false);
});

test('the headline tag shows its 1-day and 7-day moves and its low, and each move is green only when it is a rise', function () {
    seedMovedHeadline(); // $40.00 today, $32.00 yesterday, $8.00 a week ago, low $35.00
    $day = DayLabel::of(today()->subDay());
    $week = DayLabel::of(today()->subDays(7));

    $html = $this->get('/carlos/me05/116')->getContent();

    expect(substr_count($html, 'class="crd-htag crd-htag--lead"'))->toBe(1)
        ->and($html)->toContain('<span class="crd-seg crd-move--up" title="Since '.$day.'">+$8.00 · 1d</span>')
        ->and($html)->toContain('<span class="crd-seg crd-move--up" title="Since '.$week.'">+$32.00 · 7d</span>')
        ->and($html)->toContain('<span class="crd-seg" title="Lowest current listing">low $35.00</span>');
});

test('a fall on the day and a rise on the week colour only the rise', function () {
    seedMovedHeadline(today: 3000);
    $day = DayLabel::of(today()->subDay());
    $week = DayLabel::of(today()->subDays(7));

    expect($this->get('/carlos/me05/116')->getContent())
        ->toContain("<span class=\"crd-seg\" title=\"Since {$day}\">\u{2212}\$2.00 · 1d</span>")
        ->toContain('<span class="crd-seg crd-move--up" title="Since '.$week.'">+$22.00 · 7d</span>');
});

test('when both moves are falls nothing on the tag is green', function () {
    seedMovedHeadline(today: 500);

    expect($this->get('/carlos/me05/116')->getContent())
        ->toContain("\u{2212}\$27.00 · 1d</span>")
        ->toContain("\u{2212}\$3.00 · 7d</span>")
        ->not->toContain('crd-move--up');
});

test('the first move says how many days back its comparison price is, and a card with no stored baseline shows only that move', function () {
    seedMovedHeadline(previousDaysAgo: 3);
    DB::table('card_price_week_baselines')->delete();

    expect($this->get('/carlos/me05/116')->getContent())
        ->toContain('+$8.00 · 3d</span>')
        ->not->toContain('· 7d')
        ->not->toContain('· 1d');
});

test('a read that is not the headline shows its low and trend on separate lines, and no move', function () {
    ['collection' => $collection, 'card' => $card] = seedCardPage();
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'holofoil', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 900]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 700, 'low_minor' => 500, 'trend_minor' => 650]);

    expect($this->get('/carlos/me05/116')->getContent())
        ->toContain('<span class="crd-seg" title="Lowest current listing">low €5.00</span>')
        ->toContain('<span class="crd-seg" title="Price trend">trend €6.50</span>');
});

test('only the headline tag carries the moves, and another source keeps its own low and trend', function () {
    ['card' => $card] = seedMovedHeadline(); // headline: TCGplayer, +$8.00 on the day and +$32.00 on the week
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 700, 'low_minor' => 500, 'trend_minor' => 650]);

    $html = $this->get('/carlos/me05/116')->getContent();
    preg_match('#class="crd-htag crd-htag--lead".*?<b>([^<]+)</b>#s', $html, $lead);
    $chunk = collect(preg_split('/(?=<div class="crd-htag)/', $html))->first(fn (string $tag) => str_contains($tag, '<b>Cardmarket</b>')) ?? '';
    $cardmarket = strstr($chunk, '</section>', true) ?: $chunk;

    expect($lead[1] ?? null)->toBe('TCGplayer')
        ->and($html)->toContain('+$8.00 · 1d')->toContain('+$32.00 · 7d')
        ->and($cardmarket)->toContain('low €5.00')->toContain('trend €6.50')
        ->and($cardmarket)->not->toContain('· 1d')->not->toContain('· 7d');
});

test('an empty market shows one sentence and no tags', function () {
    ['collection' => $collection, 'card' => $card] = seedCardPage();
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    $html = $this->get('/carlos/me05/116')->getContent();

    expect($html)->toContain('<p class="crd-mv-empty">No market price recorded for this card yet.</p>')
        ->and($html)->not->toContain('crd-htag');
});

test('each copy is a ticket stub: quantity, condition or grade, date added and notes', function () {
    ['collection' => $collection, 'card' => $card] = seedCardPage();
    $raw = CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'LP', 'quantity' => 2, 'notes' => 'binder copy']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'grade_company' => 'PSA', 'grade_value' => '10', 'quantity' => 1]);

    $html = $this->get('/carlos/me05/116')->getContent();

    expect(substr_count($html, '<li class="crd-stub"'))->toBe(2)
        ->and($html)->toContain('<h2 id="crd-cp-h" class="crd-sec-t">In the collection · 3 copies</h2>')
        ->and($html)->toContain('<div class="crd-stub-q crd-mono" aria-label="2 copies">×2</div>')
        ->and($html)->toContain('Lightly Played <span class="crd-stub-aside crd-mono">LP</span>')
        ->and($html)->toContain('PSA 10 <span class="crd-stub-aside">graded</span>')
        ->and($html)->toContain('Added <span class="crd-mono">'.$raw->created_at->format('j M Y').'</span>')
        ->and($html)->toContain('<p class="crd-stub-notes">binder copy</p>');
});

test('a card not in the collection says so in a sentence that links its set', function () {
    ['collection' => $collection, 'card' => $card] = seedCardPage();
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    expect($this->get('/carlos/me05/003')->getContent())
        ->toContain('<h2 id="crd-cp-h" class="crd-sec-t">Collection</h2>')
        ->toContain('<p class="crd-note-text">This card is in <a href="'.route('gallery.show', ['username' => 'carlos', 'setTcgdexId' => 'me05']).'">Pitch Black</a>, but not in carlos\'s collection yet.</p>');
});

test('the facts are a catalogue label with numbers in the numeral face', function () {
    $html = ownedCardPageHtml();

    expect($html)->toContain('<dl class="crd-kv">')
        ->toContain('<div><dt>Illustrator</dt><dd>AKIRA EGAWA</dd></div>')
        ->toContain('<div><dt>HP</dt><dd class="crd-mono">280</dd></div>')
        ->toContain('<div><dt>Pokédex</dt><dd class="crd-mono">#0491</dd></div>')
        ->toContain('<div><dt>Catalog ID</dt><dd class="crd-mono">me05-116</dd></div>');
});

test('the related strip is the collection tile: own-print price, 7-day move, a plain link, and a way to the set', function () {
    seedRelatedStrip();

    $html = $this->get('/carlos/me05/116')->getContent();

    expect($html)->toContain('<section class="crd-related col-page" aria-labelledby="crd-rl-h">')
        ->and($html)->toContain('<h2 id="crd-rl-h" class="crd-rel-tab">More from Pitch Black</h2>')
        ->and($html)->toContain('<a class="crd-setlink" href="'.route('gallery.show', ['username' => 'carlos', 'setTcgdexId' => 'me05']).'">View the set</a>')
        ->and($html)->toContain('<ol class="col-grid crd-strip">')
        ->and($html)->toContain('href="'.route('gallery.card', ['username' => 'carlos', 'setTcgdexId' => 'me05', 'localId' => '003']).'"')
        ->and($html)->toContain('aria-label="Fomantis, Pitch Black 003, $9.00, down $1.00 in 7 days"')
        ->and($html)->toContain('<span class="col-price">$9.00</span>')
        ->and($html)->toContain("<span class=\"col-delta\">\u{2212}\$1.00 · 7d</span>");
});

test('a pill the collector holds no copies of says so, naming who holds the others', function () {
    $seed = seedVariantCard();
    ownCopy($seed, 'normal');

    Livewire::withQueryParams(['variant' => 'reverse-holofoil'])
        ->test(CardShow::class, ['username' => 'carlos', 'setTcgdexId' => 'me05', 'localId' => '116'])
        ->assertSeeHtml('<p class="crd-note-text crd-note-text--muted">No Reverse Holofoil copies. carlos holds other variants of this card.</p>');
});

test('a strip card keeps its copies, its slab and its stamps, as its collection tile does', function () {
    ['collection' => $collection, 'card' => $card, 'set' => $set] = seedCardPage();
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);
    $petilil = Card::create(['tcgdex_id' => 'me05-004', 'set_id' => $set->id, 'local_id' => '004', 'name' => 'Petilil', 'rarity' => 'Rare']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $petilil->id, 'card_tcgdex_id' => 'me05-004', 'variant' => 'holofoil:cosmos+player-rewards-program', 'condition' => 'NM', 'quantity' => 2]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $petilil->id, 'card_tcgdex_id' => 'me05-004', 'variant' => 'normal', 'grade_company' => 'PSA', 'grade_value' => '10', 'condition' => 'NM', 'quantity' => 1]);

    $html = $this->get('/carlos/me05/116')->getContent();

    expect($html)->toContain('<span class="col-qty" title="3 copies">×3</span>')
        ->and($html)->toContain('<span class="col-slab">PSA 10</span>')
        ->and($html)->toContain('title="Player Rewards Program">Player Rewards Program</span>')
        ->and($html)->toContain('aria-label="Petilil, Pitch Black 004, 3 copies, graded PSA 10, Player Rewards Program, no price"');
});

test('nothing on the card page uses the old styles or the old navigation', function () {
    seedRelatedStrip();

    expect($this->get('/carlos/me05/116')->getContent())
        ->not->toContain('class="nw-')
        ->not->toContain('class="mono')
        ->not->toContain('imgwrap')
        ->not->toContain('wire:navigate');
});

test('the card page ships the card script, and the landing and the collection do not', function () {
    $html = ownedCardPageHtml();
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
    $script = $manifest['resources/js/card.js']['file'];

    expect($html)->toContain($script)
        ->and($this->get('/')->getContent())->not->toContain($script)
        ->and($this->get('/carlos')->getContent())->not->toContain($script);
});

test('the card script bundle carries the plate tools', function () {
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
    $bundle = (string) file_get_contents(public_path('build/'.$manifest['resources/js/card.js']['file']));

    expect($bundle)->toContain('tcg-vault-cal')->toContain('crd:view');
});
