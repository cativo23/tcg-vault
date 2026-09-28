<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\Set;
use Illuminate\Support\Facades\Http;

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

test('finding no images at all warns that tcgdex may be unreachable', function () {
    $set = Set::create(['tcgdex_id' => 'mep', 'name' => 'MEP Black Star Promos']);
    Card::create(['tcgdex_id' => 'mep-031', 'set_id' => $set->id, 'local_id' => '031', 'name' => "N's Zekrom"]);
    Http::fake(['*' => Http::response('', 503)]);

    $this->artisan('catalog:backfill-images')
        ->expectsOutputToContain('Found images for 0 of 1 cards without one.')
        ->expectsOutputToContain('None found: tcgdex may have been unreachable, or these cards have no image there.')
        ->assertSuccessful();
});
