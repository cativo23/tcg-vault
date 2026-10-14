<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Support\ChartRange;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

    $this->artisan('gallery:warm-value-series')->assertSuccessful();

    foreach (ChartRange::cases() as $range) {
        $computed = seriesComputations(fn () => $this->get("/carlos/activity?range={$range->value}")->assertOk());

        expect($computed)->toBe(0, "range {$range->value} was computed on the request");
    }
});

test('a visitor to an unwarmed user does compute the series, so the check above can fail', function () {
    publicCollector('carlos');

    expect(seriesComputations(fn () => $this->get('/carlos/activity')->assertOk()))->toBeGreaterThan(0);
});

test('a private collection is skipped and nothing is cached for it', function () {
    publicCollector('shy', public: false);

    $this->artisan('gallery:warm-value-series')->assertSuccessful();

    // Once the collection goes public the first visit still has to compute.
    Collection::withoutGlobalScopes()->update(['is_public' => true]);

    expect(seriesComputations(fn () => $this->get('/shy/activity')->assertOk()))->toBeGreaterThan(0);
});

test('a suspended user is skipped, as their page is a 404', function () {
    $user = publicCollector('gone');
    $user->forceFill(['suspended_at' => now()])->save();
    Log::spy();

    $this->artisan('gallery:warm-value-series')->assertSuccessful();

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context = []) => ($context['users'] ?? null) === 0)->once();
});

test('every public collection of a user is warmed together, as the page shows them', function () {
    $user = publicCollector('carlos');
    $second = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'second']);
    $card = Card::create(['tcgdex_id' => 'me05-extra', 'set_id' => Set::first()->id, 'local_id' => 'extra', 'name' => 'Extra']);
    CollectionItem::create(['collection_id' => $second->id, 'card_id' => $card->id, 'card_tcgdex_id' => $card->tcgdex_id, 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 1]);
    foreach ([40, 0] as $ago) {
        CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDays($ago), 'currency' => 'USD', 'market_minor' => 50]);
    }

    $this->artisan('gallery:warm-value-series')->assertSuccessful();

    expect(seriesComputations(fn () => $this->get('/carlos/activity')->assertOk()))->toBe(0);
});

test('a second run finds everything warm and logs the counts', function () {
    publicCollector('carlos');
    publicCollector('ana');
    $this->artisan('gallery:warm-value-series')->assertSuccessful();
    Log::spy();

    $this->artisan('gallery:warm-value-series')->assertSuccessful();

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context = []) => ($context['users'] ?? null) === 2
        && ($context['ranges'] ?? null) === 4
        && ($context['computed'] ?? null) === 0
        && ($context['warm'] ?? null) === 8)->once();
});

test('the first run logs how many series it computed', function () {
    publicCollector('carlos');
    Log::spy();

    $this->artisan('gallery:warm-value-series')->assertSuccessful();

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context = []) => ($context['computed'] ?? null) === 4 && ($context['warm'] ?? null) === 0)->once();
});

test('a failure for one user is logged and does not stop the others', function () {
    $bad = publicCollector('broken');
    $good = publicCollector('carlos');
    $badCollectionId = Collection::withoutGlobalScopes()->where('user_id', $bad->id)->value('id');
    Log::spy();

    DB::listen(function ($query) use ($badCollectionId) {
        if (str_contains($query->sql, 'collection_items') && in_array($badCollectionId, $query->bindings, true)) {
            throw new RuntimeException('boom');
        }
    });

    $this->artisan('gallery:warm-value-series')->assertFailed();

    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context = []) => ($context['user_id'] ?? null) === $bad->id)->atLeast()->once();

    expect(seriesComputations(fn () => $this->get("/{$good->username}/activity")->assertOk()))->toBe(0);
});
