<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\CardTcgplayerLink;
use App\Modules\Catalog\Models\Set;
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

    Http::assertSent(fn ($request) => str_contains($request['content'], 'no price snapshot'));
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

test('in fill mode a stalled tcgcsv sync is alerted on its own', function () {
    Http::fake();
    config(['tcgcsv.mode' => 'fill']);
    makeSnapshotAt(now()->subHours(2)->toDateTimeString());
    $card = Card::first();
    CardTcgplayerLink::create(['card_id' => $card->id, 'variant' => 'holofoil', 'product_id' => 1, 'sub_type' => 'Holofoil', 'group_id' => 1, 'method' => 'admin']);
    $old = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'origin' => 'tcgcsv', 'variant' => 'holofoil:cosmos', 'captured_on' => now()->subDays(2)->toDateString(), 'currency' => 'USD', 'market_minor' => 129]);
    $old->forceFill(['created_at' => now()->subHours(40)])->save();

    $this->artisan('catalog:check-pricing-freshness')->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request['content'], 'tcgcsv'));
});

test('in fill mode no tcgcsv price yet is not an alert, since there may be no gaps to fill', function () {
    Http::fake();
    config(['tcgcsv.mode' => 'fill']);
    makeSnapshotAt(now()->subHours(2)->toDateTimeString());
    CardTcgplayerLink::create(['card_id' => Card::first()->id, 'variant' => 'holofoil', 'product_id' => 1, 'sub_type' => 'Holofoil', 'group_id' => 1, 'method' => 'admin']);

    $this->artisan('catalog:check-pricing-freshness')->assertSuccessful();

    Http::assertNothingSent();
});
