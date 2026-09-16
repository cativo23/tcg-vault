<?php

declare(strict_types=1);

use App\Models\User;

test('the public layout renders the anti-FOUC theme script before any other head tag', function () {
    $response = $this->get('/');

    $response->assertOk();
    $html = $response->getContent();

    $headStart = strpos($html, '<head>');
    $scriptStart = strpos($html, 'tcg-vault-theme');
    expect($scriptStart)->not->toBeFalse();
    expect($scriptStart)->toBeGreaterThan($headStart);
    // Must run before Vite's asset tags so there's no flash of the wrong theme.
    $viteAssets = strpos($html, 'rel="preload"');
    expect($viteAssets)->not->toBeFalse();
    expect($scriptStart)->toBeLessThan($viteAssets);
});

test('the public layout exposes a theme toggle button', function () {
    $response = $this->get('/');

    $response->assertOk();
    $response->assertSee('data-theme-toggle', false);
});

test('the guest layout renders the anti-FOUC theme script', function () {
    $response = $this->get('/login');

    $response->assertOk();
    $response->assertSee('tcg-vault-theme', false);
});

test('the authenticated app layout exposes a theme toggle button', function () {
    $this->seed(\Database\Seeders\PermissionSeeder::class);
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/admin');

    $response->assertOk();
    $response->assertSee('data-theme-toggle', false);
});

test('no layout renders the hardcoded Tailwind colors that used to bypass the token system', function () {
    $loginHtml = $this->get('/login')->getContent();
    expect($loginHtml)->not->toContain('text-gray-900');

    $this->seed(\Database\Seeders\PermissionSeeder::class);
    $user = User::factory()->create();
    $adminHtml = $this->actingAs($user)->get('/admin')->getContent();
    expect($adminHtml)->not->toContain('bg-white');
});
