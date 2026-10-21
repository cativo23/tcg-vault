<?php

declare(strict_types=1);

use App\Jobs\WarmValueSeriesJob;
use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Support\ChartRange;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

function publicCollector(string $username, bool $public = true, int $minor = 100): User
{
    $user = User::factory()->create(['username' => $username]);
    $collection = Collection::factory()->for($user)->create(['is_public' => $public, 'slug' => 'main']);
    $set = Set::firstOrCreate(['tcgdex_id' => 'me05'], ['name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => "me05-{$username}", 'set_id' => $set->id, 'local_id' => $username, 'name' => "Card {$username}"]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => $card->tcgdex_id, 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 1]);

    foreach ([40, 10, 0] as $ago) {
        CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDays($ago), 'currency' => 'USD', 'market_minor' => $minor + $ago]);
    }

    // Adding the copy queued a warm of its own (see the item observer) and took the
    // user's unique lock; these tests are about the command's dispatches, so give it back.
    (new UniqueLock(Cache::driver()))->release(new WarmValueSeriesJob($user->id));

    return $user;
}

/** Counts the series' reduction queries while the callback runs. */
function seriesComputations(Closure $callback): int
{
    $count = 0;
    DB::listen(function ($query) use (&$count) {
        $count += str_contains(strtolower($query->sql), 'lateral') ? 1 : 0;
    });
    $callback();

    return $count;
}

test('a visitor to the activity page of a warmed user computes no series on any range', function () {
    publicCollector('carlos');

    $this->artisan('gallery:warm-value-series --sync')->assertSuccessful();

    foreach (ChartRange::cases() as $range) {
        $computed = seriesComputations(fn () => $this->get("/carlos/activity?range={$range->value}")->assertOk());

        expect($computed)->toBe(0, "range {$range->value} was computed on the request");
    }
});

test('a visitor to an unwarmed user does compute the series, so the check above can fail', function () {
    publicCollector('carlos');

    expect(seriesComputations(fn () => $this->get('/carlos/activity')->assertOk()))->toBeGreaterThan(0);
});

test('without --sync the command queues one job per public user and computes nothing itself', function () {
    $carlos = publicCollector('carlos');
    $ana = publicCollector('ana');
    Queue::fake();

    expect(seriesComputations(fn () => $this->artisan('gallery:warm-value-series')->assertSuccessful()))->toBe(0);

    Queue::assertPushed(WarmValueSeriesJob::class, 2);
    Queue::assertPushed(WarmValueSeriesJob::class, fn (WarmValueSeriesJob $job) => $job->userId === $carlos->id);
    Queue::assertPushed(WarmValueSeriesJob::class, fn (WarmValueSeriesJob $job) => $job->userId === $ana->id);
});

test('a private collection is skipped: no job is queued and the sync run reports no users', function () {
    publicCollector('shy', public: false);
    Queue::fake();

    $this->artisan('gallery:warm-value-series')->assertSuccessful();
    Queue::assertNothingPushed();

    Log::spy();
    $this->artisan('gallery:warm-value-series --sync')->assertSuccessful();

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context = []) => ($context['users'] ?? null) === 0)->once();
});

test('a suspended user is skipped, as their page is a 404', function () {
    $user = publicCollector('gone');
    $user->forceFill(['suspended_at' => now()])->save();
    Queue::fake();

    $this->artisan('gallery:warm-value-series')->assertSuccessful();

    Queue::assertNothingPushed();
});

test('every public collection of a user is warmed together, as the page shows them', function () {
    $user = publicCollector('carlos');
    $second = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'second']);
    $card = Card::create(['tcgdex_id' => 'me05-extra', 'set_id' => Set::first()->id, 'local_id' => 'extra', 'name' => 'Extra']);
    CollectionItem::create(['collection_id' => $second->id, 'card_id' => $card->id, 'card_tcgdex_id' => $card->tcgdex_id, 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 1]);
    foreach ([40, 0] as $ago) {
        CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDays($ago), 'currency' => 'USD', 'market_minor' => 50]);
    }

    $this->artisan('gallery:warm-value-series --sync')->assertSuccessful();

    expect(seriesComputations(fn () => $this->get('/carlos/activity')->assertOk()))->toBe(0);
});

test('a second sync run finds everything warm and logs the counts', function () {
    publicCollector('carlos');
    publicCollector('ana');
    $this->artisan('gallery:warm-value-series --sync')->assertSuccessful();
    Log::spy();

    $this->artisan('gallery:warm-value-series --sync')->assertSuccessful();

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context = []) => ($context['users'] ?? null) === 2
        && ($context['computed'] ?? null) === 0
        && ($context['warm'] ?? null) === 8)->once();
});

test('the first sync run logs how many series it computed', function () {
    publicCollector('carlos');
    Log::spy();

    $this->artisan('gallery:warm-value-series --sync')->assertSuccessful();

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context = []) => ($context['computed'] ?? null) === 4 && ($context['warm'] ?? null) === 0)->once();
});

test('a failure for one user is logged and does not stop the others in a sync run', function () {
    $bad = publicCollector('broken');
    $good = publicCollector('carlos');
    $badCollectionId = Collection::withoutGlobalScopes()->where('user_id', $bad->id)->value('id');
    Log::spy();

    DB::listen(function ($query) use ($badCollectionId) {
        if (str_contains($query->sql, 'collection_items') && in_array($badCollectionId, $query->bindings, true)) {
            throw new RuntimeException('boom');
        }
    });

    $this->artisan('gallery:warm-value-series --sync')->assertFailed();

    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context = []) => ($context['user_id'] ?? null) === $bad->id)->atLeast()->once();

    expect(seriesComputations(fn () => $this->get("/{$good->username}/activity")->assertOk()))->toBe(0);
});

test('the job warms its user and is unique per user for under an hour', function () {
    $user = publicCollector('carlos');
    $job = new WarmValueSeriesJob($user->id);

    $job->handle();

    expect(seriesComputations(fn () => $this->get('/carlos/activity')->assertOk()))->toBe(0)
        // Unique only until it starts, so an edit made during a run still queues the next one.
        ->and($job)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)
        ->and($job->uniqueId())->toBe((string) $user->id)
        ->and($job->uniqueFor)->toBeLessThan(3600)
        ->and($job->tries)->toBe(1);
});

test('the job does nothing for a user who is gone, private or suspended', function () {
    $shy = publicCollector('shy', public: false);
    $gone = publicCollector('gone');
    $gone->forceFill(['suspended_at' => now()])->save();
    Log::spy();

    foreach ([$shy->id, $gone->id, 999_999] as $id) {
        (new WarmValueSeriesJob($id))->handle();
    }

    Log::shouldNotHaveReceived('info');
});

test('a failed job alerts Discord with the user and the exception class, never its message', function () {
    config(['services.discord.alert_webhook_url' => 'https://discord.com/api/webhooks/test']);
    Http::fake();
    Log::spy();
    $sql = 'SQLSTATE[HY000]: select * from "cards" where id in (1,2,3) '.str_repeat('x', 3000);

    (new WarmValueSeriesJob(42))->failed(new RuntimeException($sql));

    Http::assertSent(fn ($request) => str_contains($request['content'], 'Activity chart')
        && str_contains($request['content'], '42')
        && str_contains($request['content'], 'RuntimeException')
        && ! str_contains($request['content'], 'SQLSTATE')
        && ! str_contains($request['content'], 'cards')
        && strlen($request['content']) < 300);
    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context = []) => ($context['user_id'] ?? null) === 42 && $context['exception'] instanceof RuntimeException)->once();
});

test('a user\'s failures alert Discord once a day, and another user\'s still alert', function () {
    config(['services.discord.alert_webhook_url' => 'https://discord.com/api/webhooks/test']);
    Http::fake();

    (new WarmValueSeriesJob(42))->failed(new RuntimeException('a'));
    (new WarmValueSeriesJob(42))->failed(new RuntimeException('b'));
    (new WarmValueSeriesJob(43))->failed(new RuntimeException('c'));

    Http::assertSentCount(2);

    $this->travel(1)->day();
    (new WarmValueSeriesJob(42))->failed(new RuntimeException('d'));

    Http::assertSentCount(3);
});

test('the job runs on the tcgcsv queue of the redis-long connection', function () {
    $job = new WarmValueSeriesJob(1);

    expect($job->connection)->toBe('redis-long')
        ->and($job->queue)->toBe('tcgcsv');
});

test('the logged peak memory is the job\'s own', function () {
    $user = publicCollector('carlos');
    $junk = str_repeat('x', 80 * 1024 * 1024);
    unset($junk);
    $baselineMb = memory_get_usage() / 1048576;
    Log::spy();

    (new WarmValueSeriesJob($user->id))->handle();

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context = []) => isset($context['peak_mb']) && $context['peak_mb'] < $baselineMb + 60)->once();
});

test('a user\'s warm run loads the cards once for every range, not once per range', function () {
    $user = publicCollector('carlos');
    $cardQueries = 0;
    DB::listen(function ($query) use (&$cardQueries) {
        $cardQueries += preg_match('/^select \* from "cards" where exists/i', $query->sql);
    });

    (new WarmValueSeriesJob($user->id))->handle();
    $cardQueries = 0;
    (new WarmValueSeriesJob($user->id))->handle();

    expect($cardQueries)->toBe(1);
});
