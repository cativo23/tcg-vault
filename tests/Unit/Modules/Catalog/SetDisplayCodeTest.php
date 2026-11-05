<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Set;

test('a set is coded by its abbreviation, upper-cased', function () {
    expect((new Set(['tcgdex_id' => 'me05', 'abbreviation' => 'pbl']))->display_code)->toBe('PBL');
});

test('a set without an abbreviation is coded by its upper-cased tcgdex id', function (?string $abbreviation) {
    expect((new Set(['tcgdex_id' => 'sv08.5', 'abbreviation' => $abbreviation]))->display_code)->toBe('SV08.5');
})->with([
    'null' => [null],
    'empty' => [''],
]);
