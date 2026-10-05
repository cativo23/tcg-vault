<?php

declare(strict_types=1);

use App\Livewire\Admin\CollectionItems;
use App\Livewire\Gallery\Activity;
use App\Livewire\Gallery\CardShow;
use App\Livewire\Gallery\Index;
use App\Livewire\Gallery\Show;
use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use App\Modules\Collection\Services\CollectionCsvExporter;
use App\Modules\Collection\Services\PublicCollection;
use Livewire\Livewire;

// Every listing screen walks a card's price history in PHP, and the sync
// adds rows for every card every day — so they load only the recent
// window, never the whole history. The card detail page loads it all but
// prices from the same window; only its sparkline uses the rest.

beforeEach(function () {
    $this->user = User::factory()->create(['username' => 'carlos']);
    $this->collection = Collection::factory()->for($this->user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 1]);
    $this->card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $this->collection->id, 'card_id' => $this->card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 1]);

    foreach ([0, 1, CardPriceSnapshot::RECENT_DAYS, CardPriceSnapshot::RECENT_DAYS + 1, 90] as $daysAgo) {
        CardPriceSnapshot::create(['card_id' => $this->card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDays($daysAgo), 'currency' => 'USD', 'market_minor' => 1000 + $daysAgo]);
    }
});

/** @return list<string> */
function loadedDays(Card $card): array
{
    return $card->priceSnapshots->map(fn (CardPriceSnapshot $s) => $s->capturedOnKey())->sort()->values()->all();
}

function expectedRecentDays(): array
{
    return collect([CardPriceSnapshot::RECENT_DAYS, 1, 0])->map(fn (int $d) => today()->subDays($d)->toDateString())->all();
}

test('the recent scope keeps the window up to and including its first day', function () {
    $days = CardPriceSnapshot::query()->recent()->get()->map->capturedOnKey()->sort()->values()->all();

    expect($days)->toBe(expectedRecentDays());
});

test('the public collection loads only recent price history', function () {
    $card = PublicCollection::for($this->user)->cardsQuery()->firstOrFail();

    expect(loadedDays($card))->toBe(expectedRecentDays());
});

test('the collection grid loads only recent price history', function () {
    $entries = Livewire::test(Index::class, ['username' => 'carlos'])->viewData('entries');

    expect(loadedDays($entries->first()['card']))->toBe(expectedRecentDays());
});

test('a set page loads only recent price history', function () {
    $entries = Livewire::test(Show::class, ['username' => 'carlos', 'setTcgdexId' => 'me05'])->viewData('entries');

    expect(loadedDays($entries->first()['card']))->toBe(expectedRecentDays());
});

test('the activity feed loads only recent price history for added copies', function () {
    $added = Livewire::test(Activity::class, ['username' => 'carlos'])->viewData('feed')
        ->firstWhere('kind', 'added');

    expect(loadedDays($added['card']))->toBe(expectedRecentDays());
});

test('the collector admin list loads only recent price history', function () {
    $groups = Livewire::actingAs($this->user)->test(CollectionItems::class)->viewData('cardGroups');

    expect(loadedDays($groups->first()->card))->toBe(expectedRecentDays());
});

test('the CSV export prices a copy from recent history only', function () {
    CardPriceSnapshot::where('captured_on', '>=', today()->subDays(CardPriceSnapshot::RECENT_DAYS))->delete();

    $this->actingAs($this->user);
    $out = fopen('php://memory', 'w+');
    (new CollectionCsvExporter)->write($out);
    rewind($out);
    $lines = preg_split('/\r\n/', trim(preg_replace('/^\xEF\xBB\xBF/', '', stream_get_contents($out))));
    $row = array_combine(CollectionCsvExporter::HEADER, str_getcsv($lines[1], escape: ''));

    // Only rows older than the window remain, so the copy has no price.
    expect($row['market_price'])->toBe('')->and($row['price_date'])->toBe('');
});

test('the card page prices a card from the same window as the listings, but charts its full history', function () {
    // tcgplayer stopped listing this print before the window; cardmarket
    // still prices it. Without the window the card page would keep the old
    // tcgplayer USD figure while every listing shows the cardmarket EUR one.
    CardPriceSnapshot::query()->delete();
    $old = today()->subDays(CardPriceSnapshot::RECENT_DAYS + 10);
    CardPriceSnapshot::create(['card_id' => $this->card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => $old, 'currency' => 'USD', 'market_minor' => 5000]);
    CardPriceSnapshot::create(['card_id' => $this->card->id, 'source' => 'cardmarket', 'variant' => 'normal', 'captured_on' => $old, 'currency' => 'EUR', 'market_minor' => 300]);
    CardPriceSnapshot::create(['card_id' => $this->card->id, 'source' => 'cardmarket', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 400]);

    $grid = Livewire::test(Index::class, ['username' => 'carlos'])->viewData('entries')->first();
    $page = Livewire::test(CardShow::class, ['username' => 'carlos', 'setTcgdexId' => 'me05', 'localId' => '116']);

    expect($page->viewData('snapshot')->market_minor)->toBe(400)
        ->and($grid['snapshot']->market_minor)->toBe(400)
        ->and($page->viewData('marketReads')->pluck('source')->all())->toBe(['cardmarket'])
        ->and($page->viewData('history')->pluck('market_minor')->all())->toBe([300, 400])
        ->and($page->viewData('ownedTotal'))->toBe(['EUR' => 400]);
});

test('a card priced only before the window says so instead of claiming it was never priced', function () {
    CardPriceSnapshot::where('captured_on', '>=', CardPriceSnapshot::recentFrom())->delete();

    $this->get('/carlos/me05/116')
        ->assertOk()
        ->assertSee('No market price in the last '.CardPriceSnapshot::RECENT_DAYS.' days.')
        ->assertDontSee('No market price recorded for this card yet.');
});

test('a card never priced at all still says it has no price yet', function () {
    CardPriceSnapshot::query()->delete();

    $this->get('/carlos/me05/116')
        ->assertOk()
        ->assertSee('No market price recorded for this card yet.');
});
