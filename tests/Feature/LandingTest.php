<?php

declare(strict_types=1);

test('the landing uses the revamp shell with a theme toggle, login link and legal footer', function () {
    $this->get('/')->assertOk()
        ->assertSee('data-theme-toggle', escape: false)
        ->assertSee('href="'.route('login').'"', escape: false)
        ->assertSee(route('privacy'), escape: false)
        ->assertSee(route('terms'), escape: false)
        ->assertSee('tcgdex.dev')
        ->assertSee('Not affiliated with Nintendo, Creatures, GAME FREAK or The Pokémon Company.')
        ->assertSee('class="bar"', escape: false);
});

test('the landing is indexable and describes itself', function () {
    $this->get('/')->assertOk()
        ->assertDontSee('noindex', escape: false)
        ->assertSee('<meta name="description" content="Every card you own, on one page.">', escape: false);
});

test('the landing keeps the site title and does not call a third-party font host', function () {
    $this->get('/')->assertOk()
        ->assertSee('<title>tcg-vault</title>', escape: false)
        ->assertDontSee('fonts.googleapis.com')
        ->assertDontSee('fonts.gstatic.com');
});
