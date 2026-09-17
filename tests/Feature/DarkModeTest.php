<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\Set;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use App\Modules\Invites\Models\Invite;
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

test('the pagination controls use design tokens, not the framework default views hardcoded Tailwind grays', function () {
    // An empty list never renders the pagination markup at all
    // ($paginator->hasPages() is false) — the previous test's assertion
    // passed for the wrong reason (nothing to check), not because the
    // pager was actually themed. Seed past one page on both a page
    // that already had this bug and the newly-paginated invite list.
    $this->seed(\Database\Seeders\PermissionSeeder::class);
    $user = User::factory()->create();
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    for ($i = 1; $i <= 26; $i++) {
        $card = Card::create(['tcgdex_id' => "me05-{$i}", 'set_id' => $set->id, 'local_id' => (string) $i, 'name' => "Card {$i}"]);
        CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => "me05-{$i}", 'condition' => 'NM', 'quantity' => 1]);
    }
    $adminHtml = $this->actingAs($user)->get('/admin')->getContent();
    expect($adminHtml)->not->toContain('bg-white');
    expect($adminHtml)->not->toContain('text-gray-700');

    $admin = User::factory()->create();
    $admin->givePermissionTo('manage-invites');
    Invite::factory()->count(26)->create();
    $invitesHtml = $this->actingAs($admin)->get('/staff/invites')->getContent();
    expect($invitesHtml)->not->toContain('bg-white');
    expect($invitesHtml)->not->toContain('text-gray-700');
});
