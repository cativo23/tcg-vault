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
    $log = tcgcsvLog();
    $before = CardPriceSnapshot::count();

    runShadowSync();

    expect(CardPriceSnapshot::count())->toBe($before);
    expect(CardTcgplayerLink::where('card_id', $darkrai->id)->value('product_id'))->toBe(704873);
    expect(CardTcgplayerLink::where('card_id', $pikachu->id)->value('product_id'))->toBe(696682);
    $log->shouldHaveReceived('info')->withArgs(fn (string $message, array $context) => $message === 'tcgcsv shadow sync'
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

    runShadowSync();

    expect(CardTcgplayerLink::where('card_id', $pikachu->id)->exists())->toBeTrue();
    $log->shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $message === 'tcgcsv group failed' && $context === ['group' => 24688, 'error' => 'RequestException']);
    $log->shouldHaveReceived('info')->withArgs(fn (string $message, array $context) => $message === 'tcgcsv shadow sync'
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

    expect(fn () => runShadowSync())->toThrow(RuntimeException::class);
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

    runShadowSync();

    Http::assertSentCount(1);
    $log->shouldHaveReceived('info')->withArgs(fn (string $message, array $context) => $message === 'tcgcsv shadow sync' && $context['groups_ok'] === 0);
});

test('a partly failed build is not marked as pulled, so its groups are retried', function () {
    shadowFixture();
    Http::fake([
        'tcgcsv.com/last-updated.txt' => Http::response('2026-10-05T20:05:57+0000', 200),
        'tcgcsv.com/tcgplayer/3/24688/prices' => Http::response('down', 503),
        'tcgcsv.com/tcgplayer/3/24722/prices' => Http::response(tcgcsvPricesResponse([]), 200),
    ]);

    runShadowSync();

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

    runShadowSync();
    runShadowSync();

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

    runShadowSync();
    runShadowSync();

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

    runShadowSync();

    Http::assertSentCount(2); // the build check and group 1 only
    $log->shouldHaveReceived('info')->withArgs(fn (string $m, array $c) => $m === 'tcgcsv shadow sync' && $c['groups_failed'] === 3);
});

test('a bug in a group\'s handling is not swallowed as a failed group', function () {
    shadowFixture();
    Http::fake([
        'tcgcsv.com/last-updated.txt' => Http::response('2026-10-05T20:05:57+0000', 200),
        'tcgcsv.com/tcgplayer/3/*' => Http::response(tcgcsvPricesResponse([]), 200),
    ]);
    Cache::shouldReceive('get')->andReturnUsing(fn () => null);
    Cache::shouldReceive('put')->andThrow(new LogicException('cache misconfigured'));

    expect(fn () => runShadowSync())->toThrow(LogicException::class);
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
