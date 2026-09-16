<?php

declare(strict_types=1);

test('a single currency renders with no separator glyph at all', function () {
    $view = $this->blade('<x-value-totals :totals="$totals" />', ['totals' => ['USD' => 38936]]);

    $view->assertSee('$389.36');
    $view->assertDontSee('+');
});

test('a second currency uses a neutral separator, never a plus sign that implies a sum', function () {
    $view = $this->blade('<x-value-totals :totals="$totals" />', ['totals' => ['USD' => 38936, 'EUR' => 33330]]);

    $view->assertSee('$389.36');
    $view->assertSee('€333.30');
    // "+ €333.30" reads as "add this to the total above" even with a
    // tooltip saying otherwise — a tooltip isn't visible at a glance, and
    // isn't reachable at all on touch. The glyph itself must not imply
    // addition; a plain "+" glyph directly preceding the second amount
    // does, so it must not appear (the title attribute's own "+" inside
    // its explanatory text is fine — it's not what a viewer scans first).
    $view->assertDontSee('>+ €333.30<', false);
});

test('no totals at all falls back to the empty label', function () {
    $view = $this->blade('<x-value-totals :totals="$totals" empty="No prices yet" />', ['totals' => []]);

    $view->assertSee('No prices yet');
});
