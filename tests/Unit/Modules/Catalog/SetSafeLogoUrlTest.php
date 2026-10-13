<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Set;

test('safeLogoUrl returns a logo on the tcgdex assets host', function () {
    $set = new Set(['logo_url' => 'https://assets.tcgdex.net/en/base/base1/logo.png']);

    expect($set->safe_logo_url)->toBe('https://assets.tcgdex.net/en/base/base1/logo.png');
});

test('safeLogoUrl is null for a logo stored from another host', function (?string $stored) {
    $set = new Set(['logo_url' => $stored]);

    expect($set->safe_logo_url)->toBeNull();
})->with([
    'foreign host' => 'https://evil.example/logo.png',
    'lookalike host' => 'https://assets.tcgdex.net.evil.example/logo.png',
    'plain http' => 'http://assets.tcgdex.net/logo.png',
    'no logo' => null,
]);
