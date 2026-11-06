<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;

function pocketCard(?string $image = 'https://assets.tcgdex.net/en/sv/sv03.5/002/high.webp'): Card
{
    $set = Set::updateOrCreate(['tcgdex_id' => 'sv03.5'], ['name' => '151', 'abbreviation' => 'MEW']);

    return Card::updateOrCreate(['tcgdex_id' => 'sv03.5-002'], [
        'set_id' => $set->id, 'local_id' => '002', 'name' => 'Ivysaur', 'rarity' => 'Uncommon', 'official_image_url' => $image,
    ])->fresh('set');
}

/** @param array<string, mixed> $with */
function renderPocket(array $with = []): string
{
    // The default card is built only when the caller brings none: a test that passes its own card must not have the row rewritten first.
    $data = ['snapshot' => null, 'index' => 0, ...$with];
    $data['card'] ??= pocketCard();

    return (string) test()->blade(
        '<x-revamp.missing-pocket :card="$card" :snapshot="$snapshot" :href="\'/demo/sv03.5/002\'" :index="$index" />',
        $data,
    );
}

function pocketPrice(int $minor): CardPriceSnapshot
{
    return CardPriceSnapshot::make(['source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => $minor]);
}

test('a missing card is an empty pocket with its number pencilled in and its price on a slip', function () {
    $html = renderPocket(['snapshot' => pocketPrice(75)]);

    expect($html)->toContain('<span class="set-pencil" aria-hidden="true">002</span>')
        ->and($html)->toContain('<span class="set-gprice" aria-hidden="true">$0.75</span>')
        ->and($html)->toContain('aria-label="Ivysaur, #002, missing, $0.75"')
        ->and($html)->toContain('<span class="col-mono">#002</span><span>missing</span>')
        ->and($html)->not->toContain('col-tag');
});

test('the card shows through the plastic as its low-resolution art, lazily, and may simply disappear', function () {
    expect(renderPocket())->toContain('<img src="https://assets.tcgdex.net/en/sv/sv03.5/002/low.webp" alt="" width="245" height="342" decoding="async" loading="lazy" data-optional>');
});

test('an unpriced pocket has no slip and says nothing about a price', function () {
    $html = renderPocket();

    expect($html)->not->toContain('set-gprice')
        ->and($html)->toContain('aria-label="Ivysaur, #002, missing"');
});

test('a card with no art is still a numbered pocket', function () {
    $html = renderPocket(['card' => pocketCard(image: null)]);

    expect($html)->not->toContain('<img')
        ->and($html)->toContain('<span class="set-pencil" aria-hidden="true">002</span>');
});

test('the pocket is one keyed list item with a plain link, never client-side navigation', function () {
    $card = pocketCard();
    $html = renderPocket(['card' => $card, 'index' => 3]);

    expect($html)->toContain('<li wire:key="miss-'.$card->id.'" class="col-slot set-miss col-enter" style="--i: 3">')
        ->and($html)->toContain('<a href="/demo/sv03.5/002" class="set-pocket"')
        ->and($html)->not->toContain('wire:navigate');
});
