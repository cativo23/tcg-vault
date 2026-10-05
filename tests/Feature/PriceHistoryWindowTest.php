<?php

declare(strict_types=1);

use App\Livewire\Admin\CollectionItems;
use App\Livewire\Gallery\Activity;
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
// window, never the whole history. The card detail page is the one place
// that still reads it all.

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
