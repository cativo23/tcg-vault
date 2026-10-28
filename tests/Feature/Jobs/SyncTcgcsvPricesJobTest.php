<?php

declare(strict_types=1);

use App\Jobs\SyncTcgcsvPricesJob;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\CardTcgplayerLink;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Services\CardPriceResolver;
use App\Modules\Catalog\Tcgcsv\TcgcsvClient;
use App\Modules\Catalog\Tcgcsv\TcgcsvFallbackPrices;
use App\Modules\Catalog\Tcgcsv\TcgplayerIdentityMatcher;
use App\Modules\Catalog\Tcgcsv\TcgplayerLinkDiscovery;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use Tests\Support\ShownPrices;

// The faked tcgcsv builds are dated; fill mode only works a build from
// today's UTC day, so a test that fills sets the mode and a build dated the
// day of the clock below, and the rest run in shadow mode.
// The extra groups are off unless a test turns them on, so the faked
// responses of the others never need a group they don't mention.
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 20:30', 'UTC'));
    config(['tcgcsv.extra_groups' => [], 'tcgcsv.mode' => 'shadow']);
});

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

function runTcgcsvSync(?TcgplayerIdentityMatcher $matcher = null): void
{
    (new SyncTcgcsvPricesJob)->handle(app(TcgcsvClient::class), app(TcgplayerLinkDiscovery::class), $matcher ?? app(TcgplayerIdentityMatcher::class));
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
    $job->handle(app(TcgcsvClient::class), app(TcgplayerLinkDiscovery::class), app(TcgplayerIdentityMatcher::class));

    $this->travelTo(today()->setTime(22, 45));
    $late = Mockery::mock(Job::class);
    $late->shouldNotReceive('release');
    $job = new SyncTcgcsvPricesJob;
    $job->setJob($late);
    $job->handle(app(TcgcsvClient::class), app(TcgplayerLinkDiscovery::class), app(TcgplayerIdentityMatcher::class));
});

test('a failed build check is retried later instead of failing the job', function () {
    shadowFixture();
    Http::fake(['tcgcsv.com/last-updated.txt' => Http::response('down', 503)]);

    $this->travelTo(today()->setTime(20, 30));
    $queued = Mockery::mock(Job::class);
    $queued->shouldReceive('release')->once()->with(3600);
    $job = new SyncTcgcsvPricesJob;
    $job->setJob($queued);
    $job->handle(app(TcgcsvClient::class), app(TcgplayerLinkDiscovery::class), app(TcgplayerIdentityMatcher::class));

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
    $job->handle(app(TcgcsvClient::class), app(TcgplayerLinkDiscovery::class), app(TcgplayerIdentityMatcher::class));
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
        'tcgcsv.com/last-updated.txt' => Http::response('2026-10-08T20:05:57+0000', 200),
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
    expect($row->source_updated_at->toIso8601String())->toBe('2026-10-08T20:05:57+00:00');
    expect((new CardPriceResolver)->resolveForVariant($card->fresh(), 'holofoil:cosmos')->id)->toBe($row->id);
});

test('fill mode never writes over a print tcgdex still prices on TCGplayer', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = gapFixture();
    $tcgdex = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'holofoil:cosmos', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 150]);
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
        'tcgcsv.com/last-updated.txt' => Http::sequence()->push('2026-10-08T20:05:57+0000')->push('2026-10-08T21:05:57+0000'),
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

test('a tcgdex TCGplayer price captured recently but last updated by TCGplayer over three days ago leaves a gap to fill', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = gapFixture();
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'holofoil:cosmos', 'captured_on' => today()->subDay(), 'currency' => 'USD', 'market_minor' => 120, 'source_updated_at' => today()->subDays(5)]);
    gapFake();

    runTcgcsvSync();

    expect(CardPriceSnapshot::where('card_id', $card->id)->where('origin', 'tcgcsv')->value('market_minor'))->toBe(129);
});

test('a tcgdex TCGplayer price for today that TCGplayer updated within three days is not a gap', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = gapFixture();
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'holofoil:cosmos', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 120, 'source_updated_at' => today()->subDays(2)]);
    gapFake();

    runTcgcsvSync();

    expect(CardPriceSnapshot::where('card_id', $card->id)->where('origin', 'tcgcsv')->count())->toBe(0);
});

test('a gap price far from the print\'s last tcgdex TCGplayer price is held back', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = gapFixture();
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'holofoil:cosmos', 'captured_on' => today()->subDay(), 'currency' => 'USD', 'market_minor' => 120, 'source_updated_at' => today()->subDays(5)]);
    gapFake([['productId' => 659941, 'lowPrice' => 9.00, 'marketPrice' => 15.00, 'subTypeName' => 'Holofoil']]);

    runTcgcsvSync();

    expect(CardPriceSnapshot::where('card_id', $card->id)->where('origin', 'tcgcsv')->count())->toBe(0);
});

test('a last tcgdex TCGplayer price TCGplayer updated long ago no longer holds a new one back', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = gapFixture();
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'holofoil:cosmos', 'captured_on' => today()->subDay(), 'currency' => 'USD', 'market_minor' => 120, 'source_updated_at' => today()->subDays(20)]);
    gapFake([['productId' => 659941, 'lowPrice' => 9.00, 'marketPrice' => 15.00, 'subTypeName' => 'Holofoil']]);

    runTcgcsvSync();

    expect(CardPriceSnapshot::where('card_id', $card->id)->where('origin', 'tcgcsv')->value('market_minor'))->toBe(1500);
});

function fallbackKept(Card $card): array
{
    return app(TcgcsvFallbackPrices::class)->forCard($card->id, ['holofoil:cosmos']);
}

test('a run keeps the price it fills for the next tcgdex sync', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = gapFixture();
    gapFake();

    runTcgcsvSync();

    expect(fallbackKept($card)['holofoil:cosmos']['market'] ?? null)->toBe(129);
});

test('a run also keeps tcgcsv\'s price for a print tcgdex still prices, without writing a row for it', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = gapFixture();
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'holofoil:cosmos', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 120, 'source_updated_at' => today()->subDay()]);
    gapFake();

    runTcgcsvSync();

    expect(fallbackKept($card)['holofoil:cosmos']['market'] ?? null)->toBe(129);
    expect(CardPriceSnapshot::where('card_id', $card->id)->where('origin', 'tcgcsv')->count())->toBe(0);
});

test('a price that fails its checks is not kept', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = gapFixture();
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'holofoil:cosmos', 'captured_on' => today()->subDay(), 'currency' => 'USD', 'market_minor' => 120, 'source_updated_at' => today()->subDays(5)]);
    gapFake([['productId' => 659941, 'lowPrice' => 9.00, 'marketPrice' => 15.00, 'subTypeName' => 'Holofoil']]);

    runTcgcsvSync();

    expect(fallbackKept($card))->toBe([]);
});

test('shadow mode keeps nothing', function () {
    config(['tcgcsv.mode' => 'shadow']);
    $card = gapFixture();
    gapFake();

    runTcgcsvSync();

    config(['tcgcsv.mode' => 'fill']);
    expect(fallbackKept($card))->toBe([]);
});

test('a build from an earlier UTC day keeps nothing', function () {
    config(['tcgcsv.mode' => 'fill']);
    $this->travelTo(CarbonImmutable::parse('2026-10-09 20:30', 'UTC'));
    $card = gapFixture();
    gapFake(); // build 2026-10-08T20:05

    runTcgcsvSync();

    expect(fallbackKept($card))->toBe([]);
});

test('a print tcgdex already wrote for today is not held back or reported when its tcgcsv price looks wrong', function () {
    config(['tcgcsv.mode' => 'fill', 'services.discord.alert_webhook_url' => 'https://discord.com/api/webhooks/test']);
    $card = gapFixture();
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'holofoil:cosmos', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 120, 'source_updated_at' => today()->subDays(5)]);
    Http::fake(['discord.com/*' => Http::response('', 204)]);
    gapFake([['productId' => 659941, 'lowPrice' => 9.00, 'marketPrice' => 15.00, 'subTypeName' => 'Holofoil']]);

    runTcgcsvSync();

    expect(Cache::has('tcgcsv:held:'.$card->id.'|holofoil:cosmos'))->toBeFalse();
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'discord.com'));
});

test('a print tcgdex already wrote for today keeps its row, and tcgcsv\'s price is kept for the next sync', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = gapFixture();
    $tcgdex = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'holofoil:cosmos', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 120, 'source_updated_at' => today()->subDays(5)]);
    gapFake();

    runTcgcsvSync();

    expect($tcgdex->fresh()->only(['origin', 'market_minor']))->toBe(['origin' => 'tcgdex', 'market_minor' => 120]);
    expect(fallbackKept($card)['holofoil:cosmos']['market'] ?? null)->toBe(129);
});

/** The day's last run summary, as the job keeps it for review. */
function lastTcgcsvSummary(): array
{
    $day = Cache::get('tcgcsv:shadow:'.now()->toDateString(), []);

    return $day === [] ? [] : end($day);
}

test('a tcgcsv price that fails its checks for a print tcgdex already wrote for today is counted and named in the run summary', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = gapFixture();
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'holofoil:cosmos', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 120, 'source_updated_at' => today()->subDays(5)]);
    gapFake([['productId' => 659941, 'lowPrice' => 9.00, 'marketPrice' => 15.00, 'subTypeName' => 'Holofoil']]);

    runTcgcsvSync();

    expect(lastTcgcsvSummary())->toMatchArray(['fallback_rejected_count' => 1, 'fallback_rejected_prints' => ['sv10-096 holofoil:cosmos']]);
});

test('a price that passed the check against tcgdex\'s last price is not reported as accepted with nothing to check it against', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = gapFixture();
    CardPriceSnapshot::where('card_id', $card->id)->where('source', 'cardmarket')->delete();
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'holofoil:cosmos', 'captured_on' => today()->subDay(), 'currency' => 'USD', 'market_minor' => 120, 'source_updated_at' => today()->subDays(5)]);
    Cache::put('tcgcsv:held:'.$card->id.'|holofoil:cosmos', true, now()->addDays(7));
    gapFake();

    runTcgcsvSync();

    expect(CardPriceSnapshot::where('card_id', $card->id)->where('origin', 'tcgcsv')->value('market_minor'))->toBe(129);
    expect(lastTcgcsvSummary()['gaps_accepted_after_hold'])->toBe([]);
});

test('a print only a third-party link vouches for is not kept without a price to check it against', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = gapFixture();
    CardTcgplayerLink::where('card_id', $card->id)->update(['method' => 'tcgdex-thirdparty']);
    CardPriceSnapshot::where('card_id', $card->id)->where('source', 'cardmarket')->delete();
    gapFake();

    runTcgcsvSync();

    expect(fallbackKept($card))->toBe([]);
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

test('a build from the previous UTC day is not used, and is left unrecorded so today\'s is still pulled', function () {
    config(['tcgcsv.mode' => 'fill']);
    $this->travelTo(CarbonImmutable::parse('2026-10-08 00:10', 'UTC'));
    $card = gapFixture();
    Http::fake([
        'tcgcsv.com/last-updated.txt' => Http::response('2026-10-07T20:05:57+0000', 200), // four hours old
        'tcgcsv.com/tcgplayer/3/2374/prices' => Http::response(tcgcsvPricesResponse([['productId' => 659941, 'lowPrice' => 0.53, 'marketPrice' => 1.29, 'subTypeName' => 'Holofoil']]), 200),
        'tcgcsv.com/tcgplayer/3/24269/prices' => Http::response(tcgcsvPricesResponse([]), 200),
    ]);
    $queued = Mockery::mock(Job::class);
    $queued->shouldReceive('release')->once()->with(3600);
    $job = new SyncTcgcsvPricesJob;
    $job->setJob($queued);

    $job->handle(app(TcgcsvClient::class), app(TcgplayerLinkDiscovery::class), app(TcgplayerIdentityMatcher::class));

    expect(CardPriceSnapshot::where('card_id', $card->id)->where('source', 'tcgplayer')->count())->toBe(0)
        ->and(fallbackKept($card))->toBe([])
        ->and(Cache::get('tcgcsv:last_build'))->toBeNull()
        ->and(Cache::get('tcgcsv:last_run'))->toBeNull();
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/prices'));
});

test('in shadow mode a build from the previous UTC day is still compared', function () {
    config(['tcgcsv.mode' => 'shadow']);
    $this->travelTo(CarbonImmutable::parse('2026-10-08 00:10', 'UTC'));
    gapFixture();
    Http::fake([
        'tcgcsv.com/last-updated.txt' => Http::response('2026-10-07T20:05:57+0000', 200),
        'tcgcsv.com/tcgplayer/3/2374/prices' => Http::response(tcgcsvPricesResponse([['productId' => 659941, 'lowPrice' => 0.53, 'marketPrice' => 1.29, 'subTypeName' => 'Holofoil']]), 200),
        'tcgcsv.com/tcgplayer/3/24269/prices' => Http::response(tcgcsvPricesResponse([]), 200),
    ]);

    runTcgcsvSync();

    expect(Cache::get('tcgcsv:last_build'))->not->toBeNull();
});

test('a print tcgdex priced yesterday but sent nothing for today is a gap tcgcsv fills', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = gapFixture();
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'origin' => 'tcgdex', 'variant' => 'holofoil:cosmos', 'captured_on' => today()->subDay(), 'currency' => 'USD', 'market_minor' => 120, 'source_updated_at' => today()->subDay()]);
    gapFake();

    runTcgcsvSync();

    $row = CardPriceSnapshot::where('card_id', $card->id)->where('source', 'tcgplayer')->whereDate('captured_on', today())->sole();
    expect([$row->origin, $row->market_minor])->toBe(['tcgcsv', 129]);
});

test('a build from an earlier UTC day is not stamped as today\'s price', function () {
    config(['tcgcsv.mode' => 'fill']);
    $this->travelTo(CarbonImmutable::parse('2026-10-09 20:30', 'UTC'));
    $card = gapFixture();
    gapFake(); // build 2026-10-08T20:05

    runTcgcsvSync();

    expect(CardPriceSnapshot::where('card_id', $card->id)->where('source', 'tcgplayer')->count())->toBe(0);
});

test('a complete run is recorded for the freshness check, a partly failed one is not', function () {
    $this->freezeTime();
    config(['tcgcsv.mode' => 'fill']);
    gapFixture();
    Http::fake([
        'tcgcsv.com/last-updated.txt' => Http::response('2026-10-08T20:05:57+0000', 200),
        'tcgcsv.com/tcgplayer/3/2374/prices' => Http::sequence()->push('down', 503)->push(tcgcsvPricesResponse([])),
        'tcgcsv.com/tcgplayer/3/24269/prices' => Http::response(tcgcsvPricesResponse([]), 200),
    ]);
    tcgcsvLog();

    runTcgcsvSync();
    expect(Cache::get('tcgcsv:last_run'))->toBeNull();

    runTcgcsvSync(); // the retry fetches only the failed group
    expect(Cache::get('tcgcsv:last_run'))->toBe(now()->toIso8601String());
});

test('a last tcgcsv price older than the staleness window no longer holds a new one back, so a hold lasts days, not forever', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = gapFixture();
    CardPriceSnapshot::where('card_id', $card->id)->where('source', 'cardmarket')->delete();
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'origin' => 'tcgcsv', 'variant' => 'holofoil:cosmos', 'captured_on' => today()->subDays(8), 'currency' => 'USD', 'market_minor' => 129]);
    gapFake([['productId' => 659941, 'lowPrice' => 9.0, 'marketPrice' => 12.90, 'subTypeName' => 'Holofoil']]);

    runTcgcsvSync();

    expect(CardPriceSnapshot::where('card_id', $card->id)->whereDate('captured_on', today())->value('market_minor'))->toBe(1290);
});

test('a link only tcgdex\'s third-party ids vouch for is not filled without a price to check it against', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = gapFixture();
    CardTcgplayerLink::where('card_id', $card->id)->update(['method' => 'tcgdex-thirdparty']);
    CardPriceSnapshot::where('card_id', $card->id)->where('source', 'cardmarket')->delete();
    gapFake();
    $log = tcgcsvLog();

    runTcgcsvSync();

    expect(CardPriceSnapshot::where('card_id', $card->id)->where('source', 'tcgplayer')->count())->toBe(0);
    $log->shouldHaveReceived('info')->withArgs(fn (string $m, array $c) => $m === 'tcgcsv sync' && $c['gaps_unverified'] === 1);
});

test('an unknown mode is warned about once a day, not on every retry', function () {
    config(['tcgcsv.mode' => 'off']);
    gapFixture();
    gapFake();
    $log = tcgcsvLog();

    runTcgcsvSync();
    Cache::forget('tcgcsv:last_build');
    runTcgcsvSync();

    $log->shouldHaveReceived('warning')->withArgs(fn (string $m) => str_contains($m, 'TCGCSV_MODE'))->once();
});

test('a gap price far below the print\'s last tcgcsv price is held back too', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = gapFixture();
    CardPriceSnapshot::where('card_id', $card->id)->where('source', 'cardmarket')->delete();
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'origin' => 'tcgcsv', 'variant' => 'holofoil:cosmos', 'captured_on' => today()->subDay(), 'currency' => 'USD', 'market_minor' => 1290]);
    gapFake();

    runTcgcsvSync();

    expect(CardPriceSnapshot::where('card_id', $card->id)->whereDate('captured_on', today())->count())->toBe(0);
});

test('an admin link with no price to check against is filled', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = gapFixture();
    CardPriceSnapshot::where('card_id', $card->id)->where('source', 'cardmarket')->delete();
    gapFake();

    runTcgcsvSync();

    expect(CardPriceSnapshot::where('card_id', $card->id)->where('source', 'tcgplayer')->value('market_minor'))->toBe(129);
});

test('held-back gap prices are sent to Discord, not only logged', function () {
    config(['tcgcsv.mode' => 'fill', 'services.discord.alert_webhook_url' => 'https://discord.com/api/webhooks/test']);
    $card = gapFixture();
    CardTcgplayerLink::where('card_id', $card->id)->update(['method' => 'tcgdex-price']);
    earlierTcgcsvRow($card, 100);
    Http::fake(['discord.com/*' => Http::response('', 204)]);
    gapFake([['productId' => 659941, 'lowPrice' => 9.0, 'marketPrice' => 12.90, 'subTypeName' => 'Holofoil']]);

    runTcgcsvSync();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'discord.com') && str_contains($request['content'], 'sv10-096 holofoil:cosmos'));
});

test('a price accepted once its hold expires is reported to Discord', function () {
    config(['tcgcsv.mode' => 'fill', 'services.discord.alert_webhook_url' => 'https://discord.com/api/webhooks/test']);
    $card = gapFixture();
    CardPriceSnapshot::where('card_id', $card->id)->where('source', 'cardmarket')->delete();
    Cache::put('tcgcsv:held:'.$card->id.'|holofoil:cosmos', true, now()->addDays(7));
    Http::fake(['discord.com/*' => Http::response('', 204)]);
    gapFake();

    runTcgcsvSync();

    expect(CardPriceSnapshot::where('card_id', $card->id)->where('source', 'tcgplayer')->count())->toBe(1);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'discord.com') && str_contains($request['content'], 'after a hold') && str_contains($request['content'], 'sv10-096 holofoil:cosmos'));
});

test('a held-back price is remembered so its later acceptance can be reported', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = gapFixture();
    CardTcgplayerLink::where('card_id', $card->id)->update(['method' => 'tcgdex-price']);
    earlierTcgcsvRow($card, 100);
    gapFake([['productId' => 659941, 'lowPrice' => 9.0, 'marketPrice' => 12.90, 'subTypeName' => 'Holofoil']]);

    runTcgcsvSync();

    expect(Cache::has('tcgcsv:held:'.$card->id.'|holofoil:cosmos'))->toBeTrue();
});

test('unverified prints are named in the log, not just counted', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = gapFixture();
    CardTcgplayerLink::where('card_id', $card->id)->update(['method' => 'tcgdex-thirdparty']);
    CardPriceSnapshot::where('card_id', $card->id)->where('source', 'cardmarket')->delete();
    gapFake();
    $log = tcgcsvLog();

    runTcgcsvSync();

    $log->shouldHaveReceived('info')->withArgs(fn (string $m, array $c) => $m === 'tcgcsv sync' && $c['gaps_unverified_prints'] === ['sv10-096 holofoil:cosmos']);
});

test('a normal price after a held glitch passes its check and is not reported as an expired hold', function () {
    config(['tcgcsv.mode' => 'fill', 'services.discord.alert_webhook_url' => 'https://discord.com/api/webhooks/test']);
    $card = gapFixture();
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'origin' => 'tcgcsv', 'variant' => 'holofoil:cosmos', 'captured_on' => today()->subDays(2), 'currency' => 'USD', 'market_minor' => 129]);
    Cache::put('tcgcsv:held:'.$card->id.'|holofoil:cosmos', true, now()->addDays(7));
    Http::fake(['discord.com/*' => Http::response('', 204)]);
    gapFake();

    runTcgcsvSync();

    expect(CardPriceSnapshot::where('card_id', $card->id)->where('source', 'tcgplayer')->whereDate('captured_on', today())->value('market_minor'))->toBe(129);
    expect(Cache::has('tcgcsv:held:'.$card->id.'|holofoil:cosmos'))->toBeFalse();
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'discord.com'));
});

test('an admin-confirmed link gets a wider cardmarket bound, since cardmarket lumps stamped prints together', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = gapFixture(); // admin link
    CardPriceSnapshot::where('card_id', $card->id)->where('source', 'cardmarket')->update(['market_minor' => 1313]); // ~4.9x below
    gapFake([['productId' => 659941, 'lowPrice' => 62.24, 'marketPrice' => 64.16, 'subTypeName' => 'Holofoil']]);

    runTcgcsvSync();

    expect(CardPriceSnapshot::where('card_id', $card->id)->where('source', 'tcgplayer')->value('market_minor'))->toBe(6416);
});

test('an admin-confirmed link is still held back by a jump from its own recent tcgcsv price', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = gapFixture();
    CardPriceSnapshot::where('card_id', $card->id)->where('source', 'cardmarket')->delete(); // only the tcgcsv bound applies
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'origin' => 'tcgcsv', 'variant' => 'holofoil:cosmos', 'captured_on' => today()->subDay(), 'currency' => 'USD', 'market_minor' => 1290]);
    gapFake([['productId' => 659941, 'lowPrice' => 62.24, 'marketPrice' => 64.16, 'subTypeName' => 'Holofoil']]);

    runTcgcsvSync();

    expect(CardPriceSnapshot::where('card_id', $card->id)->where('source', 'tcgplayer')->whereDate('captured_on', today())->count())->toBe(0);
});

test('an admin link is held back by an upstream glitch far from its last tcgcsv price', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = gapFixture();
    earlierTcgcsvRow($card, 129);
    gapFake([['productId' => 659941, 'lowPrice' => 140.0, 'marketPrice' => 144.00, 'subTypeName' => 'Holofoil']]);

    runTcgcsvSync();

    expect(CardPriceSnapshot::where('card_id', $card->id)->where('source', 'tcgplayer')->whereDate('captured_on', today())->count())->toBe(0);
});

test('a price tcgcsv fills is the one the next gallery render shows', function () {
    config(['tcgcsv.mode' => 'fill']);
    tcgcsvLog();
    $card = gapFixture();
    $username = ShownPrices::ownPublicly($card, 'holofoil:cosmos');
    $before = ShownPrices::totals($username);
    Http::fake([
        'tcgcsv.com/last-updated.txt' => Http::sequence()->push('2026-10-08T20:05:57+0000')->push('2026-10-08T21:05:57+0000'),
        'tcgcsv.com/tcgplayer/3/2374/prices' => Http::sequence()
            ->push(tcgcsvPricesResponse([['productId' => 659941, 'lowPrice' => 0.53, 'marketPrice' => 1.29, 'subTypeName' => 'Holofoil']]))
            ->push(tcgcsvPricesResponse([['productId' => 659941, 'lowPrice' => 0.60, 'marketPrice' => 1.35, 'subTypeName' => 'Holofoil']])),
        'tcgcsv.com/tcgplayer/3/24269/prices' => Http::response(tcgcsvPricesResponse([]), 200),
    ]);

    runTcgcsvSync();
    $filled = ShownPrices::totals($username);
    runTcgcsvSync();

    expect($before)->toBe(['EUR' => 144])
        ->and($filled)->toBe(['USD' => 129])
        ->and(ShownPrices::totals($username))->toBe(['USD' => 135]);
});

/**
 * A card whose `normal` print is the Deck Exclusives product 707025 (Bastiodon
 * me05-062). The cardmarket price on file (0.05) is the main product's; the
 * print's own cardmarket average, in its variants_detailed entry, is
 * $average (3.44 unless a test sets it), and the fake group prices it at 4.67
 * unless a test sets that.
 */
function deckExclusiveFixture(bool $ownAverage = true, float $average = 3.44): Card
{
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    DB::table('set_tcgplayer_groups')->insert(['set_id' => $set->id, 'group_id' => 24688]);
    $card = Card::create(['tcgdex_id' => 'me05-062', 'set_id' => $set->id, 'local_id' => '062', 'name' => 'Bastiodon', 'raw' => [
        'pricing' => ['cardmarket' => ['unit' => 'EUR', 'idProduct' => 895847]],
        'variants_detailed' => [[
            'type' => 'normal', 'size' => 'standard', 'thirdParty' => ['cardmarket' => 897297, 'tcgplayer' => 707025],
            ...($ownAverage ? ['pricing' => ['cardmarket' => ['unit' => 'EUR', 'avg' => $average]]] : []),
        ]],
    ]]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 5]);

    return $card;
}

function deckExclusiveFake(float $market = 4.67, int $productsStatus = 200, string $productName = 'Bastiodon - 062/084 (Non-Holo)'): void
{
    Http::fake([
        'tcgcsv.com/last-updated.txt' => Http::response('2026-10-08T20:05:57+0000', 200),
        'tcgcsv.com/tcgplayer/3/24688/prices' => Http::response(tcgcsvPricesResponse([]), 200),
        'tcgcsv.com/tcgplayer/3/1840/prices' => Http::response(tcgcsvPricesResponse([['productId' => 707025, 'lowPrice' => 3.9, 'marketPrice' => $market, 'subTypeName' => 'Normal']]), 200),
        'tcgcsv.com/tcgplayer/3/1840/products' => $productsStatus === 200
            ? Http::response(tcgcsvPricesResponse([['productId' => 707025, 'name' => $productName, 'extendedData' => [['name' => 'Number', 'value' => '062/084']]]]), 200)
            : Http::response('down', $productsStatus),
    ]);
}

test('an extra group is fetched with its products, and a deck exclusive print links and is filled from it', function () {
    config(['tcgcsv.mode' => 'fill', 'tcgcsv.extra_groups' => [1840]]);
    $card = deckExclusiveFixture();
    deckExclusiveFake();
    $log = tcgcsvLog();

    runTcgcsvSync();

    $link = CardTcgplayerLink::where('card_id', $card->id)->sole();
    expect([$link->variant, $link->product_id, $link->group_id, $link->method])->toBe(['normal', 707025, 1840, 'tcgdex-thirdparty']);
    $row = CardPriceSnapshot::where('card_id', $card->id)->where('source', 'tcgplayer')->sole();
    expect([$row->origin, $row->variant, $row->market_minor])->toBe(['tcgcsv', 'normal', 467]);
    $log->shouldHaveReceived('info')->withArgs(fn (string $m, array $c) => $m === 'tcgcsv sync' && $c['groups_fetched'] === [1840, 24688]);
});

test('a verified link\'s price is judged against the print\'s own average at the wider bound, not the 4x one', function () {
    // 1.96 USD against an own average of 16.88 EUR is 8.6x: held at 4x, within the admin bound of 15x.
    config(['tcgcsv.mode' => 'fill', 'tcgcsv.extra_groups' => [1840]]);
    $card = deckExclusiveFixture(average: 16.88);
    deckExclusiveFake(market: 1.96);
    tcgcsvLog();

    runTcgcsvSync();

    expect(CardPriceSnapshot::where('card_id', $card->id)->where('source', 'tcgplayer')->value('market_minor'))->toBe(196);
});

/**
 * A 30th Celebration holo print (Pikachu 030): tcgdex files its cardmarket
 * price under `default`, and the print's own cardmarket product is the card's
 * top-level one.
 *
 * @param  list<array<string, mixed>>  $entries  variants_detailed
 */
function stampedPrintFixture(string $variant = 'holofoil', ?array $entries = null, int $defaultMinor = 101): Card
{
    $set = Set::create(['tcgdex_id' => '30th', 'name' => '30th Celebration']);
    DB::table('set_tcgplayer_groups')->insert(['set_id' => $set->id, 'group_id' => 24722]);
    $card = Card::create(['tcgdex_id' => '30th-030', 'set_id' => $set->id, 'local_id' => '030', 'name' => 'Pikachu', 'raw' => [
        'pricing' => ['cardmarket' => ['unit' => 'EUR', 'idProduct' => 907637]],
        'variants_detailed' => $entries ?? [['type' => 'holo', 'size' => 'standard', 'stamp' => ['30th-anniversary'], 'thirdParty' => ['cardmarket' => 907637, 'tcgplayer' => 712938]]],
    ]]);
    CardTcgplayerLink::create(['card_id' => $card->id, 'variant' => $variant, 'product_id' => 712938, 'sub_type' => 'Holofoil', 'group_id' => 24722, 'method' => 'tcgdex-thirdparty']);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => $defaultMinor]);

    return $card;
}

function stampedPrintFake(float $market = 0.56): void
{
    Http::fake([
        'tcgcsv.com/last-updated.txt' => Http::response('2026-10-08T20:05:57+0000', 200),
        'tcgcsv.com/tcgplayer/3/24722/prices' => Http::response(tcgcsvPricesResponse([['productId' => 712938, 'lowPrice' => 0.4, 'marketPrice' => $market, 'subTypeName' => 'Holofoil']]), 200),
    ]);
}

function tcgplayerRows(Card $card): int
{
    return CardPriceSnapshot::where('card_id', $card->id)->where('source', 'tcgplayer')->count();
}

test('a default cardmarket price is no reference when another entry shares the print\'s product on a different cardmarket product', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = stampedPrintFixture(entries: [
        ['type' => 'holo', 'size' => 'standard', 'stamp' => ['30th-anniversary'], 'thirdParty' => ['cardmarket' => 907637, 'tcgplayer' => 712938]],
        ['type' => 'reverse', 'size' => 'standard', 'thirdParty' => ['cardmarket' => 222, 'tcgplayer' => 712938]],
    ]);
    stampedPrintFake();
    tcgcsvLog();

    runTcgcsvSync();

    expect(tcgplayerRows($card))->toBe(0);
});

test('a default cardmarket price is no reference for a reverse holofoil link', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = stampedPrintFixture(variant: 'reverse-holofoil', entries: [['type' => 'reverse', 'size' => 'standard', 'thirdParty' => ['cardmarket' => 907637, 'tcgplayer' => 712938]]]);
    stampedPrintFake();
    tcgcsvLog();

    runTcgcsvSync();

    expect(tcgplayerRows($card))->toBe(0);
});

test('a default cardmarket price is no reference for a link tcgdex priced', function () {
    config(['tcgcsv.mode' => 'fill']);
    // Far from the gap price: it would hold the price back if it were a reference.
    $card = stampedPrintFixture(defaultMinor: 5000);
    // An old tcgdex price on the product makes discovery keep the link as tcgdex-price.
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'origin' => 'tcgdex', 'variant' => 'holofoil', 'captured_on' => today()->subDays(10), 'currency' => 'USD', 'market_minor' => 55, 'raw' => ['productId' => 712938]]);
    stampedPrintFake();
    tcgcsvLog();

    runTcgcsvSync();

    expect(CardPriceSnapshot::where('card_id', $card->id)->where('source', 'tcgplayer')->where('origin', 'tcgcsv')->value('market_minor'))->toBe(56);
});

test('a default cardmarket price is no reference when the link\'s product is no longer on the card', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = stampedPrintFixture(entries: [['type' => 'holo', 'size' => 'standard', 'stamp' => ['30th-anniversary'], 'thirdParty' => ['cardmarket' => 907637, 'tcgplayer' => 999]]]);
    stampedPrintFake();
    tcgcsvLog();

    runTcgcsvSync();

    expect(tcgplayerRows($card))->toBe(0);
});

test('a default cardmarket price is no reference when the card has no main cardmarket product', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = stampedPrintFixture(entries: [['type' => 'holo', 'size' => 'standard', 'stamp' => ['30th-anniversary'], 'thirdParty' => ['tcgplayer' => 712938]]]);
    $card->update(['raw' => [...$card->raw, 'pricing' => ['cardmarket' => ['unit' => 'EUR']]]]);
    stampedPrintFake();
    tcgcsvLog();

    runTcgcsvSync();

    expect(tcgplayerRows($card))->toBe(0);
});

test('a third-party link with no price of the print to check against is unverified', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = stampedPrintFixture();
    stampedPrintFake();
    $log = tcgcsvLog();

    runTcgcsvSync();

    expect(tcgplayerRows($card))->toBe(0);
    $log->shouldHaveReceived('info')->withArgs(fn (string $m, array $c) => $m === 'tcgcsv sync' && $c['gaps_unverified'] === 1);
});

test('a lone stamped normal print is written against its last tcgcsv price', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = stampedPrintFixture(variant: 'normal', entries: [['type' => 'normal', 'size' => 'standard', 'stamp' => ['30th-anniversary'], 'thirdParty' => ['cardmarket' => 907637, 'tcgplayer' => 712938]]]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'origin' => 'tcgcsv', 'variant' => 'normal', 'captured_on' => today()->subDay(), 'currency' => 'USD', 'market_minor' => 60]);
    stampedPrintFake();
    tcgcsvLog();

    runTcgcsvSync();

    expect(CardPriceSnapshot::where('card_id', $card->id)->where('source', 'tcgplayer')->where('variant', 'normal')->whereDate('captured_on', today())->value('market_minor'))->toBe(56);
});

test('an admin link into an extra group is held back by a price far from its last tcgcsv price', function () {
    // The admin vouches for the link, not for any price: a 93x price against its last tcgcsv one stays held.
    config(['tcgcsv.mode' => 'fill', 'tcgcsv.extra_groups' => [1840]]);
    $card = deckExclusiveFixture();
    CardTcgplayerLink::create(['card_id' => $card->id, 'variant' => 'normal', 'product_id' => 707025, 'sub_type' => 'Normal', 'group_id' => 1840, 'method' => 'admin', 'verified_at' => now()]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'origin' => 'tcgcsv', 'variant' => 'normal', 'captured_on' => today()->subDay(), 'currency' => 'USD', 'market_minor' => 122]);
    deckExclusiveFake();
    tcgcsvLog();

    runTcgcsvSync();

    expect(CardPriceSnapshot::where('card_id', $card->id)->where('source', 'tcgplayer')->whereDate('captured_on', today())->exists())->toBeFalse();
    expect(CardTcgplayerLink::where('card_id', $card->id)->sole()->method)->toBe('admin');
});

test('a verified link keeps its verification when its group\'s products cannot be read on one run', function () {
    config(['tcgcsv.mode' => 'fill', 'tcgcsv.extra_groups' => [1840]]);
    $card = deckExclusiveFixture();
    CardTcgplayerLink::create(['card_id' => $card->id, 'variant' => 'normal', 'product_id' => 707025, 'sub_type' => 'Normal', 'group_id' => 1840, 'method' => 'tcgdex-thirdparty', 'verified_at' => now()]);
    deckExclusiveFake(productsStatus: 503);
    tcgcsvLog();

    runTcgcsvSync();

    expect(CardTcgplayerLink::where('card_id', $card->id)->sole()->verified_at)->not->toBeNull();
    expect(CardPriceSnapshot::where('card_id', $card->id)->where('source', 'tcgplayer')->exists())->toBeFalse();
});

test('a verified link stays written on later runs, when the link already points at the extra group', function () {
    config(['tcgcsv.mode' => 'fill', 'tcgcsv.extra_groups' => [1840]]);
    $card = deckExclusiveFixture();
    // Two evenings' builds: the print's price moves a little between them.
    Http::fake([
        'tcgcsv.com/last-updated.txt' => Http::sequence()->push('2026-10-08T20:05:57+0000')->push('2026-10-09T20:05:57+0000'),
        'tcgcsv.com/tcgplayer/3/24688/prices' => Http::response(tcgcsvPricesResponse([]), 200),
        'tcgcsv.com/tcgplayer/3/1840/prices' => Http::sequence()
            ->push(tcgcsvPricesResponse([['productId' => 707025, 'lowPrice' => 3.9, 'marketPrice' => 4.67, 'subTypeName' => 'Normal']]))
            ->push(tcgcsvPricesResponse([['productId' => 707025, 'lowPrice' => 3.9, 'marketPrice' => 4.80, 'subTypeName' => 'Normal']])),
        'tcgcsv.com/tcgplayer/3/1840/products' => Http::response(tcgcsvPricesResponse([['productId' => 707025, 'name' => 'Bastiodon - 062/084 (Non-Holo)', 'extendedData' => [['name' => 'Number', 'value' => '062/084']]]]), 200),
    ]);
    tcgcsvLog();
    runTcgcsvSync();

    $this->travelTo(CarbonImmutable::parse('2026-10-09 20:30', 'UTC'));
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 5]);
    runTcgcsvSync();

    expect(CardTcgplayerLink::where('card_id', $card->id)->sole()->verified_at)->not->toBeNull();
    expect(CardPriceSnapshot::where('card_id', $card->id)->where('source', 'tcgplayer')->whereDate('captured_on', today())->value('market_minor'))->toBe(480);
});

test('an identity-verified link is held back by a jump from its own last tcgcsv price', function () {
    // Cardmarket's own average (3.44) allows 25.00, so only the last tcgcsv price (1.22) can hold it.
    config(['tcgcsv.mode' => 'fill', 'tcgcsv.extra_groups' => [1840]]);
    $card = deckExclusiveFixture();
    CardTcgplayerLink::create(['card_id' => $card->id, 'variant' => 'normal', 'product_id' => 707025, 'sub_type' => 'Normal', 'group_id' => 1840, 'method' => 'tcgdex-thirdparty', 'verified_at' => now()]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'origin' => 'tcgcsv', 'variant' => 'normal', 'captured_on' => today()->subDay(), 'currency' => 'USD', 'market_minor' => 122]);
    deckExclusiveFake(market: 25.00);
    tcgcsvLog();

    runTcgcsvSync();

    expect(CardPriceSnapshot::where('card_id', $card->id)->where('source', 'tcgplayer')->whereDate('captured_on', today())->exists())->toBeFalse();
    expect(CardTcgplayerLink::where('card_id', $card->id)->sole()->verified_at)->not->toBeNull();
});

test('a verified link\'s first price has nothing to be bounded by and is written', function () {
    config(['tcgcsv.mode' => 'fill', 'tcgcsv.extra_groups' => [1840]]);
    $card = deckExclusiveFixture();
    deckExclusiveFake(market: 4.67);
    tcgcsvLog();

    runTcgcsvSync();

    expect(CardPriceSnapshot::where('card_id', $card->id)->where('source', 'tcgplayer')->exists())->toBeTrue();
});
test('a verified link with no own cardmarket average has nothing to bound its first price, and is written', function () {
    config(['tcgcsv.mode' => 'fill', 'tcgcsv.extra_groups' => [1840]]);
    $card = deckExclusiveFixture(ownAverage: false);
    deckExclusiveFake(market: 4.67);
    tcgcsvLog();

    runTcgcsvSync();

    expect(CardPriceSnapshot::where('card_id', $card->id)->where('source', 'tcgplayer')->value('market_minor'))->toBe(467);
});

test('a verified link\'s price accepted once its hold expires is reported to Discord', function () {
    config(['tcgcsv.mode' => 'fill', 'tcgcsv.extra_groups' => [1840], 'services.discord.alert_webhook_url' => 'https://discord.com/api/webhooks/test']);
    $card = deckExclusiveFixture(ownAverage: false);
    Cache::put('tcgcsv:held:'.$card->id.'|normal', true, now()->addDays(7));
    Http::fake(['discord.com/*' => Http::response('', 204)]);
    deckExclusiveFake();

    runTcgcsvSync();

    expect(CardPriceSnapshot::where('card_id', $card->id)->where('source', 'tcgplayer')->count())->toBe(1);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'discord.com') && str_contains($request['content'], 'after a hold') && str_contains($request['content'], 'me05-062 normal'));
});

test('a link to an extra group\'s product that no longer matches the card loses its verification and is checked against cardmarket again', function () {
    config(['tcgcsv.mode' => 'fill', 'tcgcsv.extra_groups' => [1840]]);
    $card = deckExclusiveFixture();
    CardTcgplayerLink::create(['card_id' => $card->id, 'variant' => 'normal', 'product_id' => 707025, 'sub_type' => 'Normal', 'group_id' => 1840, 'method' => 'tcgdex-thirdparty', 'verified_at' => now()]);
    deckExclusiveFake(productName: 'Rampardos - 062/084 (Non-Holo)');
    tcgcsvLog();

    runTcgcsvSync();

    expect(CardTcgplayerLink::where('card_id', $card->id)->sole()->verified_at)->toBeNull();
    // 4.67 against the main product's 0.05 on file.
    expect(CardPriceSnapshot::where('card_id', $card->id)->where('source', 'tcgplayer')->whereDate('captured_on', today())->exists())->toBeFalse();
});

test('an extra group whose products cannot be read counts as failed, and the build is retried', function () {
    config(['tcgcsv.mode' => 'fill', 'tcgcsv.extra_groups' => [1840]]);
    $card = deckExclusiveFixture();
    deckExclusiveFake(productsStatus: 503);
    $log = tcgcsvLog();

    runTcgcsvSync();

    expect(CardTcgplayerLink::where('card_id', $card->id)->exists())->toBeFalse();
    expect(Cache::get('tcgcsv:last_build'))->toBeNull();
    $log->shouldHaveReceived('warning')->withArgs(fn (string $m, array $c) => $m === 'tcgcsv group failed' && $c['group'] === 1840);
});

test('a group both a set and the extra groups name is fetched once', function () {
    config(['tcgcsv.mode' => 'shadow', 'tcgcsv.extra_groups' => [1840]]);
    $card = deckExclusiveFixture();
    DB::table('set_tcgplayer_groups')->insert(['set_id' => $card->set_id, 'group_id' => 1840]);
    deckExclusiveFake();
    $log = tcgcsvLog();

    runTcgcsvSync();

    $log->shouldHaveReceived('info')->withArgs(fn (string $m, array $c) => $m === 'tcgcsv sync' && $c['groups_fetched'] === [1840, 24688]);
    Http::assertSentCount(4);
});

test('once a link points at an extra group it is fetched even if the group is no longer configured', function () {
    config(['tcgcsv.mode' => 'shadow', 'tcgcsv.extra_groups' => []]);
    $card = deckExclusiveFixture();
    CardTcgplayerLink::create(['card_id' => $card->id, 'variant' => 'normal', 'product_id' => 707025, 'sub_type' => 'Normal', 'group_id' => 1840, 'method' => 'tcgdex-thirdparty', 'verified_at' => now()]);
    deckExclusiveFake();
    $log = tcgcsvLog();

    runTcgcsvSync();

    $log->shouldHaveReceived('info')->withArgs(fn (string $m, array $c) => $m === 'tcgcsv sync' && $c['groups_fetched'] === [1840, 24688]);
});

/** A Treecko tcgdex lists but links to nothing (the unlinked MEP shape), in a group the set maps. */
function identityFixture(): Card
{
    $set = Set::create(['tcgdex_id' => 'mep', 'name' => 'MEP']);
    DB::table('set_tcgplayer_groups')->insert(['set_id' => $set->id, 'group_id' => 24451]);

    return Card::create(['tcgdex_id' => 'mep-055', 'set_id' => $set->id, 'local_id' => '055', 'name' => 'Treecko', 'variants' => ['holo' => true]]);
}

function identityFake(int $productsStatus = 200): void
{
    Http::fake([
        'tcgcsv.com/last-updated.txt' => Http::response('2026-10-08T20:05:57+0000', 200),
        'tcgcsv.com/tcgplayer/3/24451/prices' => Http::response(tcgcsvPricesResponse([['productId' => 709969, 'lowPrice' => 2.0, 'marketPrice' => 2.43, 'subTypeName' => 'Holofoil']]), 200),
        'tcgcsv.com/tcgplayer/3/24451/products' => $productsStatus === 200
            ? Http::response(tcgcsvPricesResponse([['productId' => 709969, 'name' => 'Treecko -  055', 'extendedData' => [['name' => 'Number', 'value' => '055']]]]), 200)
            : Http::response('down', $productsStatus),
    ]);
}

test('with the identity switch on log, an unlinked print is reported as one the matcher would link, and nothing is written', function () {
    config(['tcgcsv.mode' => 'fill', 'tcgcsv.identity_links' => 'log']);
    $card = identityFixture();
    identityFake();

    runTcgcsvSync();

    expect(lastTcgcsvSummary()['identity'])->toMatchArray(['mode' => 'log', 'new' => 1, 'agree' => 0, 'different' => 0, 'refused' => 0, 'new_prints' => ['mep-055 holofoil → 709969 Holofoil']])
        ->and(CardTcgplayerLink::where('card_id', $card->id)->count())->toBe(0)
        ->and(CardPriceSnapshot::where('card_id', $card->id)->count())->toBe(0);
});

test('with the identity switch off, the set\'s products are not requested and the summary has no identity block', function () {
    config(['tcgcsv.mode' => 'fill', 'tcgcsv.identity_links' => null]);
    identityFixture();
    identityFake();

    runTcgcsvSync();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/24451/products'));
    expect(lastTcgcsvSummary())->not->toHaveKey('identity');
});

test('any value other than log leaves the identity matcher off', function (mixed $value) {
    config(['tcgcsv.mode' => 'fill', 'tcgcsv.identity_links' => $value]);
    identityFixture();
    identityFake();

    runTcgcsvSync();

    expect(lastTcgcsvSummary())->not->toHaveKey('identity');
})->with(['on', 'true', true, '', 'LOG ']);

test('refusals are counted by reason and the first ones named', function () {
    config(['tcgcsv.mode' => 'fill', 'tcgcsv.identity_links' => 'log']);
    identityFixture();
    Http::fake([
        'tcgcsv.com/last-updated.txt' => Http::response('2026-10-08T20:05:57+0000', 200),
        'tcgcsv.com/tcgplayer/3/24451/prices' => Http::response(tcgcsvPricesResponse([['productId' => 709969, 'lowPrice' => 2.0, 'marketPrice' => 2.43, 'subTypeName' => 'Holofoil']]), 200),
        'tcgcsv.com/tcgplayer/3/24451/products' => Http::response(tcgcsvPricesResponse([['productId' => 709969, 'name' => 'Treecko (Cosmos Holo) - 055', 'extendedData' => [['name' => 'Number', 'value' => '055']]]]), 200),
    ]);

    runTcgcsvSync();

    expect(lastTcgcsvSummary()['identity'])->toMatchArray(['new' => 0, 'refused' => 1, 'refused_reasons' => ['no-candidate' => 1], 'refused_prints' => ['mep-055 holofoil (no-candidate)']]);
});

test('a group whose products cannot be read still has its prices used, and its cards are refused', function () {
    config(['tcgcsv.mode' => 'fill', 'tcgcsv.identity_links' => 'log']);
    $card = identityFixture();
    CardTcgplayerLink::create(['card_id' => $card->id, 'variant' => 'holofoil', 'product_id' => 709969, 'sub_type' => 'Holofoil', 'group_id' => 24451, 'method' => 'admin']);
    identityFake(productsStatus: 503);

    runTcgcsvSync();

    $summary = lastTcgcsvSummary();
    expect($summary['groups_failed'])->toBe(0)
        ->and($summary['identity']['refused_reasons'])->toBe(['group-without-identities' => 1])
        ->and(CardPriceSnapshot::where('card_id', $card->id)->where('source', 'tcgplayer')->count())->toBe(1);
});

test('a print whose link already points at the product the matcher found agrees', function () {
    config(['tcgcsv.mode' => 'fill', 'tcgcsv.identity_links' => 'log']);
    $card = identityFixture();
    CardTcgplayerLink::create(['card_id' => $card->id, 'variant' => 'holofoil', 'product_id' => 709969, 'sub_type' => 'Holofoil', 'group_id' => 24451, 'method' => 'tcgdex-price']);
    identityFake();

    runTcgcsvSync();

    expect(lastTcgcsvSummary()['identity'])->toMatchArray(['agree' => 1, 'new' => 0, 'different' => 0]);
});

test('a throttle on a mapped group\'s products stops the loop like a throttled price file', function () {
    config(['tcgcsv.mode' => 'fill', 'tcgcsv.identity_links' => 'log']);
    $card = identityFixture();
    identityFake(productsStatus: 429);

    runTcgcsvSync();

    // The group counts as failed, so none of its prices are used this run and the retry re-reads both.
    expect(lastTcgcsvSummary()['groups_failed'])->toBe(1)
        ->and(CardPriceSnapshot::where('card_id', $card->id)->count())->toBe(0);
});

test('a matcher that throws costs the identity report, not the day\'s price fill', function () {
    config(['tcgcsv.mode' => 'fill', 'tcgcsv.identity_links' => 'log']);
    $card = gapFixture();
    gapFake();
    Http::fake(['tcgcsv.com/tcgplayer/3/*/products' => Http::response(tcgcsvPricesResponse([]), 200)]);
    $matcher = Mockery::mock(TcgplayerIdentityMatcher::class);
    $matcher->shouldReceive('evaluate')->andThrow(new RuntimeException('boom'));

    runTcgcsvSync($matcher);

    expect(lastTcgcsvSummary()['identity']['refused_reasons'])->toBe(['matcher-error' => 1])
        ->and(CardPriceSnapshot::where('card_id', $card->id)->where('source', 'tcgplayer')->where('origin', 'tcgcsv')->count())->toBe(1);
});

test('a lost connection on a mapped group\'s products fails the group and stops the loop', function () {
    config(['tcgcsv.mode' => 'fill', 'tcgcsv.identity_links' => 'log']);
    $card = identityFixture();
    Http::fake([
        'tcgcsv.com/last-updated.txt' => Http::response('2026-10-08T20:05:57+0000', 200),
        'tcgcsv.com/tcgplayer/3/24451/prices' => Http::response(tcgcsvPricesResponse([['productId' => 709969, 'lowPrice' => 2.0, 'marketPrice' => 2.43, 'subTypeName' => 'Holofoil']]), 200),
        'tcgcsv.com/tcgplayer/3/24451/products' => fn () => throw new ConnectionException('reset'),
    ]);

    runTcgcsvSync();

    expect(lastTcgcsvSummary()['groups_failed'])->toBe(1)
        ->and(CardPriceSnapshot::where('card_id', $card->id)->count())->toBe(0);
});

test('a retry that re-fetches only some groups says so inside the identity block', function () {
    config(['tcgcsv.mode' => 'fill', 'tcgcsv.identity_links' => 'log']);
    identityFixture();
    Cache::put('tcgcsv:group:24451', '2026-10-08T20:05:57+00:00', now()->addDay());
    Cache::put('tcgcsv:group:99999', 'old', now()->addDay());
    identityFake();

    runTcgcsvSync();

    expect(lastTcgcsvSummary()['identity'])->toHaveKey('partial_retry');
});

test('a link that differs only by subtype is named with its product and subtype', function () {
    config(['tcgcsv.mode' => 'fill', 'tcgcsv.identity_links' => 'log']);
    $card = identityFixture();
    CardTcgplayerLink::create(['card_id' => $card->id, 'variant' => 'holofoil', 'product_id' => 709969, 'sub_type' => 'Normal', 'group_id' => 24451, 'method' => 'admin']);
    identityFake();

    runTcgcsvSync();

    expect(lastTcgcsvSummary()['identity'])->toMatchArray(['different' => 1, 'different_prints' => ['mep-055 holofoil → 709969 Holofoil']]);
});

test('turning the identity log on changes no link and no price for a linked print', function () {
    config(['tcgcsv.mode' => 'fill', 'tcgcsv.identity_links' => 'log']);
    $card = identityFixture();
    CardTcgplayerLink::create(['card_id' => $card->id, 'variant' => 'holofoil', 'product_id' => 709969, 'sub_type' => 'Holofoil', 'group_id' => 24451, 'method' => 'tcgdex-price']);
    identityFake();
    runTcgcsvSync();
    $withLog = [CardTcgplayerLink::all()->toArray(), CardPriceSnapshot::all()->map(fn ($r) => [$r->variant, $r->market_minor, $r->origin])->all()];

    DB::table('card_price_snapshots')->delete();
    Cache::flush();
    config(['tcgcsv.identity_links' => null]);
    identityFake();
    runTcgcsvSync();

    expect([CardTcgplayerLink::all()->toArray(), CardPriceSnapshot::all()->map(fn ($r) => [$r->variant, $r->market_minor, $r->origin])->all()])->toEqual($withLog);
});

test('a link refused because the card already has that product and subtype on another print is named in the summary', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = identityFixture();
    CardTcgplayerLink::create(['card_id' => $card->id, 'variant' => 'holofoil', 'product_id' => 709969, 'sub_type' => 'Holofoil', 'group_id' => 24451, 'method' => 'admin']);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'origin' => 'tcgdex', 'variant' => 'holofoil+staff', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 243, 'raw' => ['productId' => 709969]]);
    identityFake();

    runTcgcsvSync();

    expect(lastTcgcsvSummary())->toMatchArray(['link_conflicts' => 1, 'link_conflicts_prints' => ['mep-055 holofoil+staff → 709969 Holofoil (held by holofoil)']])
        ->and(CardTcgplayerLink::where('card_id', $card->id)->pluck('variant')->all())->toBe(['holofoil']);
});

test('a run with no refused links reports none', function () {
    config(['tcgcsv.mode' => 'fill']);
    identityFixture();
    identityFake();

    runTcgcsvSync();

    expect(lastTcgcsvSummary())->toMatchArray(['link_conflicts' => 0, 'link_conflicts_prints' => []]);
});

/** The print's tcgcsv row from an earlier day, as an earlier evening run left it. */
function earlierTcgcsvRow(Card $card, int $market, int $daysAgo = 1): void
{
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'origin' => 'tcgcsv', 'variant' => 'holofoil:cosmos', 'captured_on' => today()->subDays($daysAgo), 'currency' => 'USD', 'market_minor' => $market]);
}

test('a price far from cardmarket\'s figure but near its last tcgcsv price is written', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = gapFixture(); // cardmarket 1.44 EUR, another print's price
    earlierTcgcsvRow($card, 2400);
    gapFake([['productId' => 659941, 'lowPrice' => 20.00, 'marketPrice' => 25.00, 'subTypeName' => 'Holofoil']]);

    runTcgcsvSync();

    expect(lastTcgcsvSummary())->toMatchArray(['gaps_implausible_count' => 0, 'gaps_filled' => 1])
        ->and(CardPriceSnapshot::where('card_id', $card->id)->where('captured_on', today())->where('source', 'tcgplayer')->value('market_minor'))->toBe(2500);
});

test('a link only tcgdex\'s ids vouch for is not filled when only cardmarket has a price for it', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = gapFixture();
    CardTcgplayerLink::where('card_id', $card->id)->update(['method' => 'tcgdex-thirdparty']);
    gapFake();

    runTcgcsvSync();

    expect(lastTcgcsvSummary())->toMatchArray(['gaps_unverified' => 1, 'gaps_unverified_prints' => ['sv10-096 holofoil:cosmos'], 'gaps_filled' => 0])
        ->and(CardPriceSnapshot::where('card_id', $card->id)->where('source', 'tcgplayer')->count())->toBe(0);
});

test('a price far from the last tcgcsv price of many days ago is held back', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = gapFixture();
    earlierTcgcsvRow($card, 100, 9); // older than the three-day window
    gapFake([['productId' => 659941, 'lowPrice' => 20.00, 'marketPrice' => 25.00, 'subTypeName' => 'Holofoil']]);

    runTcgcsvSync();

    expect(lastTcgcsvSummary())->toMatchArray(['gaps_implausible_count' => 1, 'gaps_filled' => 0]);
});

test('a price 16x its last tcgcsv price of a few days ago is held back', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = gapFixture();
    earlierTcgcsvRow($card, 100, 5);
    gapFake([['productId' => 659941, 'lowPrice' => 15.00, 'marketPrice' => 16.00, 'subTypeName' => 'Holofoil']]);

    runTcgcsvSync();

    expect(lastTcgcsvSummary())->toMatchArray(['gaps_implausible_count' => 1, 'gaps_filled' => 0]);
});

test('a price 10x its last tcgcsv price of a few days ago is written', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = gapFixture();
    earlierTcgcsvRow($card, 100, 5);
    gapFake([['productId' => 659941, 'lowPrice' => 9.00, 'marketPrice' => 10.00, 'subTypeName' => 'Holofoil']]);

    runTcgcsvSync();

    expect(lastTcgcsvSummary())->toMatchArray(['gaps_implausible_count' => 0, 'gaps_filled' => 1]);
});

test('a price tcgcsv checks out for a print tcgdex prices is kept even when cardmarket\'s figure is far from it', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = gapFixture();
    CardTcgplayerLink::where('card_id', $card->id)->update(['method' => 'tcgdex-thirdparty']);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'origin' => 'tcgdex', 'variant' => 'holofoil:cosmos', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 1450, 'source_updated_at' => today()->subDay()]);
    gapFake([['productId' => 659941, 'lowPrice' => 14.00, 'marketPrice' => 15.00, 'subTypeName' => 'Holofoil']]);

    runTcgcsvSync();

    expect(fallbackKept($card)['holofoil:cosmos']['market'] ?? null)->toBe(1500);
});

test('a tcgdex price of the print counts as a reference when there is no tcgcsv one', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = gapFixture();
    CardTcgplayerLink::where('card_id', $card->id)->update(['method' => 'tcgdex-thirdparty']);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'origin' => 'tcgdex', 'variant' => 'holofoil:cosmos', 'captured_on' => today()->subDays(10), 'currency' => 'USD', 'market_minor' => 120, 'source_updated_at' => today()->subDays(10)]);
    gapFake();

    runTcgcsvSync();

    expect(lastTcgcsvSummary())->toMatchArray(['gaps_unverified' => 0, 'gaps_filled' => 1]);
});

test('a cardmarket price never decides a verdict, however absurd', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = gapFixture();
    earlierTcgcsvRow($card, 125);
    CardPriceSnapshot::where('card_id', $card->id)->where('source', 'cardmarket')->update(['market_minor' => 99999999]);
    gapFake();

    runTcgcsvSync();

    expect(lastTcgcsvSummary())->toMatchArray(['gaps_implausible_count' => 0, 'gaps_filled' => 1]);
});

test('every link left unverified is named', function () {
    config(['tcgcsv.mode' => 'fill']);
    $card = gapFixture();
    $other = Card::create(['tcgdex_id' => 'sv10-097', 'set_id' => $card->set_id, 'local_id' => '097', 'name' => 'Other']);
    foreach ([$card, $other] as $c) {
        CardTcgplayerLink::updateOrCreate(['card_id' => $c->id, 'variant' => 'holofoil:cosmos'], ['product_id' => 659941, 'sub_type' => 'Holofoil', 'group_id' => 2374, 'method' => 'tcgdex-thirdparty']);
    }
    gapFake();

    runTcgcsvSync();

    expect(lastTcgcsvSummary()['gaps_unverified_prints'])->toEqualCanonicalizing(['sv10-096 holofoil:cosmos', 'sv10-097 holofoil:cosmos']);
});
