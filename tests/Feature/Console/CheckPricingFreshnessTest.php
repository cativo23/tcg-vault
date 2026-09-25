<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
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
