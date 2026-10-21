<?php

declare(strict_types=1);

use App\Modules\Catalog\Services\PriceDataVersion;
use App\Modules\Catalog\Services\PriceHistoryEpoch;
use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;

/*
 * The epoch changes only when someone says past price history was corrected
 * by hand (prices:bump-version). Ordinary price writes never touch it.
 */

function historyEpoch(): int
{
    return app(PriceHistoryEpoch::class)->current();
}

test('the epoch is stable between bumps', function () {
    expect(historyEpoch())->toBe(historyEpoch());
});

test('a bump moves the epoch on', function () {
    $before = historyEpoch();

    app(PriceHistoryEpoch::class)->bump();

    expect(historyEpoch())->not->toBe($before);
});

test('an epoch the cache lost starts at a random point, not at 1', function () {
    $first = historyEpoch();

    Cache::flush();

    expect(historyEpoch())->not->toBe($first)
        ->and(historyEpoch())->toBeGreaterThan(1);
});

test('a bump after the cache lost the epoch starts fresh instead of at 1', function () {
    Cache::forget(PriceHistoryEpoch::KEY);

    app(PriceHistoryEpoch::class)->bump();

    expect(historyEpoch())->toBeGreaterThan(1);
});

test('the epoch is kept apart from the price-data version', function () {
    $epoch = historyEpoch();

    app(PriceDataVersion::class)->bump();

    expect(historyEpoch())->toBe($epoch)
        ->and(PriceHistoryEpoch::KEY)->not->toBe(PriceDataVersion::KEY);
});

test('prices:bump-version bumps both the epoch and the price-data version', function () {
    $epoch = historyEpoch();
    $version = app(PriceDataVersion::class)->current();

    $this->artisan('prices:bump-version')->assertSuccessful();

    expect(historyEpoch())->not->toBe($epoch)
        ->and(app(PriceDataVersion::class)->current())->not->toBe($version);
});

test('a bump that cannot increment reports the failure and drops the epoch', function () {
    Exceptions::fake();

    Cache::extend('flaky-epoch', fn () => Cache::repository(new class extends ArrayStore
    {
        public function increment($key, $value = 1)
        {
            throw new RuntimeException('redis went away');
        }
    }));
    config(['cache.stores.flaky-epoch' => ['driver' => 'flaky-epoch'], 'cache.default' => 'flaky-epoch']);
    $before = historyEpoch();

    app(PriceHistoryEpoch::class)->bump();

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'redis went away');
    expect(Cache::get(PriceHistoryEpoch::KEY))->toBeNull()
        ->and(historyEpoch())->not->toBe($before);
});

test('a bump says it worked when it moved the epoch or dropped it', function () {
    expect(app(PriceHistoryEpoch::class)->bump())->toBeTrue();

    Exceptions::fake();
    Cache::extend('flaky-increment', fn () => Cache::repository(new class extends ArrayStore
    {
        public function increment($key, $value = 1)
        {
            throw new RuntimeException('redis went away');
        }
    }));
    config(['cache.stores.flaky-increment' => ['driver' => 'flaky-increment'], 'cache.default' => 'flaky-increment']);

    // The increment failed but the key could be dropped, which invalidates just as well.
    expect(app(PriceHistoryEpoch::class)->bump())->toBeTrue();
});

function useUnbumpableCache(): void
{
    Cache::extend('down', fn () => Cache::repository(new class extends ArrayStore
    {
        public function increment($key, $value = 1)
        {
            throw new RuntimeException('redis went away');
        }

        public function forget($key)
        {
            throw new RuntimeException('redis went away');
        }
    }));
    config(['cache.stores.down' => ['driver' => 'down'], 'cache.default' => 'down']);
}

test('a bump says it failed when the epoch could neither be moved nor dropped', function () {
    Exceptions::fake();
    useUnbumpableCache();

    expect(app(PriceHistoryEpoch::class)->bump())->toBeFalse();
});

test('prices:bump-version fails with a clear message when the epoch could not be bumped', function () {
    Exceptions::fake();
    useUnbumpableCache();

    $this->artisan('prices:bump-version')
        ->expectsOutputToContain('history epoch could not be bumped')
        ->assertFailed();
});
