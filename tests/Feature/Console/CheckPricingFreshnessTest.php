<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function makeSnapshotAt(string $createdAt): void
{
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);

    $snapshot = CardPriceSnapshot::create([
        'card_id' => $card->id,
        'source' => 'tcgplayer',
        'variant' => 'default',
        'captured_on' => now()->toDateString(),
        'currency' => 'USD',
        'market_minor' => 100,
    ]);

    $snapshot->forceFill(['created_at' => $createdAt])->save();
}

beforeEach(function () {
    config(['services.discord.alert_webhook_url' => 'https://discord.com/api/webhooks/test']);
});

test('alerts when the newest price snapshot is older than the staleness threshold', function () {
    Http::fake();
    makeSnapshotAt(now()->subHours(30)->toDateTimeString());

    $this->artisan('catalog:check-pricing-freshness')->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request['content'], 'looks stuck'));
});

test('does not alert when the newest price snapshot is within the threshold', function () {
    Http::fake();
    makeSnapshotAt(now()->subHours(4)->toDateTimeString());

    $this->artisan('catalog:check-pricing-freshness')->assertSuccessful();

    Http::assertNothingSent();
});

test('alerts when there are no price snapshots at all, since that also means pricing is stale', function () {
    Http::fake();

    $this->artisan('catalog:check-pricing-freshness')->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request['content'], 'no tcgdex price snapshot'));
});

test('a fresh manual price does not hide a stalled sync', function () {
    Http::fake();
    makeSnapshotAt(now()->subHours(30)->toDateTimeString());
    CardPriceSnapshot::create([
        'card_id' => Card::first()->id, 'source' => 'manual', 'variant' => 'holofoil:cosmos',
        'captured_on' => now()->toDateString(), 'currency' => 'USD', 'market_minor' => 70,
    ]);

    $this->artisan('catalog:check-pricing-freshness')->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request['content'], 'looks stuck'));
});

test('fresh tcgcsv prices do not hide a stalled tcgdex sync', function () {
    Http::fake();
    makeSnapshotAt(now()->subHours(30)->toDateTimeString());
    CardPriceSnapshot::create([
        'card_id' => Card::first()->id, 'source' => 'tcgplayer', 'origin' => 'tcgcsv', 'variant' => 'holofoil:cosmos',
        'captured_on' => now()->toDateString(), 'currency' => 'USD', 'market_minor' => 129,
    ]);

    $this->artisan('catalog:check-pricing-freshness')->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request['content'], 'looks stuck'));
});

/** A set mapped to a TCGplayer group, so the tcgcsv sync has something to fetch. */
function linkOnePrint(): void
{
    DB::table('set_tcgplayer_groups')->insert(['set_id' => Card::first()->set_id, 'group_id' => 1]);
}

test('a tcgcsv sync that last completed over 30h ago is alerted on its own', function () {
    Http::fake();
    config(['tcgcsv.mode' => 'fill']);
    makeSnapshotAt(now()->subHours(2)->toDateTimeString());
    linkOnePrint();
    Cache::forever('tcgcsv:last_run', now()->subHours(40)->toIso8601String());

    $this->artisan('catalog:check-pricing-freshness')->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request['content'], 'tcgcsv'));
});

test('a tcgcsv sync with no completed run on record is alerted', function () {
    Http::fake();
    config(['tcgcsv.mode' => 'fill']);
    makeSnapshotAt(now()->subHours(2)->toDateTimeString());
    linkOnePrint();

    $this->artisan('catalog:check-pricing-freshness')->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request['content'], 'tcgcsv'));
});

test('a recent tcgcsv run that filled no gaps is not an alert, however old its last price', function () {
    Http::fake();
    config(['tcgcsv.mode' => 'fill']);
    makeSnapshotAt(now()->subHours(2)->toDateTimeString());
    linkOnePrint();
    Cache::forever('tcgcsv:last_run', now()->subHours(3)->toIso8601String());
    $old = CardPriceSnapshot::create(['card_id' => Card::first()->id, 'source' => 'tcgplayer', 'origin' => 'tcgcsv', 'variant' => 'holofoil:cosmos', 'captured_on' => now()->subDays(5)->toDateString(), 'currency' => 'USD', 'market_minor' => 129]);
    $old->forceFill(['created_at' => now()->subDays(5)])->save();

    $this->artisan('catalog:check-pricing-freshness')->assertSuccessful();

    Http::assertNothingSent();
});

test('the tcgcsv sync is watched in shadow mode too, since it still runs', function () {
    Http::fake();
    config(['tcgcsv.mode' => 'shadow']);
    makeSnapshotAt(now()->subHours(2)->toDateTimeString());
    linkOnePrint();

    $this->artisan('catalog:check-pricing-freshness')->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request['content'], 'tcgcsv'));
});

test('with no TCGplayer group mapped the tcgcsv sync is not watched', function () {
    Http::fake();
    makeSnapshotAt(now()->subHours(2)->toDateTimeString());

    $this->artisan('catalog:check-pricing-freshness')->assertSuccessful();

    Http::assertNothingSent();
});

test('alert messages give whole hours', function () {
    Http::fake();
    makeSnapshotAt(now()->subHours(30)->subMinutes(20)->toDateTimeString());

    $this->artisan('catalog:check-pricing-freshness')->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request['content'], ' 30h old'));
});
