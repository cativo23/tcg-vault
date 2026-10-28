<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\CardTcgplayerLink;
use App\Modules\Catalog\Models\Set;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.discord.alert_webhook_url' => 'https://discord.com/api/webhooks/test']);
    Http::fake();
});

function coverageSet(string $id = 'mep', bool $mapped = true): Set
{
    $set = Set::firstOrCreate(['tcgdex_id' => $id], ['name' => strtoupper($id)]);
    if ($mapped) {
        DB::table('set_tcgplayer_groups')->insertOrIgnore(['set_id' => $set->id, 'group_id' => 24451]);
    }

    return $set;
}

function coverageCard(string $tcgdexId, bool $linked = true, string $setId = 'mep', bool $mapped = true): Card
{
    $card = Card::create([
        'tcgdex_id' => $tcgdexId, 'set_id' => coverageSet($setId, $mapped)->id,
        'local_id' => substr($tcgdexId, strrpos($tcgdexId, '-') + 1), 'name' => 'Treecko',
    ]);
    if ($linked) {
        CardTcgplayerLink::create(['card_id' => $card->id, 'variant' => 'holofoil', 'product_id' => random_int(1, 1_000_000), 'sub_type' => 'Holofoil', 'group_id' => 24451, 'method' => 'tcgdex-price']);
    }

    return $card;
}

function coverageRow(Card $card, string $source, string $origin, ?string $updatedAt = null, ?string $createdAt = null, string $variant = 'holofoil'): CardPriceSnapshot
{
    $row = CardPriceSnapshot::create([
        'card_id' => $card->id, 'source' => $source, 'origin' => $origin, 'variant' => $variant,
        'captured_on' => now()->toDateString(), 'currency' => $source === 'cardmarket' ? 'EUR' : 'USD',
        'market_minor' => 100, 'source_updated_at' => $updatedAt ?? now()->toDateTimeString(),
    ]);
    $row->forceFill(['created_at' => $createdAt ?? now()->toDateTimeString()])->save();

    return $row;
}

/** Every source up to date, so only the finding a test is about can alert. */
function coverageFreshSources(Card $card): void
{
    coverageRow($card, 'tcgplayer', 'tcgcsv');
}

function coverageAlerts(): int
{
    return Http::recorded()->count();
}

test('a card in a mapped set with no TCGplayer link is reported', function () {
    $linked = coverageCard('mep-001');
    coverageFreshSources($linked);
    coverageCard('mep-055', linked: false);

    $this->artisan('catalog:report-price-coverage')->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request['content'], 'mep-055') && ! str_contains($request['content'], 'mep-001'));
});

test('a set with cards and no mapped group is reported', function () {
    coverageFreshSources(coverageCard('mep-001'));
    coverageCard('swsh3-001', linked: false, setId: 'swsh3', mapped: false);

    $this->artisan('catalog:report-price-coverage')->assertSuccessful();

    // The set is the finding; its cards are not also listed as unlinked cards of a mapped set.
    Http::assertSent(fn ($request) => str_contains($request['content'], 'swsh3') && str_contains($request['content'], 'no TCGplayer group')
        && ! str_contains($request['content'], 'swsh3-001') && ! str_contains($request['content'], 'no TCGplayer link'));
});

test('a price captured today whose TCGplayer date is old is reported as stale', function () {
    $card = coverageCard('mep-001');
    coverageFreshSources($card);
    $frozen = coverageCard('mep-002');
    coverageRow($frozen, 'tcgplayer', 'tcgdex', updatedAt: now()->subDays(8)->toDateTimeString());

    $this->artisan('catalog:report-price-coverage')->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request['content'], 'mep-002') && str_contains($request['content'], 'stale'));
});

test('a stale print someone owns is flagged as owned', function () {
    coverageFreshSources(coverageCard('mep-001'));
    $frozen = coverageCard('mep-002');
    coverageRow($frozen, 'tcgplayer', 'tcgdex', updatedAt: now()->subDays(8)->toDateTimeString());
    $collection = Collection::factory()->for(User::factory()->create())->create();
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $frozen->id, 'card_tcgdex_id' => 'mep-002', 'condition' => 'NM', 'quantity' => 1, 'variant' => 'holofoil']);

    $this->artisan('catalog:report-price-coverage')->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request['content'], 'owned: 1'));
});

test('a fresh TCGplayer date keeps a print out of the report', function () {
    $card = coverageCard('mep-001');
    coverageFreshSources($card);
    coverageRow(coverageCard('mep-002'), 'tcgplayer', 'tcgdex', updatedAt: now()->subDay()->toDateTimeString());

    $this->artisan('catalog:report-price-coverage')->assertSuccessful();

    expect(coverageAlerts())->toBe(0);
});

test('a print with no TCGplayer price in the recent window is unpriced, not stale, and is not listed', function () {
    coverageFreshSources(coverageCard('mep-001'));
    $old = coverageCard('mep-002');
    CardPriceSnapshot::create([
        'card_id' => $old->id, 'source' => 'tcgplayer', 'origin' => 'tcgdex', 'variant' => 'holofoil',
        'captured_on' => now()->subDays(40)->toDateString(), 'currency' => 'USD', 'market_minor' => 100,
        'source_updated_at' => now()->subDays(40)->toDateTimeString(),
    ]);

    $this->artisan('catalog:report-price-coverage')->assertSuccessful();

    Http::assertNotSent(fn ($request) => str_contains($request['content'], 'mep-002'));
});

test('the age of the newest price rows is not a finding, the sync alerts watch that', function () {
    $card = coverageCard('mep-001');
    coverageRow($card, 'tcgplayer', 'tcgcsv', createdAt: now()->subHours(40)->toDateTimeString());
    coverageRow($card, 'cardmarket', 'tcgdex', createdAt: now()->subHours(40)->toDateTimeString(), variant: 'normal');

    $this->artisan('catalog:report-price-coverage')->assertSuccessful();

    expect(coverageAlerts())->toBe(0);
});

test('a finding is sent once and not again on the next run', function () {
    coverageFreshSources(coverageCard('mep-001'));
    coverageCard('mep-055', linked: false);

    $this->artisan('catalog:report-price-coverage')->assertSuccessful();
    $this->artisan('catalog:report-price-coverage')->assertSuccessful();

    expect(coverageAlerts())->toBe(1);
});

test('a new finding is sent even when an older one was already reported', function () {
    coverageFreshSources(coverageCard('mep-001'));
    coverageCard('mep-055', linked: false);
    $this->artisan('catalog:report-price-coverage')->assertSuccessful();

    coverageCard('mep-056', linked: false);
    $this->artisan('catalog:report-price-coverage')->assertSuccessful();

    expect(coverageAlerts())->toBe(2);
    Http::assertSent(fn ($request) => str_contains($request['content'], 'mep-056') && ! str_contains($request['content'], 'mep-055'));
});

test('nothing is sent when every print is linked and every source is fresh', function () {
    coverageFreshSources(coverageCard('mep-001'));

    $this->artisan('catalog:report-price-coverage')->assertSuccessful();

    expect(coverageAlerts())->toBe(0);
});

test('findings past the listed ten are named on the next run instead of being silenced', function () {
    coverageFreshSources(coverageCard('mep-001'));
    foreach (range(100, 111) as $n) {
        coverageCard("mep-{$n}", linked: false);
    }

    $this->artisan('catalog:report-price-coverage')->assertSuccessful();
    $this->artisan('catalog:report-price-coverage')->assertSuccessful();

    expect(coverageAlerts())->toBe(2);
    Http::assertSent(fn ($request) => str_contains($request['content'], 'mep-110') && str_contains($request['content'], 'mep-111') && ! str_contains($request['content'], 'mep-100'));
});

test('a print is judged by its newest TCGplayer price across origins', function () {
    coverageFreshSources(coverageCard('mep-001'));
    $card = coverageCard('mep-002');
    // tcgdex re-stamps a frozen price with today's capture day; yesterday's tcgcsv row is the live one.
    coverageRow($card, 'tcgplayer', 'tcgdex', updatedAt: now()->subDays(8)->toDateTimeString());
    CardPriceSnapshot::create([
        'card_id' => $card->id, 'source' => 'tcgplayer', 'origin' => 'tcgcsv', 'variant' => 'holofoil',
        'captured_on' => now()->subDay()->toDateString(), 'currency' => 'USD', 'market_minor' => 100,
        'source_updated_at' => now()->subDay()->toDateTimeString(),
    ]);

    $this->artisan('catalog:report-price-coverage')->assertSuccessful();

    Http::assertNotSent(fn ($request) => str_contains($request['content'], 'mep-002'));
});

test('a stale print without a TCGplayer date falls back to its capture day', function () {
    coverageFreshSources(coverageCard('mep-001'));
    $card = coverageCard('mep-002');
    CardPriceSnapshot::create([
        'card_id' => $card->id, 'source' => 'tcgplayer', 'origin' => 'tcgdex', 'variant' => 'holofoil',
        'captured_on' => now()->subDays(8)->toDateString(), 'currency' => 'USD', 'market_minor' => 100,
    ]);

    $this->artisan('catalog:report-price-coverage')->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request['content'], 'mep-002'));
});

test('a finding is repeated once its quiet week is over', function () {
    coverageFreshSources(coverageCard('mep-001'));
    coverageCard('mep-055', linked: false);
    $this->artisan('catalog:report-price-coverage')->assertSuccessful();

    $this->travel(8)->days();
    // Everything else must still look fresh a week on.
    DB::table('card_price_snapshots')->update(['created_at' => now(), 'source_updated_at' => now()]);
    $this->artisan('catalog:report-price-coverage')->assertSuccessful();

    expect(coverageAlerts())->toBe(2);
});
