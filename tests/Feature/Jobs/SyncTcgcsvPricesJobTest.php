<?php

declare(strict_types=1);

use App\Jobs\SyncTcgcsvPricesJob;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\CardTcgplayerLink;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Services\CardPriceResolver;
use App\Modules\Catalog\Tcgcsv\TcgcsvClient;
use App\Modules\Catalog\Tcgcsv\TcgplayerLinkDiscovery;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;

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

/** A spy standing in for the tcgcsv log channel. */
function tcgcsvLog(): MockInterface
{
    $logger = Mockery::spy(LoggerInterface::class);
    Log::shouldReceive('channel')->with('tcgcsv')->andReturn($logger);
    Log::shouldReceive('error')->andReturnNull(); // report() of a failed group

    return $logger;
}

function runTcgcsvSync(): void
{
    (new SyncTcgcsvPricesJob)->handle(app(TcgcsvClient::class), app(TcgplayerLinkDiscovery::class));
}

test('in shadow mode it links prints and compares prices, writing no price at all', function () {
    config(['tcgcsv.mode' => 'shadow']);
    ['darkrai' => $darkrai, 'pikachu' => $pikachu] = shadowFixture();
    Http::fake([
        'tcgcsv.com/last-updated.txt' => Http::response('2026-10-05T20:05:57+0000', 200),
        'tcgcsv.com/tcgplayer/3/24688/prices' => Http::response(tcgcsvPricesResponse([['productId' => 704873, 'lowPrice' => 150.0, 'marketPrice' => 160.63, 'subTypeName' => 'Holofoil']]), 200),
        'tcgcsv.com/tcgplayer/3/24722/prices' => Http::response(tcgcsvPricesResponse([['productId' => 696682, 'lowPrice' => 0.29, 'marketPrice' => 0.49, 'subTypeName' => 'Holofoil']]), 200),
    ]);
    $log = tcgcsvLog();
    $before = CardPriceSnapshot::count();

    runTcgcsvSync();

    expect(CardPriceSnapshot::count())->toBe($before);
    expect(CardTcgplayerLink::where('card_id', $darkrai->id)->value('product_id'))->toBe(704873);
    expect(CardTcgplayerLink::where('card_id', $pikachu->id)->value('product_id'))->toBe(696682);
    $log->shouldHaveReceived('info')->withArgs(fn (string $message, array $context) => $message === 'tcgcsv sync'
        && $context['groups_ok'] === 2 && $context['groups_failed'] === 0 && $context['partial_retry'] === false
        && $context['compared'] === 1 && $context['within_2_percent'] === 1
        && $context['linked_without_tcgdex_price'] === 1 && $context['groups_fetched'] === [24688, 24722]);
    Http::assertSent(fn ($request) => str_starts_with($request->header('User-Agent')[0] ?? '', 'tcg-vault/'));
});

test('a group that fails does not stop the others', function () {
    ['pikachu' => $pikachu] = shadowFixture();
    Http::fake([
        'tcgcsv.com/last-updated.txt' => Http::response('2026-10-05T20:05:57+0000', 200),
        'tcgcsv.com/tcgplayer/3/24688/prices' => Http::response('down', 503),
        'tcgcsv.com/tcgplayer/3/24722/prices' => Http::response(tcgcsvPricesResponse([['productId' => 696682, 'marketPrice' => 0.49, 'subTypeName' => 'Holofoil']]), 200),
    ]);
    $log = tcgcsvLog();

    runTcgcsvSync();

    expect(CardTcgplayerLink::where('card_id', $pikachu->id)->exists())->toBeTrue();
    $log->shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $message === 'tcgcsv group failed' && $context === ['group' => 24688, 'error' => 'RequestException']);
    $log->shouldHaveReceived('info')->withArgs(fn (string $message, array $context) => $message === 'tcgcsv sync'
        && $context['groups_ok'] === 1 && $context['groups_failed'] === 1);
});

test('a build already ingested is not pulled again', function () {
    shadowFixture();
    Cache::put('tcgcsv:last_build', '2026-10-05T20:05:57+00:00');
    Http::fake(['tcgcsv.com/last-updated.txt' => Http::response('2026-10-05T20:05:57+0000', 200)]);

    runTcgcsvSync();

    Http::assertSentCount(1);
});

test('the tcgcsv sync runs after tcgcsv\'s daily build', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->description, 'SyncTcgcsvPricesJob'));

    expect($event?->expression)->toBe('30 20 * * *');
});

test('it runs on its own long-timeout queue and backs off between retries', function () {
    $job = new SyncTcgcsvPricesJob;

    expect($job->connection)->toBe('redis-long');
    expect($job->queue)->toBe('tcgcsv');
    expect($job->timeout)->toBe(600);
    expect($job->backoff())->toBe([600, 1800, 3600]);
    // The connection must not hand the job to a second worker mid-run.
    expect(config('queue.connections.redis-long.retry_after'))->toBeGreaterThan(600);
    expect(collect(config('horizon.defaults'))->firstWhere('connection', 'redis-long')['queue'] ?? null)->toBe(['tcgcsv']);
});

test('a fetched build is recorded before linking, so a later failure never re-pulls it', function () {
    shadowFixture();
    Http::fake([
        'tcgcsv.com/last-updated.txt' => Http::response('2026-10-05T20:05:57+0000', 200),
        'tcgcsv.com/tcgplayer/3/24688/prices' => Http::response(tcgcsvPricesResponse([['productId' => 704873, 'marketPrice' => 160.63, 'subTypeName' => 'Holofoil']]), 200),
        'tcgcsv.com/tcgplayer/3/24722/prices' => Http::response(tcgcsvPricesResponse([]), 200),
    ]);
    CardTcgplayerLink::creating(fn () => throw new RuntimeException('database went away'));

    expect(fn () => runTcgcsvSync())->toThrow(RuntimeException::class);
    expect(Cache::get('tcgcsv:last_build'))->toBe('2026-10-05T20:05:57+00:00');
});

test('an unpublished build waits an hour, and late in the day the job ends quietly', function () {
    shadowFixture();
    Cache::put('tcgcsv:last_build', '2026-10-05T20:05:57+00:00');
    Http::fake(['tcgcsv.com/last-updated.txt' => Http::response('2026-10-05T20:05:57+0000', 200)]);

    $this->travelTo(today()->setTime(20, 30));
    $queued = Mockery::mock(Job::class);
    $queued->shouldReceive('release')->once()->with(3600);
    $job = new SyncTcgcsvPricesJob;
    $job->setJob($queued);
    $job->handle(app(TcgcsvClient::class), app(TcgplayerLinkDiscovery::class));

    $this->travelTo(today()->setTime(22, 45));
    $late = Mockery::mock(Job::class);
    $late->shouldNotReceive('release');
    $job = new SyncTcgcsvPricesJob;
    $job->setJob($late);
    $job->handle(app(TcgcsvClient::class), app(TcgplayerLinkDiscovery::class));
});

test('a failed build check is retried later instead of failing the job', function () {
    shadowFixture();
    Http::fake(['tcgcsv.com/last-updated.txt' => Http::response('down', 503)]);

    $this->travelTo(today()->setTime(20, 30));
    $queued = Mockery::mock(Job::class);
    $queued->shouldReceive('release')->once()->with(3600);
    $job = new SyncTcgcsvPricesJob;
    $job->setJob($queued);
    $job->handle(app(TcgcsvClient::class), app(TcgplayerLinkDiscovery::class));

    Http::assertSentCount(1);
});

test('with no groups configured it only checks the build', function () {
    Http::fake(['tcgcsv.com/last-updated.txt' => Http::response('2026-10-05T20:05:57+0000', 200)]);
    $log = tcgcsvLog();

    runTcgcsvSync();

    Http::assertSentCount(1);
    $log->shouldHaveReceived('info')->withArgs(fn (string $message, array $context) => $message === 'tcgcsv sync' && $context['groups_ok'] === 0);
});

test('a partly failed build is not marked as pulled, so its groups are retried', function () {
    shadowFixture();
    Http::fake([
        'tcgcsv.com/last-updated.txt' => Http::response('2026-10-05T20:05:57+0000', 200),
        'tcgcsv.com/tcgplayer/3/24688/prices' => Http::response('down', 503),
        'tcgcsv.com/tcgplayer/3/24722/prices' => Http::response(tcgcsvPricesResponse([]), 200),
    ]);

    runTcgcsvSync();

    expect(Cache::get('tcgcsv:last_build'))->toBeNull();
});

test('a retry of a partly failed build fetches only the groups that failed', function () {
    shadowFixture();
    $calls = 0;
    Http::fake([
        'tcgcsv.com/last-updated.txt' => Http::response('2026-10-05T20:05:57+0000', 200),
        'tcgcsv.com/tcgplayer/3/24688/prices' => function () use (&$calls) {
            return ++$calls === 1
                ? Http::response('down', 503)
                : Http::response(tcgcsvPricesResponse([['productId' => 704873, 'marketPrice' => 160.63, 'subTypeName' => 'Holofoil']]), 200);
        },
        'tcgcsv.com/tcgplayer/3/24722/prices' => Http::response(tcgcsvPricesResponse([]), 200),
    ]);

    runTcgcsvSync();
    runTcgcsvSync();

    $sent = Http::recorded()->map(fn ($r) => $r[0]->url())->all();
    expect(array_count_values($sent))->toBe([
        'https://tcgcsv.com/last-updated.txt' => 2,
        'https://tcgcsv.com/tcgplayer/3/24688/prices' => 2,
        'https://tcgcsv.com/tcgplayer/3/24722/prices' => 1,
    ]);
    expect(Cache::get('tcgcsv:last_build'))->toBe('2026-10-05T20:05:57+00:00');
});

test('the day\'s comparison is kept for the shadow-phase review, a retry marked as partial', function () {
    shadowFixture();
    $calls = 0;
    Http::fake([
        'tcgcsv.com/last-updated.txt' => Http::response('2026-10-05T20:05:57+0000', 200),
        'tcgcsv.com/tcgplayer/3/24688/prices' => function () use (&$calls) {
            return ++$calls === 1 ? Http::response('down', 503) : Http::response(tcgcsvPricesResponse([]), 200);
        },
        'tcgcsv.com/tcgplayer/3/24722/prices' => Http::response(tcgcsvPricesResponse([]), 200),
    ]);

    runTcgcsvSync();
    runTcgcsvSync();

    $runs = Cache::get('tcgcsv:shadow:'.now()->toDateString());
    expect($runs)->toHaveCount(2);
    expect([$runs[0]['partial_retry'], $runs[1]['partial_retry'], $runs[1]['groups_fetched']])->toBe([false, true, [24688]]);
});

test('a throttle stops the loop and leaves the remaining groups for the retry', function () {
    $c = Set::create(['tcgdex_id' => 'x', 'name' => 'x']);
    DB::table('set_tcgplayer_groups')->insert([['set_id' => $c->id, 'group_id' => 1], ['set_id' => $c->id, 'group_id' => 2], ['set_id' => $c->id, 'group_id' => 3]]);
    Http::fake([
        'tcgcsv.com/last-updated.txt' => Http::response('2026-10-05T20:05:57+0000', 200),
        'tcgcsv.com/tcgplayer/3/1/prices' => Http::response('slow down', 429),
        'tcgcsv.com/tcgplayer/3/*' => Http::response(tcgcsvPricesResponse([]), 200),
    ]);
    $log = tcgcsvLog();

    runTcgcsvSync();

    Http::assertSentCount(2); // the build check and group 1 only
    $log->shouldHaveReceived('info')->withArgs(fn (string $m, array $c) => $m === 'tcgcsv sync' && $c['groups_failed'] === 3);
});

test('a bug in a group\'s handling is not swallowed as a failed group', function () {
    shadowFixture();
    Http::fake([
        'tcgcsv.com/last-updated.txt' => Http::response('2026-10-05T20:05:57+0000', 200),
        'tcgcsv.com/tcgplayer/3/*' => Http::response(tcgcsvPricesResponse([]), 200),
    ]);
    Cache::shouldReceive('get')->andReturnUsing(fn () => null);
    Cache::shouldReceive('put')->andThrow(new LogicException('cache misconfigured'));

    expect(fn () => runTcgcsvSync())->toThrow(LogicException::class);
});

test('a run after the evening cutoff still gets an hour', function () {
    $this->travelTo(today()->setTime(23, 45));

    expect((new SyncTcgcsvPricesJob)->retryUntil()->format('H:i'))->toBe('00:45');
});

test('a partly failed build is released for its retry', function () {
    $this->travelTo(today()->setTime(20, 30));
    shadowFixture();
    Http::fake([
        'tcgcsv.com/last-updated.txt' => Http::response('2026-10-05T20:05:57+0000', 200),
        'tcgcsv.com/tcgplayer/3/24688/prices' => Http::response('down', 503),
        'tcgcsv.com/tcgplayer/3/24722/prices' => Http::response(tcgcsvPricesResponse([]), 200),
    ]);
    $queued = Mockery::mock(Job::class);
    $queued->shouldReceive('release')->once()->with(3600);
    $job = new SyncTcgcsvPricesJob;
    $job->setJob($queued);
    $job->handle(app(TcgcsvClient::class), app(TcgplayerLinkDiscovery::class));
});

/** The Tyranitar shape: a linked print tcgdex prices on cardmarket only. */
function gapFixture(): Card
{
    $set = Set::create(['tcgdex_id' => 'sv10', 'name' => 'Destined Rivals']);
    DB::table('set_tcgplayer_groups')->insert(['set_id' => $set->id, 'group_id' => 24269]);
    $card = Card::create(['tcgdex_id' => 'sv10-096', 'set_id' => $set->id, 'local_id' => '096', 'name' => "Team Rocket's Tyranitar"]);
    CardTcgplayerLink::create(['card_id' => $card->id, 'variant' => 'holofoil:cosmos', 'product_id' => 659941, 'sub_type' => 'Holofoil', 'group_id' => 2374, 'method' => 'admin']);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'holofoil:cosmos', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 144]);

    return $card;
}

function gapFake(array $mcap = [['productId' => 659941, 'lowPrice' => 0.53, 'marketPrice' => 1.29, 'subTypeName' => 'Holofoil']]): void
{
    Http::fake([
        'tcgcsv.com/last-updated.txt' => Http::response('2026-10-07T20:05:57+0000', 200),
        'tcgcsv.com/tcgplayer/3/2374/prices' => Http::response(tcgcsvPricesResponse($mcap), 200),
        'tcgcsv.com/tcgplayer/3/24269/prices' => Http::response(tcgcsvPricesResponse([]), 200),
    ]);
}

test('in fill mode a linked print tcgdex has no TCGplayer price for gets tcgcsv\'s, daily', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = gapFixture();
    gapFake();

    runTcgcsvSync();

    $row = CardPriceSnapshot::where('card_id', $card->id)->where('source', 'tcgplayer')->sole();
    expect([$row->origin, $row->variant, $row->currency, $row->market_minor, $row->low_minor, $row->capturedOnKey()])
        ->toBe(['tcgcsv', 'holofoil:cosmos', 'USD', 129, 53, today()->toDateString()]);
    expect($row->raw['productId'])->toBe(659941);
    expect($row->source_updated_at->toIso8601String())->toBe('2026-10-07T20:05:57+00:00');
    expect((new CardPriceResolver)->resolveForVariant($card->fresh(), 'holofoil:cosmos')->id)->toBe($row->id);
});

test('fill mode never writes over a print tcgdex still prices on TCGplayer', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = gapFixture();
    $tcgdex = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'holofoil:cosmos', 'captured_on' => today()->subDay(), 'currency' => 'USD', 'market_minor' => 150]);
    gapFake();

    runTcgcsvSync();

    expect(CardPriceSnapshot::where('card_id', $card->id)->where('source', 'tcgplayer')->pluck('id')->all())->toBe([$tcgdex->id]);
});

test('fill mode leaves a manual price in place, and the filled price is the one shown', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = gapFixture();
    $manual = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'manual', 'origin' => 'hand', 'variant' => 'holofoil:cosmos', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 100]);
    gapFake();

    runTcgcsvSync();

    $filled = CardPriceSnapshot::where('card_id', $card->id)->where('source', 'tcgplayer')->sole();
    expect($manual->fresh()->market_minor)->toBe(100);
    expect((new CardPriceResolver)->resolveForVariant($card->fresh(), 'holofoil:cosmos')->id)->toBe($filled->id);
});

test('fill mode writes nothing for a print tcgcsv has no market price for', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = gapFixture();
    gapFake([['productId' => 659941, 'lowPrice' => null, 'marketPrice' => null, 'subTypeName' => 'Holofoil']]);

    runTcgcsvSync();

    expect(CardPriceSnapshot::where('card_id', $card->id)->where('source', 'tcgplayer')->count())->toBe(0);
});

test('a second run the same day updates the price it filled earlier', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = gapFixture();
    Http::fake([
        'tcgcsv.com/last-updated.txt' => Http::sequence()->push('2026-10-07T20:05:57+0000')->push('2026-10-07T21:05:57+0000'),
        'tcgcsv.com/tcgplayer/3/2374/prices' => Http::sequence()
            ->push(tcgcsvPricesResponse([['productId' => 659941, 'lowPrice' => 0.53, 'marketPrice' => 1.29, 'subTypeName' => 'Holofoil']]))
            ->push(tcgcsvPricesResponse([['productId' => 659941, 'lowPrice' => 0.60, 'marketPrice' => 1.35, 'subTypeName' => 'Holofoil']])),
        'tcgcsv.com/tcgplayer/3/24269/prices' => Http::response(tcgcsvPricesResponse([]), 200),
    ]);

    runTcgcsvSync();
    runTcgcsvSync();

    expect(CardPriceSnapshot::where('card_id', $card->id)->where('source', 'tcgplayer')->sole()->market_minor)->toBe(135);
});

test('a tcgdex TCGplayer price older than the staleness window leaves a gap to fill', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = gapFixture();
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'holofoil:cosmos', 'captured_on' => today()->subDays(4), 'currency' => 'USD', 'market_minor' => 120]);
    gapFake();

    runTcgcsvSync();

    expect(CardPriceSnapshot::where('card_id', $card->id)->where('origin', 'tcgcsv')->value('market_minor'))->toBe(129);
});

test('shadow mode still writes no price at all', function () {
    config(['tcgcsv.mode' => 'shadow']);
    $card = gapFixture();
    gapFake();

    runTcgcsvSync();

    expect(CardPriceSnapshot::where('card_id', $card->id)->where('source', 'tcgplayer')->count())->toBe(0);
});

test('the run reports how many gaps it filled', function () {
    config(['tcgcsv.mode' => 'fill']);
    gapFixture();
    gapFake();
    $log = tcgcsvLog();

    runTcgcsvSync();

    $log->shouldHaveReceived('info')->withArgs(fn (string $m, array $c) => $m === 'tcgcsv sync' && $c['mode'] === 'fill' && $c['gaps_filled'] === 1);
});

test('fill mode never overwrites today\'s tcgdex row, even one without a market price', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = gapFixture();
    $tcgdex = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'holofoil:cosmos', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => null, 'low_minor' => 90]);
    gapFake();

    runTcgcsvSync();

    expect($tcgdex->fresh()->only(['origin', 'market_minor', 'low_minor']))->toBe(['origin' => 'tcgdex', 'market_minor' => null, 'low_minor' => 90]);
});

test('a mode other than fill or shadow writes nothing, so a typo in the rollback switch fails safe', function (string $mode) {
    config(['tcgcsv.mode' => $mode]);
    $card = gapFixture();
    gapFake();

    runTcgcsvSync();

    expect(CardPriceSnapshot::where('card_id', $card->id)->where('source', 'tcgplayer')->count())->toBe(0);
})->with(['Shadow', ' shadow', 'off', '']);

test('a gap price far from the print\'s last tcgcsv price is not written', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = gapFixture();
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'origin' => 'tcgcsv', 'variant' => 'holofoil:cosmos', 'captured_on' => today()->subDay(), 'currency' => 'USD', 'market_minor' => 129]);
    gapFake([['productId' => 659941, 'lowPrice' => 9.0, 'marketPrice' => 12.90, 'subTypeName' => 'Holofoil']]);
    $log = tcgcsvLog();

    runTcgcsvSync();

    expect(CardPriceSnapshot::where('card_id', $card->id)->where('source', 'tcgplayer')->whereDate('captured_on', today())->count())->toBe(0);
    $log->shouldHaveReceived('info')->withArgs(fn (string $m, array $c) => $m === 'tcgcsv sync' && $c['gaps_filled'] === 0
        && $c['gaps_implausible_count'] === 1 && $c['gaps_implausible'] === ['sv10-096 holofoil:cosmos']);
});

test('a gap price far from the print\'s cardmarket price is not written', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = gapFixture(); // cardmarket 1.44 EUR
    gapFake([['productId' => 659941, 'lowPrice' => 9.0, 'marketPrice' => 12.90, 'subTypeName' => 'Holofoil']]);

    runTcgcsvSync();

    expect(CardPriceSnapshot::where('card_id', $card->id)->where('source', 'tcgplayer')->count())->toBe(0);
});

test('a cheap print\'s large ratio but small absolute move is still written', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = gapFixture();
    CardPriceSnapshot::where('card_id', $card->id)->where('source', 'cardmarket')->update(['market_minor' => 5]);
    gapFake([['productId' => 659941, 'lowPrice' => 0.10, 'marketPrice' => 0.30, 'subTypeName' => 'Holofoil']]);

    runTcgcsvSync();

    expect(CardPriceSnapshot::where('card_id', $card->id)->where('source', 'tcgplayer')->value('market_minor'))->toBe(30);
});

test('a zero market price is not written as a price', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = gapFixture();
    gapFake([['productId' => 659941, 'lowPrice' => 0.0, 'marketPrice' => 0.0, 'subTypeName' => 'Holofoil']]);

    runTcgcsvSync();

    expect(CardPriceSnapshot::where('card_id', $card->id)->where('source', 'tcgplayer')->count())->toBe(0);
});

test('a build more than a day and a half old is not stamped as today\'s price', function () {
    config(['tcgcsv.mode' => 'fill']);
    $this->travelTo(CarbonImmutable::parse('2026-10-09 20:30', 'UTC'));
    $card = gapFixture();
    gapFake(); // build 2026-10-07T20:05

    runTcgcsvSync();

    expect(CardPriceSnapshot::where('card_id', $card->id)->where('source', 'tcgplayer')->count())->toBe(0);
});

test('a complete run is recorded for the freshness check, a partly failed one is not', function () {
    $this->freezeTime();
    config(['tcgcsv.mode' => 'fill']);
    gapFixture();
    Http::fake([
        'tcgcsv.com/last-updated.txt' => Http::response('2026-10-07T20:05:57+0000', 200),
        'tcgcsv.com/tcgplayer/3/2374/prices' => Http::sequence()->push('down', 503)->push(tcgcsvPricesResponse([])),
        'tcgcsv.com/tcgplayer/3/24269/prices' => Http::response(tcgcsvPricesResponse([]), 200),
    ]);
    tcgcsvLog();

    runTcgcsvSync();
    expect(Cache::get('tcgcsv:last_run'))->toBeNull();

    runTcgcsvSync(); // the retry fetches only the failed group
    expect(Cache::get('tcgcsv:last_run'))->toBe(now()->toIso8601String());
});
