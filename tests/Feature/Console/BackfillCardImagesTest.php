<?php

declare(strict_types=1);

use App\Modules\Catalog\Contracts\CardImageFallback;
use App\Modules\Catalog\Contracts\LinkedCardImage;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardTcgplayerLink;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Services\TcgdexImageFallback;
use App\Modules\Catalog\Tcgcsv\TcgplayerProductImage;
use Illuminate\Support\Facades\Http;

// These tests exercise the real tcgdex lookup, with HTTP faked.
beforeEach(fn () => $this->app->instance(CardImageFallback::class, new TcgdexImageFallback('https://api.tcgdex.net/v2/en')));

test('cards stored without an image get one when tcgdex hosts it, and only then', function () {
    $set = Set::create(['tcgdex_id' => 'mep', 'name' => 'MEP Black Star Promos']);
    $found = Card::create(['tcgdex_id' => 'mep-031', 'set_id' => $set->id, 'local_id' => '031', 'name' => "N's Zekrom"]);
    $missing = Card::create(['tcgdex_id' => 'mep-120', 'set_id' => $set->id, 'local_id' => '120', 'name' => 'Nothing Here']);
    $hasImage = Card::create(['tcgdex_id' => 'mep-001', 'set_id' => $set->id, 'local_id' => '001', 'name' => 'Already Fine', 'official_image_url' => 'https://example.test/keep.webp']);

    Http::fake([
        'api.tcgdex.net/v2/en/sets/mep' => Http::response(['id' => 'mep', 'serie' => ['id' => 'me']]),
        'assets.tcgdex.net/en/me/mep/031/high.webp' => Http::response('', 200),
        'assets.tcgdex.net/*' => Http::response('', 404),
    ]);

    $this->artisan('catalog:backfill-images')
        ->expectsOutputToContain('Found images for 1 of 2 cards without one.')
        ->assertSuccessful();

    expect($found->fresh()->official_image_url)->toBe('https://assets.tcgdex.net/en/me/mep/031/high.webp')
        ->and($missing->fresh()->official_image_url)->toBeNull()
        ->and($hasImage->fresh()->official_image_url)->toBe('https://example.test/keep.webp');
});

test('a card tcgdex has no image for takes the one from its linked TCGplayer product', function () {
    $this->app->instance(LinkedCardImage::class, app(TcgplayerProductImage::class));
    $set = Set::create(['tcgdex_id' => '30th-c', 'name' => '30th Classic Collection']);
    $linked = Card::create(['tcgdex_id' => '30th-c-007', 'set_id' => $set->id, 'local_id' => '007', 'name' => 'Sneasel']);
    $unlinked = Card::create(['tcgdex_id' => '30th-c-008', 'set_id' => $set->id, 'local_id' => '008', 'name' => 'Nothing Here']);
    CardTcgplayerLink::create(['card_id' => $linked->id, 'variant' => 'holofoil', 'product_id' => 716161, 'sub_type' => 'Holofoil', 'group_id' => 24837, 'method' => 'admin']);

    Http::fake([
        'api.tcgdex.net/v2/en/sets/30th-c' => Http::response(['id' => '30th-c', 'serie' => ['id' => 'me']]),
        'assets.tcgdex.net/*' => Http::response('', 404),
        'tcgplayer-cdn.tcgplayer.com/product/716161_in_1000x1000.jpg' => Http::response('', 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $this->artisan('catalog:backfill-images')
        ->expectsOutputToContain('Found images for 1 of 2 cards without one.')
        ->assertSuccessful();

    expect($linked->fresh()->official_image_url)->toBe('https://tcgplayer-cdn.tcgplayer.com/product/716161_in_1000x1000.jpg')
        ->and($unlinked->fresh()->official_image_url)->toBeNull();
});

test('tcgdex\'s image wins over the TCGplayer one', function () {
    $this->app->instance(LinkedCardImage::class, app(TcgplayerProductImage::class));
    $set = Set::create(['tcgdex_id' => 'mep', 'name' => 'MEP Black Star Promos']);
    $card = Card::create(['tcgdex_id' => 'mep-031', 'set_id' => $set->id, 'local_id' => '031', 'name' => "N's Zekrom"]);
    CardTcgplayerLink::create(['card_id' => $card->id, 'variant' => 'holofoil', 'product_id' => 111, 'sub_type' => 'Holofoil', 'group_id' => 1, 'method' => 'admin']);

    Http::fake([
        'api.tcgdex.net/v2/en/sets/mep' => Http::response(['id' => 'mep', 'serie' => ['id' => 'me']]),
        'assets.tcgdex.net/en/me/mep/031/high.webp' => Http::response('', 200),
    ]);

    $this->artisan('catalog:backfill-images')->assertSuccessful();

    expect($card->fresh()->official_image_url)->toBe('https://assets.tcgdex.net/en/me/mep/031/high.webp');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'tcgplayer-cdn'));
});

test('a dry run lists what it would set and writes nothing', function () {
    $this->app->instance(LinkedCardImage::class, app(TcgplayerProductImage::class));
    $set = Set::create(['tcgdex_id' => '30th-c', 'name' => '30th Classic Collection']);
    $card = Card::create(['tcgdex_id' => '30th-c-007', 'set_id' => $set->id, 'local_id' => '007', 'name' => 'Sneasel']);
    CardTcgplayerLink::create(['card_id' => $card->id, 'variant' => 'holofoil', 'product_id' => 716161, 'sub_type' => 'Holofoil', 'group_id' => 24837, 'method' => 'admin']);

    Http::fake([
        'api.tcgdex.net/v2/en/sets/30th-c' => Http::response('', 404),
        'assets.tcgdex.net/*' => Http::response('', 404),
        'tcgplayer-cdn.tcgplayer.com/*' => Http::response('', 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $this->artisan('catalog:backfill-images --dry-run')
        ->expectsOutputToContain('30th-c-007 Sneasel → https://tcgplayer-cdn.tcgplayer.com/product/716161_in_1000x1000.jpg')
        ->expectsOutputToContain('Would set images for 1 of 1 cards without one.')
        ->assertSuccessful();

    expect($card->fresh()->official_image_url)->toBeNull();
});

test('finding no images at all warns that tcgdex may be unreachable', function () {
    $set = Set::create(['tcgdex_id' => 'mep', 'name' => 'MEP Black Star Promos']);
    Card::create(['tcgdex_id' => 'mep-031', 'set_id' => $set->id, 'local_id' => '031', 'name' => "N's Zekrom"]);
    Http::fake(['*' => Http::response('', 503)]);

    $this->artisan('catalog:backfill-images')
        ->expectsOutputToContain('Found images for 0 of 1 cards without one.')
        ->expectsOutputToContain('None found: tcgdex or TCGplayer may have been unreachable, or these cards have no image there.')
        ->assertSuccessful();
});
