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
