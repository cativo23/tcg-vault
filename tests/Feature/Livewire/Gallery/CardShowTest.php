<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use Illuminate\Support\Facades\Storage;

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
    $response->assertSee('as of '.today()->subDays(12)->format('j M'));
    $response->assertSeeInOrder(['box-shadow: 0 0 0 1.5px var(--ink)', 'Cardmarket'], false);
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
    $response->assertSeeInOrder(['$9.00', 'as of '.today()->subDays(12)->format('j M'), 'Price history']);
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
    $response->assertSee('<span class="mono" style="letter-spacing: -.02em">'.today()->subDay()->format('j M Y').'</span>', false);
    $response->assertDontSee(today()->format('j M Y').' · via');
});

test('a manual price says when it was entered, and every tile says where its price came from', function () {
    ['collection' => $collection, 'card' => $card] = seedCardPage();
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1, 'variant' => 'holofoil:cosmos']);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'holofoil', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 16027, 'low_minor' => 15000]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'manual', 'origin' => 'hand', 'variant' => 'holofoil:cosmos', 'captured_on' => today()->subDays(2), 'currency' => 'USD', 'market_minor' => 500, 'low_minor' => 400]);

    $response = $this->get('/carlos/me05/116');

    $response->assertOk();
    $response->assertSee('entered '.today()->subDays(2)->format('j M'));
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

test('a manual price copied from a feed says so, is never shown as a move, and dates older entries with the year', function () {
    ['collection' => $collection, 'card' => $card] = seedCardPage();
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1, 'variant' => 'holofoil:cosmos']);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'manual', 'origin' => 'hand', 'variant' => 'holofoil:cosmos', 'captured_on' => today()->subYear(), 'currency' => 'USD', 'market_minor' => 400]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'manual', 'origin' => 'hand', 'variant' => 'holofoil:cosmos', 'captured_on' => today()->subYear()->addDay(), 'currency' => 'USD', 'market_minor' => 500, 'raw' => ['origin' => 'tcgcsv']]);

    $response = $this->get('/carlos/me05/116');

    $response->assertOk();
    $response->assertSee('copied by hand from TCGplayer (tcgcsv)');
    $response->assertSee('entered '.today()->subYear()->addDay()->format('j M Y'));
    $response->assertDontSee('class="amt up"', false);
});
