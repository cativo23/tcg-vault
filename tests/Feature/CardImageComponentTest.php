<?php

declare(strict_types=1);

test('renders an img tag when a url is given', function () {
    $view = $this->blade('<x-card-image url="https://example.com/card.webp" name="Pikachu" />');

    $view->assertSee('<img', false);
    $view->assertSee('https://example.com/card.webp', false);
});

test('renders the empty-image placeholder when url is null', function () {
    $view = $this->blade('<x-card-image :url="null" name="Pikachu" />');

    $view->assertDontSee('<img', false);
    $view->assertSee('No image');
});
