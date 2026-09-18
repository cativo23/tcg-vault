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

test('an image marks itself loaded (or failed) so the shimmer placeholder can stop', function () {
    // Card art loads from assets.tcgdex.net and can take a couple of
    // seconds — .imgwrap's plain background color was the only thing
    // visible with no feedback that anything was happening. The <img>
    // itself flips a class on load/error; app.css's shimmer keyframe
    // stops once that class is present.
    $view = $this->blade('<x-card-image url="https://example.com/card.webp" name="Pikachu" />');

    $view->assertSee("onload=\"this.classList.add('is-loaded')\"", false);
    $view->assertSee("onerror=\"this.classList.add('is-loaded')\"", false);
});
