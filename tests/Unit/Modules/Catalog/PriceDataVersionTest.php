<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Services\PriceDataVersion;
use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;

/*
 * The price-data version changes on every write to card_price_snapshots,
 * whatever path it takes: Eloquent, the query builder, raw SQL, or a
 * cascade from deleting a card or a set. Caches of price rows key on it.
 */

function versionCard(): Card
{
    $set = Set::firstOrCreate(['tcgdex_id' => 'ver'], ['name' => 'Version']);

    return Card::create(['tcgdex_id' => 'ver-'.Str::random(6), 'set_id' => $set->id, 'local_id' => '1', 'name' => 'Versioned']);
}

/** @return array<string, mixed> */
function versionRow(Card $card, int $daysAgo = 0, ?int $minor = 100): array
{
    return [
        'card_id' => $card->id, 'source' => 'tcgplayer', 'origin' => 'tcgdex', 'variant' => 'normal',
        'captured_on' => today()->subDays($daysAgo)->toDateString(), 'currency' => 'USD', 'market_minor' => $minor,
        'created_at' => now(), 'updated_at' => now(),
    ];
}

function priceVersion(): int
{
    return app(PriceDataVersion::class)->current();
}

dataset('snapshot writes', [
    'Eloquent create' => [fn (Card $c) => CardPriceSnapshot::create(versionRow($c, 1))],
    'Eloquent update' => [fn (Card $c) => CardPriceSnapshot::where('card_id', $c->id)->first()->update(['market_minor' => 555])],
    'Eloquent updateOrCreate' => [fn (Card $c) => CardPriceSnapshot::updateOrCreate(
        ['card_id' => $c->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->toDateString()],
        ['currency' => 'USD', 'market_minor' => 321],
    )],
    'Eloquent model delete' => [fn (Card $c) => CardPriceSnapshot::where('card_id', $c->id)->first()->delete()],
    'static insert' => [fn (Card $c) => CardPriceSnapshot::insert([versionRow($c, 2)])],
    'builder mass update' => [fn (Card $c) => CardPriceSnapshot::where('card_id', $c->id)->update(['market_minor' => 7])],
    'builder mass delete' => [fn (Card $c) => CardPriceSnapshot::where('card_id', $c->id)->delete()],
    'DB::table insert' => [fn (Card $c) => DB::table('card_price_snapshots')->insert(versionRow($c, 3))],
    'DB::table upsert' => [fn (Card $c) => DB::table('card_price_snapshots')->upsert([versionRow($c, 0, 999)], ['card_id', 'source', 'variant', 'captured_on'], ['market_minor'])],
    'DB::table update' => [fn (Card $c) => DB::table('card_price_snapshots')->where('card_id', $c->id)->update(['market_minor' => 8])],
    'DB::table delete' => [fn (Card $c) => DB::table('card_price_snapshots')->where('card_id', $c->id)->delete()],
    'raw DB::update' => [fn (Card $c) => DB::update('UPDATE card_price_snapshots SET market_minor = 9 WHERE card_id = ?', [$c->id])],
    'raw DB::delete' => [fn (Card $c) => DB::delete('DELETE FROM card_price_snapshots WHERE card_id = ?', [$c->id])],
    'raw DB::statement' => [fn (Card $c) => DB::statement('UPDATE "card_price_snapshots" SET "market_minor" = 10 WHERE "card_id" = ?', [$c->id])],
    'raw DB::unprepared' => [fn (Card $c) => DB::unprepared("UPDATE card_price_snapshots SET market_minor = 11 WHERE card_id = {$c->id}")],
    'card delete (cascades)' => [fn (Card $c) => $c->delete()],
    'set delete (cascades)' => [fn (Card $c) => Set::whereKey($c->set_id)->delete()],
    'truncate' => [fn (Card $c) => DB::statement('TRUNCATE card_price_snapshots')],
]);

test('every kind of write to the price rows changes the version', function (Closure $write) {
    $card = versionCard();
    DB::table('card_price_snapshots')->insert(versionRow($card));
    $before = priceVersion();

    $write($card);

    expect(priceVersion())->not->toBe($before);
})->with('snapshot writes');

test('reads and writes elsewhere leave the version alone', function () {
    $card = versionCard();
    CardPriceSnapshot::insert([versionRow($card)]);
    $before = priceVersion();

    CardPriceSnapshot::where('card_id', $card->id)->get();
    DB::select('SELECT count(*) FROM card_price_snapshots');
    $card->update(['name' => 'Renamed']);
    DB::table('cards')->where('id', $card->id)->update(['local_id' => '2']);

    expect(priceVersion())->toBe($before);
});

test('a write inside a transaction changes the version when it commits, not before', function () {
    $card = versionCard();
    $before = priceVersion();
    $inside = null;

    DB::transaction(function () use ($card, &$inside) {
        CardPriceSnapshot::create(versionRow($card));
        $inside = priceVersion();
    });

    expect($inside)->toBe($before)
        ->and(priceVersion())->not->toBe($before);
});

test('a rolled back write leaves the version alone', function () {
    $card = versionCard();
    $before = priceVersion();

    try {
        DB::transaction(function () use ($card) {
            CardPriceSnapshot::create(versionRow($card));
            throw new RuntimeException('roll back');
        });
    } catch (RuntimeException) {
    }

    expect(priceVersion())->toBe($before);
});

test('the version is stable between writes and starts fresh when the cache loses it', function () {
    $first = priceVersion();

    expect(priceVersion())->toBe($first);

    Cache::flush();

    // A version lost with the cache must not restart at a value an old entry
    // could still carry: it starts at a random point instead of at 1.
    expect(priceVersion())->not->toBe($first)
        ->and(priceVersion())->toBeGreaterThan(1);
});

test('prices:bump-version moves the version on for writes made outside the app', function () {
    $before = priceVersion();

    $this->artisan('prices:bump-version')->assertSuccessful();

    expect(priceVersion())->not->toBe($before);
});

test('a bump after the cache lost the version starts fresh instead of at 1', function () {
    Cache::forget(PriceDataVersion::KEY);

    app(PriceDataVersion::class)->bump();

    expect(priceVersion())->toBeGreaterThan(1);
});

test('a bump that cannot increment reports the failure, drops the version and never throws to the writer', function () {
    Exceptions::fake();
    $card = versionCard();

    Cache::extend('flaky', fn () => Cache::repository(new class extends ArrayStore
    {
        public function increment($key, $value = 1)
        {
            throw new RuntimeException('redis went away');
        }
    }));
    config(['cache.stores.flaky' => ['driver' => 'flaky'], 'cache.default' => 'flaky']);
    $before = priceVersion();

    // The row is committed: the caller must not see the cache failing.
    CardPriceSnapshot::create(versionRow($card));

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'redis went away');
    expect(Cache::get(PriceDataVersion::KEY))->toBeNull()
        ->and(priceVersion())->not->toBe($before);
});

test('a bump never increments a missing key, so no reader sees a fresh 1 in between', function () {
    $seen = new ArrayObject;
    Cache::extend('watching', fn () => Cache::repository(new class($seen) extends ArrayStore
    {
        public function __construct(private readonly ArrayObject $seen)
        {
            parent::__construct();
        }

        public function increment($key, $value = 1)
        {
            $this->seen['present'] = $this->get($key) !== null;

            return parent::increment($key, $value);
        }
    }));
    config(['cache.stores.watching' => ['driver' => 'watching'], 'cache.default' => 'watching']);

    app(PriceDataVersion::class)->bump();

    expect($seen['present'] ?? null)->toBeTrue()
        ->and(priceVersion())->toBeGreaterThan(1);
});
