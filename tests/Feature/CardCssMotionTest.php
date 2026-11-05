<?php

declare(strict_types=1);

test('no time literal in the card stylesheet is longer than the 520 ms motion cap', function () {
    $css = (string) file_get_contents(resource_path('css/card.css'));
    preg_match_all('/(?<![\w-])(\d+(?:\.\d+)?)ms\b/', $css, $literals);

    expect($literals[1])->not->toBeEmpty()
        ->and(max(array_map('floatval', $literals[1])))->toBeLessThanOrEqual(520.0)
        ->and($css)->not->toMatch('/(?<![\w-])\d+(?:\.\d+)?s\b(?!\s*\))/');
});
