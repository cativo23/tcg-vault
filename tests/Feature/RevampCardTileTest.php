<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Services\PriceDelta;
use App\Modules\Collection\Models\CollectionItem;

// updateOrCreate: a test may render several tiles, each asking for its own rarity or image.
function tileCard(string $rarity = 'Common', ?string $image = 'https://assets.tcgdex.net/en/me/me05/116/high.webp', ?string $abbreviation = 'PBL'): Card
{
    $set = Set::updateOrCreate(['tcgdex_id' => 'me05'], ['name' => 'Pitch Black', 'abbreviation' => $abbreviation]);

    return Card::updateOrCreate(['tcgdex_id' => 'me05-116'], [
        'set_id' => $set->id, 'local_id' => '116',
        'name' => 'Mega Darkrai ex', 'rarity' => $rarity, 'official_image_url' => $image,
    ])->fresh('set');
}

function tilePrice(int $minor, string $currency = 'USD'): CardPriceSnapshot
{
    return CardPriceSnapshot::make(['source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => $currency, 'market_minor' => $minor]);
}

function tileMove(CardPriceSnapshot $latest, int $deltaMinor): PriceDelta
{
    return new PriceDelta($latest, CardPriceSnapshot::make(['market_minor' => $latest->market_minor - $deltaMinor]), $deltaMinor);
}

/** @param array<string, mixed> $with */
function renderTile(array $with = [], string $attributes = ''): string
{
    $card = $with['card'] ?? tileCard();

    return (string) test()->blade(
        '<x-revamp.card-tile :card="$card" :snapshot="$snapshot" :week-delta="$week" :items="$items" :href="\'/carlos/me05/116\'" '.$attributes.' />',
        ['card' => $card, 'snapshot' => null, 'week' => null, 'items' => [], ...$with],
    );
}

test('a rising 7-day move is green and says so in words for assistive tech', function () {
    $price = tilePrice(19468);
    $html = renderTile(['snapshot' => $price, 'week' => tileMove($price, 240)]);

    expect($html)->toContain('<span class="col-price">$194.68</span>')
        ->and($html)->toContain('<span class="col-delta col-delta--up">+$2.40 · 7d</span>')
        ->and($html)->toContain('aria-label="Mega Darkrai ex, Pitch Black 116, $194.68, up $2.40 in 7 days"');
});

test('a falling or flat move is neutral: no green class, a real minus sign', function () {
    $price = tilePrice(19468);

    $down = renderTile(['snapshot' => $price, 'week' => tileMove($price, -100)]);
    expect($down)->toContain('<span class="col-delta">−$1.00 · 7d</span>')
        ->and($down)->not->toContain('col-delta--up')
        ->and($down)->toContain('down $1.00 in 7 days');

    $flat = renderTile(['snapshot' => $price, 'week' => tileMove($price, 0)]);
    expect($flat)->toContain('<span class="col-delta">±$0.00 · 7d</span>')
        ->and($flat)->toContain('unchanged in 7 days');
});

test('an unknown move shows the price alone; an unknown price says so', function () {
    $price = tilePrice(19468);

    $noMove = renderTile(['snapshot' => $price]);
    expect($noMove)->toContain('<span class="col-price">$194.68</span>')
        ->and($noMove)->not->toContain('7d')
        ->and($noMove)->not->toContain('col-delta');

    $noPrice = renderTile();
    expect($noPrice)->toContain('<span class="col-price">No price</span>')
        ->and($noPrice)->toContain(', no price"');
});

test('rarity shows as a tinted abbreviation and the chase tier also gets a foil ring', function () {
    $standard = renderTile(['card' => tileCard('Common')]);
    expect($standard)->toContain('<span class="col-rar" title="Common">C</span>')
        ->and($standard)->not->toContain('col-ring')
        ->and($standard)->toContain('class="col-card col-tier-standard"');
});

test('silver and chase tiers tint the chip, and only chase draws the ring', function () {
    $silver = renderTile(['card' => tileCard('Double Rare')]);
    expect($silver)->toContain('<span class="col-rar col-rar--silver" title="Double Rare">RR</span>')
        ->and($silver)->not->toContain('col-ring');

    $chase = renderTile(['card' => tileCard('Special Illustration Rare')]);
    expect($chase)->toContain('<span class="col-rar col-rar--chase" title="Special Illustration Rare">SIR</span>')
        ->and($chase)->toContain('<span class="col-ring" aria-hidden="true"></span>')
        ->and($chase)->toContain('col-tier-chase');
});

test('a graded copy shows its slab in the meta line instead of the rarity, with no foil ring', function () {
    $items = [CollectionItem::make(['quantity' => 1, 'grade_company' => 'PSA', 'grade_value' => '10'])];
    $html = renderTile(['card' => tileCard('Special Illustration Rare'), 'items' => $items]);

    expect($html)->toContain('<span class="col-slab">PSA 10</span>')
        ->and($html)->not->toContain('col-rar')
        ->and($html)->not->toContain('col-ring')
        ->and($html)->toContain('graded PSA 10');
});

test('a prize-pack stamp is named as text under the set line, capped at two with the rest counted', function () {
    $one = renderTile(['items' => [CollectionItem::make(['quantity' => 1, 'variant' => 'normal+player-rewards-program'])]]);
    expect($one)->toContain('<span class="col-sub col-sub--stamp" title="Player Rewards Program">Player Rewards Program</span>');

    $three = renderTile(['items' => [
        CollectionItem::make(['quantity' => 1, 'variant' => 'normal+aaa-stamp']),
        CollectionItem::make(['quantity' => 1, 'variant' => 'holofoil+bbb-stamp']),
        CollectionItem::make(['quantity' => 1, 'variant' => 'reverse-holofoil+ccc-stamp']),
    ]]);
    expect($three)->toContain('>Aaa Stamp, Bbb Stamp +1</span>')
        ->and($three)->toContain('title="Aaa Stamp, Bbb Stamp, Ccc Stamp"');
});

test('the set code and number lead the meta line and the quantity shows only above one', function () {
    $many = renderTile(['items' => [CollectionItem::make(['quantity' => 2])]]);
    expect($many)->toContain('<span class="col-mono">PBL 116</span>')
        ->and($many)->toContain('<span class="col-qty" title="2 copies">×2</span>')
        ->and($many)->toContain('2 copies');

    $one = renderTile(['items' => [CollectionItem::make(['quantity' => 1])]]);
    expect($one)->not->toContain('col-qty');

    $noCode = renderTile(['card' => tileCard('Common', abbreviation: null)]);
    expect($noCode)->toContain('<span class="col-mono">116</span>')->not->toContain('null');
});

test('the collector\'s own photo is the primary image', function () {
    $html = renderTile(['items' => [CollectionItem::make(['quantity' => 1, 'photo_path' => 'u/7/darkrai.jpg'])]]);

    expect($html)->toContain('/storage/collection-photos/u/7/darkrai.jpg')
        ->and($html)->not->toContain('assets.tcgdex.net');
});

test('official art is the low-resolution file in a normal tile and the high one in a lead', function () {
    $normal = renderTile();
    expect($normal)->toContain('src="https://assets.tcgdex.net/en/me/me05/116/low.webp"')
        ->and($normal)->not->toContain('col-lead');

    $lead = renderTile([], ':lead="true"');
    expect($lead)->toContain('src="https://assets.tcgdex.net/en/me/me05/116/high.webp"')
        ->and($lead)->toContain('col-slot col-lead col-enter')
        ->and($lead)->toContain('<svg class="col-string"');
});

test('a card with no image still shows its name on the hatched face', function () {
    $html = renderTile(['card' => tileCard('Common', image: null)]);

    expect($html)->toContain('class="col-pic col-pic--empty"')
        ->and($html)->toContain('<b>Mega Darkrai ex</b><span>No image</span>')
        ->and($html)->not->toContain('<img');
});

test('images load lazily unless eager, and only the first asks for high fetch priority', function () {
    expect(renderTile())->toContain('loading="lazy"')->not->toContain('fetchpriority');
    expect(renderTile([], ':eager="true"'))->not->toContain('loading="lazy"')->not->toContain('fetchpriority');
    expect(renderTile([], ':eager="true" :priority="true"'))->toContain('fetchpriority="high"');
});

test('the tile is one keyed list item with a plain link, never client-side navigation', function () {
    $html = renderTile([], ':index="5"');

    expect($html)->toContain('<li wire:key="tile-')
        ->and($html)->toContain('style="--i: 5"')
        ->and($html)->toContain('<a href="/carlos/me05/116" class="col-card')
        ->and($html)->not->toContain('wire:navigate')
        ->and($html)->not->toContain('class="nw-');
});
