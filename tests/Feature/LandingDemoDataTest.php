<?php

declare(strict_types=1);

/**
 * The landing's demo figures are static data bundled with the page, so the
 * promises the page makes about them are checked here.
 *
 * @return array<string, mixed>
 */
function landingDemoData(): array
{
    return json_decode((string) file_get_contents(resource_path('js/landing-demo.json')), true, flags: JSON_THROW_ON_ERROR);
}

test('the hero stack opens with the franchise mascot and the iconic chase cards', function () {
    $demo = landingDemoData();
    $byId = collect($demo['pool'])->keyBy('id');

    // Pikachu first (the mascot), then Charizard, Umbreon and Gengar.
    expect($demo['hero'])->toBe(['sv08-238', 'base1-4', 'swsh7-215', 'swsh8-271']);

    foreach ($demo['hero'] as $id) {
        expect($byId->has($id))->toBeTrue("hero card {$id} is missing from the pool");
        expect($byId[$id]['price'])->toBeGreaterThan(0);
        expect($byId[$id]['img'])->toStartWith('https://assets.tcgdex.net/en/');
    }
});

test('every landing hero card is in the demo collection seed list', function () {
    // The hero's "See the demo collection" button lands on the seeded gallery,
    // so a visitor must find each card they just saw there.
    $missing = array_values(array_diff(landingDemoData()['hero'], config('tcgvault.demo.cards')));

    expect($missing)->toBe([], 'hero cards missing from tcgvault.demo.cards: '.implode(', ', $missing));
});

test('the demo headline total is the last point of its value history', function () {
    $demo = landingDemoData();

    expect($demo['total'])->toBe(end($demo['history'])['v']);
});

test('the pool stays ordered by value so the price slip reads highest first', function () {
    $prices = array_column(landingDemoData()['pool'], 'price');
    $sorted = $prices;
    rsort($sorted);

    expect($prices)->toBe($sorted);
});
