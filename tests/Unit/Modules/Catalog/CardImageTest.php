<?php

declare(strict_types=1);

use App\Modules\Catalog\Support\CardImage;

test('a tcgdex high-resolution image is served at low resolution for a normal tile', function () {
    expect(CardImage::sized('https://assets.tcgdex.net/en/me/me05/116/high.webp', false))
        ->toBe('https://assets.tcgdex.net/en/me/me05/116/low.webp');
});

test('a lead tile keeps the high-resolution image', function () {
    expect(CardImage::sized('https://assets.tcgdex.net/en/me/me05/116/high.webp', true))
        ->toBe('https://assets.tcgdex.net/en/me/me05/116/high.webp');
});

test('an image on another host or in another shape is used exactly as stored', function (string $url) {
    expect(CardImage::sized($url, false))->toBe($url);
})->with([
    'tcgplayer' => ['https://product-images.tcgplayer.com/fit-in/437x437/123.jpg'],
    'tcgdex without a size file' => ['https://assets.tcgdex.net/en/me/me05/116'],
    'a lookalike host' => ['https://assets.tcgdex.net.evil.test/en/me/me05/116/high.webp'],
]);

test('no image stays no image', function () {
    expect(CardImage::sized(null, false))->toBeNull()
        ->and(CardImage::sized('', true))->toBeNull();
});
