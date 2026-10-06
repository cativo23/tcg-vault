<?php

declare(strict_types=1);

use App\Jobs\SyncTcgcsvPricesJob;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\CardTcgplayerLink;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Tcgcsv\TcgcsvClient;
use App\Modules\Catalog\Tcgcsv\TcgplayerLinkDiscovery;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Pitch Black (group 24688) and 30th Celebration (24722), one owned print each. */
function shadowFixture(): array
{
    $pb = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $c30 = Set::create(['tcgdex_id' => '30th', 'name' => '30th Celebration']);
    DB::table('set_tcgplayer_groups')->insert([['set_id' => $pb->id, 'group_id' => 24688], ['set_id' => $c30->id, 'group_id' => 24722]]);

    $darkrai = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $pb->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CardPriceSnapshot::create(['card_id' => $darkrai->id, 'source' => 'tcgplayer', 'variant' => 'holofoil', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 16027, 'raw' => ['productId' => 704873]]);
    $pikachu = Card::create(['tcgdex_id' => '30th-047', 'set_id' => $c30->id, 'local_id' => '047', 'name' => 'Pikachu', 'raw' => ['variants_detailed' => [
        ['type' => 'holo', 'size' => 'standard', 'stamp' => ['30th-anniversary'], 'thirdParty' => ['tcgplayer' => 696682]],
    ]]]);

    return compact('darkrai', 'pikachu');
}

function tcgcsvPricesResponse(array $rows): array
{
    return ['success' => true, 'errors' => [], 'results' => $rows];
}

function runShadowSync(): void
{
    (new SyncTcgcsvPricesJob)->handle(app(TcgcsvClient::class), app(TcgplayerLinkDiscovery::class));
}

test('in shadow mode it links prints and compares prices, writing no price at all', function () {
    ['darkrai' => $darkrai, 'pikachu' => $pikachu] = shadowFixture();
    Http::fake([
        'tcgcsv.com/last-updated.txt' => Http::response('2026-10-05T20:05:57+0000', 200),
        'tcgcsv.com/tcgplayer/3/24688/prices' => Http::response(tcgcsvPricesResponse([['productId' => 704873, 'lowPrice' => 150.0, 'marketPrice' => 160.63, 'subTypeName' => 'Holofoil']]), 200),
        'tcgcsv.com/tcgplayer/3/24722/prices' => Http::response(tcgcsvPricesResponse([['productId' => 696682, 'lowPrice' => 0.29, 'marketPrice' => 0.49, 'subTypeName' => 'Holofoil']]), 200),
    ]);
    Log::spy();
    $before = CardPriceSnapshot::count();

    runShadowSync();

    expect(CardPriceSnapshot::count())->toBe($before);
    expect(CardTcgplayerLink::where('card_id', $darkrai->id)->value('product_id'))->toBe(704873);
    expect(CardTcgplayerLink::where('card_id', $pikachu->id)->value('product_id'))->toBe(696682);
    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context) => $message === 'tcgcsv shadow sync'
        && $context['groups_ok'] === 2 && $context['groups_failed'] === 0
        && $context['compared'] === 1 && $context['within_2_percent'] === 1
        && $context['linked_without_tcgdex_price'] === 1);
    Http::assertSent(fn ($request) => str_starts_with($request->header('User-Agent')[0] ?? '', 'tcg-vault/'));
});

test('a group that fails does not stop the others', function () {
    ['pikachu' => $pikachu] = shadowFixture();
    Http::fake([
        'tcgcsv.com/last-updated.txt' => Http::response('2026-10-05T20:05:57+0000', 200),
        'tcgcsv.com/tcgplayer/3/24688/prices' => Http::response('down', 503),
        'tcgcsv.com/tcgplayer/3/24722/prices' => Http::response(tcgcsvPricesResponse([['productId' => 696682, 'marketPrice' => 0.49, 'subTypeName' => 'Holofoil']]), 200),
    ]);
    Log::spy();

    runShadowSync();

    expect(CardTcgplayerLink::where('card_id', $pikachu->id)->exists())->toBeTrue();
    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context) => $message === 'tcgcsv shadow sync'
        && $context['groups_ok'] === 1 && $context['groups_failed'] === 1);
});

test('a build already ingested is not pulled again', function () {
    shadowFixture();
    Cache::put('tcgcsv:last_build', '2026-10-05T20:05:57+00:00');
    Http::fake(['tcgcsv.com/last-updated.txt' => Http::response('2026-10-05T20:05:57+0000', 200)]);

    runShadowSync();

    Http::assertSentCount(1);
});

test('the tcgcsv sync runs after tcgcsv\'s daily build', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->description, 'SyncTcgcsvPricesJob'));

    expect($event?->expression)->toBe('30 20 * * *');
});
