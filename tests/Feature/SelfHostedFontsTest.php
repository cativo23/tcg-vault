<?php

use App\Models\User;

/*
 * The fonts are bundled with the app's own CSS, so rendering a page never
 * makes a visitor's browser contact Google (see the privacy policy).
 */

test('public pages load no fonts from Google', function (string $path) {
    $this->get($path)
        ->assertDontSee('fonts.googleapis.com')
        ->assertDontSee('fonts.gstatic.com');
})->with(['/', '/privacy', '/login']);

test('signed-in pages load no fonts from Google', function () {
    $this->actingAs(User::factory()->create())
        ->get('/profile')
        ->assertOk()
        ->assertDontSee('fonts.googleapis.com')
        ->assertDontSee('fonts.gstatic.com');
});

test('error pages load no fonts from Google', function () {
    $this->get('/this-page-does-not-exist-anywhere/really')
        ->assertNotFound()
        ->assertDontSee('fonts.googleapis.com')
        ->assertDontSee('fonts.gstatic.com');
});
