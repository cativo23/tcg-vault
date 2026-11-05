<?php

declare(strict_types=1);

test('no time literal in the card stylesheet is longer than the 520 ms motion cap', function () {
    $css = (string) file_get_contents(resource_path('css/card.css'));
    preg_match_all('/(?<![\w-])(\d+(?:\.\d+)?)ms\b/', $css, $literals);

    expect($literals[1])->not->toBeEmpty()
        ->and(max(array_map('floatval', $literals[1])))->toBeLessThanOrEqual(520.0)
        ->and($css)->not->toMatch('/(?<![\w-])\d+(?:\.\d+)?s\b(?!\s*\))/');
});

test('the card stylesheet resets buttons with zero specificity so component rules keep their type', function () {
    $css = (string) file_get_contents(resource_path('css/card.css'));

    expect($css)->toContain(':where(.crd-page) button')
        ->and($css)->not->toMatch('/\.crd-page\s+(button|input)\b/');
});

test('the chart hands horizontal touch drags to the page script and keeps vertical page scroll', function () {
    $css = (string) file_get_contents(resource_path('css/card.css'));
    preg_match('/^\.crd-chart \{([^}]*)\}/m', $css, $rule);

    expect($rule[1] ?? '')->toContain('touch-action: pan-y');
});

test('the card stylesheet resets heading and paragraph margins with zero specificity so a component margin wins', function () {
    $css = (string) file_get_contents(resource_path('css/card.css'));

    expect($css)->toContain(':where(.crd-page) h1, :where(.crd-page) h2, :where(.crd-page) p')
        ->and($css)->not->toMatch('/\.crd-page\s+(h1|h2|p)\b/');
});
